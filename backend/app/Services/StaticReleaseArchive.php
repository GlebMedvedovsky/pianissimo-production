<?php

namespace App\Services;

use DomainException;
use RuntimeException;

class StaticReleaseArchive
{
    public const METADATA_FILE = '.pianissimo-release.json';
    public const FILES_FILE = '.pianissimo-release-files.json';
    private const MAX_ARCHIVE_BYTES = 2147483648;
    private const MAX_ENTRY_BYTES = 536870912;
    private const MAX_ENTRIES = 20000;

    /** @param resource $archive */
    public function inspect($archive): array
    {
        if (fseek($archive, 0) !== 0) {
            throw new RuntimeException('Production release archive is not seekable.');
        }
        $entries = [];
        $seen = [];
        $total = 0;
        $zeroBlocks = 0;
        while (true) {
            $headerOffset = ftell($archive);
            $header = fread($archive, 512);
            if ($header === '' || $header === false) {
                break;
            }
            if (strlen($header) !== 512) {
                throw new DomainException('Truncated production release archive header.');
            }
            if ($header === str_repeat("\0", 512)) {
                $zeroBlocks++;
                if ($zeroBlocks === 2) {
                    break;
                }
                continue;
            }
            if ($zeroBlocks !== 0) {
                throw new DomainException('Production release archive has data after an end marker.');
            }
            $this->verifyHeader($header);
            $name = rtrim(substr($header, 0, 100), "\0");
            $prefix = rtrim(substr($header, 345, 155), "\0");
            $path = $prefix === '' ? $name : $prefix.'/'.$name;
            $path = $this->safePath($path);
            if (isset($seen[$path])) {
                throw new DomainException('Production release archive contains duplicate normalized paths.');
            }
            $seen[$path] = true;
            $type = $header[156];
            if ($type === "\0") {
                $type = '0';
            }
            if (! in_array($type, ['0', '5'], true)) {
                throw new DomainException('Production release archive contains a link or special entry.');
            }
            $size = $this->octal(substr($header, 124, 12), 'entry size');
            if (($type === '5' && $size !== 0) || $size > self::MAX_ENTRY_BYTES) {
                throw new DomainException('Production release archive entry size is unsafe.');
            }
            $total += $size;
            if ($total > self::MAX_ARCHIVE_BYTES || count($entries) >= self::MAX_ENTRIES) {
                throw new DomainException('Production release archive exceeds safety limits.');
            }
            $dataOffset = $headerOffset + 512;
            $entries[] = ['path' => $path, 'type' => $type, 'size' => $size, 'offset' => $dataOffset];
            $skip = (int) (ceil($size / 512) * 512);
            if (fseek($archive, $dataOffset + $skip) !== 0) {
                throw new DomainException('Truncated production release archive payload.');
            }
        }
        if ($zeroBlocks !== 2 || $entries === []) {
            throw new DomainException('Production release archive has no valid USTAR end marker or entries.');
        }
        $end = ftell($archive);
        $stat = fstat($archive);
        if (! is_array($stat) || ($stat['size'] ?? 0) > self::MAX_ARCHIVE_BYTES || ($stat['size'] ?? 0) < $end) {
            throw new DomainException('Production release archive size is invalid.');
        }
        while (! feof($archive)) {
            $tail = fread($archive, 8192);
            if ($tail === false || trim($tail, "\0") !== '') {
                throw new DomainException('Production release archive has non-zero trailing data.');
            }
        }

        return $entries;
    }

    /** @param resource $archive */
    public function extract($archive, array $entries, string $destination): void
    {
        foreach ($entries as $entry) {
            $path = $destination.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $entry['path']);
            if ($entry['type'] === '5') {
                $this->makeDirectory($path);
                continue;
            }
            $this->makeDirectory(dirname($path));
            if (file_exists($path) || is_link($path) || fseek($archive, $entry['offset']) !== 0) {
                throw new DomainException('Production release extraction target is unsafe.');
            }
            $output = @fopen($path, 'xb');
            if ($output === false) {
                throw new RuntimeException('Production release file could not be created.');
            }
            try {
                $remaining = $entry['size'];
                while ($remaining > 0) {
                    $chunk = fread($archive, min(1048576, $remaining));
                    if ($chunk === false || $chunk === '' || fwrite($output, $chunk) !== strlen($chunk)) {
                        throw new DomainException('Production release archive payload is truncated.');
                    }
                    $remaining -= strlen($chunk);
                }
            } finally {
                fclose($output);
            }
        }
    }

    public function validateExtracted(string $root, array $expected): array
    {
        $metadataPath = $root.DIRECTORY_SEPARATOR.self::METADATA_FILE;
        if (! is_file($root.DIRECTORY_SEPARATOR.'index.html') || is_link($root.DIRECTORY_SEPARATOR.'index.html')) {
            throw new DomainException('Static production release is missing index.html.');
        }
        if (! is_dir($root.DIRECTORY_SEPARATOR.'_astro') || is_link($root.DIRECTORY_SEPARATOR.'_astro')) {
            throw new DomainException('Static production release is missing Astro output.');
        }
        if (! is_file($metadataPath) || is_link($metadataPath)) {
            throw new DomainException('Static production release metadata is missing.');
        }
        $metadataBytes = file_get_contents($metadataPath);
        if (! is_string($metadataBytes)
            || ! preg_match('/^[0-9a-f]{64}$/D', (string) ($expected['releaseMetadataSha256'] ?? ''))
            || ! hash_equals((string) $expected['releaseMetadataSha256'], hash('sha256', $metadataBytes))) {
            throw new DomainException('Static production release metadata digest is invalid.');
        }
        $metadata = json_decode($metadataBytes, true, flags: JSON_THROW_ON_ERROR);
        $canonicalMetadataBytes = json_encode(
            $metadata,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        )."\n";
        if (! hash_equals($canonicalMetadataBytes, $metadataBytes)) {
            throw new DomainException('Static production release metadata is not canonical.');
        }
        $this->validateMetadata($metadata, $expected);

        $inventoryPath = $root.DIRECTORY_SEPARATOR.self::FILES_FILE;
        if (! is_file($inventoryPath) || is_link($inventoryPath)) {
            throw new DomainException('Static production release file inventory is missing.');
        }
        $inventoryBytes = file_get_contents($inventoryPath);
        if (! is_string($inventoryBytes)
            || ! hash_equals((string) $metadata['releaseFilesSha256'], hash('sha256', $inventoryBytes))) {
            throw new DomainException('Static production release file inventory digest is invalid.');
        }
        $inventory = json_decode($inventoryBytes, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($inventory)) {
            throw new DomainException('Static production release file inventory is invalid.');
        }

        $files = [];
        $astroFiles = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $item) {
            $absolute = $item->getPathname();
            $relative = str_replace('\\', '/', substr($absolute, strlen($root) + 1));
            if ($item->isLink() || (! $item->isDir() && ! $item->isFile())) {
                throw new DomainException('Static production release contains a link or special file.');
            }
            $this->validateStaticPath($relative, $item->isDir());
            if ($item->isFile()) {
                $files[$relative] = true;
                if (str_starts_with($relative, '_astro/')) {
                    $astroFiles++;
                }
            }
        }
        if ($astroFiles === 0) {
            throw new DomainException('Static production release has no Astro assets.');
        }
        $expectedFiles = [];
        $previousPath = null;
        foreach ($inventory as $entry) {
            if (! is_array($entry) || array_keys($entry) !== ['path', 'sha256', 'size']
                || ! preg_match('/^[0-9a-f]{64}$/D', (string) ($entry['sha256'] ?? ''))
                || ! is_int($entry['size'] ?? null) || $entry['size'] < 0) {
                throw new DomainException('Static production release file inventory entry is invalid.');
            }
            $path = $this->safePath((string) ($entry['path'] ?? ''));
            if (in_array($path, [self::METADATA_FILE, self::FILES_FILE, StaticReleaseFilesystem::ARCHIVE_DIGEST_FILE], true)
                || ($previousPath !== null && strcmp($previousPath, $path) >= 0)) {
                throw new DomainException('Static production release file inventory has a reserved, duplicate, or unsorted path.');
            }
            $this->validateStaticPath($path, false);
            $expectedFiles[$path] = $entry;
            $previousPath = $path;
        }
        $actualPublicFiles = array_diff(array_keys($files), [self::METADATA_FILE, self::FILES_FILE, StaticReleaseFilesystem::ARCHIVE_DIGEST_FILE]);
        sort($actualPublicFiles, SORT_STRING);
        if ($actualPublicFiles !== array_keys($expectedFiles)) {
            throw new DomainException('Static production release file inventory contains missing or extra files.');
        }
        foreach ($expectedFiles as $path => $entry) {
            $absolute = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
            if (! hash_equals($entry['sha256'], hash_file('sha256', $absolute)) || $entry['size'] !== filesize($absolute)) {
                throw new DomainException('Static production release file digest or size is invalid.');
            }
        }
        foreach ($metadata['media'] as $media) {
            $path = $this->safePath((string) ($media['publicPath'] ?? ''));
            if (! str_starts_with($path, 'media/') || ! isset($files[$path])) {
                throw new DomainException('Static production release is missing referenced media.');
            }
            $absolute = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
            if (! hash_equals((string) $media['sha256'], hash_file('sha256', $absolute)) || (int) $media['size'] !== filesize($absolute)) {
                throw new DomainException('Static production release media digest or size is invalid.');
            }
        }
        $this->validateTextReferences($root, array_keys($files));

        return $metadata;
    }

    private function verifyHeader(string $header): void
    {
        if (substr($header, 257, 6) !== "ustar\0" || substr($header, 263, 2) !== '00') {
            throw new DomainException('Production release archive is not deterministic POSIX USTAR.');
        }
        $stored = $this->octal(substr($header, 148, 8), 'checksum');
        $checksumHeader = substr_replace($header, str_repeat(' ', 8), 148, 8);
        $actual = array_sum(unpack('C*', $checksumHeader));
        if ($stored !== $actual) {
            throw new DomainException('Production release archive header checksum is invalid.');
        }
    }

    private function octal(string $bytes, string $field): int
    {
        $value = trim($bytes, "\0 ");
        if ($value === '' || preg_match('/^[0-7]+$/D', $value) !== 1) {
            throw new DomainException("Production release archive {$field} is invalid.");
        }

        return intval($value, 8);
    }

    private function safePath(string $path): string
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path)) {
            throw new DomainException('Production release archive contains an unsafe path.');
        }
        $path = rtrim($path, '/');
        $parts = explode('/', $path);
        if ($path === '' || preg_match('//u', $path) !== 1 || array_filter($parts, static fn (string $part): bool => $part === '' || $part === '.' || $part === '..') !== []) {
            throw new DomainException('Production release archive contains an ambiguous path.');
        }

        return implode('/', $parts);
    }

    private function validateMetadata(mixed $metadata, array $expected): void
    {
        if (! is_array($metadata)
            || array_keys($metadata) !== [
                'releaseContractVersion',
                'publicationId',
                'sequence',
                'sourceRevision',
                'packageSha256',
                'manifestSha256',
                'releaseFilesSha256',
                'media',
            ]
            || ($metadata['releaseContractVersion'] ?? null) !== 1
            || ($metadata['publicationId'] ?? null) !== $expected['publicationId']
            || ($metadata['sequence'] ?? null) !== $expected['sequence']
            || ($metadata['sourceRevision'] ?? null) !== $expected['sourceRevision']
            || ($metadata['packageSha256'] ?? null) !== $expected['packageSha256']
            || ($metadata['manifestSha256'] ?? null) !== $expected['manifestSha256']
            || ($metadata['releaseFilesSha256'] ?? null) !== $expected['releaseFilesSha256']
            || ! preg_match('/^[0-9a-f]{64}$/D', (string) ($metadata['releaseFilesSha256'] ?? ''))
            || ! is_array($metadata['media'] ?? null)
            || ! array_is_list($metadata['media'])) {
            throw new DomainException('Static production release metadata does not match the publication.');
        }
        $seen = [];
        foreach ($metadata['media'] as $media) {
            if (! is_array($media)
                || array_keys($media) !== ['publicPath', 'sha256', 'size', 'type']
                || ! preg_match('/^[0-9a-f]{64}$/D', (string) ($media['sha256'] ?? ''))
                || ! is_int($media['size'] ?? null)
                || $media['size'] < 0
                || ! in_array($media['type'] ?? null, ['image', 'audio', 'video'], true)) {
                throw new DomainException('Static production release media metadata is invalid.');
            }
            $path = $this->safePath((string) ($media['publicPath'] ?? ''));
            if (! str_starts_with($path, 'media/') || isset($seen[$path])) {
                throw new DomainException('Static production release media metadata has an unsafe or duplicate path.');
            }
            $seen[$path] = true;
        }
    }

    private function validateStaticPath(string $path, bool $directory): void
    {
        $parts = explode('/', strtolower($path));
        $blocked = ['backend', 'storage', 'vendor', 'node_modules', 'private', 'config', 'database', '.git'];
        if (array_intersect($parts, $blocked) !== []) {
            throw new DomainException('Static production release contains a forbidden directory.');
        }
        $name = strtolower(basename($path));
        if ($name === '.env' || str_starts_with($name, '.env.') || preg_match('/(?:^|[._-])(?:config(?:uration)?|secret|credential|password|database)(?:[._-]|$)/i', $name)) {
            throw new DomainException('Static production release contains a secret or configuration file.');
        }
        if ($directory) {
            return;
        }
        if ($path === self::METADATA_FILE || $path === self::FILES_FILE || $path === StaticReleaseFilesystem::ARCHIVE_DIGEST_FILE) {
            return;
        }
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $forbidden = ['php', 'phtml', 'phar', 'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'zsh', 'fish', 'exe', 'dll', 'so', 'bat', 'cmd', 'ps1', 'jar', 'war', 'sql', 'sqlite', 'db', 'pem', 'key', 'ini', 'conf', 'yaml', 'yml'];
        $allowed = ['html', 'htm', 'css', 'js', 'mjs', 'json', 'map', 'svg', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'avif', 'ico', 'webmanifest', 'txt', 'xml', 'mp3', 'wav', 'm4a', 'aac', 'ogg', 'opus', 'mp4', 'webm', 'vtt', 'woff', 'woff2', 'ttf', 'otf', 'pdf'];
        if (in_array($extension, $forbidden, true) || ! in_array($extension, $allowed, true)) {
            throw new DomainException('Static production release contains an unexpected or executable file type.');
        }
    }

    private function validateTextReferences(string $root, array $files): void
    {
        $known = array_fill_keys($files, true);
        foreach ($files as $relative) {
            if (! preg_match('/\.(?:html?|css|m?js|json|xml|svg)$/i', $relative)) {
                continue;
            }
            $bytes = file_get_contents($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative));
            if (! is_string($bytes) || ! preg_match_all('#/media/([A-Za-z0-9._~!$&*+,;=:@%/-]+)#', $bytes, $matches)) {
                continue;
            }
            foreach ($matches[1] as $reference) {
                $path = 'media/'.rawurldecode(preg_split('/[?#]/', $reference, 2)[0]);
                try {
                    $path = $this->safePath($path);
                } catch (DomainException) {
                    throw new DomainException('Static production release has an unsafe media reference.');
                }
                if (! isset($known[$path])) {
                    throw new DomainException('Static production release references missing media.');
                }
            }
        }
    }

    private function makeDirectory(string $path): void
    {
        if (is_link($path) || (file_exists($path) && ! is_dir($path)) || (! is_dir($path) && ! @mkdir($path, 0750, true) && ! is_dir($path))) {
            throw new RuntimeException('Production release staging directory could not be created safely.');
        }
    }
}
