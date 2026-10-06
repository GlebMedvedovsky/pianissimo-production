<?php

namespace App\Services;

use App\Enums\ProductionPublicationStatus;
use App\Models\ProductionPublication;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ProductionPublicationPackager
{
    public const CONTRACT_VERSION = 1;

    public function __construct(
        private readonly ContentValidator $validator,
        private readonly ProductionPublicationService $lifecycle,
    ) {}

    /**
     * Prepare an immutable, transport-independent production directory.
     *
     * Required payload keys: manifest, candidateHash, planHash, sourceRevision and media.
     */
    public function prepare(array $payload, ?int $requestedBy = null): ProductionPublication
    {
        $candidateHash = $this->hash($payload['candidateHash'] ?? null, 'candidateHash', 64);
        $planHash = $this->hash($payload['planHash'] ?? null, 'planHash', 64);
        $sourceRevision = $this->hash($payload['sourceRevision'] ?? null, 'sourceRevision', 40);
        $manifest = $payload['manifest'] ?? null;
        if (! is_array($manifest) || ($manifest['mode'] ?? null) !== 'release') {
            throw new DomainException('Production packages require an explicit release-mode manifest.');
        }
        $manifest = $this->validator->normalizeJsonObjectSlots($manifest);

        // File checks are performed against the exact supplied inventory below.
        $this->validator->validate($manifest, true, false, ContentValidator::POLICY_PUBLICATION);
        $manifestBytes = $this->canonicalJson($manifest)."\n";
        $manifestSha256 = hash('sha256', $manifestBytes);
        [$inventory, $sources] = $this->validatedInventory($manifest, $payload['media'] ?? null);
        $packageSha256 = hash('sha256', $this->canonicalJson([
            'contractVersion' => self::CONTRACT_VERSION,
            'candidateHash' => $candidateHash,
            'planHash' => $planHash,
            'sourceRevision' => $sourceRevision,
            'manifestSha256' => $manifestSha256,
            'media' => $inventory,
        ]));

        $root = $this->safePackageRoot();
        $publication = $this->claim(
            $candidateHash,
            $planHash,
            $sourceRevision,
            $packageSha256,
            $manifestSha256,
            $requestedBy ?? auth()->id(),
        );

        $final = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $publication->package_path);
        $publicationBytes = $this->canonicalJson($this->publicationMetadata($publication, $inventory))."\n";

        if ($publication->status !== ProductionPublicationStatus::Preparing) {
            $this->verifyPackage($final, $manifestBytes, $publicationBytes, $inventory, $root);
            return $publication->refresh();
        }

        $pending = $root.DIRECTORY_SEPARATOR.'.pending'.DIRECTORY_SEPARATOR.$publication->publication_id.'-'.bin2hex(random_bytes(8));
        $createdFinal = false;
        try {
            $this->buildPending($pending, $manifestBytes, $publicationBytes, $inventory, $sources, $root);
            $createdFinal = $this->publishPending($pending, $final, $manifestBytes, $publicationBytes, $inventory, $root);
            $publication = $this->lifecycle->markPrepared($publication);
        } catch (Throwable $exception) {
            $this->removeDirectory($pending);
            $publication->refresh();
            if ($publication->status === ProductionPublicationStatus::Prepared) {
                $this->verifyPackage($final, $manifestBytes, $publicationBytes, $inventory, $root);
                return $publication;
            }
            if ($createdFinal) {
                $this->removeDirectory($final);
            }
            if ($publication->status === ProductionPublicationStatus::Preparing) {
                $this->lifecycle->markFailed($publication, 'package_preparation_failed');
            }
            throw $exception;
        }

        return $publication->refresh();
    }

    private function claim(
        string $candidateHash,
        string $planHash,
        string $sourceRevision,
        string $packageSha256,
        string $manifestSha256,
        ?int $requestedBy,
    ): ProductionPublication {
        return DB::transaction(function () use ($candidateHash, $planHash, $sourceRevision, $packageSha256, $manifestSha256, $requestedBy): ProductionPublication {
            $counter = DB::table('production_publication_counters')->where('id', 1)->lockForUpdate()->first();
            if (! $counter) {
                throw new DomainException('Production publication sequence counter is unavailable.');
            }

            $existing = ProductionPublication::query()
                ->where('plan_hash', $planHash)
                ->where('source_revision', $sourceRevision)
                ->where('status', '!=', ProductionPublicationStatus::Failed->value)
                ->latest('attempt')
                ->lockForUpdate()
                ->first();
            if ($existing) {
                if (! hash_equals($existing->candidate_hash, $candidateHash)
                    || ! hash_equals($existing->package_sha256, $packageSha256)
                    || ! hash_equals($existing->manifest_sha256, $manifestSha256)) {
                    throw new DomainException('Conflicting content attempted to reuse a claimed production publication identity.');
                }
                return $existing;
            }

            $sequence = (int) $counter->next_sequence;
            if ($sequence < 1) {
                throw new DomainException('Production publication sequence counter is invalid.');
            }
            DB::table('production_publication_counters')->where('id', 1)->update(['next_sequence' => $sequence + 1]);
            $publicationId = strtolower((string) Str::uuid());
            $attempt = (int) ProductionPublication::query()
                ->where('plan_hash', $planHash)
                ->where('source_revision', $sourceRevision)
                ->max('attempt') + 1;
            $packagePath = 'production/'.str_pad((string) $sequence, 12, '0', STR_PAD_LEFT).'-'.$publicationId;

            return ProductionPublication::create([
                'publication_id' => $publicationId,
                'sequence' => $sequence,
                'candidate_hash' => $candidateHash,
                'plan_hash' => $planHash,
                'source_revision' => $sourceRevision,
                'attempt' => $attempt,
                'package_sha256' => $packageSha256,
                'manifest_sha256' => $manifestSha256,
                'package_path' => $packagePath,
                'status' => ProductionPublicationStatus::Preparing,
                'requested_by' => $requestedBy,
            ]);
        }, 3);
    }

    private function validatedInventory(array $manifest, mixed $media): array
    {
        if (! is_array($media)) {
            throw new DomainException('Production package media inventory is required.');
        }

        $manifestAssets = [];
        foreach ($manifest['assets'] as $asset) {
            $path = $this->normalizePublicPath($asset['path'] ?? null);
            if (isset($manifestAssets[$path])) {
                throw new DomainException("Duplicate normalized manifest media path: {$path}.");
            }
            $manifestAssets[$path] = $asset;
        }

        $allowedRoots = $this->allowedMediaRoots();
        $inventoryByPath = [];
        $sources = [];
        foreach ($media as $entry) {
            if (! is_array($entry)) {
                throw new DomainException('Invalid production package media entry.');
            }
            $publicPath = $this->normalizePublicPath($entry['publicPath'] ?? null);
            if (isset($inventoryByPath[$publicPath])) {
                throw new DomainException("Duplicate normalized production media path: {$publicPath}.");
            }
            $asset = $manifestAssets[$publicPath] ?? null;
            if (! $asset) {
                throw new DomainException("Unreferenced production media is forbidden: {$publicPath}.");
            }
            $assetId = $entry['assetId'] ?? null;
            $type = $entry['type'] ?? null;
            if (! is_string($assetId) || $assetId !== ($asset['id'] ?? null) || ! is_string($type) || $type !== ($asset['type'] ?? null)) {
                throw new DomainException("Production media identity/type mismatch: {$publicPath}.");
            }
            $this->validateExtension($publicPath, $type);
            $sha256 = $this->hash($entry['sha256'] ?? null, 'media sha256', 64);
            if (! isset($asset['sha256']) || ! is_string($asset['sha256']) || ! hash_equals($asset['sha256'], $sha256)) {
                throw new DomainException("Production media checksum does not match the release manifest: {$publicPath}.");
            }
            if (! is_int($entry['size'] ?? null) || $entry['size'] < 0) {
                throw new DomainException("Invalid production media size: {$publicPath}.");
            }
            $source = $this->validatedSource($entry['sourcePath'] ?? null, $allowedRoots, $publicPath);
            if ((int) filesize($source) !== $entry['size']) {
                throw new DomainException("Production media size mismatch: {$publicPath}.");
            }
            if (! hash_equals($sha256, hash_file('sha256', $source))) {
                throw new DomainException("Production media checksum mismatch: {$publicPath}.");
            }

            $inventoryByPath[$publicPath] = [
                'assetId' => $assetId,
                'publicPath' => $publicPath,
                'sha256' => $sha256,
                'size' => $entry['size'],
                'type' => $type,
            ];
            $sources[$publicPath] = $source;
        }

        if (array_diff_key($manifestAssets, $inventoryByPath) !== []) {
            throw new DomainException('Every release manifest asset requires one exact production media entry.');
        }
        ksort($inventoryByPath, SORT_STRING);

        return [array_values($inventoryByPath), $sources];
    }

    private function validatedSource(mixed $path, array $roots, string $publicPath): string
    {
        if (! is_string($path) || ! $this->isAbsolute($path) || $this->hasTraversal($path)) {
            throw new DomainException("Invalid production media source path: {$publicPath}.");
        }

        $normalizedPath = $this->comparablePath($path);
        $allowedRoot = null;
        foreach ($roots as $root) {
            if ($this->inside($normalizedPath, $root)) {
                $allowedRoot = $root;
                break;
            }
        }
        if ($allowedRoot === null) {
            throw new DomainException("Production media source is outside allowed media roots: {$publicPath}.");
        }
        if ($this->containsSymlinkComponent($path, $allowedRoot) || is_dir($path) || ! is_file($path)) {
            throw new DomainException("Production media source must be a regular non-symlink file: {$publicPath}.");
        }
        $real = realpath($path);
        if ($real === false) {
            throw new DomainException("Production media source is missing: {$publicPath}.");
        }
        $normalized = $this->comparablePath($real);
        if (! $this->inside($normalized, $allowedRoot)) {
            throw new DomainException("Production media source is outside allowed media roots: {$publicPath}.");
        }

        return $real;
    }

    private function allowedMediaRoots(): array
    {
        $roots = [];
        $runtimeRoot = $this->runtimeSiteRoot();
        $runtimeReal = realpath($runtimeRoot);
        if ($runtimeReal === false || $this->containsSymlinkComponent($runtimeRoot, $runtimeRoot)) {
            throw new DomainException('Production runtime site root is unavailable.');
        }
        $runtimeReal = $this->comparablePath($runtimeReal);
        foreach (['media_root', 'managed_media_root'] as $key) {
            $configured = config("cms.{$key}");
            if (! is_string($configured) || ! $this->isAbsolute($configured) || $this->hasTraversal($configured)
                || ! $this->inside($this->comparablePath($configured), $this->comparablePath($runtimeRoot))
                || $this->containsSymlinkComponent($configured, $runtimeRoot)) {
                continue;
            }
            $real = realpath($configured);
            if ($real !== false && is_dir($real) && ! is_link($configured)
                && $this->inside($this->comparablePath($real), $runtimeReal)) {
                $roots[$this->comparablePath($real)] = true;
            }
        }
        if ($roots === []) {
            throw new DomainException('No allowed production media root is available.');
        }

        return array_keys($roots);
    }

    private function buildPending(string $pending, string $manifestBytes, string $publicationBytes, array $inventory, array $sources, string $root): void
    {
        $this->makeDirectory($pending);
        $this->writeFile($pending.DIRECTORY_SEPARATOR.'manifest.json', $manifestBytes);
        $this->writeFile($pending.DIRECTORY_SEPARATOR.'publication.json', $publicationBytes);

        foreach ($inventory as $entry) {
            $target = $pending.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $entry['publicPath']);
            $this->makeDirectory(dirname($target));
            if (! copy($sources[$entry['publicPath']], $target)) {
                throw new DomainException("Could not copy production media: {$entry['publicPath']}.");
            }
            if (is_link($target) || ! is_file($target)
                || (int) filesize($target) !== $entry['size']
                || ! hash_equals($entry['sha256'], hash_file('sha256', $target))) {
                throw new DomainException("Copied production media failed verification: {$entry['publicPath']}.");
            }
        }
        $this->verifyPackage($pending, $manifestBytes, $publicationBytes, $inventory, $root);
    }

    private function publishPending(string $pending, string $final, string $manifestBytes, string $publicationBytes, array $inventory, string $root): bool
    {
        $this->makeDirectory(dirname($final));
        if (file_exists($final)) {
            $this->verifyPackage($final, $manifestBytes, $publicationBytes, $inventory, $root);
            $this->removeDirectory($pending);
            return false;
        }
        if (! @rename($pending, $final)) {
            if (is_dir($final)) {
                $this->verifyPackage($final, $manifestBytes, $publicationBytes, $inventory, $root);
                $this->removeDirectory($pending);
                return false;
            }
            throw new DomainException('Production package could not be published atomically.');
        }
        try {
            $this->verifyPackage($final, $manifestBytes, $publicationBytes, $inventory, $root);
        } catch (Throwable $exception) {
            $this->removeDirectory($final);
            throw $exception;
        }

        return true;
    }

    private function verifyPackage(string $directory, string $manifestBytes, string $publicationBytes, array $inventory, string $root): void
    {
        if (! $this->inside($this->comparablePath($directory), $this->comparablePath($root))
            || $this->containsSymlinkComponent($directory, $root) || ! is_dir($directory)) {
            throw new DomainException('Immutable production package is missing or is not a regular directory.');
        }
        $expected = [
            'manifest.json' => ['bytes' => $manifestBytes],
            'publication.json' => ['bytes' => $publicationBytes],
        ];
        foreach ($inventory as $entry) {
            $expected[$entry['publicPath']] = ['sha256' => $entry['sha256'], 'size' => $entry['size']];
        }

        $actual = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );
        foreach ($iterator as $file) {
            if ($file->isLink() || ! $file->isFile()) {
                throw new DomainException('Immutable production package contains a symlink or special file.');
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));
            $actual[$relative] = $file->getPathname();
        }
        if (array_diff_key($expected, $actual) !== [] || array_diff_key($actual, $expected) !== []) {
            throw new DomainException('Immutable production package file inventory does not match.');
        }
        foreach ($expected as $relative => $expectation) {
            $file = $actual[$relative];
            if (isset($expectation['bytes'])) {
                if (file_get_contents($file) !== $expectation['bytes']) {
                    throw new DomainException("Immutable production package file changed: {$relative}.");
                }
            } elseif ((int) filesize($file) !== $expectation['size'] || ! hash_equals($expectation['sha256'], hash_file('sha256', $file))) {
                throw new DomainException("Immutable production package media changed: {$relative}.");
            }
        }
    }

    private function publicationMetadata(ProductionPublication $publication, array $inventory): array
    {
        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'publicationId' => $publication->publication_id,
            'sequence' => $publication->sequence,
            'candidateHash' => $publication->candidate_hash,
            'planHash' => $publication->plan_hash,
            'sourceRevision' => $publication->source_revision,
            'manifestSha256' => $publication->manifest_sha256,
            'packageSha256' => $publication->package_sha256,
            'createdAt' => $publication->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'media' => $inventory,
        ];
    }

    private function safePackageRoot(): string
    {
        $configured = config('cms.publication_package_root');
        $runtimeRoot = $this->runtimeSiteRoot();
        if (! is_string($configured) || ! $this->isAbsolute($configured) || $this->hasTraversal($configured)
            || ! $this->inside($this->comparablePath($configured), $this->comparablePath($runtimeRoot))
            || $this->containsSymlinkComponent($configured, $runtimeRoot)) {
            throw new DomainException('Production publication package root must be an absolute traversal-free path.');
        }
        $root = $this->resolvedPath($configured, $runtimeRoot);
        $staticReleaseRoot = config('cms.static_release_root');
        $forbidden = [public_path(), config('cms.public_site_root')];
        if (is_string($staticReleaseRoot) && $staticReleaseRoot !== '') {
            $forbidden[] = $staticReleaseRoot;
            $forbidden[] = $staticReleaseRoot.DIRECTORY_SEPARATOR.'current';
        }
        foreach ($forbidden as $path) {
            if (is_string($path) && $path !== '' && $this->inside($root, $this->resolvedPath($path, $runtimeRoot))) {
                throw new DomainException('Production publication package root must remain private and outside live/static public paths.');
            }
        }
        $this->makeDirectory($root);
        $this->makeDirectory($root.DIRECTORY_SEPARATOR.'.pending');

        return str_replace('/', DIRECTORY_SEPARATOR, $root);
    }

    private function normalizePublicPath(mixed $path): string
    {
        if (! is_string($path) || $path === '' || str_contains($path, "\0") || $this->isAbsolute($path) || $this->hasTraversal($path)) {
            throw new DomainException('Production media public paths must be safe relative paths.');
        }
        $parts = array_values(array_filter(explode('/', str_replace('\\', '/', $path)), static fn (string $part): bool => $part !== '' && $part !== '.'));
        $normalized = implode('/', $parts);
        if (! str_starts_with($normalized, 'media/') || preg_match('/[:*?"<>|]/', $normalized)) {
            throw new DomainException('Production media public paths must remain below media/.');
        }

        return $normalized;
    }

    private function validateExtension(string $path, string $type): void
    {
        $allowed = [
            'image' => ['webp', 'jpg', 'jpeg', 'png'],
            'audio' => ['mp3', 'wav', 'ogg'],
            'video' => ['mp4', 'webm'],
        ];
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (! isset($allowed[$type]) || ! in_array($extension, $allowed[$type], true)) {
            throw new DomainException("Unsupported production media extension/type: {$path}.");
        }
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode($this->canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (is_object($value)) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);
            foreach ($properties as $key => $item) {
                $properties[$key] = $this->canonicalize($item);
            }

            return (object) $properties;
        }
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

    private function hash(mixed $value, string $name, int $length): string
    {
        if (! is_string($value) || ! preg_match('/^[0-9a-f]{'.$length.'}$/', $value)) {
            throw new DomainException("{$name} must be an exact lowercase hexadecimal value.");
        }

        return $value;
    }

    private function isAbsolute(string $path): bool
    {
        $path = str_replace('\\', '/', $path);
        return str_starts_with($path, '/') || (bool) preg_match('#^[A-Za-z]:/#', $path);
    }

    private function hasTraversal(string $path): bool
    {
        return (bool) preg_match('#(^|[\\\\/])\.\.([\\\\/]|$)#', $path);
    }

    private function containsSymlinkComponent(string $path, string $boundary): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $boundary = rtrim(str_replace('\\', '/', $boundary), '/');
        if (! $this->inside($this->comparablePath($path), $this->comparablePath($boundary))) {
            throw new DomainException('Configured production path is outside its trusted boundary.');
        }

        $probe = $boundary;
        if (is_link($probe)) {
            return true;
        }
        $relative = ltrim(substr($path, strlen($boundary)), '/');
        $parts = array_values(array_filter(explode('/', $relative), static fn (string $part): bool => $part !== ''));
        foreach ($parts as $part) {
            $probe .= '/'.$part;
            if (is_link($probe)) {
                return true;
            }
        }

        return false;
    }

    private function resolvedPath(string $path, string $boundary): string
    {
        $path = str_replace('\\', '/', $path);
        $boundary = rtrim(str_replace('\\', '/', $boundary), '/');
        if (! $this->inside($this->comparablePath($path), $this->comparablePath($boundary))) {
            throw new DomainException('Configured production path is outside its trusted boundary.');
        }
        $probe = $path;
        $suffix = [];
        while (! file_exists($probe)) {
            if ($this->comparablePath($probe) === $this->comparablePath($boundary)) {
                throw new DomainException('Configured production path cannot be resolved safely.');
            }
            $parent = str_replace('\\', '/', dirname($probe));
            if ($parent === $probe || $parent === '.'
                || ! $this->inside($this->comparablePath($parent), $this->comparablePath($boundary))) {
                throw new DomainException('Configured production path cannot be resolved safely.');
            }
            array_unshift($suffix, basename($probe));
            $probe = $parent;
        }
        $real = realpath($probe);
        if ($real === false) {
            throw new DomainException('Configured production path cannot be resolved safely.');
        }
        $boundaryReal = realpath($boundary);
        if ($boundaryReal === false || ! $this->inside($this->comparablePath($real), $this->comparablePath($boundaryReal))) {
            throw new DomainException('Configured production path cannot be resolved safely.');
        }
        $resolved = rtrim($this->comparablePath($real), '/');
        if ($suffix !== []) {
            $resolved .= '/'.implode('/', $suffix);
        }

        return $resolved;
    }

    private function comparablePath(string $path): string
    {
        $normalized = rtrim(str_replace('\\', '/', $path), '/');
        return PHP_OS_FAMILY === 'Windows' ? strtolower($normalized) : $normalized;
    }

    private function inside(string $candidate, string $root): bool
    {
        $root = rtrim($root, '/');
        return $candidate === $root || str_starts_with($candidate, $root.'/');
    }

    private function runtimeSiteRoot(): string
    {
        return rtrim(str_replace('\\', '/', dirname(base_path(), 2)), '/');
    }

    private function makeDirectory(string $path): void
    {
        if (is_link($path) || (file_exists($path) && ! is_dir($path))) {
            throw new DomainException('Production package path is not a regular directory.');
        }
        if (! is_dir($path) && ! mkdir($path, 0750, true) && ! is_dir($path)) {
            throw new DomainException('Production package directory could not be created.');
        }
    }

    private function writeFile(string $path, string $bytes): void
    {
        if (file_put_contents($path, $bytes, LOCK_EX) === false || is_link($path) || ! is_file($path)) {
            throw new DomainException('Production package metadata could not be written safely.');
        }
    }

    private function removeDirectory(string $directory): void
    {
        if ($directory === '' || ! file_exists($directory)) {
            return;
        }
        if (is_link($directory)) {
            @unlink($directory);
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() && ! $entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($directory);
    }
}
