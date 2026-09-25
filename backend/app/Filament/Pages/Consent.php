<?php
namespace App\Filament\Pages;
class Consent extends \App\Filament\SettingsPage
{
    protected static ?int $navigationSort=30;
    protected static string $settingsKey='consent';
    protected static ?string $title='Cookie / Consent';
    protected static string|\UnitEnum|null $navigationGroup='Rechtliches & Datenschutz';
    protected string $view='cms.consent';
    protected function getHeaderActions(): array { return [...parent::getHeaderActions(),\Filament\Actions\Action::make('help')->label('Consent-Hilfe')->url(fn()=>Help::getUrl(['category'=>'Cookie / Consent']))]; }
}
