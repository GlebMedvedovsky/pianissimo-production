<?php

namespace App\Services;

use App\Models\ProductionPublication;
use DomainException;

final class ProductionPublicationPackageReader
{
    public function read(ProductionPublication $publication): array
    {
        return $this->inspect($publication, true);
    }

    /**
     * @return array{
     *   publication:array<string,mixed>, publicationBytes:string, manifestBytes:string,
     *   media:list<array{assetId:string,publicPath:string,sha256:string,size:int,type:string}>,
     *   mediaBySha256:array<string,array{entry:array{assetId:string,publicPath:string,sha256:string,size:int,type:string},path:string}>,
     *   files:array<string,string>
     * }
     */
    private function inspect(ProductionPublication $publication, bool $verifyAllMedia): array
    {
        $configuredRoot = config('cms.publication_package_root');
        $runtimeRoot = $this->runtimeSiteRoot();
        if (! is_string($configuredRoot) || ! $this->isAbsolute($configuredRoot) || $this->hasTraversal($configuredRoot)
            || ! $this->inside($this->comparablePath($configuredRoot), $this->comparablePath($runtimeRoot))
            || $this->containsSymlinkComponent($configuredRoot, $runtimeRoot)) {
            throw new DomainException('Immutable publication package is unavailable.');
        }
        $root = realpath($configuredRoot);
        $runtimeReal = realpath($runtimeRoot);
        if ($root === false || $runtimeReal === false || is_link($root) || ! is_dir($root)
            || ! $this->inside($this->comparablePath($root), $this->comparablePath($runtimeReal))) {
            throw new DomainException('Immutable publication package is unavailable.');
        }

        $expectedRelative = 'production/'.str_pad((string) $publication->sequence, 12, '0', STR_PAD_LEFT).'-'.$publication->publication_id;
        if (! hash_equals($expectedRelative, (string) $publication->package_path)) {
            throw new DomainException('Immutable publication package identity is invalid.');
        }
        $expectedDirectory = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $expectedRelative);
        if ($this->containsSymlinkComponent($expectedDirectory, $root)) {
            throw new DomainException('Immutable publication package is unavailable.');
        }
        $directory = realpath($expectedDirectory);
        if ($directory === false || ! is_dir($directory) || is_link($directory) || ! $this->inside($directory, $root)) {
            throw new DomainException('Immutable publication package is unavailable.');
        }

        $publicationPath = $directory.DIRECTORY_SEPARATOR.'publication.json';
        $manifestPath = $directory.DIRECTORY_SEPARATOR.'manifest.json';
        $publicationBytes = $this->regularBytes($publicationPath);
        $manifestBytes = $this->regularBytes($manifestPath);
        try {
            $metadata = json_decode($publicationBytes, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new DomainException('Immutable publication metadata is invalid.');
        }
        if (! is_array($metadata) || array_keys($metadata) !== [
            'candidateHash', 'contractVersion', 'createdAt', 'manifestSha256', 'media', 'packageSha256',
            'planHash', 'publicationId', 'sequence', 'sourceRevision',
        ]) {
            throw new DomainException('Immutable publication metadata contract changed.');
        }
        foreach ([
            'candidateHash' => $publication->candidate_hash,
            'createdAt' => $publication->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
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
            || ! hash_equals($publicationBytes, $this->canonicalJson($metadata)."\n")
            || ! hash_equals($publication->manifest_sha256, hash('sha256', $manifestBytes))) {
            throw new DomainException('Immutable publication package verification failed.');
        }

        $files = ['manifest.json' => $manifestPath, 'publication.json' => $publicationPath];
        $inventory = [];
        $mediaBySha256 = [];
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
                || ! is_string($entry['sha256']) || ! preg_match('/^[0-9a-f]{64}$/D', $entry['sha256'])
                || ! is_int($entry['size']) || $entry['size'] < 0
                || ! in_array($entry['type'], ['image', 'audio', 'video'], true)) {
                throw new DomainException('Immutable publication media inventory is invalid.');
            }
            $path = $directory.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if ($this->containsSymlinkComponent($path, $directory)) {
                throw new DomainException('Immutable publication package media changed.');
            }
            $real = realpath($path);
            if ($real === false || ! $this->inside($real, $directory) || is_link($path) || ! is_file($path)
                || filesize($path) !== $entry['size']
                || ($verifyAllMedia && ! hash_equals($entry['sha256'], (string) hash_file('sha256', $path)))) {
                throw new DomainException('Immutable publication package media changed.');
            }
            if (isset($mediaBySha256[$entry['sha256']])
                && $mediaBySha256[$entry['sha256']]['entry']['size'] !== $entry['size']) {
                throw new DomainException('Immutable publication media digest is ambiguous.');
            }
            $inventory[] = $entry;
            $files[$relative] = $path;
            $mediaBySha256[$entry['sha256']] ??= ['entry' => $entry, 'path' => $path];
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
        foreach ($iterator as $file) {
            if ($file->isLink() || ! $file->isFile()) {
                throw new DomainException('Immutable publication package contains a symlink or special file.');
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));
            $actual[$relative] = true;
        }
        if (array_diff_key($files, $actual) !== [] || array_diff_key($actual, $files) !== []) {
            throw new DomainException('Immutable publication package file inventory changed.');
        }
        ksort($files, SORT_STRING);

        return [
            'publication' => $metadata,
            'publicationBytes' => $publicationBytes,
            'manifestBytes' => $manifestBytes,
            'media' => $inventory,
            'mediaBySha256' => $mediaBySha256,
            'files' => $files,
        ];
    }

    /** @return array{entry:array{assetId:string,publicPath:string,sha256:string,size:int,type:string},path:string} */
    public function media(ProductionPublication $publication, string $sha256): array
    {
        if (! preg_match('/^[0-9a-f]{64}$/D', $sha256)) {
            throw new DomainException('Immutable publication media is unavailable.');
        }
        $media = $this->inspect($publication, false)['mediaBySha256'][$sha256] ?? null;
        if (! is_array($media)) {
            throw new DomainException('Immutable publication media is unavailable.');
        }
        if (filesize($media['path']) !== $media['entry']['size']
            || ! hash_equals($media['entry']['sha256'], (string) hash_file('sha256', $media['path']))) {
            throw new DomainException('Immutable publication package media changed.');
        }

        return $media;
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

    private function canonicalJson(mixed $value): string
    {
        return json_encode($this->canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) return $value;
        if (array_is_list($value)) return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);

        return $value;
    }

    private function inside(string $candidate, string $root): bool
    {
        $candidate = $this->comparablePath($candidate);
        $root = $this->comparablePath($root);

        return $candidate === $root || str_starts_with($candidate, $root.'/');
    }

    private function containsSymlinkComponent(string $path, string $boundary): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $boundary = rtrim(str_replace('\\', '/', $boundary), '/');
        if (! $this->inside($path, $boundary)) {
            throw new DomainException('Publication package path is outside its trusted boundary.');
        }
        $probe = $boundary;
        if (is_link($probe)) return true;
        $relative = ltrim(substr($path, strlen($boundary)), '/');
        $parts = array_values(array_filter(explode('/', $relative), static fn (string $part): bool => $part !== ''));
        foreach ($parts as $part) {
            $probe .= '/'.$part;
            if (is_link($probe)) return true;
        }

        return false;
    }

    private function runtimeSiteRoot(): string
    {
        return rtrim(str_replace('\\', '/', dirname(base_path(), 2)), '/');
    }

    private function comparablePath(string $path): string
    {
        $normalized = rtrim(str_replace('\\', '/', $path), '/');

        return PHP_OS_FAMILY === 'Windows' ? strtolower($normalized) : $normalized;
    }

    private function isAbsolute(string $path): bool
    {
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, '/') || (bool) preg_match('#^[A-Za-z]:/#', $path);
    }

    private function hasTraversal(string $path): bool
    {
        return (bool) preg_match('#(^|[\\/])\.\.([\\/]|$)#', $path);
    }
}
