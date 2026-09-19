<?php
namespace App\Filament\Pages;
class Privacy extends \App\Filament\SettingsPage
{
    protected static ?int $navigationSort=20;
    protected static string $settingsKey='privacy';
    protected static ?string $title='Datenschutz';
    protected static string|\UnitEnum|null $navigationGroup='Rechtliches & Datenschutz';
}
