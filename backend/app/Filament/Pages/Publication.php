<?php
namespace App\Filament\Pages;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use App\Services\ManifestExporter;
use App\Services\FrontendPublication;
use App\Services\DraftStateSnapshot;
class Publication extends \Filament\Pages\Page
{
    protected static ?int $navigationSort=30;
    protected static ?string $title='Veröffentlichung';
    protected static string|\UnitEnum|null $navigationGroup='System';
    protected string $view='cms.publication';
    public ?array $result=null;
    protected function getHeaderActions(): array
    {
        return [Action::make('validate')->label('Paket prüfen')->action(fn()=> $this->run(true,false)),
            Action::make('candidate')->label('Vorschau erstellen')->action(fn()=> $this->run(false,false)),
            Action::make('review')->label('Änderungen prüfen')->visible(fn()=>isset($this->result['candidateHash']))->action(fn()=> $this->review()),
            Action::make('apply')->label('Für Website übernehmen')->color('success')->visible(fn()=>($this->result['kind']??null)==='review'&&$this->candidateState()['kind']==='current')->requiresConfirmation()->modalHeading('Diese geprüften Änderungen wirklich für die Website übernehmen?')->modalDescription('Nur die zuvor geprüften Änderungen werden übernommen. Die öffentliche Production-Website ist weiterhin nicht verbunden.')->action(fn()=> $this->apply()),
            Action::make('discard')->label('Nicht veröffentlichte Änderungen verwerfen')->color('danger')->visible(fn()=>app(DraftStateSnapshot::class)->hasChanges())->requiresConfirmation()->modalHeading('Nicht veröffentlichte Änderungen verwerfen?')->modalDescription(function(){try{$items=app(DraftStateSnapshot::class)->preview()['descriptions'];return "Diese Änderungen gehen verloren:\n• ".implode("\n• ",$items)."\n\nDie Website selbst wird nicht verändert.";}catch(\Throwable $e){return $e->getMessage();}})->action(fn()=> $this->discard()),
            Action::make('rollback')->label('Letzte Änderung zurücksetzen')->color('danger')->requiresConfirmation()->modalHeading('Letzte Änderung zurücksetzen?')->modalDescription(function(){try{$r=app(FrontendPublication::class)->rollbackReview((int)auth()->id());return 'Stand vom '.$r['timestamp'].' · '.count($r['affectedPaths']).' betroffene Pfade.';}catch(\Throwable $e){return $e->getMessage();}})->action(fn()=> $this->rollback()),
            Action::make('publish')->label('Lokalen Paketstand sichern')->requiresConfirmation()->modalHeading('Lokalen Paketstand sichern?')->modalDescription('Erstellt einen unveränderlichen Archivstand. Kein Deployment.')->action(fn()=> $this->run(false,true))];
    }
    public function run(bool $dryRun,bool $publish): void
    {
        try {
            $this->result=app(ManifestExporter::class)->export($dryRun,$publish,auth()->id());
            if(!$dryRun && !$publish)$this->result=['kind'=>'candidate','candidateHash'=>$this->result['hash'],'timestamp'=>now()->toIso8601String(),'validation'=>'gültig','exportingAdminId'=>auth()->id(),...$this->result];
            Notification::make()->title($dryRun?'Validierung erfolgreich':'Lokales Paket erstellt')->success()->send();
        } catch (\DomainException|\Illuminate\Validation\ValidationException $e) {
            $message=$e instanceof \Illuminate\Validation\ValidationException?implode(' ',\Illuminate\Support\Arr::flatten($e->errors())):$e->getMessage();
            $this->result=['error'=>$message];
            Notification::make()->title('Export blockiert')->body($message)->danger()->persistent()->send();
        }
    }
    public function review(): void { $this->guard(fn()=> $this->result=app(FrontendPublication::class)->review($this->result['candidateHash'],(int)auth()->id()),'Änderungen geprüft'); }
    public function apply(): void { $this->guard(fn()=> $this->result=['kind'=>'applied',...app(FrontendPublication::class)->apply($this->result['planHash'],(int)auth()->id())],'Für Website übernommen'); }
    public function discard(): void { $this->guard(fn()=> $this->result=['kind'=>'draft-discarded',...app(DraftStateSnapshot::class)->discard((int)auth()->id())],'Nicht veröffentlichte Änderungen verworfen'); }
    public function candidateState(): array
    {
        $candidate=\App\Models\Publication::whereIn('status',['candidate','stale'])->latest('id')->first();if(!$candidate)return ['kind'=>'empty'];
        if($candidate->status==='stale')return ['kind'=>'stale'];
        try{$current=\App\Services\ManifestImporter::hash(app(ManifestExporter::class)->package());}catch(\Throwable){$current=null;}
        if($current!==$candidate->hash){app(DraftStateSnapshot::class)->markCandidatesStale([$candidate->hash]);return ['kind'=>'stale'];}
        return ['kind'=>'current','createdAt'=>$candidate->created_at];
    }
    public function rollback(): void { $this->guard(function(){ $review=app(FrontendPublication::class)->rollbackReview((int)auth()->id()); $this->result=['kind'=>'rollback-complete',...app(FrontendPublication::class)->rollback($review['planHash'],(int)auth()->id())]; },'Letzte Änderung zurückgesetzt'); }
    private function guard(callable $callback,string $title): void
    {
        try{$callback();Notification::make()->title($title)->success()->send();}
        catch(\Throwable $e){$this->result=['error'=>$e->getMessage()];Notification::make()->title('Aktion blockiert')->body($e->getMessage())->danger()->persistent()->send();}
    }
}
