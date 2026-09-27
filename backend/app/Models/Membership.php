<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Membership extends Model
{
    protected static function booted(): void
    {
        static::saving(function ($membership): void {
            \App\Services\MediaTypePolicy::assert($membership->cover_asset_id,'image','Projekt-Cover');
            \App\Services\MediaTypePolicy::assert($membership->hover_asset_id,'video','Hover-Video');
        });
    }
    protected $guarded=[];
    protected $casts=['crop'=>'array'];
    public function project() {return $this->belongsTo(Project::class);}
}
