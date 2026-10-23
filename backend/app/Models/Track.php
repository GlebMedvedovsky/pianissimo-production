<?php
namespace App\Models;
class Track extends StableModel
{
    protected static function booted(): void
    {
        parent::booted();
        static::saving(function ($track): void {
            \App\Services\MediaTypePolicy::assert($track->audio_asset_id,'audio','Track-Playback');
            app(\App\Services\TrackAudioMetadata::class)->prepareForSave($track);
        });
        static::saved(function ($track): void {
            if (($track->wasRecentlyCreated || $track->wasChanged('audio_asset_id')) && $track->audio_asset_id !== null) {
                app(\App\Services\TrackAudioMetadata::class)->dispatchForTrack($track);
            }
        });
    }
    protected $casts = ['peaks'=>'array','genres'=>'array','emotions'=>'array','platform_overrides'=>'array','duration'=>'float','library_visible'=>'boolean'];
    public function soundtrack() { return $this->hasMany(ProjectTrack::class)->orderBy('sort_order')->orderBy('id'); }
    public function audioAsset() { return $this->belongsTo(MediaAsset::class,'audio_asset_id'); }
}
