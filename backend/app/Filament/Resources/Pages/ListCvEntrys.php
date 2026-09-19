<?php
namespace App\Filament\Resources\Pages;
class ListCvEntrys extends \Filament\Resources\Pages\ListRecords
{
    protected static string $resource = \App\Filament\Resources\CvEntryResource::class;
    protected function getHeaderActions(): array { return [\Filament\Actions\CreateAction::make()]; }

}

