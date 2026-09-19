<?php
namespace App\Filament\Pages;
class Consent extends \App\Filament\SettingsPage
{
    protected static ?int $navigationSort=30;
    protected static string $settingsKey='consent';
    protected static ?string $title='Cookie / Consent';
    protected static string|\UnitEnum|null $navigationGroup='Rechtliches & Datenschutz';
}
