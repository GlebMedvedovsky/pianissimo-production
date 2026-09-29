<?php

namespace App\Services;

class MediaSlotPolicy
{
    public const MODE_IMAGE = 'image';
    public const MODE_VIDEO = 'video';

    public static function hydrate(string $setting, array $data): array
    {
        $media = $data['media'] ?? [];
        $media['mode'] = self::mode($setting, $media);
        $data['media'] = $media;

        return $data;
    }

    public static function mode(string $setting, array $media): string
    {
        if (in_array($media['mode'] ?? null, [self::MODE_IMAGE, self::MODE_VIDEO], true)) {
            return $media['mode'];
        }

        // About is intentionally a static portrait unless its owner explicitly opts in to video.
        if ($setting === 'about') {
            return self::MODE_IMAGE;
        }

        return ! empty($media['video']) ? self::MODE_VIDEO : self::MODE_IMAGE;
    }

    public static function project(string $setting, array $media): array
    {
        $mode = self::mode($setting, $media);

        return [
            'image' => $mode === self::MODE_IMAGE ? ($media['image'] ?? null) : null,
            'video' => $mode === self::MODE_VIDEO ? ($media['video'] ?? null) : null,
            'poster' => $mode === self::MODE_VIDEO ? ($media['poster'] ?? null) : null,
        ];
    }

    public static function isActive(string $setting, string $field, array $media): bool
    {
        $mode = self::mode($setting, $media);

        return $field === $mode || ($field === 'poster' && $mode === self::MODE_VIDEO);
    }

    public static function validate(string $setting, array $media): void
    {
        $mode = self::mode($setting, $media);
        if ($mode === self::MODE_IMAGE) {
            MediaTypePolicy::assert($media['image'] ?? null, 'image', $setting.' Bild');
            return;
        }

        MediaTypePolicy::assert($media['video'] ?? null, 'video', $setting.' Video');
        MediaTypePolicy::assert($media['poster'] ?? null, 'image', $setting.' Video-Poster');
    }
}
