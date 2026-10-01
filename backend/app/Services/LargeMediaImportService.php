<?php

namespace App\Services;

use App\Models\MediaAsset;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class LargeMediaImportService
{
    public const SSH_INCOMING_PATH = '/pianissimo-production.de/private/incoming/media/';
    private const LOCK_FILE = '.pianissimo-media-import.lock';

    /** @return array<int,array{name:string,size:int,mime:string,type:string,modified:int,label:string}> */
    public function pending(): array
    {
        $root = $this->root();
        $maximum = $this->maximumBytes();
        $entries = [];
        foreach (scandir($root) ?: [] as $name) {
            if (! $this->validSelection($name, false)) continue;
            $path = $root.DIRECTORY_SEPARATOR.$name;
            $stat = lstat($path);
            if (! is_array($stat) || is_link($path) || ! $this->regular($stat) || $stat['size'] < 1 || $stat['size'] > $maximum) continue;
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
            if (! is_string($mime) || ! isset(MediaStorage::MIME_TYPES[$mime])) continue;
            $type = MediaStorage::MIME_TYPES[$mime];
            $label = $name.' · '.$this->humanSize((int) $stat['size']).' · '.$this->typeLabel($type).' · '.date('d.m.Y H:i', (int) $stat['mtime']);
            $entries[] = ['name' => $name, 'size' => (int) $stat['size'], 'mime' => $mime, 'type' => $type, 'modified' => (int) $stat['mtime'], 'label' => $label];
        }
        usort($entries, static fn (array $left, array $right): int => strnatcasecmp($left['name'], $right['name']));
        return $entries;
    }

    public function options(): array
    {
        $options = [];
        foreach ($this->pending() as $entry) $options[$entry['name']] = $entry['label'];
        return $options;
    }

    public function import(
        string $selection,
        array $data,
        ?callable $afterCopy = null,
        ?callable $afterPromotion = null,
        ?callable $beforeCommit = null,
    ): MediaAsset
    {
        $this->validSelection($selection, true);
        $attributes = $this->validatedAttributes($data);

        return $this->withLock(function (string $root) use ($selection, $attributes, $afterCopy, $afterPromotion, $beforeCommit): MediaAsset {
            if (MediaAsset::withTrashed()->whereKey($attributes['id'])->exists()) {
                throw new DomainException('Diese stabile Medien-ID ist bereits vergeben.');
            }

            $source = $root.DIRECTORY_SEPARATOR.$selection;
            $pathStat = lstat($source);
            if (! is_array($pathStat) || is_link($source) || ! $this->regular($pathStat)) {
                throw new DomainException('Die ausgewählte SFTP-Datei ist keine sichere reguläre Datei.');
            }
            $resolved = realpath($source);
            if ($resolved === false || ! $this->inside($resolved, $root) || $resolved !== $source) {
                throw new DomainException('Die ausgewählte SFTP-Datei liegt außerhalb des erlaubten Eingangsordners.');
            }
            if ($pathStat['size'] < 1 || $pathStat['size'] > $this->maximumBytes()) {
                throw new DomainException('Die ausgewählte SFTP-Datei überschreitet das konfigurierte Import-Limit.');
            }

            $storage = app(MediaStorage::class);
            $originalsRoot = $storage->originalsRoot(true);
            $assetDirectory = $originalsRoot.DIRECTORY_SEPARATOR.$attributes['id'];
            if (file_exists($assetDirectory) || is_link($assetDirectory)) {
                throw new DomainException('Für diese stabile Medien-ID existieren bereits private Dateien.');
            }
            $pendingDirectory = $originalsRoot.DIRECTORY_SEPARATOR.'pending';
            if (is_link($pendingDirectory) || (file_exists($pendingDirectory) && ! is_dir($pendingDirectory))) {
                throw new DomainException('Der temporäre Medienbereich ist unsicher.');
            }
            if (! is_dir($pendingDirectory) && ! mkdir($pendingDirectory, 0750)) {
                throw new DomainException('Der temporäre Medienbereich konnte nicht vorbereitet werden.');
            }
            $pendingDirectory = realpath($pendingDirectory);
            if ($pendingDirectory === false || ! $this->inside($pendingDirectory, $originalsRoot)) {
                throw new DomainException('Der temporäre Medienbereich ist unsicher.');
            }

            $temporaryName = 'sftp-'.bin2hex(random_bytes(12)).'.bin';
            $temporary = $pendingDirectory.DIRECTORY_SEPARATOR.$temporaryName;
            $relative = 'media/originals/pending/'.$temporaryName;
            $input = fopen($source, 'rb');
            $output = fopen($temporary, 'xb');
            if ($input === false || $output === false) {
                if (is_resource($input)) fclose($input);
                if (is_resource($output)) fclose($output);
                throw new DomainException('Die SFTP-Datei konnte nicht sicher geöffnet werden.');
            }

            $metadata = null;
            $canonicalCreated = false;
            $claimedSource = null;
            $committed = false;
            try {
                $openedStat = fstat($input);
                if (! is_array($openedStat) || ! $this->sameFile($pathStat, $openedStat) || ! $this->regular($openedStat)) {
                    throw new DomainException('Die SFTP-Datei wurde zwischen Auswahl und Import verändert.');
                }
                $hash = hash_init('sha256');
                $bytes = 0;
                while (! feof($input)) {
                    $buffer = fread($input, 1048576);
                    if ($buffer === false) throw new DomainException('Die SFTP-Datei konnte nicht vollständig gelesen werden.');
                    if ($buffer === '') continue;
                    $bytes += strlen($buffer);
                    if ($bytes > $this->maximumBytes()) throw new DomainException('Die SFTP-Datei überschreitet das konfigurierte Import-Limit.');
                    $this->writeAll($output, $buffer);
                    hash_update($hash, $buffer);
                }
                fflush($output);
                $copiedSha = hash_final($hash);
                if ($afterCopy !== null) $afterCopy($source);
                clearstatcache(true, $source);
                $afterHandle = fstat($input);
                $afterPath = lstat($source);
                if ($bytes !== $pathStat['size'] || ! is_array($afterHandle) || ! is_array($afterPath)
                    || ! $this->sameFile($openedStat, $afterHandle) || ! $this->sameFile($openedStat, $afterPath)) {
                    throw new DomainException('Die SFTP-Datei hat sich während des Imports verändert. Bitte warten Sie auf das vollständige Ende der Übertragung.');
                }
                fclose($input); $input = null;
                fclose($output); $output = null;

                $metadata = $storage->inspectStored(
                    $relative,
                    $attributes['id'],
                    $selection,
                    (int) config('cms.media_import_max_kb'),
                    $canonicalCreated,
                );
                if ($afterPromotion !== null) $afterPromotion($metadata, $source);
                if (! hash_equals($copiedSha, $metadata['sha256'])) {
                    throw new DomainException('Die Medien-Prüfsumme hat sich während der Validierung geändert.');
                }

                $claimedSource = $root.DIRECTORY_SEPARATOR.'.pianissimo-importing-'.bin2hex(random_bytes(12));
                clearstatcache(true, $source);
                $current = lstat($source);
                if (! is_array($current) || ! $this->sameFile($openedStat, $current)
                    || file_exists($claimedSource) || is_link($claimedSource) || ! rename($source, $claimedSource)) {
                    throw new DomainException('Die geprüfte SFTP-Datei konnte nicht sicher für den Import reserviert werden.');
                }
                clearstatcache(true, $claimedSource);
                $claimedStat = lstat($claimedSource);
                if (! is_array($claimedStat) || ! $this->sameClaimedFile($openedStat, $claimedStat)) {
                    throw new DomainException('Die SFTP-Datei wurde vor dem Abschluss des Imports ausgetauscht.');
                }

                $asset = DB::transaction(function () use ($attributes, $metadata, $beforeCommit, $claimedSource): MediaAsset {
                    $asset = MediaAsset::create([...$attributes, ...$metadata]);
                    app(AuditLogger::class)->record('media', $asset->id, 'sftp-import', $metadata['sha256'], [
                        'mime' => $metadata['mime_type'], 'size' => $metadata['file_size'],
                    ]);
                    if ($beforeCommit !== null) $beforeCommit($asset, $claimedSource);
                    return $asset;
                });
                $committed = true;

                if (is_file($claimedSource) && ! is_link($claimedSource) && ! unlink($claimedSource)) {
                    Log::warning('A consumed SFTP media import source could not be removed.', [
                        'media_id' => $asset->id,
                        'filename' => $selection,
                    ]);
                }
                return $asset;
            } catch (Throwable $exception) {
                if (! $committed && is_string($claimedSource) && is_file($claimedSource) && ! is_link($claimedSource)
                    && ! file_exists($source) && ! is_link($source) && ! rename($claimedSource, $source)) {
                    Log::error('A failed SFTP media import source could not be restored.', [
                        'media_id' => $attributes['id'],
                        'filename' => $selection,
                    ]);
                }
                if (! $committed && $canonicalCreated && is_array($metadata) && isset($metadata['storage_path'])) {
                    try {
                        $storage->deleteStored($metadata['storage_path']);
                    } catch (Throwable $cleanupException) {
                        Log::error('A failed SFTP media import canonical file could not be removed.', [
                            'media_id' => $attributes['id'],
                        ]);
                        throw new DomainException('Der fehlgeschlagene Medienimport konnte nicht vollständig bereinigt werden.', 0, $exception);
                    }
                }
                throw $exception;
            } finally {
                if (is_resource($input)) fclose($input);
                if (is_resource($output)) fclose($output);
                if (is_file($temporary) && ! is_link($temporary)) unlink($temporary);
            }
        });
    }

    private function withLock(callable $callback): mixed
    {
        $root = $this->root();
        $lockPath = $root.DIRECTORY_SEPARATOR.self::LOCK_FILE;
        if (is_link($lockPath)) throw new DomainException('Die SFTP-Importsperre ist unsicher.');
        $lock = fopen($lockPath, 'c+b');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            if (is_resource($lock)) fclose($lock);
            throw new DomainException('Die SFTP-Importsperre ist nicht verfügbar.');
        }
        try {
            return $callback($root);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function root(): string
    {
        $configured = (string) config('cms.media_incoming_root');
        $normalized = rtrim(str_replace('\\', '/', trim($configured)), '/');
        $runtimeRoot = $this->runtimeSiteRoot();
        if ($normalized === '' || (! str_starts_with($normalized, '/') && ! preg_match('#^[A-Za-z]:/#', $normalized))
            || preg_match('#(^|/)\.\.(/|$)#', $normalized) || ! $this->inside($normalized, $runtimeRoot)
            || $this->containsSymlinkComponent($normalized, $runtimeRoot)) {
            throw new DomainException('Der SFTP-Medieneingang ist unsicher konfiguriert.');
        }
        $root = realpath($configured);
        $resolvedRuntimeRoot = realpath($runtimeRoot);
        if ($root === false || $resolvedRuntimeRoot === false || ! is_dir($root)
            || ! $this->inside($root, $resolvedRuntimeRoot)
            || $this->containsSymlinkComponent($normalized, $runtimeRoot)
            || ! is_readable($root) || ! is_writable($root)) {
            throw new DomainException('Der SFTP-Medieneingang ist nicht les- und schreibbar verfügbar.');
        }
        foreach ([public_path(), config('cms.public_site_root')] as $public) {
            $resolved = is_string($public) ? realpath($public) : false;
            if ($resolved && ($this->inside($root, $resolved) || $this->inside($resolved, $root))) {
                throw new DomainException('Der SFTP-Medieneingang muss außerhalb öffentlicher Verzeichnisse liegen.');
            }
        }
        return $root;
    }

    private function validatedAttributes(array $data): array
    {
        $id = trim((string) ($data['id'] ?? ''));
        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $id) || strlen($id) > 100) {
            throw new DomainException('Die stabile Medien-ID ist ungültig.');
        }
        $purpose = (string) ($data['purpose'] ?? 'other');
        $state = (string) ($data['state'] ?? 'temporary');
        $rights = (string) ($data['rights_scope'] ?? 'unconfirmed');
        if (! in_array($purpose, ['hero', 'menu', 'about', 'cover', 'gallery', 'hover', 'trailer', 'audio', 'other'], true)
            || ! in_array($state, ['approved', 'temporary', 'test'], true)
            || ! in_array($rights, ['unconfirmed', 'existing_local_draft', 'next_draft', 'public'], true)) {
            throw new DomainException('Die Medienangaben sind ungültig.');
        }
        return [
            'id' => $id,
            'purpose' => $purpose,
            'alt' => trim((string) ($data['alt'] ?? '')),
            'caption' => $this->nullableText($data['caption'] ?? null),
            'credit' => $this->nullableText($data['credit'] ?? null),
            'license' => $this->nullableText($data['license'] ?? null),
            'source' => $this->nullableText($data['source'] ?? null),
            'state' => $state,
            'rights_scope' => $rights,
            'crop' => ['x' => 50, 'y' => 50],
        ];
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function validSelection(string $name, bool $throw): bool
    {
        $valid = $name !== '' && strlen($name) <= 255 && ! str_starts_with($name, '.')
            && ! str_contains($name, "\0") && ! str_contains($name, '/') && ! str_contains($name, '\\')
            && ! str_contains($name, '%') && preg_match('/^[\pL\pN][\pL\pN ._()\-]*$/uD', $name) === 1;
        if (! $valid && $throw) throw new DomainException('Die ausgewählte SFTP-Datei ist ungültig.');
        return $valid;
    }

    private function maximumBytes(): int
    {
        $application = (int) config('cms.media_max_kb');
        $import = (int) config('cms.media_import_max_kb');
        if ($application < 1 || $import < 1) throw new DomainException('Die Medienlimits sind ungültig konfiguriert.');
        return min($application, $import) * 1024;
    }

    private function sameFile(array $left, array $right): bool
    {
        foreach (['dev', 'ino', 'mode', 'size', 'mtime', 'ctime'] as $field) {
            if (($left[$field] ?? null) !== ($right[$field] ?? null)) return false;
        }
        return true;
    }

    private function sameClaimedFile(array $left, array $right): bool
    {
        // Renaming the verified source into its hidden claim can legitimately
        // change ctime while device, inode, mode, size, and mtime stay stable.
        foreach (['dev', 'ino', 'mode', 'size', 'mtime'] as $field) {
            if (($left[$field] ?? null) !== ($right[$field] ?? null)) return false;
        }
        return true;
    }

    private function regular(array $stat): bool
    {
        return (((int) ($stat['mode'] ?? 0)) & 0170000) === 0100000;
    }

    /** @param resource $output */
    private function writeAll($output, string $bytes): void
    {
        for ($offset = 0, $length = strlen($bytes); $offset < $length;) {
            $written = fwrite($output, substr($bytes, $offset));
            if ($written === false || $written === 0) throw new DomainException('Die SFTP-Datei konnte nicht vollständig kopiert werden.');
            $offset += $written;
        }
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 1, ',', '.').' GiB';
        if ($bytes >= 1048576) return number_format($bytes / 1048576, 1, ',', '.').' MiB';
        if ($bytes >= 1024) return number_format($bytes / 1024, 1, ',', '.').' KiB';
        return $bytes.' Byte';
    }

    private function typeLabel(string $type): string
    {
        return ['image' => 'Bild', 'video' => 'Video', 'audio' => 'Audio'][$type] ?? $type;
    }

    private function runtimeSiteRoot(?string $applicationBasePath = null): string
    {
        return rtrim(str_replace('\\', '/', dirname($applicationBasePath ?? base_path(), 2)), '/');
    }

    private function inside(string $candidate, string $root): bool
    {
        $candidate = str_replace('\\', '/', $candidate);
        $root = rtrim(str_replace('\\', '/', $root), '/');
        return $candidate === $root || str_starts_with($candidate, $root.'/');
    }

    private function containsSymlinkComponent(string $path, ?string $runtimeRoot = null, ?callable $isLink = null): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $runtimeRoot = rtrim(str_replace('\\', '/', $runtimeRoot ?? $this->runtimeSiteRoot()), '/');
        $isLink ??= static fn (string $candidate): bool => is_link($candidate);
        if (! $this->inside($path, $runtimeRoot) || $isLink($runtimeRoot)) return true;
        $relative = ltrim(substr($path, strlen($runtimeRoot)), '/');
        $prefix = $runtimeRoot;
        foreach ($relative === '' ? [] : explode('/', $relative) as $part) {
            $prefix .= '/'.$part;
            if ($isLink($prefix)) return true;
        }
        return false;
    }
}
