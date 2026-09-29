<?php
namespace App\Filament\Pages;
class Seo extends \App\Filament\SettingsPage
{
    protected static ?int $navigationSort=60;
    protected static string $settingsKey='seo';
    protected static ?string $title='SEO';
    protected static string|\UnitEnum|null $navigationGroup='Website';
    protected string $view='cms.seo';
    protected function getHeaderActions(): array { return [...parent::getHeaderActions(),\Filament\Actions\Action::make('help')->label('SEO-Hilfe')->url(fn()=>Help::getUrl(['category'=>'SEO']))]; }
}
