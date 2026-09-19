<?php
namespace App\Filament\Pages;
class Impressum extends \App\Filament\SettingsPage
{
    protected static ?int $navigationSort=10;
    protected static string $settingsKey='impressum';
    protected static ?string $title='Impressum';
    protected static string|\UnitEnum|null $navigationGroup='Rechtliches & Datenschutz';
}
