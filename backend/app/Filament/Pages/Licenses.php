<?php
namespace App\Filament\Pages;
class Licenses extends \App\Filament\SettingsPage
{
    protected static ?int $navigationSort=50;
    protected static string $settingsKey='licenses';
    protected static ?string $title='Lizenzen / Credits';
    protected static string|\UnitEnum|null $navigationGroup='Rechtliches & Datenschutz';
}
