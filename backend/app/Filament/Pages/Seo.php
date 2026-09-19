<?php
namespace App\Filament\Pages;
class Seo extends \App\Filament\SettingsPage
{
    protected static ?int $navigationSort=60;
    protected static string $settingsKey='seo';
    protected static ?string $title='SEO';
    protected static string|\UnitEnum|null $navigationGroup='Website';
}
