<?php
namespace App\Filament\Pages;
class Home extends \App\Filament\SettingsPage
{
    protected static ?int $navigationSort=10;
    protected static string $settingsKey='home';
    protected static ?string $title='Startseite';
    protected static string|\UnitEnum|null $navigationGroup='Website';
}
