<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AudioAnalysisJob extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $casts = ['duration' => 'float', 'peaks' => 'array'];
}
