<?php
namespace App\Filament\Pages;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use App\Services\ManifestExporter;
class Publication extends \Filament\Pages\Page
{
    protected static ?int $navigationSort=30;
    protected static ?string $title='Veröffentlichung';
    protected static string|\UnitEnum|null $navigationGroup='System';
    protected string $view='cms.publication';
    public ?array $result=null;
    protected function getHeaderActions(): array
    {
        return [Action::make('validate')->label('Paket prüfen (Dry Run)')->action(fn()=> $this->run(true,false)),
            Action::make('candidate')->label('Export-Vorschau erstellen')->action(fn()=> $this->run(false,false)),
            Action::make('publish')->label('Lokalen Paketstand sichern')->requiresConfirmation()->modalHeading('Lokalen Paketstand sichern?')->modalDescription('Erstellt einen geprüften, unveränderlichen Stand für den nächsten Entwurf. Kein Frontend-Build, keine Änderung des aktiven Manifests, kein Deployment und keine öffentliche Audio-Freigabe.')->action(fn()=> $this->run(false,true))];
    }
    public function run(bool $dryRun,bool $publish): void
    {
        try {
            $this->result=app(ManifestExporter::class)->export($dryRun,$publish,auth()->id());
            Notification::make()->title($dryRun?'Validierung erfolgreich':'Lokales Paket erstellt')->success()->send();
        } catch (\DomainException|\Illuminate\Validation\ValidationException $e) {
            $message=$e instanceof \Illuminate\Validation\ValidationException?implode(' ',\Illuminate\Support\Arr::flatten($e->errors())):$e->getMessage();
            $this->result=['error'=>$message];
            Notification::make()->title('Export blockiert')->body($message)->danger()->persistent()->send();
        }
    }
}
