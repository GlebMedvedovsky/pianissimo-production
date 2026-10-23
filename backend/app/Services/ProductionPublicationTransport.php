<?php

namespace App\Services;

use App\Models\ProductionPublication;
use DomainException;
use Illuminate\Support\Facades\File;

class ProductionPublicationTransport
{
    public function __construct(private readonly ProductionPublicationPackageReader $packages) {}

    /** @return array{path:string,sha256:string,filename:string} */
    public function create(ProductionPublication $publication): array
    {
        $files = $this->packages->read($publication)['files'];
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

}
