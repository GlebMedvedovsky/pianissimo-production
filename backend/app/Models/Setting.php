<?php
namespace App\Models;
class Setting extends StableModel
{
    protected $casts=['data'=>'array'];
    public static function read(string $key): array { return static::find($key)?->data ?? []; }
    public static function put(string $key, array $data): void { static::updateOrCreate(['id'=>$key], ['data'=>$data]); }
}
