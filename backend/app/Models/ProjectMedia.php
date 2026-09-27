<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ProjectMedia extends Model
{
    protected static function booted(): void
    {
        static::saving(function ($assignment): void {
            $type=\App\Services\MediaTypePolicy::projectSlotType($assignment->slot);
            if($type)\App\Services\MediaTypePolicy::assert($assignment->media_asset_id,$type,'Projektmedium '.$assignment->slot);
            if(in_array($assignment->slot,['trailer','trailer_poster','soundtrack_cover'],true)){
                $duplicate=static::where('project_id',$assignment->project_id)->where('slot',$assignment->slot)->when($assignment->exists,fn($query)=>$query->whereKeyNot($assignment->getKey()))->exists();
                if($duplicate)throw new \DomainException('Diese Medienrolle darf pro Projekt nur einmal vergeben werden: '.$assignment->slot.'.');
            }
        });
    }
    protected $table='project_media';
    protected $guarded=[];
    public function project() {return $this->belongsTo(Project::class);}
}
