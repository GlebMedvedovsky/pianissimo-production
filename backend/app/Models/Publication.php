<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Publication extends Model
{
    protected $guarded=[];
    protected $casts=['summary'=>'array'];
}
