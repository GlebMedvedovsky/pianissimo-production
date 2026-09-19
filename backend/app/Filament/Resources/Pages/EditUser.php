<?php
namespace App\Filament\Resources\Pages;
class EditUser extends \Filament\Resources\Pages\EditRecord
{
    protected static string $resource = \App\Filament\Resources\UserResource::class;

    protected function getSaveFormAction(): \Filament\Actions\Action { return parent::getSaveFormAction()->label('Entwurf speichern'); }
}

