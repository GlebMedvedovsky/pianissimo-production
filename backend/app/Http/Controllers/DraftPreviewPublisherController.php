<?php

namespace App\Http\Controllers;

use App\Services\{DraftPreviewPackageService, DraftPreviewResultService, DraftPreviewService};
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class DraftPreviewPublisherController
{
    public function package(string $identifier, DraftPreviewService $previews, DraftPreviewPackageService $packages): BinaryFileResponse
    {
        $preview = $previews->resolveForPublisher($identifier);
        $transport = $packages->transport($preview);
        return response()->download($transport['path'], $transport['filename'], [
            'Content-Type' => 'application/x-tar',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'X-Pianissimo-Package-Sha256' => $packages->metadata($preview)['packageSha256'],
            'X-Pianissimo-Transport-Sha256' => $transport['sha256'],
        ])->deleteFileAfterSend(true);
    }

    public function result(string $identifier, Request $request, DraftPreviewService $previews, DraftPreviewPackageService $packages, DraftPreviewResultService $results)
    {
        $preview = $previews->resolveForPublisher($identifier);
        $metadata = $packages->metadata($preview);
        if (! hash_equals($metadata['packageSha256'], (string) $request->header('X-Pianissimo-Package-Sha256'))) {
            throw new DomainException('Das Vorschau-Ergebnis gehört nicht zum erwarteten Paket.');
        }
        $stream = $request->getContent(true);
        if (! is_resource($stream)) throw new DomainException('Das Vorschau-Ergebnis fehlt.');
        try {
            $results->install($preview, $stream, [
                'archiveSha256' => (string) $request->header('X-Pianissimo-Archive-Sha256'),
                'releaseFilesSha256' => (string) $request->header('X-Pianissimo-Files-Sha256'),
                'releaseMetadataSha256' => (string) $request->header('X-Pianissimo-Metadata-Sha256'),
            ]);
        } finally {
            fclose($stream);
        }
        return response()->json(['ready' => true], 201, ['Cache-Control' => 'private, no-store, max-age=0']);
    }
}
