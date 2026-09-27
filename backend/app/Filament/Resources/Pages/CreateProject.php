<?php
namespace App\Filament\Resources\Pages;
class CreateProject extends \Filament\Resources\Pages\CreateRecord
{
    protected static string $resource = \App\Filament\Resources\ProjectResource::class;
    protected function getHeaderActions(): array { return [\Filament\Actions\Action::make('help')->label('Hilfe')->url(fn()=>\App\Filament\Pages\Help::getUrl(['article'=>'projekt-anlegen']).'#projekt-anlegen')]; }
}
