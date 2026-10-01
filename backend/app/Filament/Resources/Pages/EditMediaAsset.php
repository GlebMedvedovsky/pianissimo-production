<?php
namespace App\Filament\Resources\Pages;
class EditMediaAsset extends \Filament\Resources\Pages\EditRecord
{
    protected static string $resource = \App\Filament\Resources\MediaAssetResource::class;

    protected function getSaveFormAction(): \Filament\Actions\Action { return parent::getSaveFormAction()->label('Entwurf speichern'); }
    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\Action::make('help')->label('Medien-Hilfe')->url(fn()=>\App\Filament\Pages\Help::getUrl(['article'=>'hauptverwendung']).'#hauptverwendung'),\Filament\Actions\Action::make('replace')->label('Datei ersetzen')->icon('heroicon-o-arrow-path')->schema([
            \Filament\Forms\Components\FileUpload::make('replacement')->label('Neue Datei')->disk('local')->directory('media/originals/'.$this->record->id)->storeFileNamesIn('original_name')->acceptedFileTypes(\App\Services\MediaStorage::acceptedMimeTypes())->maxSize(config('cms.media_upload_max_kb'))->visibility('private')->required(),
        ])->requiresConfirmation()->modalHeading('Datei unter derselben stabilen ID ersetzen?')->modalDescription('Alle Verwendungen behalten diese ID. Die Website ändert sich erst nach geprüftem Apply.')->action(function(array $data): void {
            app(\App\Services\MediaStorage::class)->replace($this->record,$data['replacement'],$data['original_name']??null);
            $this->refreshFormData(['path','type','storage_path','original_name','mime_type','file_size','sha256','width','height','duration']);
            \Filament\Notifications\Notification::make()->title('Ersatzdatei als Entwurf gespeichert')->success()->send();
        })];
    }
}
