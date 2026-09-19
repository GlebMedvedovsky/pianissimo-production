<?php
namespace App\Filament\Resources\Pages;
class EditTrack extends \Filament\Resources\Pages\EditRecord
{
    protected static string $resource = \App\Filament\Resources\TrackResource::class;

    protected function getSaveFormAction(): \Filament\Actions\Action { return parent::getSaveFormAction()->label('Entwurf speichern'); }
}

