<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ProjectMedia extends Model
{
    protected $table='project_media';
    protected $guarded=[];
    public function project() {return $this->belongsTo(Project::class);}
}
