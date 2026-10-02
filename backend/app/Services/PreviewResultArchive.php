<?php

namespace App\Services;

use DomainException;

/**
 * Strict validator for private preview results.
 *
 * Unlike a production release, a preview result intentionally omits original
 * media bytes. References to /media/preview/* are accepted only when they are
 * declared by the already byte-verified immutable preview package.
 */
final class PreviewResultArchive
{
    public function validateExtracted(string $root, array $expected, array $expectedMedia): array
    {
        if (! is_file($root.DIRECTORY_SEPARATOR.'index.html') || is_link($root.DIRECTORY_SEPARATOR.'index.html')) {
            throw new DomainException('Das Vorschau-Ergebnis enthält keine Startseite.');
        }
        if (! is_dir($root.DIRECTORY_SEPARATOR.'_astro') || is_link($root.DIRECTORY_SEPARATOR.'_astro')) {
            throw new DomainException('Das Vorschau-Ergebnis enthält keine Astro-Dateien.');
        }

        $metadataPath = $root.DIRECTORY_SEPARATOR.StaticReleaseArchive::METADATA_FILE;
        $inventoryPath = $root.DIRECTORY_SEPARATOR.StaticReleaseArchive::FILES_FILE;
        if (is_link($metadataPath) || is_link($inventoryPath) || ! is_file($metadataPath) || ! is_file($inventoryPath)) {
            throw new DomainException('Das Vorschau-Ergebnis ist unvollständig.');
        }
        $metadataBytes = file_get_contents($metadataPath);
        $inventoryBytes = file_get_contents($inventoryPath);
        if (! is_string($metadataBytes) || ! is_string($inventoryBytes)
            || ! preg_match('/^[a-f0-9]{64}$/D', (string) ($expected['releaseMetadataSha256'] ?? ''))
            || ! hash_equals($expected['releaseMetadataSha256'], hash('sha256', $metadataBytes))) {
            throw new DomainException('Die Metadaten-Prüfsumme des Vorschau-Ergebnisses ist ungültig.');
        }
        $metadata = json_decode($metadataBytes, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($metadata)
            || array_keys($metadata) !== ['releaseContractVersion','publicationId','sequence','sourceRevision','packageSha256','manifestSha256','releaseFilesSha256','media']
            || ! hash_equals(json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)."\n", $metadataBytes)
            || ($metadata['releaseContractVersion'] ?? null) !== 1
            || ($metadata['publicationId'] ?? null) !== $expected['publicationId']
            || ($metadata['sequence'] ?? null) !== $expected['sequence']
            || ($metadata['sourceRevision'] ?? null) !== $expected['sourceRevision']
            || ($metadata['packageSha256'] ?? null) !== $expected['packageSha256']
            || ($metadata['manifestSha256'] ?? null) !== $expected['manifestSha256']
            || ($metadata['releaseFilesSha256'] ?? null) !== $expected['releaseFilesSha256']
            || $metadata['media'] !== $expectedMedia) {
            throw new DomainException('Das Vorschau-Ergebnis gehört nicht exakt zum erwarteten Paket.');
        }
        if (! hash_equals($metadata['releaseFilesSha256'], hash('sha256', $inventoryBytes))) {
            throw new DomainException('Die Dateiliste des Vorschau-Ergebnisses ist ungültig.');
        }

        $inventory = json_decode($inventoryBytes, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($inventory) || ! array_is_list($inventory)) {
            throw new DomainException('Die Dateiliste des Vorschau-Ergebnisses ist ungültig.');
        }
        $declaredMedia = [];
        $previousMedia = null;
        foreach ($expectedMedia as $media) {
            if (! is_array($media) || array_keys($media) !== ['publicPath','sha256','size','type']
                || ! preg_match('#^media/preview/[a-z0-9]+(?:-[a-z0-9]+)*\.(?:webp|jpg|png|mp3|wav|ogg|mp4|webm)$#D', (string) ($media['publicPath'] ?? ''))
                || ! preg_match('/^[a-f0-9]{64}$/D', (string) ($media['sha256'] ?? ''))
                || ! is_int($media['size'] ?? null) || $media['size'] < 0
                || ! in_array($media['type'] ?? null, ['image', 'audio', 'video'], true)
                || ($previousMedia !== null && strcmp($previousMedia, $media['publicPath']) >= 0)) {
                throw new DomainException('Die Medienfreigabe des Vorschau-Ergebnisses ist ungültig.');
            }
            $declaredMedia[$media['publicPath']] = true;
            $previousMedia = $media['publicPath'];
        }

        $actualFiles = [];
        $astroFiles = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $item) {
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
            if ($item->isLink() || (! $item->isDir() && ! $item->isFile())) {
                throw new DomainException('Das Vorschau-Ergebnis enthält einen Link oder eine Spezialdatei.');
            }
            $this->validatePath($relative, $item->isDir());
            if ($item->isFile()) {
                if (str_starts_with($relative, 'media/preview/')) {
                    throw new DomainException('Das Vorschau-Ergebnis darf keine Kopien privater Originalmedien enthalten.');
                }
                $actualFiles[$relative] = true;
                if (str_starts_with($relative, '_astro/')) $astroFiles++;
            }
        }
        if ($astroFiles === 0) throw new DomainException('Das Vorschau-Ergebnis enthält keine Astro-Dateien.');

        $expectedFiles = [];
        $previousPath = null;
        foreach ($inventory as $entry) {
            if (! is_array($entry) || array_keys($entry) !== ['path','sha256','size']
                || ! preg_match('/^[a-f0-9]{64}$/D', (string) ($entry['sha256'] ?? ''))
                || ! is_int($entry['size'] ?? null) || $entry['size'] < 0) {
                throw new DomainException('Die Dateiliste des Vorschau-Ergebnisses enthält einen ungültigen Eintrag.');
            }
            $path = $this->safePath((string) ($entry['path'] ?? ''));
            if (in_array($path, [StaticReleaseArchive::METADATA_FILE, StaticReleaseArchive::FILES_FILE, StaticReleaseFilesystem::ARCHIVE_DIGEST_FILE], true)
                || str_starts_with($path, 'media/preview/')
                || ($previousPath !== null && strcmp($previousPath, $path) >= 0)) {
                throw new DomainException('Die Dateiliste des Vorschau-Ergebnisses enthält einen reservierten, unsortierten oder privaten Medienpfad.');
            }
            $this->validatePath($path, false);
            $expectedFiles[$path] = $entry;
            $previousPath = $path;
        }
        $actualPublicFiles = array_diff(array_keys($actualFiles), [StaticReleaseArchive::METADATA_FILE, StaticReleaseArchive::FILES_FILE, StaticReleaseFilesystem::ARCHIVE_DIGEST_FILE]);
        sort($actualPublicFiles, SORT_STRING);
        if ($actualPublicFiles !== array_keys($expectedFiles)) {
            throw new DomainException('Die Dateiliste des Vorschau-Ergebnisses enthält fehlende oder zusätzliche Dateien.');
        }
        foreach ($expectedFiles as $path => $entry) {
            $absolute = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
            if (! hash_equals($entry['sha256'], (string) hash_file('sha256', $absolute)) || $entry['size'] !== filesize($absolute)) {
                throw new DomainException('Eine Vorschau-Datei hat eine ungültige Prüfsumme oder Größe.');
            }
        }
        $this->validateTextReferences($root, array_keys($actualFiles), $declaredMedia);

        return $metadata;
    }

    private function validateTextReferences(string $root, array $files, array $declaredMedia): void
    {
        foreach ($files as $relative) {
            if (! preg_match('/\.(?:html?|css|m?js|json|xml|svg)$/i', $relative)) continue;
            $bytes = file_get_contents($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative));
            if (! is_string($bytes) || ! preg_match_all('#/media/([A-Za-z0-9._~!$&*+,;=:@%/-]+)#', $bytes, $matches)) continue;
            foreach ($matches[1] as $reference) {
                $path = $this->safePath('media/'.rawurldecode(preg_split('/[?#]/', $reference, 2)[0]));
                if (! isset($declaredMedia[$path])) {
                    throw new DomainException('Das Vorschau-Ergebnis verweist auf ein nicht freigegebenes Medium.');
                }
            }
        }
    }

    private function safePath(string $path): string
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path)) {
            throw new DomainException('Das Vorschau-Ergebnis enthält einen unsicheren Pfad.');
        }
        $path = rtrim($path, '/');
        $parts = explode('/', $path);
        if ($path === '' || preg_match('//u', $path) !== 1 || array_filter($parts, static fn (string $part): bool => $part === '' || $part === '.' || $part === '..') !== []) {
            throw new DomainException('Das Vorschau-Ergebnis enthält einen mehrdeutigen Pfad.');
        }
        return implode('/', $parts);
    }

    private function validatePath(string $path, bool $directory): void
    {
        $path = $this->safePath($path);
        if (array_intersect(explode('/', strtolower($path)), ['backend','storage','vendor','node_modules','private','config','database','.git']) !== []) {
            throw new DomainException('Das Vorschau-Ergebnis enthält einen verbotenen Ordner.');
        }
        $name = strtolower(basename($path));
        if ($name === '.env' || str_starts_with($name, '.env.') || preg_match('/(?:^|[._-])(?:config(?:uration)?|secret|credential|password|database)(?:[._-]|$)/i', $name)) {
            throw new DomainException('Das Vorschau-Ergebnis enthält eine geheime oder konfigurative Datei.');
        }
        if ($directory || in_array($path, [StaticReleaseArchive::METADATA_FILE, StaticReleaseArchive::FILES_FILE, StaticReleaseFilesystem::ARCHIVE_DIGEST_FILE], true)) return;
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = ['html','htm','css','js','mjs','json','map','svg','png','jpg','jpeg','webp','gif','avif','ico','webmanifest','txt','xml','woff','woff2','ttf','otf'];
        if (! in_array($extension, $allowed, true)) throw new DomainException('Das Vorschau-Ergebnis enthält einen unerwarteten Dateityp.');
    }
}
