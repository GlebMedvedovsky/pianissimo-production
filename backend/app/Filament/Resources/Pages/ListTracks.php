<?php
namespace App\Filament\Resources\Pages;
class ListTracks extends \Filament\Resources\Pages\ListRecords
{
    protected static string $resource = \App\Filament\Resources\TrackResource::class;
    protected function getHeaderActions(): array { return [\Filament\Actions\CreateAction::make()]; }

}

