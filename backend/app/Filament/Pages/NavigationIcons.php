<?php
namespace App\Filament\Pages;

use App\Models\Setting;
use App\Services\{AuditLogger,IconRail};

class NavigationIcons extends \App\Filament\SettingsPage
{
    protected static ?int $navigationSort=45;
    protected static string $settingsKey='icon-rail';
    protected static ?string $title='Navigation & Symbole';
    protected static ?string $navigationLabel='Navigation & Symbole';
    protected static string|\UnitEnum|null $navigationGroup='Website';

    public function save(): void
    {
        $before=Setting::read(static::$settingsKey);
        $state=IconRail::validate($this->form->getState());
        Setting::put(static::$settingsKey,$state);
        app(AuditLogger::class)->record('settings',static::$settingsKey,'update',null,[
            'styleBefore'=>$before['style']??null,'styleAfter'=>$state['style'],'visibleCount'=>count(array_filter($state['items'],fn($item)=>$item['visible'])),
        ]);
        \Filament\Notifications\Notification::make()->title('Entwurf gespeichert')->body('Die aktive Website und veröffentlichte Pakete bleiben unverändert.')->success()->send();
    }
}
