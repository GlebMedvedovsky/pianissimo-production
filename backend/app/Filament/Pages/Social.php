<?php
namespace App\Filament\Pages;
class Social extends \App\Filament\SettingsPage
{
    protected static ?int $navigationSort=50;
    protected static string $settingsKey='social';
    protected static ?string $title='Social Links';
    protected static string|\UnitEnum|null $navigationGroup='Website';
}
