<?php

namespace App\Http\Controllers;

use App\Enums\ProductionPublicationStatus;
use App\Models\ProductionPublication;
use App\Services\ProductionPublicationPackageReader;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

final class ProductionContentSyncController
{
    public function __construct(private readonly ProductionPublicationPackageReader $packages) {}

    public function active(): JsonResponse
    {
        try {
            $publication = ProductionPublication::currentActive();
            if ($publication === null) {
                throw (new ModelNotFoundException)->setModel(ProductionPublication::class);
            }

            return response()->json([
                'contractVersion' => 1,
                'publicationId' => $publication->publication_id,
                'sequence' => $publication->sequence,
                'activatedAt' => $publication->activated_at?->utc()->format('Y-m-d\TH:i:s\Z'),
                'sourceRevision' => $publication->source_revision,
                'packageSha256' => $publication->package_sha256,
                'manifestSha256' => $publication->manifest_sha256,
                'releaseSha256' => $publication->release_sha256,
                'publicationUrl' => route('content-sync.publication', ['publicationId' => $publication->publication_id], false),
                'manifestUrl' => route('content-sync.manifest', ['publicationId' => $publication->publication_id], false),
                'mediaUrlTemplate' => '/api/content-sync/v1/publications/'.$publication->publication_id.'/media/{sha256}',
            ]);
        } catch (ModelNotFoundException) {
            return $this->error('Active production content unavailable.', 404);
        } catch (Throwable) {
            return $this->error('Content sync request failed.', 500);
        }
    }

    public function publication(string $publicationId): Response|JsonResponse
    {
        try {
            $package = $this->packages->read($this->activatedPublication($publicationId));

            return response($package['publicationBytes'], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        } catch (ModelNotFoundException) {
            return $this->error('Production publication unavailable.', 404);
        } catch (DomainException) {
            return $this->error('Production publication package unavailable.', 409);
        } catch (Throwable) {
            return $this->error('Content sync request failed.', 500);
        }
    }

    public function manifest(string $publicationId): Response|JsonResponse
    {
        try {
            $package = $this->packages->read($this->activatedPublication($publicationId));

            return response($package['manifestBytes'], 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        } catch (ModelNotFoundException) {
            return $this->error('Production publication unavailable.', 404);
        } catch (DomainException) {
            return $this->error('Production publication package unavailable.', 409);
        } catch (Throwable) {
            return $this->error('Content sync request failed.', 500);
        }
    }

    public function media(string $publicationId, string $sha256): BinaryFileResponse|JsonResponse
    {
        try {
            $media = $this->packages->media($this->activatedPublication($publicationId), $sha256);
            $response = response()->file($media['path'], [
                'Content-Type' => $this->contentType($media['entry']),
                'Content-Length' => (string) $media['entry']['size'],
                'ETag' => '"'.$media['entry']['sha256'].'"',
                'Accept-Ranges' => 'bytes',
            ]);
            $response->setContentDisposition('inline', basename($media['entry']['publicPath']));

            return $response;
        } catch (ModelNotFoundException) {
            return $this->error('Production publication unavailable.', 404);
        } catch (DomainException) {
            return $this->error('Production publication media unavailable.', 409);
        } catch (Throwable) {
            return $this->error('Content sync request failed.', 500);
        }
    }

    private function activatedPublication(string $publicationId): ProductionPublication
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $publicationId)) {
            throw (new ModelNotFoundException)->setModel(ProductionPublication::class);
        }

        return ProductionPublication::query()
            ->where('publication_id', $publicationId)
            ->whereNotNull('activated_at')
            ->whereIn('status', [
                ProductionPublicationStatus::Active->value,
                ProductionPublicationStatus::Superseded->value,
                ProductionPublicationStatus::RolledBack->value,
            ])
            ->firstOrFail();
    }

    /** @param array{publicPath:string,type:string} $entry */
    private function contentType(array $entry): string
    {
        $extension = strtolower(pathinfo($entry['publicPath'], PATHINFO_EXTENSION));

        return match ($entry['type'].':'.$extension) {
            'image:webp' => 'image/webp',
            'image:jpg', 'image:jpeg' => 'image/jpeg',
            'image:png' => 'image/png',
            'audio:mp3' => 'audio/mpeg',
            'audio:wav' => 'audio/wav',
            'audio:ogg' => 'audio/ogg',
            'video:mp4' => 'video/mp4',
            'video:webm' => 'video/webm',
            default => throw new DomainException('Immutable publication media type is invalid.'),
        };
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json(['message' => $message], $status, ['Cache-Control' => 'private, no-store']);
    }
}
