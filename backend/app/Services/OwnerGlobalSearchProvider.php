<?php

namespace App\Services;

use App\Filament\Pages\{About, Analytics, Appearance, Consent, Contact, Dashboard, Directing, Help, Home, Impressum, Licenses, Music, Privacy, Publication, Seo, Site, Social};
use App\Filament\Resources\{CvEntryResource, ExternalServiceResource, MediaAssetResource, ProjectResource, TrackResource, UserResource};
use App\Models\{CvEntry, MediaAsset, Project, Track};
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\GlobalSearch\GlobalSearchResults;
use Filament\GlobalSearch\Providers\Contracts\GlobalSearchProvider;

class OwnerGlobalSearchProvider implements GlobalSearchProvider
{
    public function __construct(private readonly OwnerSearch $search, private readonly HelpCenter $help) {}

    public function getResults(string $query): ?GlobalSearchResults
    {
        $ranked = collect($this->entries())
            ->map(function (array $entry) use ($query): array {
                $entry['score'] = $this->search->score($query, $entry);

                return $entry;
            })
            ->filter(fn (array $entry): bool => $entry['score'] !== null)
            ->sortByDesc('score')
            ->take(18)
            ->groupBy('group');

        $results = GlobalSearchResults::make();
        foreach (['Inhalte', 'Website', 'Rechtliches & Datenschutz', 'System', 'Hilfe / Handbuch'] as $group) {
            if (! $ranked->has($group)) {
                continue;
            }
            $results->category($group, $ranked[$group]->map(fn (array $entry) => new GlobalSearchResult(
                title: $entry['title'],
                url: $entry['url'],
                details: isset($entry['detail']) ? ['Bereich' => $entry['detail']] : [],
            ))->values());
        }

        return $results;
    }

    /** @return list<array{group:string,title:string,url:string,detail?:string,terms?:list<string>,aliases?:list<string>}> */
    private function entries(): array
    {
        $entries = $this->staticEntries();

        foreach (Project::query()->with('memberships:id,project_id,direction,role')->get(['id', 'title', 'year', 'production_type', 'categories']) as $project) {
            $entries[] = $this->entry('Inhalte', 'Projekt: '.$project->title, ProjectResource::getUrl('edit', ['record' => $project]), 'Projekte', [
                (string) $project->year,
                (string) $project->production_type,
                implode(' ', $project->categories ?? []),
                $project->memberships->pluck('direction')->implode(' '),
                $project->memberships->pluck('role')->implode(' '),
            ], ['projekt', 'film']);
        }

        foreach (Track::query()->get(['id', 'title', 'genres', 'emotions']) as $track) {
            $entries[] = $this->entry('Inhalte', 'Track: '.$track->title, TrackResource::getUrl('edit', ['record' => $track]), 'Tracks', [implode(' ', $track->genres ?? []), implode(' ', $track->emotions ?? [])], ['musik', 'audio', 'soundtrack']);
        }

        foreach (MediaAsset::query()->get(['id', 'alt', 'caption', 'credit', 'type', 'purpose']) as $media) {
            $type = ['image' => 'Bild', 'video' => 'Video', 'audio' => 'Audio'][$media->type] ?? 'Medium';
            $entries[] = $this->entry('Inhalte', 'Medium: '.($media->alt ?: $media->id), MediaAssetResource::getUrl('edit', ['record' => $media]), $type, [(string) $media->id, (string) $media->caption, (string) $media->credit, (string) $media->purpose], ['medien', 'datei', 'foto', 'bild', 'poster', 'cover']);
        }

        foreach (CvEntry::query()->get(['id', 'date', 'title', 'institution']) as $cv) {
            $entries[] = $this->entry('Inhalte', 'CV: '.$cv->title, CvEntryResource::getUrl('edit', ['record' => $cv]), 'CV / Lebenslauf', [(string) $cv->date, (string) $cv->institution], ['lebenslauf']);
        }

        foreach ($this->help->articles() as $article) {
            $entries[] = $this->entry('Hilfe / Handbuch', $article['title'], Help::getUrl(['article' => $article['id']]).'#'.$article['id'], $article['category'], [
                $article['purpose'], $article['path'], implode(' ', $article['steps']), $article['after'], $article['important'],
            ], $article['keywords']);
        }

        return $entries;
    }

    /** @return list<array{group:string,title:string,url:string,detail?:string,terms?:list<string>,aliases?:list<string>}> */
    private function staticEntries(): array
    {
        return [
            $this->entry('Inhalte', 'Projekte', ProjectResource::getUrl('index'), 'Projektliste', ['Cover Galerie Trailer Credits SEO sichtbar unsichtbar Film Music Directing'], ['film']),
            $this->entry('Inhalte', 'Tracks', TrackResource::getUrl('index'), 'Trackliste', ['Audio Reihenfolge Projektzuordnung Soundtrack'], ['musik lied titel']),
            $this->entry('Inhalte', 'Medien', MediaAssetResource::getUrl('index'), 'Mediathek', ['Bild Video Audio Datei hochladen ersetzen löschen Verwendungen Cover Poster'], ['foto upload']),
            $this->entry('Inhalte', 'About → Text, Bild oder Video', About::getUrl(), 'About', ['Sebastian Biografie Portrait Darstellung Bild Video Poster'], ['ueber mich foto']),
            $this->entry('Inhalte', 'CV / Lebenslauf', CvEntryResource::getUrl('index'), 'CV', ['Eintrag Zeitraum Institution Reihenfolge'], ['vita']),

            $this->entry('Website', 'Startseite → Bild oder Video', Home::getUrl(), 'Startseite', ['Hero Darstellung Bild Video Poster'], ['home foto']),
            $this->entry('Website', 'Film Music', Music::getUrl(), 'Bereich', ['Menübezeichnung Bild Video Poster Projekte'], ['filmmusik composer']),
            $this->entry('Website', 'Directing', Directing::getUrl(), 'Bereich', ['Menübezeichnung Bild Video Poster Projekte'], ['regie director']),
            $this->entry('Website', 'Kontakt', Contact::getUrl(), 'Kontaktseite', ['öffentliche E Mail Instagram Spotify Footer'], ['contact']),
            $this->entry('Website', 'Social Links', Social::getUrl(), 'Profile', ['Spotify Instagram Vimeo YouTube hinzufügen ausblenden löschen sortieren'], ['socials profile']),
            $this->entry('Website', 'SEO', Seo::getUrl(), 'Globale Vorgaben', ['Suchmaschinen Titel Meta Canonical OG Bild robots index noindex'], ['google suchmachine suchmaschine']),
            $this->entry('Website', 'Website-Farben', Appearance::getUrl(), 'Darstellung', ['Hintergrund Akzent hell dunkel'], ['design farbe']),
            $this->entry('Website', 'Analytics', Analytics::getUrl(), 'Besucherstatistik', ['GA4 Plausible Bericht Export Consent'], ['statistik analytycs']),

            $this->entry('Rechtliches & Datenschutz', 'Impressum', Impressum::getUrl(), 'Rechtlicher Text', ['Anbieteridentität Prüfstatus'], ['legal']),
            $this->entry('Rechtliches & Datenschutz', 'Datenschutz', Privacy::getUrl(), 'Datenschutzerklärung', ['Text Prüfstatus neue Dienste'], ['privacy']),
            $this->entry('Rechtliches & Datenschutz', 'Cookie / Consent', Consent::getUrl(), 'Einwilligung', ['Cookie Banner Version externe Medien Analytics'], ['cookies zustimmung']),
            $this->entry('Rechtliches & Datenschutz', 'Externe Dienste', ExternalServiceResource::getUrl('index'), 'Dienste', ['YouTube Vimeo Analytics Datenschutz Einwilligung'], ['provider service']),
            $this->entry('Rechtliches & Datenschutz', 'Lizenzen / Credits', Licenses::getUrl(), 'Rechte', ['Lizenz Rechtevermerk Credit'], ['copyright']),

            $this->entry('System', 'Dashboard', Dashboard::getUrl(), 'Übersicht', ['Sebastian Status nächste Schritte Vorschau'], ['start uebersicht']),
            $this->entry('System', 'Site-Einstellungen', Site::getUrl(), 'Website', ['Website Titel Beschreibung Production Domain'], ['site']),
            $this->entry('System', 'Benutzer', UserResource::getUrl('index'), 'Konten', ['Passwort zurücksetzen Benutzer verwalten'], ['konto login']),
            $this->entry('System', 'Veröffentlichung', Publication::getUrl(), 'Entwurf und Website', ['Website Vorschau öffnen Änderungen prüfen Für Website übernehmen nicht veröffentlichte Änderungen verwerfen letzte Änderung zurücksetzen'], ['veroeffentlichen verofentlichen rollback apply discard preview']),
        ];
    }

    /** @param list<string> $terms @param list<string> $aliases */
    private function entry(string $group, string $title, string $url, string $detail, array $terms = [], array $aliases = []): array
    {
        return compact('group', 'title', 'url', 'detail', 'terms', 'aliases');
    }
}
