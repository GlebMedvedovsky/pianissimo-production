<?php
namespace App\Filament\Resources\Pages;
class EditCvEntry extends \Filament\Resources\Pages\EditRecord
{
    protected static string $resource = \App\Filament\Resources\CvEntryResource::class;

    protected function getSaveFormAction(): \Filament\Actions\Action { return parent::getSaveFormAction()->label('Entwurf speichern'); }
}

