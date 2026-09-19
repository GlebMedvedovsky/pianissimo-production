<?php
namespace App\Filament\Resources\Pages;
class ListMediaAssets extends \Filament\Resources\Pages\ListRecords
{
    protected static string $resource = \App\Filament\Resources\MediaAssetResource::class;
    protected function getHeaderActions(): array { return [\Filament\Actions\CreateAction::make()]; }

}

