<?php
namespace App\Filament\Resources\Pages;
class EditExternalService extends \Filament\Resources\Pages\EditRecord
{
    protected static string $resource = \App\Filament\Resources\ExternalServiceResource::class;

    protected function getSaveFormAction(): \Filament\Actions\Action { return parent::getSaveFormAction()->label('Entwurf speichern'); }
}

