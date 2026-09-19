<?php
namespace App\Filament\Pages;
class Contact extends \App\Filament\SettingsPage
{
    protected static ?int $navigationSort=40;
    protected static string $settingsKey='contact';
    protected static ?string $title='Kontakt';
    protected static string|\UnitEnum|null $navigationGroup='Website';
}
