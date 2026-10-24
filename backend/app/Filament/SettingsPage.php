<?php
namespace App\Filament;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use App\Models\Setting;
use App\Services\{MediaSlotPolicy,MediaTypePolicy};
abstract class SettingsPage extends Page implements HasForms
{
    use InteractsWithForms;
    protected string $view='cms.settings';
    protected static string $settingsKey;
    public ?array $data=[];
    public function mount(): void { $data=Setting::read(static::$settingsKey);if(static::$settingsKey==='icon-rail'&&$data===[])$data=\App\Services\IconRail::fromManifestSite(['contacts'=>Setting::read('contact'),'artistLinks'=>Setting::read('social')['artistLinks']??[],'profileLinks'=>Setting::read('social')['profileLinks']??[],'socialLinks'=>Setting::read('social')['links']??[]]);if(in_array(static::$settingsKey,['home','music','directing','about'],true))$data=MediaSlotPolicy::hydrate(static::$settingsKey,$data);if(static::$settingsKey==='consent'&&!array_key_exists('version',$data))$data['version']=1;$this->form->fill($data); }
    public function form(Schema $schema): Schema { return $schema->components(SettingsForm::fields(static::$settingsKey))->statePath('data'); }
    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\Action::make('save')->label('Entwurf speichern')->action(fn () => $this->save())];
    }
    public function save(): void
    {
        $current=Setting::read(static::$settingsKey);$state=$this->form->getState();
        if(in_array(static::$settingsKey,['home','music','directing','about'],true)){$state['media']=array_replace($current['media']??[],$state['media']??[]);MediaSlotPolicy::validate(static::$settingsKey,$state['media']);}
        if(static::$settingsKey==='about')MediaTypePolicy::assert($state['collective']['logoAssetId']??null,'image','Kollektiv-Logo');
        if(static::$settingsKey==='seo')MediaTypePolicy::assert($state['ogAssetId']??null,'image','SEO-Bild');
        Setting::put(static::$settingsKey,array_replace($current,$state));
        Notification::make()->title('Entwurf gespeichert')->body('Die aktive Website und veröffentlichte Pakete bleiben unverändert.')->success()->send();
    }
}
