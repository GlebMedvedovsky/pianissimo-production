<?php

namespace App\Models;

use App\Enums\ProductionPublicationStatus;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionPublication extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => ProductionPublicationStatus::class,
            'sequence' => 'integer',
            'active_slot' => 'integer',
            'dispatched_at' => 'immutable_datetime',
            'claimed_at' => 'immutable_datetime',
            'built_at' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime',
        ];
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public static function currentActive(): ?self
    {
        return self::query()
            ->where('active_slot', 1)
            ->where('status', ProductionPublicationStatus::Active->value)
            ->orderByDesc('activated_at')
            ->orderByDesc('sequence')
            ->first();
    }

    public static function canTransition(ProductionPublicationStatus $from, ProductionPublicationStatus $to): bool
    {
        return in_array($to, match ($from) {
            ProductionPublicationStatus::Preparing => [ProductionPublicationStatus::Prepared, ProductionPublicationStatus::Failed],
            ProductionPublicationStatus::Prepared => [ProductionPublicationStatus::Dispatching, ProductionPublicationStatus::Failed, ProductionPublicationStatus::Superseded],
            ProductionPublicationStatus::Dispatching => [ProductionPublicationStatus::Dispatched, ProductionPublicationStatus::Failed],
            ProductionPublicationStatus::Dispatched => [ProductionPublicationStatus::Building, ProductionPublicationStatus::Failed],
            ProductionPublicationStatus::Building => [ProductionPublicationStatus::Built, ProductionPublicationStatus::Failed],
            ProductionPublicationStatus::Built => [ProductionPublicationStatus::Uploading, ProductionPublicationStatus::Failed],
            ProductionPublicationStatus::Uploading => [ProductionPublicationStatus::Uploaded, ProductionPublicationStatus::Failed],
            ProductionPublicationStatus::Uploaded => [ProductionPublicationStatus::Activating, ProductionPublicationStatus::Failed],
            ProductionPublicationStatus::Activating => [ProductionPublicationStatus::Active, ProductionPublicationStatus::Failed],
            ProductionPublicationStatus::Active => [ProductionPublicationStatus::Superseded, ProductionPublicationStatus::RolledBack],
            ProductionPublicationStatus::Failed => [ProductionPublicationStatus::Preparing, ProductionPublicationStatus::Dispatched, ProductionPublicationStatus::Built],
            ProductionPublicationStatus::Superseded, ProductionPublicationStatus::RolledBack => [ProductionPublicationStatus::Active],
        }, true);
    }

    protected static function booted(): void
    {
        static::creating(function (self $publication): void {
            if ($publication->status !== ProductionPublicationStatus::Preparing) {
                throw new DomainException('A production publication must begin in preparing state.');
            }
            if ($publication->active_slot !== null) {
                throw new DomainException('A preparing production publication cannot own the active slot.');
            }
            if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $publication->publication_id)) {
                throw new DomainException('Invalid production publication UUID.');
            }
            if ((int) $publication->sequence < 1) {
                throw new DomainException('Production publication sequence must be positive.');
            }
            $expectedPath = 'production/'.str_pad((string) $publication->sequence, 12, '0', STR_PAD_LEFT).'-'.$publication->publication_id;
            if ($publication->package_path !== $expectedPath) {
                throw new DomainException('Production publication package path is not the deterministic private relative path.');
            }
            foreach (['candidate_hash' => 64, 'plan_hash' => 64, 'package_sha256' => 64, 'manifest_sha256' => 64, 'source_revision' => 40] as $field => $length) {
                if (! preg_match('/^[0-9a-f]{'.$length.'}$/', (string) $publication->{$field})) {
                    throw new DomainException("Invalid production publication field: {$field}.");
                }
            }
        });

        static::updating(function (self $publication): void {
            foreach (['publication_id', 'sequence', 'candidate_hash', 'plan_hash', 'source_revision', 'package_sha256', 'manifest_sha256', 'package_path', 'requested_by'] as $field) {
                if ($publication->isDirty($field)) {
                    throw new DomainException("Immutable production publication field cannot change: {$field}.");
                }
            }
            if ($publication->isDirty('claimed_by') && $publication->getRawOriginal('claimed_by') !== null) {
                throw new DomainException('Production publication claim ownership is immutable.');
            }
            foreach (['release_sha256', 'release_files_sha256', 'release_metadata_sha256'] as $field) {
                if ($publication->isDirty($field) && $publication->getRawOriginal($field) !== null) {
                    throw new DomainException('Production release digest is immutable.');
                }
            }
            if ($publication->isDirty('status')) {
                $from = ProductionPublicationStatus::from((string) $publication->getRawOriginal('status'));
                $to = $publication->status;
                if (! self::canTransition($from, $to)) {
                    throw new DomainException("Illegal production publication transition: {$from->value} -> {$to->value}.");
                }
            }
            if ($publication->status === ProductionPublicationStatus::Active && $publication->active_slot !== 1) {
                throw new DomainException('The active production publication must own the unique active slot.');
            }
            if ($publication->status !== ProductionPublicationStatus::Active && $publication->active_slot !== null) {
                throw new DomainException('Only an active production publication may own the active slot.');
            }
        });
    }
}
