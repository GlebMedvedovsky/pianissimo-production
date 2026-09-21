<?php
namespace App\Filament;
use Filament\Forms\Components\{TextInput,Textarea,Select,Repeater,Toggle,ColorPicker};
use Filament\Schemas\Components\{Section,Tabs};
use Filament\Schemas\Components\Tabs\Tab;
use App\Models\{MediaAsset,Track,Project};

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
    public static function options(array $values): array { return array_combine($values,$values); }
    public static function id() { return TextInput::make('id')->label('Stabile ID')->required()->maxLength(100)->unique(ignoreRecord:true)->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')->disabledOn('edit')->dehydrated()->helperText('Einmalig vergeben, z. B. mein-projekt. Nach dem Anlegen unveränderlich.'); }
    public static function status() { return Select::make('status')->label('Auswahl für das nächste Paket')->options(['draft'=>'Entwurf','published'=>'Im nächsten Paket zeigen','hidden'=>'Ausgeblendet','needs-confirmation'=>'Bestätigung offen'])->default('draft')->required()->helperText('Speichern ändert nur den CMS-Entwurf. Die Website bleibt unverändert.'); }
    public static function media(string $name,string $label,?string $type=null) { return Select::make($name)->label($label)->options(fn()=>MediaAsset::query()->when($type,fn($q)=>$q->where('type',$type))->orderBy('id')->get()->mapWithKeys(fn($a)=>[$a->id=>($a->alt?:$a->id).' · '.$a->type])->all())->searchable()->preload(); }
    public static function crop(): array { return [TextInput::make('crop.x')->label('Fokus horizontal (%)')->numeric()->minValue(0)->maxValue(100)->default(50)->required(),TextInput::make('crop.y')->label('Fokus vertikal (%)')->numeric()->minValue(0)->maxValue(100)->default(50)->required()]; }
    public static function links(string $prefix): array { return array_map(fn($name)=>TextInput::make($prefix.'.'.$name)->label($name)->url()->maxLength(2000),['spotify','tidal','deezer','appleMusic']); }
    public static function tags(): array { return [Select::make('genres')->label('Genres')->multiple()->options(self::taxonomy('genres'))->default([]),Select::make('emotions')->label('Emotionen')->multiple()->options(self::taxonomy('emotions'))->default([])]; }
    public static function seo(string $prefix='seo'): array { return [TextInput::make($prefix.'.title')->label('SEO-Titel')->maxLength(255),Textarea::make($prefix.'.description')->label('Meta-Beschreibung')->maxLength(2000),TextInput::make($prefix.'.canonical')->label('Canonical URL')->url(),self::media($prefix.'.ogAssetId','Social-/OG-Bild','image'),Toggle::make($prefix.'.noindex')->label('Von Suchmaschinen ausschließen')->default(true)]; }
    public static function project(): array
    {
        return [Tabs::make('Projekt')->columnSpanFull()->tabs([
            Tab::make('Inhalt')->schema([self::id(),TextInput::make('slug')->label('URL-Slug')->required()->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')->unique(ignoreRecord:true),TextInput::make('title')->label('Titel')->required(),self::status(),Textarea::make('synopsis')->label('Synopsis')->default('')->dehydrateStateUsing(fn($state)=>(string)$state)->columnSpanFull(),Textarea::make('editorial_notes')->label('Offene Angaben / Quellen (intern)')->columnSpanFull(),Select::make('presentation_status')->label('Sichtbarer Materialhinweis')->options(['placeholder'=>'Platzhalter'])->nullable(),TextInput::make('production_type')->label('Produktionsart')->default('')->dehydrateStateUsing(fn($state)=>(string)$state),TextInput::make('year')->label('Jahr (leer = offen)'),TextInput::make('sort_order')->label('Gesamtreihenfolge')->integer()->required()->default(0),Select::make('categories')->label('Film-Music-Kategorien')->multiple()->options(self::taxonomy('categories'))->default([]),...self::tags()])->columns(2),
            Tab::make('Richtungen & Bilder')->schema([
                Repeater::make('memberships')->label('Richtungen – eine gemeinsame Projektidentität')->relationship()->schema([
                    Select::make('direction')->label('Richtung')->options(['music'=>'Film Music','directing'=>'Directing'])->required()->distinct()->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                    Select::make('role')->label('Rolle')->options(self::options(['Composer','Co-composer','Songwriter','Director','Assistant Director']))->required(),
                    TextInput::make('sort_order')->label('Reihenfolge in dieser Richtung')->integer()->default(0)->required(),self::media('cover_asset_id','Cover','image')->required(),self::media('hover_asset_id','Hover-Medium','video'),...self::crop(),Select::make('fit')->label('Bildanpassung')->options(['cover'=>'Füllen','contain'=>'Vollständig']),
                ])->columns(2)->defaultItems(0)->maxItems(2),
                Repeater::make('mediaAssignments')->label('Galerie, Trailer & weitere Medien')->relationship()->orderColumn('sort_order')->schema([
                    Select::make('slot')->label('Verwendung')->options(['gallery'=>'Galerie','trailer'=>'Lokaler Trailer','trailer_poster'=>'Trailer-Poster','soundtrack_cover'=>'Soundtrack-Cover','other'=>'Weitere Zuordnung'])->required(),self::media('media_asset_id','Medium')->required(),
                ])->columns(2)->defaultItems(0)->collapsible()->helperText('Reihenfolge durch Verschieben. Kinnhakenhank: ausschließlich ausgewählten V2-Trailer verwenden.'),
            ]),
            Tab::make('Soundtrack')->schema([Toggle::make('soundtrack_order_pending')->label('Track-Reihenfolge noch unbestätigt')->helperText('Sperrt den Export eines sichtbaren Projekts; interne Sortierung ist keine Freigabe.'),Repeater::make('soundtrack')->label('Tracks in manueller Reihenfolge')->relationship()->orderColumn('sort_order')->schema([Select::make('track_id')->label('Track')->options(fn()=>Track::orderBy('title')->pluck('title','id'))->searchable()->required()->distinct()->disableOptionsWhenSelectedInSiblingRepeaterItems()])->defaultItems(0)]),
            Tab::make('Credits & Links')->schema([
                Repeater::make('credits')->label('Credits')->schema([TextInput::make('role')->label('Rolle')->required(),TextInput::make('name')->label('Name')->required()])->columns(2)->defaultItems(0),
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
            self::media('audio_asset_id','Playback-Datei','audio')->helperText('Leer lassen, solange diese konkrete Playback-Version nicht bestätigt ist.'),TextInput::make('duration')->label('Dauer in Sekunden')->numeric()->minValue(0.001),...self::tags(),
            \Filament\Infolists\Components\TextEntry::make('waveform_info')->label('Waveform')->state(fn($record)=>$record?->peaks?count($record->peaks).' vorbereitete Werte. Bei Wechsel der Playback-Datei werden diese zurückgesetzt.':'Noch keine bestätigte Waveform.'),
            Section::make('Streaming-Links')->schema(self::links('platform_overrides'))->columns(2),
            Repeater::make('soundtrack')->label('Projekt-Zuordnung (optional)')->relationship()->schema([Select::make('project_id')->label('Projekt')->options(fn()=>Project::orderBy('title')->pluck('title','id'))->searchable()->required()->distinct(),TextInput::make('sort_order')->label('Reihenfolge im Soundtrack (leer = offen)')->integer()->nullable()])->defaultItems(0)->columns(2),
        ])->columns(2)];
    }
    public static function asset(): array
    {
        return [Section::make('Vorhandene Web-Datei')->columnSpanFull()->description('Nur Metadaten. Kein Upload, Zuschnitt oder erneutes Encoding. Private Originale gehören nicht hierher.')->schema([
            self::id(),TextInput::make('path')->label('Relativer Web-Pfad')->required()->regex('#^media/[a-zA-Z0-9/_-]+\.(webp|jpg|png|mp3|wav|ogg|mp4|webm)$#')->unique(ignoreRecord:true),Select::make('type')->label('Medientyp')->options(['image'=>'Bild','audio'=>'Audio','video'=>'Video'])->required(),
            Select::make('purpose')->label('Hauptverwendung')->options(self::options(['hero','menu','about','cover','gallery','hover','trailer','audio','other']))->default('other')->required(),
            Textarea::make('alt')->label('Alternativtext')->default('')->dehydrateStateUsing(fn($state)=>(string)$state),Textarea::make('caption')->label('Bildunterschrift'),TextInput::make('credit')->label('Credit'),Textarea::make('license')->label('Lizenz / Rechtevermerk (intern)'),Textarea::make('source')->label('Quelle / Herkunft (intern)'),
            Select::make('state')->label('Materialstatus')->options(['approved'=>'Bestätigt','temporary'=>'Temporär','test'=>'Test'])->default('temporary')->required(),Select::make('rights_scope')->label('Nutzungsfreigabe')->options(self::options(['unconfirmed','existing_local_draft','next_draft','public']))->default('unconfirmed')->required(),
            TextInput::make('width')->label('Breite (px)')->integer()->minValue(1),TextInput::make('height')->label('Höhe (px)')->integer()->minValue(1),TextInput::make('duration')->label('Dauer (s)')->numeric()->minValue(0.001),...self::crop(),
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
