<?php
namespace App\Filament\Pages;
class Site extends \App\Filament\SettingsPage
{
    protected static ?int $navigationSort=10;
    protected static string $settingsKey='site';
    protected static ?string $title='Site-Einstellungen';
    protected static string|\UnitEnum|null $navigationGroup='System';
}
