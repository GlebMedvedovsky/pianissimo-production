<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ProjectTrack extends Model
{
    protected $guarded=[];
    public function project() {return $this->belongsTo(Project::class);}
    public function track() {return $this->belongsTo(Track::class);}
}
