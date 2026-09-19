<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Membership extends Model
{
    protected $guarded=[];
    protected $casts=['crop'=>'array'];
    public function project() {return $this->belongsTo(Project::class);}
}
