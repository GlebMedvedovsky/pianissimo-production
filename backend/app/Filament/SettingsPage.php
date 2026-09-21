<?php
namespace App\Filament;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use App\Models\Setting;
abstract class SettingsPage extends Page implements HasForms
{
    use InteractsWithForms;
    protected string $view='cms.settings';
    protected static string $settingsKey;
    public ?array $data=[];
    public function mount(): void { $this->form->fill(Setting::read(static::$settingsKey)); }
    public function form(Schema $schema): Schema { return $schema->components(SettingsForm::fields(static::$settingsKey))->statePath('data'); }
    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\Action::make('save')->label('Entwurf speichern')->action(fn () => $this->save())];
    }
    public function save(): void
    {
        Setting::put(static::$settingsKey,array_replace(Setting::read(static::$settingsKey),$this->form->getState()));
        Notification::make()->title('Entwurf gespeichert')->body('Die aktive Website und veröffentlichte Pakete bleiben unverändert.')->success()->send();
    }
}
