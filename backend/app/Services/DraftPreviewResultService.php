<?php

namespace App\Services;

use App\Models\DraftPreview;
use DomainException;
use Illuminate\Support\Facades\File;

final class DraftPreviewResultService
{
    /** @param resource $input */
    public function install(DraftPreview $preview, $input, array $digests): void
    {
        $package = app(DraftPreviewPackageService::class)->metadata($preview);
        foreach (['archiveSha256', 'releaseFilesSha256', 'releaseMetadataSha256'] as $field) {
            if (! preg_match('/^[a-f0-9]{64}$/D', (string) ($digests[$field] ?? ''))) throw new DomainException('Die Vorschau-Ergebnisprüfsummen sind ungültig.');
        }
        $identifier = $package['previewIdentifier'];
        $incomingRoot = $this->privateRoot('preview_incoming_root');
        $incomingDirectory = $incomingRoot.DIRECTORY_SEPARATOR.$identifier;
        File::ensureDirectoryExists($incomingDirectory, 0750);
        $archivePath = $incomingDirectory.DIRECTORY_SEPARATOR.'result-'.bin2hex(random_bytes(8)).'.tar';
        $archive = fopen($archivePath, 'xb+');
        if ($archive === false) throw new DomainException('Das Vorschau-Ergebnis konnte nicht gespeichert werden.');
        try {
            $hash = hash_init('sha256');
            $bytes = 0;
            while (! feof($input)) {
                $chunk = fread($input, 1048576);
                if ($chunk === false) throw new DomainException('Das Vorschau-Ergebnis konnte nicht gelesen werden.');
                if ($chunk === '') continue;
                $bytes += strlen($chunk);
                if ($bytes > 2147483648 || fwrite($archive, $chunk) !== strlen($chunk)) throw new DomainException('Das Vorschau-Ergebnis ist zu groß oder unvollständig.');
                hash_update($hash, $chunk);
            }
            fflush($archive);
            if (! hash_equals($digests['archiveSha256'], hash_final($hash))) throw new DomainException('Die Archiv-Prüfsumme des Vorschau-Ergebnisses stimmt nicht.');

            $resultRoot = $this->privateRoot('preview_result_root');
            $final = $resultRoot.DIRECTORY_SEPARATOR.$identifier;
            $stage = $resultRoot.DIRECTORY_SEPARATOR.'.pending'.DIRECTORY_SEPARATOR.$identifier.'-'.bin2hex(random_bytes(8));
            File::ensureDirectoryExists(dirname($stage), 0750);
            if (! mkdir($stage, 0750, true)) throw new DomainException('Das Vorschau-Ergebnis konnte nicht vorbereitet werden.');
            try {
                $archiveService = app(StaticReleaseArchive::class);
                $entries = $archiveService->inspect($archive);
                $archiveService->extract($archive, $entries, $stage);
                $metadata = $archiveService->validateExtracted($stage, [
                    'publicationId' => $identifier,
                    'sequence' => $preview->id,
                    'sourceRevision' => $package['sourceRevision'],
                    'packageSha256' => $package['packageSha256'],
                    'manifestSha256' => $package['manifestSha256'],
                    'releaseFilesSha256' => $digests['releaseFilesSha256'],
                    'releaseMetadataSha256' => $digests['releaseMetadataSha256'],
                ]);
                $expectedMedia = array_map(static fn (array $entry): array => [
                    'publicPath' => $entry['publicPath'], 'sha256' => $entry['sha256'], 'size' => $entry['size'], 'type' => $entry['type'],
                ], $package['media']);
                if ($metadata['media'] !== $expectedMedia) throw new DomainException('Das Vorschau-Ergebnis enthält nicht exakt die freigegebenen Vorschau-Medien.');
                if (is_link($final) || (file_exists($final) && ! is_dir($final))) throw new DomainException('Der Vorschau-Ergebnisordner ist unsicher.');
                if (is_dir($final)) File::deleteDirectory($final);
                if (! rename($stage, $final)) throw new DomainException('Das Vorschau-Ergebnis konnte nicht atomar aktiviert werden.');
                $receipt = [
                    'archiveSha256' => $digests['archiveSha256'],
                    'packageSha256' => $package['packageSha256'],
                    'releaseFilesSha256' => $digests['releaseFilesSha256'],
                    'releaseMetadataSha256' => $digests['releaseMetadataSha256'],
                    'sourceRevision' => $package['sourceRevision'],
                ];
                $this->writeReceipt($resultRoot, $identifier, $receipt);
            } catch (\Throwable $exception) {
                File::deleteDirectory($stage);
                throw $exception;
            }
        } finally {
            fclose($archive);
            @unlink($archivePath);
            @rmdir($incomingDirectory);
        }
    }

    public function ready(DraftPreview $preview): bool
    {
        $package=app(DraftPreviewPackageService::class)->metadata($preview);
        $root=$this->privateRoot('preview_result_root');
        $receipt=$root.DIRECTORY_SEPARATOR.$package['previewIdentifier'].'.json';
        if(!file_exists($receipt)&&!is_link($receipt))return false;
        $this->receipt($preview);
        return true;
    }

    /** @return array{path:string,mime:string} */
    public function page(DraftPreview $preview, string $route): array
    {
        $relative = match ($route) {
            '/' => 'index.html',
            '/film-music/' => 'film-music/index.html',
            '/directing/' => 'directing/index.html',
            '/legal/' => 'legal/index.html',
            '/privacy/' => 'privacy/index.html',
            default => preg_match('#^/projects/([a-z0-9]+(?:-[a-z0-9]+)*)/$#D', $route, $match) ? 'projects/'.$match[1].'/index.html' : throw new DomainException('Diese Vorschau-Seite ist ungültig.'),
        };
        return $this->file($preview, $relative);
    }

    /** @return array{path:string,mime:string} */
    public function file(DraftPreview $preview, string $relative): array
    {
        $relative = $this->safeResultPath($relative);
        [$root, $inventory] = $this->receipt($preview);
        $entry = collect($inventory)->firstWhere('path', $relative);
        if (! is_array($entry)) throw new DomainException('Die Vorschau-Datei ist nicht Teil des geprüften Ergebnisses.');
        $path = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $real = realpath($path);
        if ($real === false || ! $this->inside($real, $root) || is_link($path) || ! is_file($path)
            || filesize($path) !== $entry['size'] || ! hash_equals($entry['sha256'], (string) hash_file('sha256', $path))) {
            throw new DomainException('Die Vorschau-Datei wurde verändert.');
        }
        return ['path' => $path, 'mime' => $this->mime($relative)];
    }

    /** @return array{string,array} */
    private function receipt(DraftPreview $preview): array
    {
        $package = app(DraftPreviewPackageService::class)->metadata($preview);
        $resultRoot = $this->privateRoot('preview_result_root');
        $identifier = $package['previewIdentifier'];
        $receiptPath = $resultRoot.DIRECTORY_SEPARATOR.$identifier.'.json';
        if (is_link($receiptPath) || ! is_file($receiptPath)) throw new DomainException('Das Vorschau-Ergebnis wird noch erstellt.');
        $receiptBytes = file_get_contents($receiptPath);
        $receipt = is_string($receiptBytes) ? json_decode($receiptBytes, true, flags: JSON_THROW_ON_ERROR) : null;
        if (! is_array($receipt) || array_keys($receipt) !== ['archiveSha256', 'packageSha256', 'releaseFilesSha256', 'releaseMetadataSha256', 'sourceRevision']
            || ! hash_equals($package['packageSha256'], (string) $receipt['packageSha256'])
            || ! hash_equals($package['sourceRevision'], (string) $receipt['sourceRevision'])) throw new DomainException('Das Vorschau-Ergebnis gehört nicht zum aktuellen Entwurf.');
        foreach (['archiveSha256', 'releaseFilesSha256', 'releaseMetadataSha256'] as $field) if (! preg_match('/^[a-f0-9]{64}$/D', (string) $receipt[$field])) throw new DomainException('Das Vorschau-Ergebnis ist ungültig.');
        $root = realpath($resultRoot.DIRECTORY_SEPARATOR.$identifier);
        if ($root === false || is_link($root) || ! is_dir($root) || ! $this->inside($root, $resultRoot)) throw new DomainException('Das Vorschau-Ergebnis ist nicht verfügbar.');
        $metadataPath = $root.DIRECTORY_SEPARATOR.StaticReleaseArchive::METADATA_FILE;
        $inventoryPath = $root.DIRECTORY_SEPARATOR.StaticReleaseArchive::FILES_FILE;
        if (is_link($metadataPath) || is_link($inventoryPath) || ! is_file($metadataPath) || ! is_file($inventoryPath)) throw new DomainException('Das Vorschau-Ergebnis ist unvollständig.');
        $metadataBytes = file_get_contents($metadataPath); $inventoryBytes = file_get_contents($inventoryPath);
        if (! is_string($metadataBytes) || ! is_string($inventoryBytes)
            || ! hash_equals($receipt['releaseMetadataSha256'], hash('sha256', $metadataBytes))
            || ! hash_equals($receipt['releaseFilesSha256'], hash('sha256', $inventoryBytes))) throw new DomainException('Das Vorschau-Ergebnis wurde verändert.');
        $metadata = json_decode($metadataBytes, true, flags: JSON_THROW_ON_ERROR);
        $inventory = json_decode($inventoryBytes, true, flags: JSON_THROW_ON_ERROR);
        if (($metadata['publicationId'] ?? null) !== $identifier || ($metadata['sequence'] ?? null) !== $preview->id
            || ($metadata['packageSha256'] ?? null) !== $package['packageSha256'] || ! is_array($inventory)) throw new DomainException('Das Vorschau-Ergebnis hat eine ungültige Identität.');
        return [$root, $inventory];
    }

    private function writeReceipt(string $root, string $identifier, array $receipt): void
    {
        ksort($receipt, SORT_STRING);
        $bytes = json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        $final = $root.DIRECTORY_SEPARATOR.$identifier.'.json';
        $temporary = $root.DIRECTORY_SEPARATOR.'.receipt-'.$identifier.'-'.bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes) || ! rename($temporary, $final)) {
            @unlink($temporary); throw new DomainException('Die Vorschau-Ergebnisbestätigung konnte nicht gespeichert werden.');
        }
    }

    private function privateRoot(string $key): string
    {
        $configured = (string) config('cms.'.$key);
        $normalized = str_replace('\\', '/', trim($configured));
        if ($normalized === '' || (! str_starts_with($normalized, '/') && ! preg_match('#^[A-Za-z]:/#', $normalized)) || preg_match('#(^|/)\.\.(/|$)#', $normalized)) throw new DomainException('Ein privater Vorschau-Pfad ist ungültig.');
        File::ensureDirectoryExists($configured, 0750);
        $root = realpath($configured);
        if ($root === false || $this->containsSymlinkComponent($configured) || is_link($configured) || ! is_dir($root)) throw new DomainException('Ein privater Vorschau-Pfad ist nicht verfügbar.');
        foreach ([public_path(), config('cms.public_site_root'), config('cms.static_release_root')] as $forbidden) {
            $resolved = is_string($forbidden) ? realpath($forbidden) : false;
            if ($resolved && ($this->inside($root, $resolved) || $this->inside($resolved, $root))) throw new DomainException('Vorschau-Ergebnisse müssen außerhalb öffentlicher Pfade liegen.');
        }
        return $root;
    }

    private function safeResultPath(string $path): string
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/') || preg_match('#(^|/)\.{1,2}(/|$)#', $path)
            || ! preg_match('#^(?:[A-Za-z0-9._-]+/)*[A-Za-z0-9._-]+$#D', $path)) throw new DomainException('Ungültiger Vorschau-Dateipfad.');
        return $path;
    }

    private function mime(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'html', 'htm' => 'text/html; charset=UTF-8', 'css' => 'text/css; charset=UTF-8', 'js', 'mjs' => 'text/javascript; charset=UTF-8',
            'json', 'map' => 'application/json', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp',
            'gif' => 'image/gif', 'avif' => 'image/avif', 'ico' => 'image/x-icon', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg',
            'mp4' => 'video/mp4', 'webm' => 'video/webm', 'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf',
            'txt' => 'text/plain; charset=UTF-8', 'xml' => 'application/xml', 'webmanifest' => 'application/manifest+json', default => 'application/octet-stream',
        };
    }

    private function inside(string $candidate, string $root): bool
    {
        $candidate = str_replace('\\', '/', $candidate); $root = rtrim(str_replace('\\', '/', $root), '/');
        return $candidate === $root || str_starts_with($candidate, $root.'/');
    }

    private function containsSymlinkComponent(string $path): bool
    {
        $path=str_replace('\\','/',$path);$prefix=str_starts_with($path,'/')?'/':'';
        foreach(array_values(array_filter(explode('/',$path),static fn(string $part):bool=>$part!=='')) as $part){$prefix=$prefix===''||str_ends_with($prefix,'/')?$prefix.$part:$prefix.'/'.$part;if(is_link($prefix))return true;}
        return false;
    }
}
