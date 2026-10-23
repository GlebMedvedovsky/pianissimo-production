<?php
namespace App\Filament\Resources\Pages;
class EditTrack extends \Filament\Resources\Pages\EditRecord
{
    protected static string $resource = \App\Filament\Resources\TrackResource::class;

    protected function getSaveFormAction(): \Filament\Actions\Action { return parent::getSaveFormAction()->label('Entwurf speichern'); }

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('regenerateAudioMetadata')
                ->label('Audio-Metadaten neu erzeugen')
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->record->audio_asset_id !== null)
                ->action(function (): void {
                    try {
                        app(\App\Services\TrackAudioMetadata::class)->retry($this->record);
                        $this->record->refresh();
                        $this->refreshFormData(['duration', 'peaks', 'audio_sha256', 'waveform_sha256', 'audio_metadata_error']);
                        if ($this->record->audio_metadata_status === 'failed') {
                            \Filament\Notifications\Notification::make()->title('Verarbeitung konnte nicht gestartet werden')->body('Bitte versuchen Sie es später erneut.')->danger()->send();
                        } else {
                            \Filament\Notifications\Notification::make()->title('Audio-Metadaten werden erneut vorbereitet')->success()->send();
                        }
                    } catch (\Throwable) {
                        $this->record->refresh();
                        $this->refreshFormData(['duration', 'peaks', 'audio_sha256', 'waveform_sha256', 'audio_metadata_error']);
                        \Filament\Notifications\Notification::make()->title('Verarbeitung konnte nicht gestartet werden')->body('Bitte versuchen Sie es später erneut.')->danger()->send();
                    }
                }),
        ];
    }
}

