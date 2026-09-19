<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
abstract class StableModel extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected static function booted(): void
    {
        static::updating(function ($model) {
            if ($model->isDirty('id')) throw new \DomainException('Die stabile ID darf nicht geändert werden.');
        });
    }
}
