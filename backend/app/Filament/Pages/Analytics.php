<?php
namespace App\Filament\Pages;
class Analytics extends \App\Filament\SettingsPage
{
    protected static ?int $navigationSort=80;
    protected static string $settingsKey='analytics';
    protected static ?string $title='Analytics';
    protected static string|\UnitEnum|null $navigationGroup='Website';
    protected string $view='cms.analytics';
    public function save(): void
    {
        $state=$this->form->getState();
        if(($state['enabled']??false) && ($state['provider']??'none')==='none')throw \Illuminate\Validation\ValidationException::withMessages(['data.provider'=>'Bitte wählen Sie einen Anbieter oder deaktivieren Sie Analytics.']);
        parent::save();
    }
    protected function getHeaderActions(): array
    {
        return [...parent::getHeaderActions(),
            \Filament\Actions\Action::make('open')->label('Analytics öffnen')->url(fn()=>($this->data['provider']??'none')==='ga4'?'https://analytics.google.com/':'https://plausible.io/')->openUrlInNewTab()->visible(fn()=>in_array($this->data['provider']??'none',['ga4','plausible'],true)),
            \Filament\Actions\Action::make('help')->label('Anleitung anzeigen')->url(fn()=>Help::getUrl(['category'=>'Analytics'])),
        ];
    }
}
