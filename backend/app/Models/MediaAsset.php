<?php
namespace App\Models;
class MediaAsset extends StableModel
{
    protected $casts = ['crop'=>'array','duration'=>'float'];
}
