<?php
namespace App\Filament\Pages;
class Directing extends \App\Filament\SettingsPage
{
    protected static ?int $navigationSort=30;
    protected static string $settingsKey='directing';
    protected static ?string $title='Directing';
    protected static string|\UnitEnum|null $navigationGroup='Website';
}
