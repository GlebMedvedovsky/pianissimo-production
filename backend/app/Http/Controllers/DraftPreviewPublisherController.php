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

    public function resultChunk(string $identifier, string $index, Request $request, DraftPreviewService $previews, DraftPreviewResultService $results)
    {
        $preview = $previews->resolveForPublisher($identifier);
        if (! preg_match('/^(?:0|[1-9][0-9]{0,5})$/D', $index)) throw new DomainException('Der Vorschau-Ergebnisblock hat eine ungültige Nummer.');
        $stream = $request->getContent(true);
        if (! is_resource($stream)) throw new DomainException('Der Vorschau-Ergebnisblock fehlt.');
        try {
            $result = $results->uploadChunk(
                $preview,
                (int) $index,
                $stream,
                $this->uploadIdentity($request),
                (string) $request->header('X-Pianissimo-Chunk-Sha256'),
            );
        } finally {
            fclose($stream);
        }
        return response()->json($result, $result['stored'] ? 201 : 200, ['Cache-Control' => 'private, no-store, max-age=0']);
    }

    public function finalizeResult(string $identifier, Request $request, DraftPreviewService $previews, DraftPreviewResultService $results)
    {
        $preview = $previews->resolveForPublisher($identifier);
        $installed = $results->finalize($preview, $this->uploadIdentity($request));
        return response()->json(['ready' => true], $installed ? 201 : 200, ['Cache-Control' => 'private, no-store, max-age=0']);
    }

    private function uploadIdentity(Request $request): array
    {
        return [
            'archiveSha256' => (string) $request->header('X-Pianissimo-Archive-Sha256'),
            'chunkCount' => (string) $request->header('X-Pianissimo-Chunk-Count'),
            'packageSha256' => (string) $request->header('X-Pianissimo-Package-Sha256'),
            'releaseFilesSha256' => (string) $request->header('X-Pianissimo-Files-Sha256'),
            'releaseMetadataSha256' => (string) $request->header('X-Pianissimo-Metadata-Sha256'),
            'sourceRevision' => (string) $request->header('X-Pianissimo-Source-Revision'),
        ];
    }
}
