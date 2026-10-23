<?php

namespace App\Services;

use App\Models\{MediaAsset, Track};
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MediaStorage
{
    public const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        'video/mp4' => 'mp4', 'video/webm' => 'webm',
        'audio/mpeg' => 'mp3', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/ogg' => 'ogg', 'application/ogg' => 'ogg',
    ];

    public const MIME_TYPES = [
        'image/jpeg' => 'image', 'image/png' => 'image', 'image/webp' => 'image',
        'video/mp4' => 'video', 'video/webm' => 'video',
        'audio/mpeg' => 'audio', 'audio/wav' => 'audio', 'audio/x-wav' => 'audio', 'audio/ogg' => 'audio', 'application/ogg' => 'audio',
    ];

    private const ORIGINAL_PATTERN = '#^media/originals/(?:pending|[a-z0-9]+(?:-[a-z0-9]+)*)/[A-Za-z0-9-]+\.[A-Za-z0-9]+$#D';
    private const FINAL_ORIGINAL_PATTERN = '#^media/originals/[a-z0-9]+(?:-[a-z0-9]+)*/[a-f0-9]{64}\.[a-z0-9]+$#D';

    public static function acceptedMimeTypes(): array
    {
        return array_keys(self::MIME_EXTENSIONS);
    }

    public static function mimeTypesFor(string $type): array
    {
        return array_keys(array_filter(self::MIME_TYPES, fn (string $value): bool => $value === $type));
    }

    public function inspectStored(
        string $relative,
        string $id,
        ?string $originalName = null,
        ?int $operationMaxKb = null,
        ?bool &$canonicalCreated = null,
    ): array {
        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $id) || ! preg_match(self::ORIGINAL_PATTERN, $relative)) {
            throw new DomainException('Ungültiger privater Medienpfad.');
        }

        $file = $this->resolveCandidate($relative);
        $size = filesize($file);
        $applicationMaxKb = (int) config('cms.media_max_kb');
        $operationMaxKb ??= (int) config('cms.media_upload_max_kb');
        if (! is_int($size) || $applicationMaxKb < 1 || $operationMaxKb < 1
            || $size > min($applicationMaxKb, $operationMaxKb) * 1024) {
            throw new DomainException('Die Datei überschreitet das konfigurierte Medienlimit.');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file);
        if (! is_string($mime) || ! isset(self::MIME_EXTENSIONS[$mime])) {
            throw new DomainException('Dieser Dateityp ist nicht als Web-Medium zugelassen.');
        }
        $sha = hash_file('sha256', $file);
        if (! is_string($sha)) {
            throw new DomainException('Die Medien-Prüfsumme konnte nicht berechnet werden.');
        }

        $metadata = [
            'original_name' => Str::limit($originalName ?: basename($file), 255, ''),
            'mime_type' => $mime,
            'file_size' => $size,
            'type' => self::MIME_TYPES[$mime],
            'sha256' => $sha,
        ];
        if ($metadata['type'] === 'image') {
            $dimensions = getimagesize($file);
            if (! is_array($dimensions)) {
                throw new DomainException('Die Bilddatei konnte nicht sicher gelesen werden.');
            }
            [$metadata['width'], $metadata['height']] = $dimensions;
        } else {
            $metadata['width'] = $metadata['height'] = null;
        }
        $metadata['duration'] = null;

        $extension = self::MIME_EXTENSIONS[$mime];
        $canonical = 'media/originals/'.$id.'/'.$sha.'.'.$extension;
        $canonicalCreated = $this->promote($file, $canonical, $sha, $size);

        return [
            ...$metadata,
            'storage_path' => $canonical,
            'path' => 'media/cms/'.$id.'-'.substr($sha, 0, 16).'.'.$extension,
        ];
    }

    public function registerExisting(array $data): array
    {
        $root = realpath(config('cms.media_root'));
        $path = (string) ($data['path'] ?? '');
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $extensionType = in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true) ? 'image'
            : (in_array($extension, ['mp4', 'webm'], true) ? 'video'
                : (in_array($extension, ['mp3', 'wav', 'ogg'], true) ? 'audio' : null));
        if (! $extensionType || $extensionType !== ($data['type'] ?? null)) {
            throw new DomainException('Dateiendung und gewählter Medientyp stimmen nicht überein.');
        }
        $file = realpath(config('cms.media_root').'/'.preg_replace('#^media/#', '', $path));
        // Legacy/advanced registration may describe a web file staged outside this
        // local checkout. Uploaded files always take the stricter private-storage path.
        if (! $root || ! $file || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR) || ! is_file($file)) {
            return [...$data, 'storage_path' => null];
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file);
        if (! isset(self::MIME_EXTENSIONS[$mime]) || self::MIME_TYPES[$mime] !== ($data['type'] ?? null)) {
            throw new DomainException('Dateiinhalt und gewählter Medientyp stimmen nicht überein.');
        }
        $data['mime_type'] = $mime;
        $data['file_size'] = filesize($file);
        $data['sha256'] = hash_file('sha256', $file);
        $data['original_name'] = basename($file);
        $data['storage_path'] = null;
        if ($data['type'] === 'image' && ($dimensions = getimagesize($file))) {
            [$data['width'], $data['height']] = $dimensions;
        }
        return $data;
    }

    public function dispatchCreatedAudio(MediaAsset $asset): void
    {
        if ($asset->type === 'audio' && is_string($asset->storage_path) && $asset->storage_path !== '') {
            app(TrackAudioMetadata::class)->dispatchForAsset($asset);
        }
    }

    public function replace(MediaAsset $asset, string $relative, ?string $originalName = null): void
    {
        $previousStoragePath = $asset->storage_path;
        $canonicalCreated = false;
        $metadata = $this->inspectStored($relative, $asset->id, $originalName, null, $canonicalCreated);
        if ($metadata['type'] !== $asset->type) {
            if ($metadata['storage_path'] !== $asset->storage_path) {
                $this->deleteStored($metadata['storage_path']);
            }
            throw new DomainException('Die Ersatzdatei muss denselben Medientyp haben.');
        }
        try {
            DB::transaction(function () use ($asset, $metadata): void {
                $asset->update($metadata);
                if ($asset->type === 'audio') Track::where('audio_asset_id', $asset->id)
                    ->update(TrackAudioMetadata::emptyMetadata('pending'));
                app(AuditLogger::class)->record('media', $asset->id, 'replace', $metadata['sha256'], ['mime' => $metadata['mime_type'], 'size' => $metadata['file_size']]);
            });
        } catch (\Throwable $exception) {
            if ($canonicalCreated && $metadata['storage_path'] !== $previousStoragePath) {
                try { $this->deleteStored($metadata['storage_path']); } catch (\Throwable) {}
            }
            throw $exception;
        }
        if ($asset->type === 'audio') app(TrackAudioMetadata::class)->dispatchForAsset($asset->fresh(), true);
    }

    public function originalsRoot(bool $writable = false): string
    {
        $configured = (string) config('cms.media_originals_root');
        $normalized = rtrim(str_replace('\\', '/', trim($configured)), '/');
        $runtimeRoot = $this->runtimeSiteRoot();
        if ($normalized === '' || (! str_starts_with($normalized, '/') && ! preg_match('#^[A-Za-z]:/#', $normalized))
            || preg_match('#(^|/)\.\.(/|$)#', $normalized) || ! $this->inside($normalized, $runtimeRoot)
            || $this->containsSymlinkComponent($normalized, $runtimeRoot)) {
            throw new DomainException('Der private Originalspeicher ist unsicher konfiguriert.');
        }
        $root = realpath($configured);
        $resolvedRuntimeRoot = realpath($runtimeRoot);
        if ($root === false || $resolvedRuntimeRoot === false || ! is_dir($root)
            || ! $this->inside($root, $resolvedRuntimeRoot)
            || $this->containsSymlinkComponent($normalized, $runtimeRoot)
            || ($writable && ! is_writable($root))) {
            throw new DomainException('Der private Originalspeicher ist nicht verfügbar.');
        }
        return $root;
    }

    public function resolveStored(string $relative): string
    {
        if (! preg_match(self::FINAL_ORIGINAL_PATTERN, $relative)) {
            throw new DomainException('Ungültiger privater Medienpfad.');
        }
        $root = $this->originalsRoot();
        $candidate = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, substr($relative, strlen('media/originals/')));
        if (is_link($candidate)) {
            throw new DomainException('Die private Mediendatei ist unsicher.');
        }
        $file = realpath($candidate);
        if ($file === false || ! is_file($file) || ! $this->inside($file, $root)) {
            throw new DomainException('Die private Mediendatei fehlt.');
        }
        return $file;
    }

    public function resolveAsset(MediaAsset $asset): string
    {
        if ($asset->storage_path) return $this->resolveStored((string) $asset->storage_path);
        $path = (string) $asset->path;
        if (! preg_match('#^media/[a-zA-Z0-9/_-]+\.(?:mp3|wav|ogg)$#D', $path) || str_contains($path, '..')) {
            throw new DomainException('Der Audio-Medienpfad ist ungültig.');
        }
        $relative = substr($path, strlen('media/'));
        foreach ([config('cms.media_root'), config('cms.private_media_root')] as $configuredRoot) {
            $root = realpath((string) $configuredRoot);
            if ($root === false || ! is_dir($root) || is_link((string) $configuredRoot)) continue;
            $candidate = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (is_link($candidate)) continue;
            $file = realpath($candidate);
            if ($file !== false && is_file($file) && $this->inside($file, $root)) return $file;
        }
        throw new DomainException('Die zugeordnete Audio-Datei fehlt.');
    }

    public function deleteStored(string $relative): void
    {
        $file = $this->resolveStored($relative);
        if (! unlink($file)) {
            throw new DomainException('Die private Mediendatei konnte nicht entfernt werden.');
        }
        $directory = dirname($file);
        if ((scandir($directory) ?: []) === ['.', '..']) {
            rmdir($directory);
        }
    }

    private function resolveCandidate(string $relative): string
    {
        $root = $this->originalsRoot(true);
        $suffix = str_replace('/', DIRECTORY_SEPARATOR, substr($relative, strlen('media/originals/')));
        $configuredCandidate = $root.DIRECTORY_SEPARATOR.$suffix;
        if (! is_link($configuredCandidate)) {
            $resolved = realpath($configuredCandidate);
            if ($resolved !== false && is_file($resolved) && $this->inside($resolved, $root)) {
                return $resolved;
            }
        }

        $storageRoot = realpath(storage_path('app/private'));
        $storageCandidate = storage_path('app/private/'.$relative);
        $resolved = ! is_link($storageCandidate) ? realpath($storageCandidate) : false;
        if ($storageRoot !== false && $resolved !== false && is_file($resolved) && $this->inside($resolved, $storageRoot)) {
            return $resolved;
        }
        throw new DomainException('Die hochgeladene Datei fehlt im privaten Speicher.');
    }

    private function promote(string $source, string $relative, string $sha, int $size): bool
    {
        $root = $this->originalsRoot(true);
        $target = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, substr($relative, strlen('media/originals/')));
        $directory = dirname($target);
        if (is_link($directory) || (file_exists($directory) && ! is_dir($directory))) {
            throw new DomainException('Der kanonische Medienspeicher ist unsicher.');
        }
        if (! is_dir($directory) && ! mkdir($directory, 0750, true)) {
            throw new DomainException('Der kanonische Medienspeicher konnte nicht vorbereitet werden.');
        }
        $resolvedDirectory = realpath($directory);
        if ($resolvedDirectory === false || ! $this->inside($resolvedDirectory, $root) || is_link($directory)) {
            throw new DomainException('Der kanonische Medienspeicher ist unsicher.');
        }
        if (is_link($target)) {
            throw new DomainException('Die kanonische Mediendatei ist unsicher.');
        }
        if (is_file($target)) {
            if (filesize($target) !== $size || ! hash_equals($sha, (string) hash_file('sha256', $target))) {
                throw new DomainException('Eine bestehende kanonische Mediendatei hat andere Daten.');
            }
            if ($source !== $target && ! unlink($source)) {
                throw new DomainException('Die temporäre Mediendatei konnte nicht entfernt werden.');
            }
            return false;
        }

        $sourceStat = stat($source);
        $directoryStat = stat($resolvedDirectory);
        if (is_array($sourceStat) && is_array($directoryStat) && $sourceStat['dev'] === $directoryStat['dev']) {
            if (! rename($source, $target)) {
                throw new DomainException('Die Mediendatei konnte nicht atomar gespeichert werden.');
            }
            return true;
        }

        $temporary = $resolvedDirectory.DIRECTORY_SEPARATOR.'.media-'.bin2hex(random_bytes(8));
        $input = fopen($source, 'rb');
        $output = fopen($temporary, 'xb');
        if ($input === false || $output === false) {
            if (is_resource($input)) fclose($input);
            if (is_resource($output)) fclose($output);
            throw new DomainException('Die Mediendatei konnte nicht übertragen werden.');
        }
        try {
            $hash = hash_init('sha256');
            $bytes = 0;
            while (! feof($input)) {
                $buffer = fread($input, 1048576);
                if ($buffer === false) throw new DomainException('Die Mediendatei konnte nicht gelesen werden.');
                if ($buffer === '') continue;
                $bytes += strlen($buffer);
                $this->writeAll($output, $buffer);
                hash_update($hash, $buffer);
            }
            fflush($output);
            if ($bytes !== $size || ! hash_equals($sha, hash_final($hash))) {
                throw new DomainException('Die Medien-Prüfsumme hat sich während der Übertragung geändert.');
            }
        } catch (\Throwable $exception) {
            if (is_file($temporary)) unlink($temporary);
            throw $exception;
        } finally {
            fclose($input);
            fclose($output);
        }
        if (! rename($temporary, $target)) {
            if (is_file($temporary)) unlink($temporary);
            throw new DomainException('Die Mediendatei konnte nicht atomar gespeichert werden.');
        }
        if (! unlink($source)) {
            unlink($target);
            throw new DomainException('Die temporäre Mediendatei konnte nicht entfernt werden.');
        }
        return true;
    }

    /** @param resource $output */
    private function writeAll($output, string $bytes): void
    {
        for ($offset = 0, $length = strlen($bytes); $offset < $length;) {
            $written = fwrite($output, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new DomainException('Die Mediendatei konnte nicht vollständig gespeichert werden.');
            }
            $offset += $written;
        }
    }

    private function runtimeSiteRoot(): string
    {
        return rtrim(str_replace('\\', '/', dirname(base_path(), 2)), '/');
    }

    private function inside(string $candidate, string $root): bool
    {
        $candidate = str_replace('\\', '/', $candidate);
        $root = rtrim(str_replace('\\', '/', $root), '/');
        return $candidate === $root || str_starts_with($candidate, $root.'/');
    }

    private function containsSymlinkComponent(string $path, string $runtimeRoot): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $runtimeRoot = rtrim(str_replace('\\', '/', $runtimeRoot), '/');
        if (! $this->inside($path, $runtimeRoot) || is_link($runtimeRoot)) return true;
        $relative = ltrim(substr($path, strlen($runtimeRoot)), '/');
        $prefix = $runtimeRoot;
        foreach ($relative === '' ? [] : explode('/', $relative) as $part) {
            $prefix .= '/'.$part;
            if (is_link($prefix)) return true;
        }
        return false;
    }
}
