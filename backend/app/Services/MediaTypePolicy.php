<?php

namespace App\Services;

use App\Models\MediaAsset;
use DomainException;
use Illuminate\Database\Eloquent\Builder;

class MediaTypePolicy
{
    public static function query(?string $type = null): Builder
    {
        return MediaAsset::query()->when($type, function (Builder $query, string $type): void {
            $query->where('type', $type)->where(function (Builder $mimeQuery) use ($type): void {
                $mimeQuery->whereNull('mime_type')->orWhereIn('mime_type', MediaStorage::mimeTypesFor($type));
            });
        });
    }

    public static function isCompatible(MediaAsset $asset, string $type): bool
    {
        return $asset->type === $type
            && ($asset->mime_type === null || in_array($asset->mime_type, MediaStorage::mimeTypesFor($type), true));
    }

    public static function assert(?string $assetId, string $type, string $owner): void
    {
        if ($assetId === null || $assetId === '') {
            return;
        }

        $asset = MediaAsset::find($assetId);
        if (! $asset || ! self::isCompatible($asset, $type)) {
            throw new DomainException($owner.': Medium fehlt oder hat einen inkompatiblen Dateiinhalt.');
        }
    }

    public static function projectSlotType(?string $slot): ?string
    {
        return match ($slot) {
            'gallery', 'trailer_poster', 'soundtrack_cover' => 'image',
            'trailer' => 'video',
            default => null,
        };
    }
}
