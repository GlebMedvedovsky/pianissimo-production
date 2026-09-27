<?php
namespace App\Models;
class Track extends StableModel
{
    protected static function booted(): void
    {
        parent::booted();
        static::saving(fn($track)=>\App\Services\MediaTypePolicy::assert($track->audio_asset_id,'audio','Track-Playback'));
        static::updating(function ($track) {
            if ($track->isDirty('audio_asset_id')) { $track->peaks=null; $track->audio_sha256=null; $track->waveform_sha256=null; }
        });
    }
    protected $casts = ['peaks'=>'array','genres'=>'array','emotions'=>'array','platform_overrides'=>'array','duration'=>'float','library_visible'=>'boolean'];
    public function soundtrack() { return $this->hasMany(ProjectTrack::class)->orderBy('sort_order')->orderBy('id'); }
}
