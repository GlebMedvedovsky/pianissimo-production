<?php
namespace App\Filament\Resources\Pages;
class EditMediaAsset extends \Filament\Resources\Pages\EditRecord
{
    protected static string $resource = \App\Filament\Resources\MediaAssetResource::class;

    protected function getSaveFormAction(): \Filament\Actions\Action { return parent::getSaveFormAction()->label('Entwurf speichern'); }
}

