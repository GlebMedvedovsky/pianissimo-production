<?php

namespace App\Filament\Resources\Pages;

use App\Filament\Fields;
use App\Filament\Pages\Help;
use App\Filament\Resources\MediaAssetResource;
use App\Services\LargeMediaImportService;
use DomainException;
use Filament\Actions\{Action, CreateAction};
use Filament\Forms\Components\{Select, Textarea, TextInput};
use Filament\Notifications\Notification;

class ListMediaAssets extends \Filament\Resources\Pages\ListRecords
{
    protected static string $resource = MediaAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('importLarge')
                ->label('Große Datei importieren')
                ->icon('heroicon-o-arrow-up-tray')
                ->modalHeading('Große Datei aus dem SFTP-Upload importieren')
                ->modalDescription('Wählen Sie nur eine Datei, deren Übertragung in FileZilla vollständig abgeschlossen ist. Der angezeigte Ordner ist nur zum Nachschlagen und kann hier nicht geändert werden.')
                ->schema([
                    TextInput::make('incoming_folder')
                        ->label('SFTP-Upload-Ordner')
                        ->default(LargeMediaImportService::SSH_INCOMING_PATH)
                        ->disabled()
                        ->dehydrated(false),
                    Select::make('incoming_file')
                        ->label('Fertig hochgeladene Datei')
                        ->options(fn (): array => app(LargeMediaImportService::class)->options())
                        ->searchable()
                        ->required()
                        ->helperText('Fehlt die Datei? Schließen Sie dieses Fenster und wählen Sie „SFTP-Liste aktualisieren“. Unvollständige, nicht unterstützte oder zu große Dateien werden nicht angeboten.'),
                    Fields::id(),
                    Select::make('purpose')->label('Hauptverwendung')->options([
                        'hero' => 'Startseite / Hero', 'menu' => 'Menübereich', 'about' => 'About', 'cover' => 'Projekt-Cover',
                        'gallery' => 'Galerie', 'hover' => 'Hover-Video', 'trailer' => 'Trailer', 'audio' => 'Audio / Playback', 'other' => 'Andere Verwendung',
                    ])->default('other')->required(),
                    Textarea::make('alt')->label('Alternativtext')->default(''),
                    Textarea::make('caption')->label('Bildunterschrift'),
                    TextInput::make('credit')->label('Credit'),
                    Textarea::make('license')->label('Lizenz / Rechtevermerk (intern)'),
                    Textarea::make('source')->label('Quelle / Herkunft (intern)'),
                    Select::make('state')->label('Materialstatus')->options(['approved' => 'Bestätigt', 'temporary' => 'Temporär', 'test' => 'Test'])->default('temporary')->required(),
                    Select::make('rights_scope')->label('Nutzungsfreigabe')->options([
                        'unconfirmed' => 'Unbestätigt', 'existing_local_draft' => 'Nur bestehender lokaler Entwurf',
                        'next_draft' => 'Für den nächsten Entwurf', 'public' => 'Öffentliche Nutzung ausdrücklich erlaubt',
                    ])->default('unconfirmed')->required(),
                ])
                ->action(function (array $data): void {
                    try {
                        $asset = app(LargeMediaImportService::class)->import((string) ($data['incoming_file'] ?? ''), $data);
                    } catch (DomainException $exception) {
                        Notification::make()->title('Import nicht möglich')->body($exception->getMessage())->danger()->send();
                        return;
                    }
                    Notification::make()->title('Große Datei sicher importiert')->body('Das Medium ist jetzt normal unter Medien verfügbar.')->success()->send();
                    $this->redirect(MediaAssetResource::getUrl('edit', ['record' => $asset]));
                }),
            Action::make('refreshLargeImports')
                ->label('SFTP-Liste aktualisieren')
                ->icon('heroicon-o-arrow-path')
                ->action(function (): void {
                    try {
                        $count = count(app(LargeMediaImportService::class)->pending());
                        Notification::make()->title('SFTP-Liste aktualisiert')->body($count.' importierbare Datei(en) gefunden.')->success()->send();
                    } catch (DomainException $exception) {
                        Notification::make()->title('SFTP-Liste nicht verfügbar')->body($exception->getMessage())->danger()->send();
                    }
                }),
            Action::make('largeMediaHelp')
                ->label('Sehr große Datei? Anleitung öffnen')
                ->icon('heroicon-o-question-mark-circle')
                ->url(fn (): string => Help::getUrl(['article' => 'grosse-dateien-sftp']).'#grosse-dateien-sftp'),
        ];
    }
}
