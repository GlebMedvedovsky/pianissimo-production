<?php
namespace App\Filament\Pages;
class Analytics extends \App\Filament\SettingsPage
{
    protected static ?int $navigationSort=80;
    protected static string $settingsKey='analytics';
    protected static ?string $title='Analytics (inaktiv)';
    protected static string|\UnitEnum|null $navigationGroup='Website';
}
