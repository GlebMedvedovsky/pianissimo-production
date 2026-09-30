<?php

namespace App\Services;

use DomainException;
use RuntimeException;

class StaticReleaseFilesystem
{
    public const INCOMING_MARKER = '.pianissimo-production-incoming';
    public const INCOMING_MARKER_CONTENT = 'pianissimo-production-incoming-v1';
    public const ROOT_MARKER = '.pianissimo-production-root';
    public const ROOT_MARKER_CONTENT = 'pianissimo-production-root-v1';
    public const STATE_FILE = '.pianissimo-production-state.json';
    public const LOCK_FILE = '.pianissimo-production.lock';
    public const ARCHIVE_DIGEST_FILE = '.pianissimo-archive.sha256';

    /** @return resource */
    public function lock(string $staticRoot)
    {
        $path = $staticRoot.DIRECTORY_SEPARATOR.self::LOCK_FILE;
        $handle = @fopen($path, 'c+');
        if ($handle === false || ! @flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('Production release lock could not be acquired.');
        }

        return $handle;
    }

    /** @param resource $handle */
    public function unlock($handle): void
    {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }

    public function currentTarget(string $staticRoot): ?string
    {
        $current = $staticRoot.DIRECTORY_SEPARATOR.'current';
        if (! file_exists($current) && ! is_link($current)) {
            return null;
        }
        if (! is_link($current)) {
            throw new DomainException('Production current pointer is not a symlink.');
        }
        $target = readlink($current);
        if (! is_string($target) || ! $this->validTarget($target)) {
            throw new DomainException('Production current pointer has an unsafe target.');
        }

        return str_replace('\\', '/', $target);
    }

    public function switchCurrent(string $staticRoot, string $target): ?string
    {
        if (! $this->validTarget($target)) {
            throw new DomainException('Refusing unsafe production release target.');
        }
        $previous = $this->currentTarget($staticRoot);
        $current = $staticRoot.DIRECTORY_SEPARATOR.'current';
        $temporary = $staticRoot.DIRECTORY_SEPARATOR.'.current-'.getmypid().'-'.bin2hex(random_bytes(8));
        try {
            if (! @symlink($target, $temporary)) {
                throw new RuntimeException('Temporary production symlink could not be created.');
            }
            if (! @rename($temporary, $current)) {
                throw new RuntimeException('Production current pointer could not be switched atomically.');
            }
        } finally {
            if (is_link($temporary)) {
                @unlink($temporary);
            }
        }

        return $previous;
    }

    public function restoreCurrent(string $staticRoot, ?string $previous): void
    {
        $current = $staticRoot.DIRECTORY_SEPARATOR.'current';
        if ($previous === null) {
            if (is_link($current) && ! @unlink($current)) {
                throw new RuntimeException('Production current pointer could not be removed during compensation.');
            }
            if (file_exists($current) && ! is_link($current)) {
                throw new DomainException('Production current pointer became a non-symlink during compensation.');
            }

            return;
        }
        $this->switchCurrent($staticRoot, $previous);
    }

    public function renameDirectory(string $from, string $to): void
    {
        if (! @rename($from, $to)) {
            throw new RuntimeException('Staged production release could not be moved atomically.');
        }
    }

    public function readState(string $staticRoot): array
    {
        $path = $staticRoot.DIRECTORY_SEPARATOR.self::STATE_FILE;
        if (is_link($path) || (file_exists($path) && ! is_file($path))) {
            throw new DomainException('Production activation state is not a regular file.');
        }
        if (! is_file($path)) {
            return ['version' => 1, 'highestActivatedSequence' => 0, 'activeRelease' => null, 'previousRelease' => null, 'status' => 'stable'];
        }
        $state = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($state)
            || ($state['version'] ?? null) !== 1
            || ! is_int($state['highestActivatedSequence'] ?? null)
            || ($state['highestActivatedSequence'] ?? -1) < 0
            || ! in_array($state['status'] ?? null, ['stable', 'ambiguous'], true)) {
            throw new DomainException('Production activation state is invalid.');
        }
        foreach (['activeRelease', 'previousRelease'] as $field) {
            if (($state[$field] ?? null) !== null && (! is_string($state[$field]) || ! $this->validTarget($state[$field]))) {
                throw new DomainException('Production activation state contains an unsafe release target.');
            }
        }

        return $state;
    }

    public function writeState(string $staticRoot, array $state): void
    {
        $temporary = $staticRoot.DIRECTORY_SEPARATOR.'.state-'.getmypid().'-'.bin2hex(random_bytes(8));
        $bytes = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        try {
            if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes) || ! @rename($temporary, $staticRoot.DIRECTORY_SEPARATOR.self::STATE_FILE)) {
                throw new RuntimeException('Production activation state could not be persisted atomically.');
            }
        } finally {
            if (is_file($temporary) || is_link($temporary)) {
                @unlink($temporary);
            }
        }
    }

    public function validTarget(string $target): bool
    {
        return preg_match('#^releases/[1-9][0-9]*-[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$#D', str_replace('\\', '/', $target)) === 1;
    }
}
