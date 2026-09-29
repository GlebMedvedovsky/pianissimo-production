<?php
namespace App\Filament\Pages;
class About extends \App\Filament\SettingsPage
{
    protected static ?int $navigationSort=40;
    protected static string $settingsKey='about';
    protected static ?string $title='About';
    protected static string|\UnitEnum|null $navigationGroup='Inhalte';
    protected function getHeaderActions(): array { return [...parent::getHeaderActions(),\Filament\Actions\Action::make('help')->label('About-Hilfe')->url(fn()=>Help::getUrl(['article'=>'about-bild-video']).'#about-bild-video')]; }
}
