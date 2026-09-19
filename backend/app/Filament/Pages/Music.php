<?php
namespace App\Filament\Pages;
class Music extends \App\Filament\SettingsPage
{
    protected static ?int $navigationSort=20;
    protected static string $settingsKey='music';
    protected static ?string $title='Film Music';
    protected static string|\UnitEnum|null $navigationGroup='Website';
}
