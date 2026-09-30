<?php

namespace App\Services;

use App\Models\ProductionPublication;
use DomainException;
use Illuminate\Support\Facades\File;

class ProductionPublicationTransport
{
    /** @return array{path:string,sha256:string,filename:string} */
    public function create(ProductionPublication $publication): array
    {
        [$directory, $files] = $this->verifyPackage($publication);
        $temporaryRoot = storage_path('framework/cache/publisher-transports');
        File::ensureDirectoryExists($temporaryRoot, 0750);
        $temporary = tempnam($temporaryRoot, 'transport-');
        if ($temporary === false) {
            throw new DomainException('Publication transport could not be created.');
        }
        $archive = $temporary.'.tar';
        if (! rename($temporary, $archive)) {
            @unlink($temporary);
            throw new DomainException('Publication transport could not be created.');
        }

        try {
            $handle = fopen($archive, 'wb');
            if ($handle === false) {
                throw new DomainException('Publication transport could not be opened.');
            }
            try {
                foreach ($files as $relative => $path) {
                    $size = filesize($path);
                    if ($size === false) {
                        throw new DomainException('Publication transport source became unavailable.');
                    }
                    $this->writeAll($handle, $this->tarHeader($relative, (int) $size));
                    $input = fopen($path, 'rb');
                    if ($input === false) {
                        throw new DomainException('Publication transport source became unavailable.');
                    }
                    try {
                        if (stream_copy_to_stream($input, $handle) !== $size) {
                            throw new DomainException('Publication transport source could not be copied.');
                        }
                    } finally {
                        fclose($input);
                    }
                    $padding = (512 - ($size % 512)) % 512;
                    if ($padding > 0) {
                        $this->writeAll($handle, str_repeat("\0", $padding));
                    }
                }
                $this->writeAll($handle, str_repeat("\0", 1024));
            } finally {
                fclose($handle);
            }

            $digest = hash_file('sha256', $archive);
            if (! is_string($digest)) {
                throw new DomainException('Publication transport digest could not be calculated.');
            }

            return [
                'path' => $archive,
                'sha256' => $digest,
                'filename' => 'pianissimo-publication-'.$publication->publication_id.'.tar',
            ];
        } catch (\Throwable $exception) {
            @unlink($archive);
            throw $exception;
        }
    }

    /** @return array{string,array<string,string>} */
    private function verifyPackage(ProductionPublication $publication): array
    {
        $configuredRoot = (string) config('cms.publication_package_root');
        $root = realpath($configuredRoot);
        if ($root === false || $this->containsSymlinkComponent($configuredRoot) || is_link($root) || ! is_dir($root)) {
            throw new DomainException('Immutable publication package is unavailable.');
        }
        $expectedRelative = 'production/'.str_pad((string) $publication->sequence, 12, '0', STR_PAD_LEFT).'-'.$publication->publication_id;
        if (! hash_equals($expectedRelative, (string) $publication->package_path)) {
            throw new DomainException('Immutable publication package identity is invalid.');
        }
        $directory = realpath($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $expectedRelative));
        if ($directory === false || ! is_dir($directory) || is_link($directory) || ! $this->inside($directory, $root)) {
            throw new DomainException('Immutable publication package is unavailable.');
        }

        $publicationPath = $directory.DIRECTORY_SEPARATOR.'publication.json';
        $manifestPath = $directory.DIRECTORY_SEPARATOR.'manifest.json';
        $metadataBytes = $this->regularBytes($publicationPath);
        $manifestBytes = $this->regularBytes($manifestPath);
        try {
            $metadata = json_decode($metadataBytes, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new DomainException('Immutable publication metadata is invalid.');
        }
        if (! is_array($metadata) || array_keys($metadata) !== [
            'candidateHash', 'contractVersion', 'createdAt', 'manifestSha256', 'media', 'packageSha256',
            'planHash', 'publicationId', 'sequence', 'sourceRevision',
        ]) {
            // Canonical JSON sorts object keys. Reject additions before considering any paths.
            throw new DomainException('Immutable publication metadata contract changed.');
        }
        foreach ([
            'candidateHash' => $publication->candidate_hash,
            'manifestSha256' => $publication->manifest_sha256,
            'packageSha256' => $publication->package_sha256,
            'planHash' => $publication->plan_hash,
            'publicationId' => $publication->publication_id,
            'sourceRevision' => $publication->source_revision,
        ] as $key => $value) {
            if (! is_string($metadata[$key] ?? null) || ! hash_equals((string) $value, $metadata[$key])) {
                throw new DomainException('Immutable publication metadata does not match its database record.');
            }
        }
        if (($metadata['contractVersion'] ?? null) !== ProductionPublicationPackager::CONTRACT_VERSION
            || ($metadata['sequence'] ?? null) !== $publication->sequence
            || ! is_array($metadata['media'] ?? null)
            || ! hash_equals($metadataBytes, $this->canonicalJson($metadata)."\n")
            || ! hash_equals($publication->manifest_sha256, hash('sha256', $manifestBytes))) {
            throw new DomainException('Immutable publication package verification failed.');
        }

        $files = ['manifest.json' => $manifestPath, 'publication.json' => $publicationPath];
        $inventory = [];
        $previousPath = null;
        foreach ($metadata['media'] as $entry) {
            if (! is_array($entry) || array_keys($entry) !== ['assetId', 'publicPath', 'sha256', 'size', 'type']) {
                throw new DomainException('Immutable publication media inventory is invalid.');
            }
            $relative = $this->safeMediaPath($entry['publicPath'] ?? null);
            if ($previousPath !== null && strcmp($previousPath, $relative) >= 0) {
                throw new DomainException('Immutable publication media inventory is not uniquely sorted.');
            }
            if (! is_string($entry['assetId']) || $entry['assetId'] === ''
                || ! is_string($entry['sha256']) || ! preg_match('/^[0-9a-f]{64}$/', $entry['sha256'])
                || ! is_int($entry['size']) || $entry['size'] < 0
                || ! in_array($entry['type'], ['image', 'audio', 'video'], true)) {
                throw new DomainException('Immutable publication media inventory is invalid.');
            }
            $path = $directory.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $real = realpath($path);
            if ($real === false || ! $this->inside($real, $directory) || is_link($path) || ! is_file($path)
                || filesize($path) !== $entry['size'] || ! hash_equals($entry['sha256'], (string) hash_file('sha256', $path))) {
                throw new DomainException('Immutable publication package media changed.');
            }
            $inventory[] = $entry;
            $files[$relative] = $path;
            $previousPath = $relative;
        }

        $logicalDigest = hash('sha256', $this->canonicalJson([
            'contractVersion' => $metadata['contractVersion'],
            'candidateHash' => $metadata['candidateHash'],
            'planHash' => $metadata['planHash'],
            'sourceRevision' => $metadata['sourceRevision'],
            'manifestSha256' => $metadata['manifestSha256'],
            'media' => $inventory,
        ]));
        if (! hash_equals($publication->package_sha256, $logicalDigest)) {
            throw new DomainException('Immutable publication logical digest changed.');
        }

        $actual = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink() || ! $entry->isFile()) {
                throw new DomainException('Immutable publication package contains a symlink or special file.');
            }
            $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($directory) + 1));
            $actual[$relative] = true;
        }
        if (array_diff_key($files, $actual) !== [] || array_diff_key($actual, $files) !== []) {
            throw new DomainException('Immutable publication package file inventory changed.');
        }
        ksort($files, SORT_STRING);

        return [$directory, $files];
    }

    private function regularBytes(string $path): string
    {
        if (is_link($path) || ! is_file($path)) {
            throw new DomainException('Immutable publication package metadata is unavailable.');
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new DomainException('Immutable publication package metadata is unavailable.');
        }

        return $bytes;
    }

    private function safeMediaPath(mixed $path): string
    {
        if (! is_string($path) || ! preg_match('#^media/[A-Za-z0-9._/-]+$#D', $path)
            || str_contains($path, '//') || preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $path)) {
            throw new DomainException('Immutable publication media path is unsafe.');
        }

        return $path;
    }

    private function tarHeader(string $path, int $size): string
    {
        [$name, $prefix] = $this->tarName($path);
        $header = str_pad($name, 100, "\0")
            .$this->octal(0644, 8).$this->octal(0, 8).$this->octal(0, 8)
            .$this->octal($size, 12).$this->octal(0, 12).str_repeat(' ', 8)
            .'0'.str_repeat("\0", 100).'ustar'."\0".'00'
            .str_repeat("\0", 32).str_repeat("\0", 32)
            .$this->octal(0, 8).$this->octal(0, 8).str_pad($prefix, 155, "\0").str_repeat("\0", 12);
        if (strlen($header) !== 512) {
            throw new DomainException('Publication transport header is invalid.');
        }
        $checksum = array_sum(unpack('C*', $header));

        return substr_replace($header, sprintf('%06o', $checksum)."\0 ", 148, 8);
    }

    /** @return array{string,string} */
    private function tarName(string $path): array
    {
        if (strlen($path) <= 100) {
            return [$path, ''];
        }
        for ($offset = strlen($path) - 1; $offset > 0; $offset--) {
            if ($path[$offset] !== '/') {
                continue;
            }
            $prefix = substr($path, 0, $offset);
            $name = substr($path, $offset + 1);
            if (strlen($prefix) <= 155 && strlen($name) <= 100) {
                return [$name, $prefix];
            }
        }
        throw new DomainException('Publication media path is too long for the transport contract.');
    }

    private function octal(int $value, int $length): string
    {
        $encoded = sprintf('%0'.($length - 1).'o', $value)."\0";
        if (strlen($encoded) > $length) {
            throw new DomainException('Publication transport value exceeds its deterministic format.');
        }

        return $encoded;
    }

    /** @param resource $handle */
    private function writeAll($handle, string $bytes): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($handle, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new DomainException('Publication transport could not be written.');
            }
            $offset += $written;
        }
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode($this->canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    private function inside(string $candidate, string $root): bool
    {
        $candidate = str_replace('\\', '/', $candidate);
        $root = rtrim(str_replace('\\', '/', $root), '/');

        return $candidate === $root || str_starts_with($candidate, $root.'/');
    }

    private function containsSymlinkComponent(string $path): bool
    {
        $path = str_replace('\\', '/', $path);
        $prefix = str_starts_with($path, '/') ? '/' : '';
        $parts = array_values(array_filter(explode('/', $path), static fn (string $part): bool => $part !== ''));
        foreach ($parts as $part) {
            $prefix = $prefix === '' || str_ends_with($prefix, '/') ? $prefix.$part : $prefix.'/'.$part;
            if (is_link($prefix)) {
                return true;
            }
        }

        return false;
    }
}
