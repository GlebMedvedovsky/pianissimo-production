<?php

namespace App\Services;

use App\Models\ProductionPublication;
use DomainException;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

final class PhysicalPublicSiteDeployer
{
    public function __construct(private readonly Filesystem $filesystem) {}

    public function prepare(string $releaseDirectory, ProductionPublication $publication): array
    {
        $public = $this->publicRoot();
        $parent = dirname($public);
        $stage = $parent.DIRECTORY_SEPARATOR.'.pianissimo-httpdocs-stage-'.$publication->publication_id;
        $backup = $parent.DIRECTORY_SEPARATOR.'.pianissimo-httpdocs-previous';
        $inventory = $this->inventory($releaseDirectory);
        if (! is_dir($public) || is_link($public) || ! is_file($public.DIRECTORY_SEPARATOR.'index.html')) {
            throw new DomainException('A working physical public website must exist before automated replacement.');
        }

        if ($this->matches($public, $inventory)) {
            return compact('public', 'stage', 'backup', 'inventory') + ['swapped' => false, 'noop' => true, 'hadPublic' => true];
        }
        $this->assertManagedSibling($stage, $parent, '.pianissimo-httpdocs-stage-');
        if (file_exists($stage) || is_link($stage)) $this->removeTree($stage);
        if (! mkdir($stage, 0755, true) && ! is_dir($stage)) {
            throw new RuntimeException('Public website staging directory could not be created.');
        }
        foreach ($inventory as $entry) {
            $relative = str_replace('/', DIRECTORY_SEPARATOR, $entry['path']);
            $source = $releaseDirectory.DIRECTORY_SEPARATOR.$relative;
            $target = $stage.DIRECTORY_SEPARATOR.$relative;
            if (! is_dir(dirname($target)) && ! mkdir(dirname($target), 0755, true) && ! is_dir(dirname($target))) {
                throw new RuntimeException('Public website staging directory could not be created.');
            }
            if (! copy($source, $target) || ! chmod($target, 0644)) {
                throw new RuntimeException('Public website file could not be staged.');
            }
        }
        if (! $this->matches($stage, $inventory)) {
            $this->removeTree($stage);
            throw new DomainException('Staged public website does not match the validated release.');
        }

        return compact('public', 'stage', 'backup', 'inventory') + [
            'swapped' => false,
            'noop' => false,
            'hadPublic' => true,
        ];
    }

    public function activate(array $deployment): array
    {
        if (($deployment['noop'] ?? false) === true) return $deployment;
        $public = $deployment['public'];
        $stage = $deployment['stage'];
        $backup = $deployment['backup'];
        $parent = dirname($public);
        $this->assertManagedSibling($backup, $parent, '.pianissimo-httpdocs-previous');
        if (file_exists($backup) || is_link($backup)) $this->removeTree($backup);

        if (! $this->filesystem->moveDirectory($public, $backup)) {
            throw new RuntimeException('Current public website could not be retained for rollback.');
        }
        if (! $this->filesystem->moveDirectory($stage, $public)) {
            if (! is_dir($backup) || is_link($backup) || ! $this->filesystem->moveDirectory($backup, $public)) {
                throw new AmbiguousPublicDeploymentException('Validated public website activation failed and the previous public website could not be restored.');
            }
            throw new RuntimeException('Validated public website could not be activated.');
        }
        $deployment['swapped'] = true;
        return $deployment;
    }

    public function rollback(array $deployment): void
    {
        if (($deployment['swapped'] ?? false) !== true) {
            if (isset($deployment['stage']) && (is_dir($deployment['stage']) || is_link($deployment['stage']))) $this->removeTree($deployment['stage']);
            return;
        }
        $public = $deployment['public'];
        $backup = $deployment['backup'];
        $failed = dirname($public).DIRECTORY_SEPARATOR.'.pianissimo-httpdocs-failed-'.bin2hex(random_bytes(6));
        if (! @rename($public, $failed)) {
            throw new RuntimeException('Failed public website could not be moved aside for rollback.');
        }
        if (! @rename($backup, $public)) {
            @rename($failed, $public);
            throw new RuntimeException('Previous public website could not be restored.');
        }
        $this->removeTree($failed);
    }

    public function abort(array $deployment): void
    {
        if (($deployment['swapped'] ?? false) === true) return;
        if (isset($deployment['stage']) && (is_dir($deployment['stage']) || is_link($deployment['stage']))) $this->removeTree($deployment['stage']);
    }

    private function inventory(string $releaseDirectory): array
    {
        $file = $releaseDirectory.DIRECTORY_SEPARATOR.StaticReleaseArchive::FILES_FILE;
        if (! is_file($file) || is_link($file)) throw new DomainException('Validated release inventory is unavailable for public deployment.');
        $inventory = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($inventory) || ! array_is_list($inventory)) throw new DomainException('Validated release inventory is invalid.');
        $previous = null;
        foreach ($inventory as $entry) {
            $path = is_array($entry) ? ($entry['path'] ?? null) : null;
            if (! is_string($path) || ! preg_match('#^[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*$#D', $path)
                || str_contains($path, '..') || ($previous !== null && strcmp($previous, $path) >= 0)
                || ! preg_match('/^[0-9a-f]{64}$/D', (string) ($entry['sha256'] ?? ''))
                || ! is_int($entry['size'] ?? null) || $entry['size'] < 0) {
                throw new DomainException('Validated release inventory contains an unsafe public entry.');
            }
            $source = $releaseDirectory.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
            if (! is_file($source) || is_link($source)
                || filesize($source) !== $entry['size']
                || ! hash_equals($entry['sha256'], hash_file('sha256', $source))) {
                throw new DomainException('Validated release file changed before public deployment.');
            }
            $previous = $path;
        }
        return $inventory;
    }

    private function matches(string $directory, array $inventory): bool
    {
        if (! is_dir($directory) || is_link($directory)) return false;
        $actual = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($iterator as $item) {
            if ($item->isLink() || ! $item->isFile()) return false;
            $actual[str_replace('\\', '/', substr($item->getPathname(), strlen($directory) + 1))] = $item->getPathname();
        }
        ksort($actual, SORT_STRING);
        if (array_keys($actual) !== array_column($inventory, 'path')) return false;
        foreach ($inventory as $entry) {
            $file = $actual[$entry['path']];
            if (filesize($file) !== $entry['size'] || ! hash_equals($entry['sha256'], hash_file('sha256', $file))) return false;
        }
        return true;
    }

    private function publicRoot(): string
    {
        $configured = (string) config('cms.public_site_root');
        if ($configured === '' || str_contains($configured, "\0") || preg_match('#(?:^|[\\/])\.\.?([\\/]|$)#', $configured)
            || (preg_match('#^/#', $configured) !== 1 && preg_match('#^[A-Za-z]:[\\/]#', $configured) !== 1)) {
            throw new DomainException('Public website root configuration is unsafe.');
        }
        $parent = realpath(dirname($configured));
        $runtime = realpath(dirname(base_path(), 2));
        if ($parent === false || $runtime === false || is_link(dirname($configured))) throw new DomainException('Public website parent is unavailable or unsafe.');
        $parentComparable = $this->comparable($parent);
        $runtimeComparable = rtrim($this->comparable($runtime), '/');
        if ($parentComparable !== $runtimeComparable && ! str_starts_with($parentComparable, $runtimeComparable.'/')) {
            throw new DomainException('Public website root is outside the trusted site boundary.');
        }
        $root = $parent.DIRECTORY_SEPARATOR.basename($configured);
        if (is_link($root) || (file_exists($root) && ! is_dir($root))) throw new DomainException('Public website root must be a physical directory.');
        return $root;
    }

    private function assertManagedSibling(string $path, string $parent, string $prefix): void
    {
        if (dirname($path) !== $parent || ! str_starts_with(basename($path), $prefix)) throw new DomainException('Public deployment support path is unsafe.');
    }

    private function removeTree(string $directory): void
    {
        if (is_link($directory) || ! is_dir($directory)) throw new DomainException('Public deployment cleanup target is unsafe.');
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isLink()) throw new DomainException('Public deployment cleanup refuses symlinks.');
            $ok = $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            if (! $ok) throw new RuntimeException('Public deployment cleanup failed.');
        }
        if (! @rmdir($directory)) throw new RuntimeException('Public deployment directory cleanup failed.');
    }

    private function comparable(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }
}
