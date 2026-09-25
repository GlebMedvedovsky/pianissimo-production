<?php
namespace App\Models;
use Illuminate\Database\Eloquent\SoftDeletes;
class MediaAsset extends StableModel
{
    use SoftDeletes;
    protected $casts = ['crop'=>'array','duration'=>'float'];
}
