<?php
namespace App\Filament\Resources\Pages;
class ListProjects extends \Filament\Resources\Pages\ListRecords
{
    protected static string $resource = \App\Filament\Resources\ProjectResource::class;
    protected function getHeaderActions(): array { return [\Filament\Actions\CreateAction::make()]; }

}

