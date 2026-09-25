<?php
namespace App\Filament\Resources\Pages;
class ListExternalServices extends \Filament\Resources\Pages\ListRecords
{
    protected static string $resource = \App\Filament\Resources\ExternalServiceResource::class;
    protected function getHeaderActions(): array { return [\Filament\Actions\Action::make('help')->label('Hilfe')->url(fn()=>\App\Filament\Pages\Help::getUrl(['search'=>'Externe Medien'])),\Filament\Actions\CreateAction::make()]; }

}
