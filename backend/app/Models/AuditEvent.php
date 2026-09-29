<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AuditEvent extends Model
{
    public $timestamps=false;
    protected $guarded=[];
    protected $casts=['metadata'=>'array','created_at'=>'datetime'];
}
