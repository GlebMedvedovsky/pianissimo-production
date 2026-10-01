<?php

namespace App\Services;

use App\Models\DraftPreview;
use DomainException;
use Illuminate\Support\Facades\File;

final class DraftPreviewPackageService
{
    public const CONTRACT_VERSION = 1;

    public function prepare(DraftPreview $preview, array $manifest): array
    {
        $sourceRevision = (string) config('cms.source_revision');
        if (! preg_match('/^[a-f0-9]{40}$/D', $sourceRevision)) {
            throw new DomainException('Die Quellversion für die Paket-Vorschau ist ungültig.');
        }
        $root = $this->root();
        $identifier = $this->identifier($preview);
        $final = $root.DIRECTORY_SEPARATOR.$identifier;
        $pending = $root.DIRECTORY_SEPARATOR.'.pending'.DIRECTORY_SEPARATOR.$identifier.'-'.bin2hex(random_bytes(8));
        if (file_exists($final) || is_link($final)) {
            throw new DomainException('Das Vorschau-Paket existiert bereits.');
        }
        File::ensureDirectoryExists(dirname($pending), 0750);
        if (! mkdir($pending, 0750, true)) {
            throw new DomainException('Das Vorschau-Paket konnte nicht vorbereitet werden.');
        }

        try {
            $manifestBytes = $this->canonicalJson($manifest)."\n";
            $manifestSha256 = hash('sha256', $manifestBytes);
            $media = [];
            foreach ($preview->media as $assetId => $entry) {
                $file = $this->safeFile($entry['file'] ?? null);
                $mime = (string) ($entry['mime'] ?? '');
                $type = str_starts_with($mime, 'image/') ? 'image' : (str_starts_with($mime, 'audio/') ? 'audio' : (str_starts_with($mime, 'video/') ? 'video' : null));
                if ($type === null || ! is_string($assetId) || $assetId === ''
                    || ! preg_match('/^[a-f0-9]{64}$/D', (string) ($entry['sha256'] ?? ''))
                    || ! is_int($entry['size'] ?? null) || $entry['size'] < 0) {
                    throw new DomainException('Das Vorschau-Paket enthält ungültige Medien-Metadaten.');
                }
                $media[] = [
                    'assetId' => $assetId,
                    'publicPath' => 'media/preview/'.$file,
                    'sha256' => $entry['sha256'],
                    'size' => $entry['size'],
                    'type' => $type,
                ];
            }
            usort($media, static fn (array $left, array $right): int => strcmp($left['publicPath'], $right['publicPath']));
            $digestPayload = [
                'contractVersion' => self::CONTRACT_VERSION,
                'draftHash' => $preview->draft_hash,
                'manifestSha256' => $manifestSha256,
                'media' => $media,
                'sourceRevision' => $sourceRevision,
            ];
            $packageSha256 = hash('sha256', $this->canonicalJson($digestPayload));
            $metadata = [
                ...$digestPayload,
                'createdAt' => $preview->created_at->toIso8601String(),
                'packageSha256' => $packageSha256,
                'previewId' => $preview->id,
                'previewIdentifier' => $identifier,
            ];
            $this->write($pending.DIRECTORY_SEPARATOR.'manifest.json', $manifestBytes);
            $this->write($pending.DIRECTORY_SEPARATOR.'preview.json', $this->canonicalJson($metadata)."\n");
            $snapshot = $this->snapshotDirectory($preview);
            foreach ($media as $entry) {
                $target = $pending.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $entry['publicPath']);
                File::ensureDirectoryExists(dirname($target), 0750);
                $source = $snapshot.DIRECTORY_SEPARATOR.'media'.DIRECTORY_SEPARATOR.basename($entry['publicPath']);
                if (! copy($source, $target)
                    || filesize($target) !== $entry['size']
                    || ! hash_equals($entry['sha256'], (string) hash_file('sha256', $target))) {
                    throw new DomainException('Ein Vorschau-Medium konnte nicht sicher paketiert werden.');
                }
            }
            $this->verifyDirectory($pending, $preview, true);
            if (! rename($pending, $final)) {
                throw new DomainException('Das Vorschau-Paket konnte nicht atomar bereitgestellt werden.');
            }

            return $metadata;
        } catch (\Throwable $exception) {
            File::deleteDirectory($pending);
            throw $exception;
        }
    }

    /** Validate immutable package identity and declared media metadata without reading media bytes. */
    public function metadata(DraftPreview $preview): array
    {
        return $this->verifyDirectory($this->directory($preview), $preview, false);
    }

    /** Validate immutable package identity, complete file inventory, and every media byte digest. */
    public function verify(DraftPreview $preview): array
    {
        return $this->verifyDirectory($this->directory($preview), $preview, true);
    }

    /** @return array{path:string,sha256:string,filename:string} */
    public function transport(DraftPreview $preview): array
    {
        $directory = $this->directory($preview);
        $this->verifyDirectory($directory, $preview, true);
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($iterator as $entry) {
            if ($entry->isLink() || ! $entry->isFile()) {
                throw new DomainException('Das Vorschau-Paket enthält einen Link oder eine Spezialdatei.');
            }
            $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($directory) + 1));
            $files[$relative] = $entry->getPathname();
        }
        ksort($files, SORT_STRING);
        $temporaryRoot = storage_path('framework/cache/preview-transports');
        File::ensureDirectoryExists($temporaryRoot, 0750);
        $temporary = tempnam($temporaryRoot, 'preview-');
        if ($temporary === false) {
            throw new DomainException('Der Vorschau-Transport konnte nicht erstellt werden.');
        }
        $archive = $temporary.'.tar';
        if (! rename($temporary, $archive)) {
            @unlink($temporary);
            throw new DomainException('Der Vorschau-Transport konnte nicht erstellt werden.');
        }
        try {
            $handle = fopen($archive, 'wb');
            if ($handle === false) {
                throw new DomainException('Der Vorschau-Transport konnte nicht geöffnet werden.');
            }
            try {
                foreach ($files as $relative => $path) {
                    $size = filesize($path);
                    if ($size === false) {
                        throw new DomainException('Eine Vorschau-Paketdatei ist nicht verfügbar.');
                    }
                    $this->writeAll($handle, $this->tarHeader($relative, $size));
                    $input = fopen($path, 'rb');
                    if ($input === false || stream_copy_to_stream($input, $handle) !== $size) {
                        if (is_resource($input)) fclose($input);
                        throw new DomainException('Eine Vorschau-Paketdatei konnte nicht übertragen werden.');
                    }
                    fclose($input);
                    $padding = (512 - ($size % 512)) % 512;
                    if ($padding) $this->writeAll($handle, str_repeat("\0", $padding));
                }
                $this->writeAll($handle, str_repeat("\0", 1024));
            } finally {
                fclose($handle);
            }
            return ['path' => $archive, 'sha256' => (string) hash_file('sha256', $archive), 'filename' => 'pianissimo-preview-'.$this->identifier($preview).'.tar'];
        } catch (\Throwable $exception) {
            @unlink($archive);
            throw $exception;
        }
    }

    public function delete(DraftPreview $preview): void
    {
        try {
            $identifier = $this->identifier($preview);
        } catch (DomainException) {
            return;
        }
        $path=rtrim((string) config('cms.preview_package_root'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$identifier;
        if(is_link($path))@unlink($path);elseif(is_dir($path))File::deleteDirectory($path);
    }

    private function verifyDirectory(string $directory, DraftPreview $preview, bool $verifyMediaBytes): array
    {
        $metadataBytes = $this->regularBytes($directory.DIRECTORY_SEPARATOR.'preview.json');
        $manifestBytes = $this->regularBytes($directory.DIRECTORY_SEPARATOR.'manifest.json');
        $metadata = json_decode($metadataBytes, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($metadata) || array_keys($metadata) !== ['contractVersion','createdAt','draftHash','manifestSha256','media','packageSha256','previewId','previewIdentifier','sourceRevision']
            || ! hash_equals($metadataBytes, $this->canonicalJson($metadata)."\n")
            || ($metadata['contractVersion'] ?? null) !== self::CONTRACT_VERSION
            || ($metadata['previewId'] ?? null) !== $preview->id
            || ($metadata['previewIdentifier'] ?? null) !== $this->identifier($preview)
            || ($metadata['draftHash'] ?? null) !== $preview->draft_hash
            || ($metadata['sourceRevision'] ?? null) !== config('cms.source_revision')
            || ! hash_equals((string) ($metadata['manifestSha256'] ?? ''), hash('sha256', $manifestBytes))
            || ! preg_match('/^[a-f0-9]{64}$/D', (string) ($metadata['packageSha256'] ?? ''))
            || ! is_array($metadata['media'] ?? null)) {
            throw new DomainException('Das unveränderliche Vorschau-Paket ist ungültig.');
        }
        $digest = hash('sha256', $this->canonicalJson([
            'contractVersion' => $metadata['contractVersion'],
            'draftHash' => $metadata['draftHash'],
            'manifestSha256' => $metadata['manifestSha256'],
            'media' => $metadata['media'],
            'sourceRevision' => $metadata['sourceRevision'],
        ]));
        if (! hash_equals($metadata['packageSha256'], $digest)) {
            throw new DomainException('Die Prüfsumme des Vorschau-Pakets ist ungültig.');
        }
        $expected = ['manifest.json' => true, 'preview.json' => true];
        $previous = null;
        foreach ($metadata['media'] as $entry) {
            if (! is_array($entry) || array_keys($entry) !== ['assetId','publicPath','sha256','size','type']) throw new DomainException('Das Vorschau-Paket enthält ungültige Medien-Metadaten.');
            $path = $this->safeMediaPath($entry['publicPath'] ?? null);
            if ($previous !== null && strcmp($previous, $path) >= 0
                || ! is_string($entry['assetId'] ?? null) || $entry['assetId'] === ''
                || ! preg_match('/^[a-f0-9]{64}$/D', (string) ($entry['sha256'] ?? ''))
                || ! is_int($entry['size'] ?? null) || $entry['size'] < 0
                || ! in_array($entry['type'] ?? null, ['image', 'audio', 'video'], true)) {
                throw new DomainException('Das Vorschau-Paket enthält ungültige Medien-Metadaten.');
            }
            if ($verifyMediaBytes) {
                $file = $directory.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
                if (is_link($file) || ! is_file($file) || filesize($file) !== $entry['size'] || ! hash_equals($entry['sha256'], (string) hash_file('sha256', $file))) {
                    throw new DomainException('Ein Medium des Vorschau-Pakets wurde verändert.');
                }
            }
            $expected[$path] = true;
            $previous = $path;
        }
        if (! $verifyMediaBytes) return $metadata;
        $actual = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($iterator as $entry) {
            if ($entry->isLink() || ! $entry->isFile()) throw new DomainException('Das Vorschau-Paket enthält einen Link oder eine Spezialdatei.');
            $actual[str_replace('\\', '/', substr($entry->getPathname(), strlen($directory) + 1))] = true;
        }
        if (array_diff_key($expected, $actual) !== [] || array_diff_key($actual, $expected) !== []) {
            throw new DomainException('Der Dateibestand des Vorschau-Pakets ist ungültig.');
        }
        return $metadata;
    }

    private function root(): string
    {
        $configured = (string) config('cms.preview_package_root');
        if (! $this->absoluteSafe($configured)) throw new DomainException('Der Vorschau-Paketpfad ist ungültig.');
        $runtimeSiteRoot = $this->runtimeSiteRoot();
        if (! $this->inside($configured, $runtimeSiteRoot)) throw new DomainException('Der Vorschau-Paketpfad ist ungültig.');
        if ($this->containsSymlinkComponent($configured, $runtimeSiteRoot)) throw new DomainException('Der Vorschau-Paketpfad ist nicht verfügbar.');
        File::ensureDirectoryExists($configured, 0750);
        $root = realpath($configured);
        $resolvedRuntimeSiteRoot = realpath($runtimeSiteRoot);
        if ($root === false || $resolvedRuntimeSiteRoot === false || ! $this->inside($root, $resolvedRuntimeSiteRoot)
            || $this->containsSymlinkComponent($configured, $runtimeSiteRoot) || ! is_dir($root)) throw new DomainException('Der Vorschau-Paketpfad ist nicht verfügbar.');
        foreach ([public_path(), config('cms.public_site_root'), config('cms.static_release_root')] as $forbidden) {
            $resolved = is_string($forbidden) ? realpath($forbidden) : false;
            if ($resolved && ($this->inside($root, $resolved) || $this->inside($resolved, $root))) throw new DomainException('Vorschau-Pakete müssen außerhalb öffentlicher Pfade liegen.');
        }
        return $root;
    }

    private function directory(DraftPreview $preview): string
    {
        $root = $this->root();
        $path = realpath($root.DIRECTORY_SEPARATOR.$this->identifier($preview));
        if ($path === false || is_link($path) || ! is_dir($path) || ! $this->inside($path, $root)) throw new DomainException('Das Vorschau-Paket ist nicht verfügbar.');
        return $path;
    }

    private function snapshotDirectory(DraftPreview $preview): string
    {
        $root = realpath((string) config('cms.preview_storage_root'));
        $path = $root ? realpath($root.DIRECTORY_SEPARATOR.$this->identifier($preview)) : false;
        if ($root === false || $path === false || is_link($path) || ! is_dir($path) || ! $this->inside($path, $root)) throw new DomainException('Der Vorschau-Speicherort ist nicht verfügbar.');
        return $path;
    }

    private function identifier(DraftPreview $preview): string
    {
        if (! preg_match('#^draft-previews/([a-f0-9]{32})$#D', (string) $preview->snapshot_path, $match)) throw new DomainException('Ungültige Vorschau-Kennung.');
        return $match[1];
    }

    private function safeFile(mixed $file): string
    {
        if (! is_string($file) || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*\.(?:webp|jpg|png|mp3|wav|ogg|mp4|webm)$/D', $file)) throw new DomainException('Ungültiger Vorschau-Medienname.');
        return $file;
    }

    private function safeMediaPath(mixed $path): string
    {
        if (! is_string($path) || ! str_starts_with($path, 'media/preview/')) throw new DomainException('Ungültiger Vorschau-Medienpfad.');
        $this->safeFile(substr($path, strlen('media/preview/')));
        return $path;
    }

    private function regularBytes(string $path): string
    {
        if (is_link($path) || ! is_file($path) || ($bytes = file_get_contents($path)) === false) throw new DomainException('Eine Vorschau-Paketdatei fehlt.');
        return $bytes;
    }

    private function write(string $path, string $bytes): void
    {
        if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) throw new DomainException('Das Vorschau-Paket konnte nicht geschrieben werden.');
    }

    private function tarHeader(string $path, int $size): string
    {
        if (! preg_match('#^(?:manifest\.json|preview\.json|media/preview/[a-z0-9-]+\.(?:webp|jpg|png|mp3|wav|ogg|mp4|webm))$#D', $path) || strlen($path) > 100) throw new DomainException('Ungültiger Vorschau-Transportpfad.');
        $octal = static fn (int $value, int $length): string => sprintf('%0'.($length - 1).'o', $value)."\0";
        $header = str_pad($path, 100, "\0").$octal(0644, 8).$octal(0, 8).$octal(0, 8).$octal($size, 12).$octal(0, 12).str_repeat(' ', 8).'0'.str_repeat("\0", 100).'ustar'."\0".'00'.str_repeat("\0", 32).str_repeat("\0", 32).$octal(0, 8).$octal(0, 8).str_repeat("\0", 155).str_repeat("\0", 12);
        if (strlen($header) !== 512) throw new DomainException('Ungültiger Vorschau-Transportheader.');
        return substr_replace($header, sprintf('%06o', array_sum(unpack('C*', $header)))."\0 ", 148, 8);
    }

    private function writeAll($handle, string $bytes): void
    {
        for ($offset = 0, $length = strlen($bytes); $offset < $length;) {
            $written = fwrite($handle, substr($bytes, $offset));
            if ($written === false || $written === 0) throw new DomainException('Der Vorschau-Transport konnte nicht geschrieben werden.');
            $offset += $written;
        }
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode($this->canonicalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) return $value;
        if (array_is_list($value)) return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);
        return $value;
    }

    private function absoluteSafe(string $path): bool
    {
        $path = str_replace('\\', '/', trim($path));
        return ($path !== '' && (str_starts_with($path, '/') || preg_match('#^[A-Za-z]:/#', $path)) && ! preg_match('#(^|/)\.\.(/|$)#', $path));
    }

    private function inside(string $candidate, string $root): bool
    {
        $candidate = str_replace('\\', '/', $candidate); $root = rtrim(str_replace('\\', '/', $root), '/');
        return $candidate === $root || str_starts_with($candidate, $root.'/');
    }

    private function runtimeSiteRoot(?string $applicationBasePath = null): string
    {
        return rtrim(str_replace('\\', '/', dirname($applicationBasePath ?? base_path(), 2)), '/');
    }

    private function containsSymlinkComponent(string $path, ?string $runtimeSiteRoot = null, ?callable $isLink = null): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $runtimeSiteRoot = rtrim(str_replace('\\', '/', $runtimeSiteRoot ?? $this->runtimeSiteRoot()), '/');
        $isLink ??= static fn (string $candidate): bool => is_link($candidate);
        if (! $this->inside($path, $runtimeSiteRoot)) return true;
        if ($isLink($runtimeSiteRoot)) return true;
        $relative = ltrim(substr($path, strlen($runtimeSiteRoot)), '/');
        if ($relative === '') return false;
        $prefix = $runtimeSiteRoot;
        foreach (explode('/', $relative) as $part) {
            $prefix .= '/'.$part;
            if ($isLink($prefix)) return true;
        }
        return false;
    }
}
