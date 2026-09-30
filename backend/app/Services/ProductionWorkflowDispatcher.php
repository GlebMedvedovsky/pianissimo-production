<?php

namespace App\Services;

use App\Enums\ProductionPublicationStatus;
use App\Models\ProductionPublication;
use DomainException;

class ProductionWorkflowDispatcher
{
    /** @return array{publication_id:string,sequence:string,package_sha256:string,source_revision:string} */
    public function payload(ProductionPublication $publication): array
    {
        $publication->refresh();
        if (! in_array($publication->status, [ProductionPublicationStatus::Prepared, ProductionPublicationStatus::Dispatched], true)) {
            throw new DomainException('Only a prepared or dispatched production publication can be dispatched.');
        }
        if (! preg_match('/^[0-9a-f-]{36}$/', $publication->publication_id)
            || $publication->sequence < 1
            || ! preg_match('/^[0-9a-f]{64}$/', $publication->package_sha256)
            || ! preg_match('/^[0-9a-f]{40}$/', $publication->source_revision)) {
            throw new DomainException('Production workflow dispatch inputs are invalid.');
        }

        return [
            'publication_id' => $publication->publication_id,
            'sequence' => (string) $publication->sequence,
            'package_sha256' => $publication->package_sha256,
            'source_revision' => $publication->source_revision,
        ];
    }
}
