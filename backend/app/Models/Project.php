<?php
namespace App\Models;
class Project extends StableModel
{
    public function setExternalFilmAttribute($value): void { $this->attributes['external_film']=is_array($value) && count(array_filter($value))===0?null:($value===null?null:json_encode($value)); }
    protected $casts = ['credits'=>'array','categories'=>'array','awards'=>'array','platform_links'=>'array','genres'=>'array','emotions'=>'array','seo'=>'array','external_links'=>'array','external_film'=>'array'];
    public function memberships() { return $this->hasMany(Membership::class)->orderBy('direction'); }
    public function mediaAssignments() { return $this->hasMany(ProjectMedia::class)->orderBy('sort_order')->orderBy('id'); }
    public function soundtrack() { return $this->hasMany(ProjectTrack::class)->orderBy('sort_order')->orderBy('id'); }
}
