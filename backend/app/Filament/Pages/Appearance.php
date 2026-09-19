<?php
namespace App\Filament\Pages;
class Appearance extends \App\Filament\SettingsPage
{
    protected static ?int $navigationSort=70;
    protected static string $settingsKey='appearance';
    protected static ?string $title='Website-Farben';
    protected static string|\UnitEnum|null $navigationGroup='Website';
}
