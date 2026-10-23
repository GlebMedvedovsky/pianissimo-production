<?php
namespace App\Filament;
use Filament\Forms\Components\{TextInput,Textarea,Select,Repeater,Toggle,ColorPicker,FileUpload,Radio};
use Filament\Schemas\Components\{Section,Tabs};
use Filament\Schemas\Components\Tabs\Tab;
use App\Models\{Track,Project};
use App\Services\MediaTypePolicy;
use Filament\Schemas\Components\Utilities\{Get,Set};

class Fields
{
    public static function taxonomy(string $field): array
    {
        static $schema;
        $schema??=json_decode(file_get_contents(config('cms.schema')),true,512,JSON_THROW_ON_ERROR);
        $entity=$field==='categories'?'projects':'tracks';
        $values=data_get($schema,"properties.$entity.items.properties.$field.items.enum");
        if (!is_array($values)) throw new \LogicException('Taxonomy missing from the shared content schema: '.$field);
        return self::options($values);
    }
    public static function directingRoles(): array
    {
        $schema=json_decode(file_get_contents(config('cms.schema')),true,512,JSON_THROW_ON_ERROR);
        $values=data_get($schema,'properties.projects.items.properties.memberships.properties.directing.properties.role.enum');
        if (!is_array($values)) throw new \LogicException('Directing roles missing from the shared content schema.');
        return $values;
    }
    public static function options(array $values): array { return array_combine($values,$values); }
    public static function id() { return TextInput::make('id')->label('Stabile ID')->required()->maxLength(100)->unique(ignoreRecord:true)->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')->disabledOn('edit')->dehydrated()->helperText('Einmalig vergeben, z. B. mein-projekt. Nach dem Anlegen unveränderlich.'); }
    public static function status() { return Select::make('status')->label('Auswahl für das nächste Paket')->options(['draft'=>'Entwurf','published'=>'Im nächsten Paket zeigen','hidden'=>'Ausgeblendet','needs-confirmation'=>'Bestätigung offen'])->default('draft')->required()->helperText('Speichern ändert nur den CMS-Entwurf. Die Website bleibt unverändert.'); }
    public static function mediaOptions(?string $type=null,?string $excludeId=null): array
    {
        $labels=['image'=>'Bild','video'=>'Video','audio'=>'Audio'];
        return MediaTypePolicy::query($type)->when($excludeId,fn($query)=>$query->whereKeyNot($excludeId))->orderBy('id')->get()->mapWithKeys(function($asset)use($labels){
            $mime=$asset->mime_type?:'geprüfter Bestandsimport';
            return [$asset->id=>($asset->alt?:$asset->id).' · '.($labels[$asset->type]??$asset->type).' · '.$mime];
        })->all();
    }
    public static function media(string $name,string $label,?string $type=null) { return Select::make($name)->label($label)->options(fn()=>self::mediaOptions($type))->searchable()->preload()->optionsLimit(500); }
    public static function projectMedia(string $name,string $label) { return Select::make($name)->label($label)->options(fn(Get $get)=>self::mediaOptions(MediaTypePolicy::projectSlotType($get('slot'))))->searchable()->preload()->optionsLimit(500)->helperText(fn(Get $get)=>match(MediaTypePolicy::projectSlotType($get('slot'))){'image'=>'Nur Bilddateien: JPEG, PNG oder WebP.','video'=>'Nur Videodateien: MP4 oder WebM.',default=>'Zusätzliche Zuordnung ohne sichtbare Standardrolle.'}); }
    public static function crop(): array { return [TextInput::make('crop.x')->label('Fokus horizontal (%)')->numeric()->minValue(0)->maxValue(100)->default(50)->required(),TextInput::make('crop.y')->label('Fokus vertikal (%)')->numeric()->minValue(0)->maxValue(100)->default(50)->required()]; }
    public static function links(string $prefix): array { return array_map(fn($name)=>TextInput::make($prefix.'.'.$name)->label($name)->url()->maxLength(2000),['spotify','tidal','deezer','appleMusic']); }
    public static function tags(): array { return [Select::make('genres')->label('Genres')->multiple()->options(self::taxonomy('genres'))->default([]),Select::make('emotions')->label('Emotionen')->multiple()->options(self::taxonomy('emotions'))->default([])]; }
    public static function seo(string $prefix='seo'): array { return [
        TextInput::make($prefix.'.title')->label('SEO-Titel')->maxLength(255)->helperText('Dokumenttitel für Browser und mögliche Suchdarstellung. Dies ist keine H1/H2/H3-Überschrift und ersetzt nicht den sichtbaren Projekttitel.'),
        Textarea::make($prefix.'.description')->label('Meta-Beschreibung')->maxLength(2000)->helperText('Kurze Zusammenfassung, die Suchmaschinen verwenden können. Sie dürfen stattdessen einen anderen Textausschnitt zeigen.'),
        TextInput::make($prefix.'.canonical')->label('Canonical URL')->url()->helperText('Bevorzugte Haupt-URL dieser Projektseite. Nur bei einer bewussten Abweichung vom automatisch gebildeten Standard manuell eintragen.'),
        self::media($prefix.'.ogAssetId','Social-/OG-Bild','image')->helperText('Bildvorschau für geteilte Links und soziale Plattformen. Es werden nur Bilder angeboten.'),
        Toggle::make($prefix.'.noindex')->label('Von Suchmaschinen ausschließen')->default(true)->live()->helperText(fn(Get $get)=>$get($prefix.'.noindex')
            ? 'Aktiviert: Dieses Projekt soll NICHT in Suchmaschinen erscheinen.'
            : 'Deaktiviert: Dieses Projekt darf grundsätzlich indexiert werden. Eine globale noindex-Vorgabe kann dies weiterhin verhindern.'),
    ]; }
    public static function project(): array
    {
        return [Tabs::make('Projekt')->columnSpanFull()->tabs([
            Tab::make('Inhalt')->schema([self::id(),TextInput::make('slug')->label('URL-Slug')->required()->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')->unique(ignoreRecord:true),TextInput::make('title')->label('Titel')->required(),self::status(),Textarea::make('synopsis')->label('Synopsis')->default('')->dehydrateStateUsing(fn($state)=>(string)$state)->columnSpanFull(),Textarea::make('editorial_notes')->label('Offene Angaben / Quellen (intern)')->columnSpanFull(),Select::make('presentation_status')->label('Sichtbarer Materialhinweis')->options(['placeholder'=>'Platzhalter'])->nullable(),TextInput::make('production_type')->label('Produktionsart')->default('')->dehydrateStateUsing(fn($state)=>(string)$state),TextInput::make('year')->label('Jahr (leer = offen)'),TextInput::make('sort_order')->label('Gesamtreihenfolge')->integer()->required()->default(0),Select::make('categories')->label('Film-Music-Kategorien')->multiple()->options(self::taxonomy('categories'))->default([]),...self::tags()])->columns(2),
            Tab::make('Richtungen & Bilder')->schema([
                Repeater::make('memberships')->label('Richtungen – eine gemeinsame Projektidentität')->relationship()->schema([
                    Select::make('direction')->label('Richtung')->options(['music'=>'Film Music','directing'=>'Directing'])->required()->distinct()->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                    Select::make('role')->label('Rolle')->options(self::options(['Composer','Co-composer','Songwriter',...self::directingRoles()]))->required(),
                    TextInput::make('sort_order')->label('Reihenfolge in dieser Richtung')->integer()->default(0)->required(),self::media('cover_asset_id','Projekt-Cover','image')->required()->helperText('Eigenständiges Bild für die Projektkarte in dieser Richtung.'),self::media('hover_asset_id','Hover-Video','video')->helperText('Optionale, vom Cover unabhängige Video-Erweiterung der Projektkarte.'),...self::crop(),Select::make('fit')->label('Bildanpassung')->options(['cover'=>'Füllen','contain'=>'Vollständig']),
                ])->columns(2)->defaultItems(0)->maxItems(2),
                Repeater::make('mediaAssignments')->label('Galerie, Trailer & weitere Medien')->relationship()->orderColumn('sort_order')->schema([
                    Select::make('slot')->label('Eigenständige Medienrolle')->options(['gallery'=>'Galeriebild','trailer'=>'Lokaler Trailer (Video)','trailer_poster'=>'Trailer-Poster (Bild)','soundtrack_cover'=>'Soundtrack-Cover (Bild)','other'=>'Weitere Zuordnung'])->required()->live()->afterStateUpdated(fn(Set $set)=>$set('media_asset_id',null)),self::projectMedia('media_asset_id','Datei für diese Rolle')->required(),
                ])->columns(2)->defaultItems(0)->collapsible()->reorderable()->reorderableWithButtons()->helperText('Ziehen zum Sortieren; alternativ die Pfeiltasten am Eintrag nutzen. Galeriebild = Bild/Still der Galerie. Lokaler Trailer (Video) = tatsächlich abgespielter Trailer. Trailer-Poster (Bild) = Vorschaubild des Trailers. Diese Rollen sind unabhängig. Die Reihenfolge der Galeriebilder bestimmt die Website-Galerie. Kinnhakenhank: ausschließlich ausgewählten V2-Trailer verwenden.'),
            ]),
            Tab::make('Soundtrack')->schema([Toggle::make('soundtrack_order_pending')->label('Track-Reihenfolge noch unbestätigt')->helperText('Sperrt den Export eines sichtbaren Projekts; interne Sortierung ist keine Freigabe.'),Repeater::make('soundtrack')->label('Tracks in manueller Reihenfolge')->relationship()->orderColumn('sort_order')->schema([Select::make('track_id')->label('Track')->options(fn()=>Track::orderBy('title')->pluck('title','id'))->searchable()->required()->distinct()->disableOptionsWhenSelectedInSiblingRepeaterItems()])->defaultItems(0)->reorderable()->reorderableWithButtons()->helperText('Ziehen zum Sortieren; alternativ die Pfeiltasten am Eintrag nutzen. Die Reihenfolge bestimmt den Projekt-Soundtrack.')] ),
            Tab::make('Credits & Links')->schema([
                Repeater::make('credits')->label('Credits')->schema([
                    TextInput::make('role')->label('Rolle / Funktion')->required()->helperText('Zum Beispiel Composer, Director oder Cinematography.'),
                    TextInput::make('name')->label('Name der Person oder Organisation')->required(),
                ])->columns(2)->defaultItems(0)->reorderable()->reorderableWithButtons()->helperText('Jeder Eintrag besteht aus einer Rolle und einem Namen. * bedeutet nur: In einem vorhandenen Eintrag sind beide Felder erforderlich – nicht jede denkbare Rolle muss angelegt werden. Nicht benötigte Einträge können über die Löschen-Aktion entfernt werden. Ziehen zum Sortieren; alternativ die Pfeiltasten nutzen. Die Reihenfolge bestimmt die Darstellung auf der Website.'),
                Repeater::make('awards')->label('Auszeichnungen')->simple(TextInput::make('award')->label('Auszeichnung')->required())->defaultItems(0),
                Section::make('Streaming')->schema(self::links('platform_links'))->columns(2),TextInput::make('external_links.instagram')->label('Projekt-Instagram')->url(),
                Section::make('Externer Film')->description('Für Kinnhakenhank gesperrt. Kein vollständiger Film und keine frühere YouTube-URL.')->schema([Select::make('external_film.provider')->label('Anbieter')->options(['youtube'=>'YouTube','vimeo'=>'Vimeo']),TextInput::make('external_film.videoId')->label('Video-ID')->regex('/^[A-Za-z0-9_-]+$/'),TextInput::make('external_film.url')->label('Öffentliche URL')->url()])->disabled(fn($record)=>$record?->id==='kinnhakenhank'),
            ]),Tab::make('SEO')->schema(self::seo())->columns(2),
        ])];
    }
    public static function track(): array
    {
        return [Section::make('Track')->columnSpanFull()->schema([
            self::id(),TextInput::make('title')->label('Titel')->required(),self::status(),Toggle::make('library_visible')->label('Eigenständig in der Music Library zeigen')->default(false)->helperText('Nur mit bestätigter Auswahl und Playback-Freigabe; keine zusätzliche Projektkarte.'),Select::make('rights_scope')->label('Nutzungsfreigabe')->options(['unconfirmed'=>'Unbestätigt','existing_local_draft'=>'Bestehender lokaler Entwurf','next_draft'=>'Nächster Entwurf','public'=>'Öffentliche Nutzung ausdrücklich erlaubt'])->default('unconfirmed')->required(),
            self::media('audio_asset_id','Playback-Datei','audio')->helperText('Nach dem Hochladen wird die Audiodatei automatisch verarbeitet. Das dauert normalerweise bis zu 1 Minute, in Einzelfällen bis zu 2–3 Minuten. Bitte warten Sie, bis „Bereit“ angezeigt wird.'),TextInput::make('duration')->label('Dauer in Sekunden')->numeric()->disabled()->dehydrated(false)->helperText('Wird automatisch aus der Playback-Datei ermittelt.'),...self::tags(),
            \Filament\Infolists\Components\TextEntry::make('waveform_info')->label('Waveform')->state(function($record) {
                if (!$record) return 'Fehlt';
                return match(app(\App\Services\TrackAudioMetadata::class)->status($record)) {
                    'ready' => 'Bereit ('.count($record->peaks).' Werte)',
                    'pending' => 'Audio-Metadaten werden vorbereitet …',
                    'processing' => 'Audio-Metadaten werden verarbeitet …',
                    'stale' => 'Audio-Metadaten müssen erneut vorbereitet werden',
                    'failed' => 'Verarbeitung fehlgeschlagen',
                    default => 'Fehlt',
                };
            }),
            Section::make('Streaming-Links')->schema(self::links('platform_overrides'))->columns(2),
            Repeater::make('soundtrack')->label('Projekt-Zuordnung (optional)')->relationship()->schema([Select::make('project_id')->label('Projekt')->options(fn()=>Project::orderBy('title')->pluck('title','id'))->searchable()->required()->distinct(),TextInput::make('sort_order')->label('Reihenfolge im Soundtrack (leer = offen)')->integer()->nullable()])->defaultItems(0)->columns(2),
        ])->columns(2)];
    }
    public static function asset(): array
    {
        return self::assetSchema(false);
    }
    public static function assetForCreate(): array
    {
        return self::assetSchema(true);
    }
    private static function assetSchema(bool $includeUpload): array
    {
        $sourceFields = $includeUpload ? [
            Radio::make('_mode')->label('Quelle')->options(['upload'=>'Neue Datei hochladen','existing'=>'Vorhandene Web-Datei registrieren'])->default('upload')->inline()->live()->dehydrated(false),
            self::id(),
            FileUpload::make('storage_path')->label('Datei auswählen')->disk('local')->directory(fn(\Filament\Schemas\Components\Utilities\Get $get)=>'media/originals/'.($get('id')?:'pending'))->storeFileNamesIn('original_name')->acceptedFileTypes(\App\Services\MediaStorage::acceptedMimeTypes())->maxSize(config('cms.media_upload_max_kb'))->visibility('private')->downloadable()->openable()->helperText('Drag & Drop oder Datei auswählen. Sehr große Datei? Nutzen Sie Medien → Große Datei importieren oder öffnen Sie die Anleitung.')->required(fn(string $operation,\Filament\Schemas\Components\Utilities\Get $get)=>$operation==='create' && $get('_mode')==='upload')->visible(fn(string $operation,\Filament\Schemas\Components\Utilities\Get $get)=>$operation==='create' && $get('_mode')==='upload'),
        ] : [self::id()];
        return [Section::make('Datei')->columnSpanFull()->schema([
            ...$sourceFields,
            TextInput::make('path')->label('Relativer Web-Pfad')->required()->regex('#^media/[a-zA-Z0-9/_-]+\.(webp|jpg|jpeg|png|mp3|wav|ogg|mp4|webm)$#')->unique(ignoreRecord:true)->visible(fn(string $operation,\Filament\Schemas\Components\Utilities\Get $get)=>$operation==='edit' || $get('_mode')==='existing')->disabled(fn(string $operation)=>$operation==='edit')->dehydrated(),
            Select::make('type')->label('Medientyp')->options(['image'=>'Bild','audio'=>'Audio','video'=>'Video'])->required()->visible(fn(string $operation,\Filament\Schemas\Components\Utilities\Get $get)=>$operation==='edit' || $get('_mode')==='existing')->disabled(fn(string $operation)=>$operation==='edit')->dehydrated(),
        ])->columns(2),Section::make('Beschreibung und Freigabe')->columnSpanFull()->schema([
            Select::make('purpose')->label('Hauptverwendung')->options(['hero'=>'Startseite / Hero','menu'=>'Menübereich','about'=>'About','cover'=>'Projekt-Cover','gallery'=>'Galerie','hover'=>'Hover-Video','trailer'=>'Trailer','audio'=>'Audio / Playback','other'=>'Andere Verwendung'])->default('other')->required()->helperText('Diese Angabe beschreibt nur den vorgesehenen Einsatz. Das Medium wird dadurch noch keiner Seite, keinem Projekt und keinem Track zugeordnet.'),
            Textarea::make('alt')->label('Alternativtext')->default('')->dehydrateStateUsing(fn($state)=>(string)$state),Textarea::make('caption')->label('Bildunterschrift'),TextInput::make('credit')->label('Credit'),Textarea::make('license')->label('Lizenz / Rechtevermerk (intern)'),Textarea::make('source')->label('Quelle / Herkunft (intern)'),
            Select::make('state')->label('Materialstatus')->options(['approved'=>'Bestätigt','temporary'=>'Temporär','test'=>'Test'])->default('temporary')->required()->helperText('Kennzeichnet, ob diese konkrete Datei bestätigt, nur vorläufig oder Testmaterial ist.'),Select::make('rights_scope')->label('Nutzungsfreigabe')->options(['unconfirmed'=>'Unbestätigt','existing_local_draft'=>'Nur bestehender lokaler Entwurf','next_draft'=>'Für den nächsten Entwurf','public'=>'Öffentliche Nutzung ausdrücklich erlaubt'])->default('unconfirmed')->required()->helperText('Nur „Öffentliche Nutzung“ wählen, wenn Rechte und konkrete Dateiversion bestätigt sind.'),
            TextInput::make('width')->label('Breite (px)')->integer()->minValue(1)->disabled()->dehydrated(),TextInput::make('height')->label('Höhe (px)')->integer()->minValue(1)->disabled()->dehydrated(),TextInput::make('duration')->label('Dauer (s)')->numeric()->minValue(0.001)->disabled()->dehydrated(),...self::crop(),
        ])->columns(2)];
    }
    public static function cv(): array { return [self::id(),TextInput::make('date')->label('Zeitraum')->required(),TextInput::make('title')->label('Titel / Eintrag')->required(),TextInput::make('institution')->label('Institution'),Textarea::make('description')->label('Beschreibung'),TextInput::make('link')->label('Link')->url(),TextInput::make('link_text')->label('Nur diesen Namen verlinken')->helperText('Exakter Ausschnitt des angezeigten Texts; leer = kompletter Eintrag.'),TextInput::make('sort_order')->label('Reihenfolge')->integer()->default(0)->required(),self::status()]; }
    public static function externalService(): array { return [TextInput::make('name')->label('Name')->required(),Select::make('provider')->label('Anbieter')->options(self::options(['youtube','vimeo','spotify','tidal','deezer','appleMusic','ga4','plausible']))->required(),Select::make('category')->label('Consent-Kategorie')->options(['externalMedia'=>'Externe Medien','analytics'=>'Analyse','marketing'=>'Marketing'])->required(),Textarea::make('purpose')->label('Zweck')->required(),Textarea::make('description')->label('Beschreibung'),TextInput::make('privacy_url')->label('Datenschutz-URL')->url()->required(),Textarea::make('storage_description')->label('Speicherung / Cookies'),Toggle::make('requires_consent')->label('Einwilligung erforderlich')->default(true)->accepted(),Toggle::make('enabled')->label('Für späteren Export aktivieren')->default(false),TextInput::make('provider_id')->label('Anbieterkennung (kein Script)')->regex('/^[A-Za-z0-9._-]{1,100}$/'),TextInput::make('sort_order')->label('Reihenfolge')->integer()->default(0)->required()]; }
    public static function user(): array
    {
        return [
            TextInput::make('name')->label('Name')->required()->maxLength(255),
            TextInput::make('email')->label('E-Mail')->email()->required()->maxLength(255)->unique(ignoreRecord:true),
            TextInput::make('password')->label('Neues Passwort')->password()->revealable()->autocomplete('new-password')
                ->minLength(12)->required(fn (string $operation): bool => $operation === 'create')
                ->afterStateHydrated(fn (TextInput $component) => $component->state(''))
                ->dehydrated(fn ($state): bool => filled($state)),
            Select::make('admin_theme')->label('Persönliches Admin-Design')->options(['dark'=>'Dark','light'=>'Light','system'=>'System'])->default('dark')->required(),
        ];
    }
}
