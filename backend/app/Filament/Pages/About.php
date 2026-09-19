<?php
namespace App\Filament\Pages;
class About extends \App\Filament\SettingsPage
{
    protected static ?int $navigationSort=40;
    protected static string $settingsKey='about';
    protected static ?string $title='About';
    protected static string|\UnitEnum|null $navigationGroup='Inhalte';
}
