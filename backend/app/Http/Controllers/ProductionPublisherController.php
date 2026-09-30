<?php

namespace App\Http\Controllers;

use App\Models\ProductionPublication;
use App\Services\ProductionPublicationService;
use App\Services\ProductionPublicationTransport;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class ProductionPublisherController
{
    public function __construct(private readonly ProductionPublicationService $lifecycle) {}

    public function claim(Request $request, string $publicationId): JsonResponse
    {
        $input = $request->validate(['runner_id' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/']]);
        try {
            $publication = $this->publication($publicationId);
            $claim = $this->lifecycle->claimForRunner($publication, $input['runner_id']);
            $resumed = $this->lifecycle->resumeClaimedWorkflow($claim['publication'], $input['runner_id']);
            $publication = $resumed['publication'];

            return response()->json($this->metadata($publication) + [
                'claim_result' => $claim['result'],
                'resume' => $resumed['resume'],
            ]);
        } catch (ModelNotFoundException) {
            return $this->error('Publication unavailable.', 404);
        } catch (DomainException) {
            return $this->error('Publication claim rejected.', 409);
        } catch (Throwable) {
            return $this->error('Publisher request failed.', 500);
        }
    }

    public function package(Request $request, string $publicationId, ProductionPublicationTransport $transport): BinaryFileResponse|JsonResponse
    {
        try {
            $publication = $this->publication($publicationId);
            $runnerId = (string) $request->header('X-Pianissimo-Runner-Id', '');
            $this->lifecycle->assertClaimingRunner($publication, $runnerId);
            if (! $this->lifecycle->requiresBuildForResume($publication->status)) {
                throw new DomainException('Publication package is not available in this state.');
            }
            $archive = $transport->create($publication);

            return response()->download($archive['path'], $archive['filename'], [
                'Content-Type' => 'application/x-tar',
                'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
                'X-Pianissimo-Package-Sha256' => $publication->package_sha256,
                'X-Pianissimo-Transport-Sha256' => $archive['sha256'],
            ])->deleteFileAfterSend(true);
        } catch (ModelNotFoundException) {
            return $this->error('Publication unavailable.', 404);
        } catch (DomainException) {
            return $this->error('Publication package unavailable.', 409);
        } catch (Throwable) {
            return $this->error('Publisher request failed.', 500);
        }
    }

    public function status(Request $request, string $publicationId): JsonResponse
    {
        $input = $request->validate([
            'runner_id' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/'],
            'status' => ['required', Rule::in(['building', 'built', 'uploading', 'uploaded', 'failed'])],
            'release_sha256' => ['nullable', 'regex:/^[0-9a-f]{64}$/'],
            'release_files_sha256' => ['nullable', 'regex:/^[0-9a-f]{64}$/'],
            'release_metadata_sha256' => ['nullable', 'regex:/^[0-9a-f]{64}$/'],
            'error_code' => ['nullable', Rule::in(['build_failed', 'package_download_failed', 'package_verification_failed', 'build_validation_failed', 'upload_failed', 'activation_failed', 'workflow_failed'])],
            'error_message' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $publication = $this->publication($publicationId);
            $publication = $this->lifecycle->updateFromRunner(
                $publication,
                $input['runner_id'],
                $input['status'],
                isset($input['release_sha256']) ? (string) $input['release_sha256'] : null,
                isset($input['release_files_sha256']) ? (string) $input['release_files_sha256'] : null,
                isset($input['release_metadata_sha256']) ? (string) $input['release_metadata_sha256'] : null,
                (string) ($input['error_code'] ?? 'workflow_failed'),
                isset($input['error_message']) ? (string) $input['error_message'] : null,
            );

            return response()->json($this->metadata($publication));
        } catch (ModelNotFoundException) {
            return $this->error('Publication unavailable.', 404);
        } catch (DomainException) {
            return $this->error('Publication status update rejected.', 409);
        } catch (Throwable) {
            return $this->error('Publisher request failed.', 500);
        }
    }

    private function publication(string $publicationId): ProductionPublication
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $publicationId)) {
            throw (new ModelNotFoundException)->setModel(ProductionPublication::class);
        }

        return ProductionPublication::query()->where('publication_id', $publicationId)->firstOrFail();
    }

    private function metadata(ProductionPublication $publication): array
    {
        return [
            'publication_id' => $publication->publication_id,
            'sequence' => $publication->sequence,
            'source_revision' => $publication->source_revision,
            'package_sha256' => $publication->package_sha256,
            'status' => $publication->status->value,
            'release_sha256' => $publication->release_sha256,
            'release_files_sha256' => $publication->release_files_sha256,
            'release_metadata_sha256' => $publication->release_metadata_sha256,
        ];
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json(['message' => $message], $status, ['Cache-Control' => 'private, no-store']);
    }
}
