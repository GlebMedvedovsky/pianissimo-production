<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DraftPreview extends Model
{
    protected $guarded=[];
    protected $hidden=['token_hash'];
    protected $casts=['media'=>'array','expires_at'=>'datetime','invalidated_at'=>'datetime'];
}
