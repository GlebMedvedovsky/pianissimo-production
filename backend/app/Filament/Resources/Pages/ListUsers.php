<?php
namespace App\Filament\Resources\Pages;
class ListUsers extends \Filament\Resources\Pages\ListRecords
{
    protected static string $resource = \App\Filament\Resources\UserResource::class;
    protected function getHeaderActions(): array { return [\Filament\Actions\CreateAction::make()]; }

}

