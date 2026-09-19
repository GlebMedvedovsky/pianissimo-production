<?php
namespace App\Providers;
use Illuminate\Database\Eloquent\Model;
class LocalAvatarProvider implements \Filament\AvatarProviders\Contracts\AvatarProvider
{
    public function get(Model $record): string { return asset('avatar.svg'); }
}
