<?php

namespace App\Services;

use App\Models\DraftPreview;
use DomainException;
use Illuminate\Support\Facades\File;

final class DraftPreviewResultService
{
    public const CHUNK_BYTES = 4194304;
    public const MAX_CHUNKS = 512;
    private const MAX_ARCHIVE_BYTES = 2147483648;

    /** @param resource $input */
    public function uploadChunk(DraftPreview $preview, int $index, $input, array $upload, string $expectedChunkSha256): array
    {
        if (! is_resource($input)) throw new DomainException('Der Vorschau-Ergebnisblock fehlt.');
        if (! preg_match('/^[a-f0-9]{64}$/D', $expectedChunkSha256)) throw new DomainException('Die Prüfsumme des Vorschau-Ergebnisblocks ist ungültig.');
        $upload = $this->validatedUpload($preview, $upload);
        if ($index < 0 || $index >= $upload['chunkCount']) throw new DomainException('Der Vorschau-Ergebnisblock liegt außerhalb des erlaubten Bereichs.');

        return $this->withUploadLock($preview, function (string $incomingRoot) use ($preview, $index, $input, $upload, $expectedChunkSha256): array {
            $this->assertUsable($preview);
            if ($this->completedResultExists($preview, $upload['previewIdentifier'])) throw new DomainException('Das Vorschau-Ergebnis ist bereits abgeschlossen.');
            $directory = $this->incomingDirectory($incomingRoot, $upload['previewIdentifier'], true);
            $this->ensureUploadMetadata($directory, $upload);
            $final = $directory.DIRECTORY_SEPARATOR.sprintf('chunk-%06d.part', $index);
            $temporary = $directory.DIRECTORY_SEPARATOR.'.chunk-'.$index.'-'.bin2hex(random_bytes(8));
            $output = fopen($temporary, 'xb');
            if ($output === false) throw new DomainException('Der Vorschau-Ergebnisblock konnte nicht gespeichert werden.');
            try {
                $hash = hash_init('sha256');
                $bytes = 0;
                while (! feof($input)) {
                    $buffer = fread($input, 1048576);
                    if ($buffer === false) throw new DomainException('Der Vorschau-Ergebnisblock konnte nicht gelesen werden.');
                    if ($buffer === '') continue;
                    $bytes += strlen($buffer);
                    if ($bytes > self::CHUNK_BYTES) throw new DomainException('Der Vorschau-Ergebnisblock ist zu groß.');
                    $this->writeAll($output, $buffer);
                    hash_update($hash, $buffer);
                }
                fflush($output);
                $actualSha256 = hash_final($hash);
            } catch (\Throwable $exception) {
                if (is_file($temporary)) unlink($temporary);
                throw $exception;
            } finally {
                fclose($output);
            }
            try {
                if ($bytes === 0 || ($index < $upload['chunkCount'] - 1 && $bytes !== self::CHUNK_BYTES)) {
                    throw new DomainException('Der Vorschau-Ergebnisblock hat eine ungültige Größe.');
                }
                if (! hash_equals($expectedChunkSha256, $actualSha256)) throw new DomainException('Die Prüfsumme des Vorschau-Ergebnisblocks stimmt nicht.');
                if (is_link($final)) throw new DomainException('Der Vorschau-Ergebnisblock ist unsicher.');
                if (is_file($final)) {
                    if (filesize($final) !== $bytes || ! hash_equals($actualSha256, (string) hash_file('sha256', $final))) {
                        throw new DomainException('Ein bereits gespeicherter Vorschau-Ergebnisblock hat andere Daten.');
                    }
                    return ['stored' => false, 'ready' => false];
                }
                if (file_exists($final) || ! rename($temporary, $final)) throw new DomainException('Der Vorschau-Ergebnisblock konnte nicht atomar gespeichert werden.');
                $temporary = '';
                return ['stored' => true, 'ready' => false];
            } finally {
                if ($temporary !== '' && is_file($temporary)) unlink($temporary);
            }
        });
    }

    public function finalize(DraftPreview $preview, array $upload): bool
    {
        $upload = $this->validatedUpload($preview, $upload);
        return $this->withUploadLock($preview, function (string $incomingRoot) use ($preview, $upload): bool {
            $this->assertUsable($preview);
            if ($this->completedResultExists($preview, $upload['previewIdentifier'])) {
                if ($this->matchesCompletedUpload($preview, $upload)) return false;
                throw new DomainException('Das Vorschau-Ergebnis ist bereits mit einer anderen Identität abgeschlossen.');
            }
            $directory = $this->incomingDirectory($incomingRoot, $upload['previewIdentifier'], false);
            $this->ensureUploadMetadata($directory, $upload);
            $totalBytes = 0;
            $chunks = [];
            for ($index = 0; $index < $upload['chunkCount']; $index++) {
                $path = $directory.DIRECTORY_SEPARATOR.sprintf('chunk-%06d.part', $index);
                if (is_link($path) || ! is_file($path)) throw new DomainException('Das Vorschau-Ergebnis ist noch nicht vollständig hochgeladen.');
                $size = filesize($path);
                if (! is_int($size) || $size < 1 || $size > self::CHUNK_BYTES || ($index < $upload['chunkCount'] - 1 && $size !== self::CHUNK_BYTES)) {
                    throw new DomainException('Ein Vorschau-Ergebnisblock hat eine ungültige Größe.');
                }
                $totalBytes += $size;
                if ($totalBytes > self::MAX_ARCHIVE_BYTES) throw new DomainException('Das Vorschau-Ergebnis ist zu groß.');
                $chunks[] = $path;
            }

            $assembled = $directory.DIRECTORY_SEPARATOR.'.assembled-'.bin2hex(random_bytes(8)).'.tar';
            $output = fopen($assembled, 'xb');
            if ($output === false) throw new DomainException('Das Vorschau-Ergebnis konnte nicht zusammengesetzt werden.');
            try {
                $hash = hash_init('sha256');
                foreach ($chunks as $path) {
                    $input = fopen($path, 'rb');
                    if ($input === false) throw new DomainException('Ein Vorschau-Ergebnisblock konnte nicht gelesen werden.');
                    try {
                        while (! feof($input)) {
                            $buffer = fread($input, 1048576);
                            if ($buffer === false) throw new DomainException('Ein Vorschau-Ergebnisblock konnte nicht gelesen werden.');
                            if ($buffer === '') continue;
                            $this->writeAll($output, $buffer);
                            hash_update($hash, $buffer);
                        }
                    } finally {
                        fclose($input);
                    }
                }
                fflush($output);
                if (! hash_equals($upload['archiveSha256'], hash_final($hash))) throw new DomainException('Die Archiv-Prüfsumme des Vorschau-Ergebnisses stimmt nicht.');
            } catch (\Throwable $exception) {
                fclose($output);
                File::deleteDirectory($directory);
                throw $exception;
            }
            fclose($output);

            try {
                $archive = fopen($assembled, 'rb');
                if ($archive === false) throw new DomainException('Das Vorschau-Ergebnis konnte nicht geöffnet werden.');
                try {
                    $this->installArchive($preview, $archive, $upload);
                } finally {
                    fclose($archive);
                }
                File::deleteDirectory($directory);
                return true;
            } catch (\Throwable $exception) {
                File::deleteDirectory($directory);
                throw $exception;
            }
        });
    }

    public function delete(DraftPreview $preview): void
    {
        try {
            $identifier = $this->identifier($preview);
            $incomingRoot = $this->privateRoot('preview_incoming_root');
            $this->withUploadLock($preview, function (string $incomingRoot) use ($identifier): void {
                $incoming = $incomingRoot.DIRECTORY_SEPARATOR.$identifier;
                if (is_link($incoming)) unlink($incoming);
                elseif (is_dir($incoming)) File::deleteDirectory($incoming);

                $resultRoot = $this->privateRoot('preview_result_root');
                $result = $resultRoot.DIRECTORY_SEPARATOR.$identifier;
                $receipt = $resultRoot.DIRECTORY_SEPARATOR.$identifier.'.json';
                if (is_link($result)) unlink($result);
                elseif (is_dir($result)) File::deleteDirectory($result);
                if (is_link($receipt) || is_file($receipt)) unlink($receipt);
            });
            $lock = $incomingRoot.DIRECTORY_SEPARATOR.'.upload-'.$identifier.'.lock';
            if (is_file($lock) && ! is_link($lock)) unlink($lock);
        } catch (DomainException) {
            return;
        }
    }

    public function ready(DraftPreview $preview): bool
    {
        $package=app(DraftPreviewPackageService::class)->metadata($preview);
        $root=$this->privateRoot('preview_result_root');
        $receipt=$root.DIRECTORY_SEPARATOR.$package['previewIdentifier'].'.json';
        if(!file_exists($receipt)&&!is_link($receipt))return false;
        $this->receipt($preview, $package);
        return true;
    }

    /** @param resource $archive */
    private function installArchive(DraftPreview $preview, $archive, array $upload): void
    {
        $this->assertUsable($preview);
        $package = app(DraftPreviewPackageService::class)->verify($preview);
        $identifier = $package['previewIdentifier'];
        $resultRoot = $this->privateRoot('preview_result_root');
        $final = $resultRoot.DIRECTORY_SEPARATOR.$identifier;
        $stage = $resultRoot.DIRECTORY_SEPARATOR.'.pending'.DIRECTORY_SEPARATOR.$identifier.'-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists(dirname($stage), 0750);
        if (! mkdir($stage, 0750, true)) throw new DomainException('Das Vorschau-Ergebnis konnte nicht vorbereitet werden.');
        try {
            $archiveService = app(StaticReleaseArchive::class);
            $entries = $archiveService->inspect($archive);
            $archiveService->extract($archive, $entries, $stage);
            $expectedMedia = array_map(static fn (array $entry): array => [
                'publicPath' => $entry['publicPath'], 'sha256' => $entry['sha256'], 'size' => $entry['size'], 'type' => $entry['type'],
            ], $package['media']);
            app(PreviewResultArchive::class)->validateExtracted($stage, [
                'publicationId' => $identifier,
                'sequence' => $preview->id,
                'sourceRevision' => $package['sourceRevision'],
                'packageSha256' => $package['packageSha256'],
                'manifestSha256' => $package['manifestSha256'],
                'releaseFilesSha256' => $upload['releaseFilesSha256'],
                'releaseMetadataSha256' => $upload['releaseMetadataSha256'],
            ], $expectedMedia);
            $this->assertUsable($preview);
            if (is_link($final) || (file_exists($final) && ! is_dir($final))) throw new DomainException('Der Vorschau-Ergebnisordner ist unsicher.');
            if (is_dir($final)) File::deleteDirectory($final);
            if (! rename($stage, $final)) throw new DomainException('Das Vorschau-Ergebnis konnte nicht atomar aktiviert werden.');
            $this->writeReceipt($resultRoot, $identifier, [
                'archiveSha256' => $upload['archiveSha256'],
                'packageSha256' => $package['packageSha256'],
                'releaseFilesSha256' => $upload['releaseFilesSha256'],
                'releaseMetadataSha256' => $upload['releaseMetadataSha256'],
                'sourceRevision' => $package['sourceRevision'],
            ]);
        } catch (\Throwable $exception) {
            File::deleteDirectory($stage);
            throw $exception;
        }
    }

    private function validatedUpload(DraftPreview $preview, array $upload): array
    {
        $package = app(DraftPreviewPackageService::class)->metadata($preview);
        $normalized = [
            'archiveSha256' => (string) ($upload['archiveSha256'] ?? ''),
            'chunkCount' => filter_var($upload['chunkCount'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_CHUNKS]]),
            'packageSha256' => (string) ($upload['packageSha256'] ?? ''),
            'previewIdentifier' => $package['previewIdentifier'],
            'releaseFilesSha256' => (string) ($upload['releaseFilesSha256'] ?? ''),
            'releaseMetadataSha256' => (string) ($upload['releaseMetadataSha256'] ?? ''),
            'sourceRevision' => (string) ($upload['sourceRevision'] ?? ''),
        ];
        foreach (['archiveSha256', 'packageSha256', 'releaseFilesSha256', 'releaseMetadataSha256'] as $field) {
            if (! preg_match('/^[a-f0-9]{64}$/D', $normalized[$field])) throw new DomainException('Die Vorschau-Ergebnisprüfsummen sind ungültig.');
        }
        if (! is_int($normalized['chunkCount']) || ! preg_match('/^[a-f0-9]{40}$/D', $normalized['sourceRevision'])) {
            throw new DomainException('Die Vorschau-Ergebnisidentität ist ungültig.');
        }
        if (! hash_equals($package['packageSha256'], $normalized['packageSha256'])
            || ! hash_equals($package['sourceRevision'], $normalized['sourceRevision'])) {
            throw new DomainException('Das Vorschau-Ergebnis gehört nicht zum erwarteten Paket oder Quellstand.');
        }
        return $normalized;
    }

    private function ensureUploadMetadata(string $directory, array $upload): void
    {
        $path = $directory.DIRECTORY_SEPARATOR.'upload.json';
        ksort($upload, SORT_STRING);
        $bytes = json_encode($upload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        if (is_link($path)) throw new DomainException('Die Vorschau-Upload-Metadaten sind unsicher.');
        if (is_file($path)) {
            $existing = file_get_contents($path);
            if (! is_string($existing) || ! hash_equals($bytes, $existing)) throw new DomainException('Die Vorschau-Upload-Metadaten widersprechen einem begonnenen Upload.');
            return;
        }
        $temporary = $directory.DIRECTORY_SEPARATOR.'.upload-'.bin2hex(random_bytes(8));
        if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes) || ! rename($temporary, $path)) {
            if (is_file($temporary)) unlink($temporary);
            throw new DomainException('Die Vorschau-Upload-Metadaten konnten nicht gespeichert werden.');
        }
    }

    private function incomingDirectory(string $root, string $identifier, bool $create): string
    {
        $directory = $root.DIRECTORY_SEPARATOR.$identifier;
        if (is_link($directory)) throw new DomainException('Der Vorschau-Eingangspfad ist unsicher.');
        if (! is_dir($directory)) {
            if (! $create || ! mkdir($directory, 0750)) throw new DomainException('Der Vorschau-Eingangspfad ist nicht verfügbar.');
        }
        $resolved = realpath($directory);
        if ($resolved === false || ! $this->inside($resolved, $root) || is_link($directory)) throw new DomainException('Der Vorschau-Eingangspfad ist unsicher.');
        return $resolved;
    }

    private function withUploadLock(DraftPreview $preview, callable $callback): mixed
    {
        $identifier = $this->identifier($preview);
        $root = $this->privateRoot('preview_incoming_root');
        $lockPath = $root.DIRECTORY_SEPARATOR.'.upload-'.$identifier.'.lock';
        if (is_link($lockPath)) throw new DomainException('Die Vorschau-Upload-Sperre ist unsicher.');
        $lock = fopen($lockPath, 'c+b');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            if (is_resource($lock)) fclose($lock);
            throw new DomainException('Die Vorschau-Upload-Sperre ist nicht verfügbar.');
        }
        try {
            return $callback($root);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function matchesCompletedUpload(DraftPreview $preview, array $upload): bool
    {
        $resultRoot = $this->privateRoot('preview_result_root');
        $receiptPath = $resultRoot.DIRECTORY_SEPARATOR.$upload['previewIdentifier'].'.json';
        $this->receipt($preview);
        $bytes = file_get_contents($receiptPath);
        $receipt = is_string($bytes) ? json_decode($bytes, true, flags: JSON_THROW_ON_ERROR) : null;
        return is_array($receipt)
            && hash_equals($upload['archiveSha256'], (string) ($receipt['archiveSha256'] ?? ''))
            && hash_equals($upload['packageSha256'], (string) ($receipt['packageSha256'] ?? ''))
            && hash_equals($upload['releaseFilesSha256'], (string) ($receipt['releaseFilesSha256'] ?? ''))
            && hash_equals($upload['releaseMetadataSha256'], (string) ($receipt['releaseMetadataSha256'] ?? ''))
            && hash_equals($upload['sourceRevision'], (string) ($receipt['sourceRevision'] ?? ''));
    }

    private function completedResultExists(DraftPreview $preview, string $identifier): bool
    {
        $resultRoot = $this->privateRoot('preview_result_root');
        $receiptPath = $resultRoot.DIRECTORY_SEPARATOR.$identifier.'.json';
        if (! file_exists($receiptPath) && ! is_link($receiptPath)) return false;
        $this->receipt($preview);
        return true;
    }

    private function assertUsable(DraftPreview $preview): void
    {
        $current = $preview->fresh();
        if (! $current || $current->invalidated_at !== null) throw new DomainException('Diese Website-Vorschau ist nicht mehr gültig.');
        if ($current->expires_at->isPast()) throw new DomainException('Diese Website-Vorschau ist abgelaufen.');
    }

    private function identifier(DraftPreview $preview): string
    {
        if (! preg_match('#^draft-previews/([a-f0-9]{32})$#D', (string) $preview->snapshot_path, $match)) throw new DomainException('Ungültige Vorschau-Kennung.');
        return $match[1];
    }

    /** @param resource $output */
    private function writeAll($output, string $bytes): void
    {
        for ($offset = 0, $length = strlen($bytes); $offset < $length;) {
            $written = fwrite($output, substr($bytes, $offset));
            if ($written === false || $written === 0) throw new DomainException('Das Vorschau-Ergebnis konnte nicht vollständig gespeichert werden.');
            $offset += $written;
        }
    }

    /** @return array{path:string,mime:string} */
    public function page(DraftPreview $preview, string $route): array
    {
        $relative = match ($route) {
            '/' => 'index.html',
            '/film-music/' => 'film-music/index.html',
            '/directing/' => 'directing/index.html',
            '/legal/' => 'legal/index.html',
            '/privacy/' => 'privacy/index.html',
            default => preg_match('#^/projects/([a-z0-9]+(?:-[a-z0-9]+)*)/$#D', $route, $match) ? 'projects/'.$match[1].'/index.html' : throw new DomainException('Diese Vorschau-Seite ist ungültig.'),
        };
        return $this->file($preview, $relative);
    }

    /** @return array{path:string,mime:string} */
    public function file(DraftPreview $preview, string $relative): array
    {
        $relative = $this->safeResultPath($relative);
        [$root, $inventory] = $this->receipt($preview);
        $entry = collect($inventory)->firstWhere('path', $relative);
        if (! is_array($entry)) throw new DomainException('Die Vorschau-Datei ist nicht Teil des geprüften Ergebnisses.');
        $path = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $real = realpath($path);
        if ($real === false || ! $this->inside($real, $root) || is_link($path) || ! is_file($path)
            || filesize($path) !== $entry['size'] || ! hash_equals($entry['sha256'], (string) hash_file('sha256', $path))) {
            throw new DomainException('Die Vorschau-Datei wurde verändert.');
        }
        return ['path' => $path, 'mime' => $this->mime($relative)];
    }

    /** @return array{string,array} */
    private function receipt(DraftPreview $preview, ?array $package = null): array
    {
        $package ??= app(DraftPreviewPackageService::class)->metadata($preview);
        $resultRoot = $this->privateRoot('preview_result_root');
        $identifier = $package['previewIdentifier'];
        $receiptPath = $resultRoot.DIRECTORY_SEPARATOR.$identifier.'.json';
        if (is_link($receiptPath) || ! is_file($receiptPath)) throw new DomainException('Das Vorschau-Ergebnis wird noch erstellt.');
        $receiptBytes = file_get_contents($receiptPath);
        $receipt = is_string($receiptBytes) ? json_decode($receiptBytes, true, flags: JSON_THROW_ON_ERROR) : null;
        if (! is_array($receipt) || array_keys($receipt) !== ['archiveSha256', 'packageSha256', 'releaseFilesSha256', 'releaseMetadataSha256', 'sourceRevision']
            || ! hash_equals($package['packageSha256'], (string) $receipt['packageSha256'])
            || ! hash_equals($package['sourceRevision'], (string) $receipt['sourceRevision'])) throw new DomainException('Das Vorschau-Ergebnis gehört nicht zum aktuellen Entwurf.');
        foreach (['archiveSha256', 'releaseFilesSha256', 'releaseMetadataSha256'] as $field) if (! preg_match('/^[a-f0-9]{64}$/D', (string) $receipt[$field])) throw new DomainException('Das Vorschau-Ergebnis ist ungültig.');
        $root = realpath($resultRoot.DIRECTORY_SEPARATOR.$identifier);
        if ($root === false || is_link($root) || ! is_dir($root) || ! $this->inside($root, $resultRoot)) throw new DomainException('Das Vorschau-Ergebnis ist nicht verfügbar.');
        $metadataPath = $root.DIRECTORY_SEPARATOR.StaticReleaseArchive::METADATA_FILE;
        $inventoryPath = $root.DIRECTORY_SEPARATOR.StaticReleaseArchive::FILES_FILE;
        if (is_link($metadataPath) || is_link($inventoryPath) || ! is_file($metadataPath) || ! is_file($inventoryPath)) throw new DomainException('Das Vorschau-Ergebnis ist unvollständig.');
        $metadataBytes = file_get_contents($metadataPath); $inventoryBytes = file_get_contents($inventoryPath);
        if (! is_string($metadataBytes) || ! is_string($inventoryBytes)
            || ! hash_equals($receipt['releaseMetadataSha256'], hash('sha256', $metadataBytes))
            || ! hash_equals($receipt['releaseFilesSha256'], hash('sha256', $inventoryBytes))) throw new DomainException('Das Vorschau-Ergebnis wurde verändert.');
        $metadata = json_decode($metadataBytes, true, flags: JSON_THROW_ON_ERROR);
        $inventory = json_decode($inventoryBytes, true, flags: JSON_THROW_ON_ERROR);
        if (($metadata['publicationId'] ?? null) !== $identifier || ($metadata['sequence'] ?? null) !== $preview->id
            || ($metadata['packageSha256'] ?? null) !== $package['packageSha256'] || ! is_array($inventory)) throw new DomainException('Das Vorschau-Ergebnis hat eine ungültige Identität.');
        return [$root, $inventory];
    }

    private function writeReceipt(string $root, string $identifier, array $receipt): void
    {
        ksort($receipt, SORT_STRING);
        $bytes = json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        $final = $root.DIRECTORY_SEPARATOR.$identifier.'.json';
        $temporary = $root.DIRECTORY_SEPARATOR.'.receipt-'.$identifier.'-'.bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes) || ! rename($temporary, $final)) {
            @unlink($temporary); throw new DomainException('Die Vorschau-Ergebnisbestätigung konnte nicht gespeichert werden.');
        }
    }

    private function privateRoot(string $key): string
    {
        $configured = (string) config('cms.'.$key);
        $normalized = str_replace('\\', '/', trim($configured));
        if ($normalized === '' || (! str_starts_with($normalized, '/') && ! preg_match('#^[A-Za-z]:/#', $normalized)) || preg_match('#(^|/)\.\.(/|$)#', $normalized)) throw new DomainException('Ein privater Vorschau-Pfad ist ungültig.');
        $runtimeSiteRoot = $this->runtimeSiteRoot();
        if (! $this->inside($normalized, $runtimeSiteRoot)) throw new DomainException('Ein privater Vorschau-Pfad ist ungültig.');
        if ($this->containsSymlinkComponent($normalized, $runtimeSiteRoot)) throw new DomainException('Ein privater Vorschau-Pfad ist nicht verfügbar.');
        File::ensureDirectoryExists($configured, 0750);
        $root = realpath($configured);
        $resolvedRuntimeSiteRoot = realpath($runtimeSiteRoot);
        if ($root === false || $resolvedRuntimeSiteRoot === false || ! $this->inside($root, $resolvedRuntimeSiteRoot)
            || $this->containsSymlinkComponent($normalized, $runtimeSiteRoot) || ! is_dir($root)) throw new DomainException('Ein privater Vorschau-Pfad ist nicht verfügbar.');
        foreach ([public_path(), config('cms.public_site_root'), config('cms.static_release_root')] as $forbidden) {
            $resolved = is_string($forbidden) ? realpath($forbidden) : false;
            if ($resolved && ($this->inside($root, $resolved) || $this->inside($resolved, $root))) throw new DomainException('Vorschau-Ergebnisse müssen außerhalb öffentlicher Pfade liegen.');
        }
        return $root;
    }

    private function safeResultPath(string $path): string
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/') || preg_match('#(^|/)\.{1,2}(/|$)#', $path)
            || ! preg_match('#^(?:[A-Za-z0-9._-]+/)*[A-Za-z0-9._-]+$#D', $path)) throw new DomainException('Ungültiger Vorschau-Dateipfad.');
        return $path;
    }

    private function mime(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'html', 'htm' => 'text/html; charset=UTF-8', 'css' => 'text/css; charset=UTF-8', 'js', 'mjs' => 'text/javascript; charset=UTF-8',
            'json', 'map' => 'application/json', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp',
            'gif' => 'image/gif', 'avif' => 'image/avif', 'ico' => 'image/x-icon', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg',
            'mp4' => 'video/mp4', 'webm' => 'video/webm', 'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf',
            'txt' => 'text/plain; charset=UTF-8', 'xml' => 'application/xml', 'webmanifest' => 'application/manifest+json', default => 'application/octet-stream',
        };
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
