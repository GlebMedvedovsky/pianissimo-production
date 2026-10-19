# Pianissimo CMS-Handbuch

Stand: 20. Oktober 2026. Dieses Handbuch beschreibt die lokale und produktive CMS-Oberfläche für Sebastian sowie künftige Redakteurinnen, Redakteure oder Assistenzpersonen. Das CMS ist mit einem kontrollierten Production-Veröffentlichungsablauf verbunden. Jede öffentliche Veröffentlichung bleibt eine eigene, bestätigungspflichtige Aktion.

## Hinweis für KI-Assistenten

Antworte in nicht-technischer Sprache. Nenne immer den genauen CMS-Pfad und die sichtbaren Schaltflächen. Erkläre, was nach dem Speichern passiert, und unterscheide klar zwischen **Entwurf**, **Website-Vorschau**, **Änderungen prüfen**, **Für Website übernehmen** und **Production**. Erfinde keine Funktionen oder Inhalte. Wenn die Oberfläche von diesem Handbuch abweicht, bitte um einen Screenshot, statt zu raten. Frage niemals nach Passwörtern, Tokens oder privaten Zugangsdaten.

## Der sichere Standardablauf

1. Inhalt im CMS bearbeiten.
2. **Entwurf speichern** wählen. Das ändert die aktive Website nicht.
3. `System → Veröffentlichung` öffnen und **Website-Vorschau öffnen** wählen.
4. Texte, Medien, Reihenfolge und Darstellung prüfen.
5. **Änderungen prüfen** wählen und die Änderungsliste lesen.
6. Erst nach Freigabe **Für Website übernehmen** wählen.
7. Nur wenn dieser übernommene Stand öffentlich erscheinen soll, **Jetzt öffentlich veröffentlichen** wählen und die Bestätigung lesen.

**Für Website übernehmen** macht den geprüften Stand zum akzeptierten Website-Stand, veröffentlicht ihn aber noch nicht im Internet. **Jetzt öffentlich veröffentlichen** erstellt daraus ein unveränderliches Production-Paket, lässt die festgelegte Code-Version bauen und aktiviert den geprüften Release erst nach erfolgreicher Validierung. Speichern, Übernehmen und öffentliches Veröffentlichen sind drei verschiedene Schritte.

## Erste Schritte

### 1. CMS-Überblick

**Wofür ist das?** Das Dashboard zeigt Website-Status, offene Aufgaben und direkte Einstiege.

**Wo finde ich das?** `Dashboard`

**So geht es:** Wähle links `Inhalte`, `Website`, `Rechtliches & Datenschutz` oder `System`. Speichere Änderungen als Entwurf und prüfe sie anschließend unter Veröffentlichung.

**Was passiert danach?** Navigation allein ändert nichts. Speichern erzeugt nur einen CMS-Entwurf.

### 2–7. Entwurf und Veröffentlichung

| Workflow                                   | CMS-Pfad                                  | Vorgehen und Wirkung                                                                                                                        |
| ------------------------------------------ | ----------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| Was ist ein Entwurf?                       | Bearbeitungsseite → **Entwurf speichern** | Speichert in MariaDB und privatem Storage. Übernommene und öffentliche Website bleiben unverändert.                                         |
| Website-Vorschau öffnen                    | `System → Veröffentlichung`               | Erst speichern, dann **Website-Vorschau öffnen**. Die private, zeitlich begrenzte Vorschau veröffentlicht nichts.                           |
| Änderungen prüfen                          | `System → Veröffentlichung`               | **Änderungen prüfen**, Fehler beheben und die Änderungsliste lesen. Noch keine Übernahme und keine öffentliche Veröffentlichung.             |
| Für Website übernehmen                     | `System → Veröffentlichung`               | Übernimmt nur den exakt geprüften Stand als akzeptierten Website-Stand. Die öffentliche Website bleibt bis zur Production-Publikation gleich. |
| Jetzt öffentlich veröffentlichen           | `System → Veröffentlichung`               | Baut und validiert den übernommenen Stand mit der festgelegten Code-Revision und aktiviert ihn anschließend öffentlich.                      |
| Nicht veröffentlichte Änderungen verwerfen | `System → Veröffentlichung`               | Liste prüfen und bestätigen. Öffentliche und übernommene Website bleiben unverändert; Entwurfsänderungen gehen verloren.                     |
| Letzte Änderung zurücksetzen               | `System → Veröffentlichung`               | Stellt den vorherigen übernommenen Website-Stand aus der lokalen Revision wieder her. Das ist nicht automatisch ein öffentlicher Rollback.   |

### Öffentliche Veröffentlichung

**Wo finde ich das?** `System → Veröffentlichung`

Die Aktion **Jetzt öffentlich veröffentlichen** ist nur sichtbar, wenn ein geprüfter, übernommener Stand technisch bereit ist. Nach der Bestätigung wird genau dieser Stand als unveränderliches Paket vorbereitet. GitHub Actions verwendet die festgelegte Git-Revision, validiert das Paket, baut die statische Astro-Website und überträgt den Release kontrolliert zum Server. Erst die erfolgreiche serverseitige Aktivierung ändert die öffentliche Website.

Während der Verarbeitung den Status im CMS beachten und nicht durch erneutes Speichern „reparieren“. Ein neuer Draft ändert ein bereits laufendes oder aktives unveränderliches Paket nicht. Bei einem Fehler bleibt der vorherige öffentliche Stand maßgeblich; Support soll Status und Logs prüfen, ohne Tokens, Cookies oder Zugangsdaten weiterzugeben.

Ein öffentlicher Rollback ist eine separate, autorisierte Operator-Aktion. **Letzte Änderung zurücksetzen** im CMS betrifft zunächst den übernommenen Website-Stand und ersetzt keinen Production-Rollback.

### Code-Synchronisation und Content-Synchronisation

- **Code** wird über Git/GitHub synchronisiert. Jede Production-Publikation ist an eine exakte Git-Revision gebunden.
- **Content** bleibt in MariaDB und privatem Storage: Entwürfe, Originale, verwaltete Medien, Apply-Belege und Publikationsdaten werden nicht automatisch nach GitHub übertragen.
- GitHub Actions liest den Code und lädt ein geprüftes Veröffentlichungspaket aus dem CMS. Der Workflow erstellt daraus keinen Git-Commit.

Ein `git pull` aktualisiert deshalb keinen CMS-Entwurf. Um Production-Inhalte lokal wiederherzustellen, werden eine separat freigegebene Datenbank-/Storage-Sicherung und Prüfsummen benötigt. Bei einem Code-Deployment dürfen Production-Datenbank, `.env`, privater Storage, aktives Manifest, Apply-Status und Releases nicht durch Repository-Dateien ersetzt werden.

## Medien

### 8. Ein neues Bild hochladen

**Wo finde ich das?** `Inhalte → Medien → Erstellen`

1. `Neue Datei hochladen` wählen.
2. Eine einmalige **Stabile ID** vergeben, zum Beispiel `about-portrait-2026`.
3. JPG, PNG oder WebP auswählen.
4. Alternativtext, Materialstatus, Nutzungsfreigabe und Rechteangaben ergänzen.
5. **Erstellen** wählen.

Danach liegt das Bild im CMS-Entwurf. Es ist noch keiner Seite und keinem Projekt zugeordnet.

### 9–10. Video oder Audio hochladen

- Video: `Inhalte → Medien → Erstellen`, stabile ID vergeben, MP4 oder WebM wählen, Status/Freigabe/Rechte pflegen und **Erstellen**. Ein Poster ist ein separates Bild.
- Audio: gleicher Pfad; unterstützte Audiodatei wählen und danach unter `Inhalte → Tracks → Track bearbeiten` als **Playback-Datei** zuordnen. Nur bestätigte Versionen öffentlich freigeben.

### Große Dateien per SFTP hochladen

Normale Bilder, Videos und Audiodateien laden Sie weiterhin direkt über `Inhalte → Medien → Erstellen` hoch. Verwenden Sie SFTP nur für ungewöhnlich große Dateien, die der normale Browser-Upload nicht zuverlässig übertragen kann.

1. Öffnen Sie auf dem Mac **FileZilla Client** oder ein anderes Programm, das **SFTP / SSH File Transfer Protocol** unterstützt. Normales FTP ist nicht gleichwertig.
2. Legen Sie eine SFTP-Verbindung mit Server `hosting213834.a2e58.netcup.net`, Port `22` und Benutzer `hosting213834` an. Verwenden Sie die separat sicher gespeicherten Pianissimo-Zugangsdaten. Tragen Sie kein Passwort in das CMS oder in Notizen im Repository ein.
3. Öffnen Sie auf dem Server genau diesen Ordner: `/pianissimo-production.de/private/incoming/media/`.
4. Laden Sie die große Datei dort hoch. Laden Sie sie niemals nach `httpdocs`, `public` oder direkt nach `media/originals`.
5. Warten Sie unbedingt, bis FileZilla die Übertragung als vollständig abgeschlossen zeigt.
6. Öffnen Sie im CMS `Inhalte → Medien` und wählen Sie **SFTP-Liste aktualisieren**.
7. Wählen Sie **Große Datei importieren**. Der Upload-Ordner wird nur zur Orientierung angezeigt und kann nicht bearbeitet werden.
8. Wählen Sie die fertig übertragene Datei aus der angebotenen Liste. Sie müssen keinen Dateipfad eintippen.
9. Vergeben Sie eine einmalige stabile ID, ergänzen Sie Beschreibung, Materialstatus, Nutzungsfreigabe und Rechte und starten Sie den Import.
10. Warten Sie auf die Erfolgs- oder Fehlermeldung. Das CMS prüft Größe, unterstützten Dateityp und Dateiinhalt und übernimmt die Datei erst danach sicher in die Mediathek.

Nach erfolgreichem Import verschwindet die Datei aus der SFTP-Liste und erscheint normal unter **Medien**. Sie kann anschließend wie jedes andere Medium einer Seite, einem Projekt oder Track zugeordnet werden. Verschieben Sie Dateien im privaten Medienspeicher niemals von Hand.

Falls der Import scheitert, prüfen Sie zuerst, ob FileZilla wirklich fertig ist und ob der Dateityp unterstützt wird. Aktualisieren Sie die SFTP-Liste und versuchen Sie den kontrollierten CMS-Import erneut. Verschieben Sie die Datei nicht in einen anderen Serverordner. Bleibt der Fehler bestehen, senden Sie dem technischen Administrator nur Dateiname und angezeigte Fehlermeldung – niemals Passwort oder Zugangsdaten.

### 11. Ein neues Medium einer Seite zuordnen

Nach dem Upload die Zielseite oder das Projekt öffnen, das ausdrücklich benannte Feld `Bild`, `Video`, `Video-Poster`, `Projekt-Cover`, `Galeriebild`, `Trailer-Poster` oder `Playback-Datei` belegen und **Entwurf speichern**. Die Angabe **Hauptverwendung** erstellt keine Zuordnung.

### 12. Ein neues Bild im About verwenden

1. `Inhalte → Medien → Erstellen` öffnen.
2. `Neue Datei hochladen` wählen.
3. Bild auswählen, stabile ID und Pflichtangaben ergänzen.
4. Medium speichern.
5. Wichtig: `Hauptverwendung = about` ordnet die Datei noch nicht dem About zu.
6. `Inhalte → About` öffnen.
7. `Darstellung = Bild` wählen.
8. Im Feld `Bild` das hochgeladene Medium auswählen.
9. **Entwurf speichern** wählen.
10. `System → Veröffentlichung → Website-Vorschau öffnen` wählen.
11. Wenn alles stimmt: **Änderungen prüfen** und erst nach Freigabe **Für Website übernehmen**.

### 13–22. Weitere Medien-Workflows

| Workflow                           | Pfad                                       | Wichtig                                                                                                                                               |
| ---------------------------------- | ------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| Bild oder Video im About           | `Inhalte → About`                          | `Darstellung` wählen. Im Bildmodus zählt `Bild`; im Videomodus zählen `Video` und optional `Video-Poster`. Inaktive Alternativen bleiben gespeichert. |
| Startseiten-Bild ändern            | `Website → Startseite`                     | `Darstellung = Bild`, Medium wählen, speichern und Vorschau prüfen.                                                                                   |
| Datei überall ersetzen             | `Inhalte → Medien → Datei löschen`         | Erst **Verwendungen anzeigen**, dann kompatiblen Ersatz und `Überall ersetzen` wählen.                                                                |
| Datei nur an einer Stelle ersetzen | Zielseite/Projekt → Medienfeld             | Genau dort ein anderes Medium wählen. Andere Verwendungen bleiben bestehen.                                                                           |
| Verwendungen anzeigen              | `Inhalte → Medien → Verwendungen anzeigen` | Zeigt Seite, Projekt und Rolle; ändert nichts.                                                                                                        |
| Medium sicher löschen              | `Inhalte → Medien → Datei löschen`         | Erst Verwendungen entfernen oder ersetzen. Pflichtverwendungen verlangen Ersatz.                                                                      |
| Hauptverwendung                    | Medium bearbeiten                          | Nur redaktionelle Einordnung; keine automatische Zuordnung und keine versteckte Priorität.                                                            |
| Materialstatus                     | Medium bearbeiten                          | `Bestätigt`, `Temporär` oder `Test`. Testmaterial kann die Übernahme blockieren.                                                                      |
| Nutzungsfreigabe                   | Medium bearbeiten                          | `Öffentliche Nutzung` nur bei bestätigten Rechten und bestätigter Dateiversion.                                                                       |
| Bild, Video, Poster                | Zielseite/Projekt                          | Bild = statisch; Video = abgespielte Datei; Poster = Vorschaubild des Videos.                                                                         |

## Projekte

### 23–34. Projekt-Workflows

| Workflow                | Pfad                                      | Vorgehen                                                                                                                                                |
| ----------------------- | ----------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Projekt bearbeiten      | `Inhalte → Projekte → Projekt bearbeiten` | Passende Registerkarte bearbeiten, **Entwurf speichern**, Vorschau prüfen.                                                                              |
| Projekt anlegen         | `Inhalte → Projekte → Erstellen`          | Stabile ID, URL-Slug, Titel, Richtung, Rolle und Cover pflegen; zunächst Entwurf lassen.                                                                |
| Projekt-Cover ändern    | Projekt → `Richtungen & Bilder`           | Richtung öffnen, `Projekt-Cover`, Fokus und Bildanpassung prüfen.                                                                                       |
| Hover-Video             | Projekt → `Richtungen & Bilder`           | Optionales `Hover-Video` wählen. Cover bleibt eine unabhängige Rolle.                                                                                   |
| Trailer                 | Projekt → `Richtungen & Bilder`           | Medienzeile mit `Lokaler Trailer (Video)` anlegen. Kinnhakenhank nutzt ausschließlich den bestätigten V2-Trailer.                                       |
| Trailer-Poster          | Projekt → `Richtungen & Bilder`           | Eigene Zeile `Trailer-Poster (Bild)` wählen. Das ist nicht der Trailer und kein Galeriebild.                                                            |
| Galerie-Bild hinzufügen | Projekt → `Richtungen & Bilder`           | Zeile hinzufügen, `Galeriebild` wählen, Bild auswählen.                                                                                                 |
| Galerie sortieren       | Projekt → `Richtungen & Bilder`           | **Ziehen zum Sortieren** oder Pfeiltasten verwenden. Diese Reihenfolge bestimmt die Website-Galerie.                                                    |
| Credits bearbeiten      | Projekt → `Credits & Links`               | Jeder Eintrag hat `Rolle / Funktion` und `Name`. `*` heißt nur: in einer vorhandenen Zeile erforderlich. Nicht benötigte Zeilen dürfen gelöscht werden. |
| Credits sortieren       | Projekt → `Credits & Links`               | **Ziehen zum Sortieren** oder Pfeiltasten. Die Reihenfolge bestimmt die Website-Darstellung.                                                            |
| Sichtbar/unsichtbar     | Projekt → `Inhalt`                        | `Auswahl für das nächste Paket` bewusst wählen; Speichern allein ist noch keine Übernahme.                                                              |
| Film Music / Directing  | Projekt → `Richtungen & Bilder`           | Richtung, öffentliche Rolle, Reihenfolge, Cover und optional Hover-Video pro Richtung pflegen.                                                          |

### Projekt-SEO

Unter `Inhalte → Projekte → Projekt → SEO` gelten Projektwerte als gezielte Abweichungen von `Website → SEO`:

- **SEO-Titel** ist der Dokumenttitel für Browser und mögliche Suchdarstellung. Er ist keine H1/H2/H3 und ersetzt nicht den sichtbaren Projekttitel.
- **Meta-Beschreibung** ist eine mögliche Kurzfassung. Suchmaschinen dürfen einen anderen Ausschnitt zeigen.
- **Canonical URL** ist die bevorzugte Haupt-URL. Nur bei echter Abweichung vom Standard manuell eintragen.
- **Social-/OG-Bild** ist das Bild für geteilte Links; nur Bilder sind auswählbar.
- **Von Suchmaschinen ausschließen** aktiviert bedeutet: Dieses Projekt soll nicht erscheinen. Deaktiviert bedeutet nur, dass Indexierung grundsätzlich erlaubt ist. Eine globale noindex-Vorgabe kann dies weiterhin verhindern.

## Tracks

### 35–38. Track-Workflows

- `Inhalte → Tracks → Track bearbeiten`: Titel, Playback, Freigabe, Genres/Emotionen und Zuordnung pflegen.
- Audio ersetzen: neue Datei zuerst unter Medien hochladen, dann als `Playback-Datei` wählen. Die Waveform-Vorbereitung wird beim Wechsel zurückgesetzt.
- Track-Reihenfolge: `Inhalte → Projekte → Projekt → Soundtrack`; Sortiergriffe/Pfeile verwenden. `Track-Reihenfolge noch unbestätigt` erst nach echter Bestätigung ausschalten.
- Track/Projektzuordnung: im Track unter `Projekt-Zuordnung`; keine Position erfinden, solange die Reihenfolge offen ist.

## About und CV

### 39–43. Workflows

- About-Text: `Inhalte → About`; Absätze mit **Ziehen zum Sortieren** oder Pfeiltasten ordnen.
- About-Bild: `Darstellung = Bild`, dann `Bild` wählen.
- About-Video: `Darstellung = Video`, dann `Video` und optional `Video-Poster` wählen. Ein gespeichertes Bild bleibt erhalten.
- CV hinzufügen: `Inhalte → CV / Lebenslauf → Erstellen`; stabile ID, Zeitraum, Titel, optionale Angaben, Reihenfolge und Status pflegen.
- CV ändern/löschen: `Inhalte → CV / Lebenslauf`; Eintrag öffnen oder die deutlich markierte Löschen-Aktion bestätigen.

## Social Links

### 44–47. Workflows

Unter `Website → Social Links` mit **Social Link hinzufügen** Anbieter, Bezeichnung, vollständige URL und Sichtbarkeit pflegen. `Sichtbar` ausschalten, um einen Link reversibel auszublenden. Die Löschen-Aktion entfernt die Zeile. Mit **Ziehen zum Sortieren** oder den Pfeiltasten wird die öffentliche Reihenfolge festgelegt. Danach immer speichern und Vorschau prüfen.

## SEO

### 48–55. Grundlagen und Production-Prüfung

`Website → SEO` enthält globale Standards:

- **Titelvorlage**: globaler Dokumenttitel-Aufbau; keine sichtbare Überschrift.
- **Standard-Meta-Beschreibung**: mögliche Suchergebnis-Zusammenfassung ohne Ranking-Garantie.
- **Canonical-Basis**: bevorzugte öffentliche Hauptadresse; ändert weder Domain noch DNS.
- **Standard-OG-Bild**: globale Linkvorschau; Projekte können ein eigenes Bild setzen.
- **Robots-Vorgabe**: globale Regel. Globales `noindex` verhindert Indexierung auch für einzeln erlaubte Projekte.

Vor jeder relevanten öffentlichen Veröffentlichung Domain/Canonical, Titel, Beschreibungen, OG-Bilder, robots, Projekt-noindex, Datenschutz, Consent und externe Dienste prüfen. Diese Prüfung veröffentlicht noch nichts und autorisiert keine zusätzliche Deployment-, DNS- oder Tracking-Änderung.

## Cookie, Consent und Datenschutz

### 56–60. Workflows

- Consent: `Rechtliches & Datenschutz → Cookie / Consent`. Nur benötigte Kategorien anbieten, Banner-Text und Datenschutz-Link prüfen.
- Externe Medien: YouTube/Vimeo erst nach Zustimmung laden; lokale Medien sind unabhängig.
- Consent-Version: nur nach wesentlichen Zweck-/Kategorieänderungen um genau eins erhöhen, damit Besucher erneut entscheiden.
- Analytics und Consent: Analytics braucht gültige Konfiguration, aktivierte Analyse-Kategorie und Zustimmung.
- Neuer Dienst: `Rechtliches & Datenschutz → Externe Dienste` pflegen und anschließend Datenschutzerklärung/Consent prüfen. Technische Konfiguration ersetzt keine rechtliche Prüfung.

## Analytics

### 61–65. Workflows

- Einrichtung: `Website → Analytics`; Anbieter wählen und nur öffentliche Kennung eintragen.
- GA4: Measurement-ID im Format `G-XXXXXXXXXX`; niemals Passwort oder Secret.
- Plausible: öffentliche Domain ohne `https://` und ohne Pfad.
- Bericht exportieren: im Anbieter-Dashboard Zeitraum wählen und CSV/PDF exportieren. Das CMS speichert keine eigenen Besucherprofile.

Die endgültige SEO-Indexierung und die reale Analytics-Einrichtung bleiben Aufgaben des Website-Inhabers. Das CMS stellt Felder, Provider-Auswahl und Consent-Anbindung bereit; daraus folgt keine Garantie, dass Suchmaschinen indexieren oder ein externer Analytics-Dienst vollständig eingerichtet und geprüft ist. Das ist eine bewusste betriebliche Zuständigkeit und kein automatisch festgestellter Implementierungsfehler.
- KI-Auswertung: unnötige personenbezogene Daten entfernen, nur den Bericht hochladen, Ziel/Zeitraum/Fragen nennen und Empfehlungen selbst prüfen. Keine Passwörter, Tokens oder privaten Besucherdaten hochladen.

## System

### 66–71. Workflows und Fehlersuche

- Benutzer verwalten: `System → Benutzer`. Nur notwendige Konten führen; neue Passwörter separat sicher übermitteln.
- Passwort zurücksetzen: Anmeldeseite → **Passwort vergessen** oder `System → Benutzer → Bearbeiten`. SMTP/Production-Mailversand ist nicht eingerichtet.
- Veröffentlichung: `System → Veröffentlichung`; Entwurf → Vorschau → Prüfen → Übernehmen.
- Website-Vorschau in Production: Der private Paket-Build kann nach dem Öffnen kurz den Hinweis **Website-Vorschau wird erstellt** zeigen und lädt danach automatisch neu. Das ist keine Veröffentlichung; die öffentliche Website bleibt unverändert. Das Vorschau-Fenster erfordert die aktive CMS-Anmeldung, läuft automatisch ab und wird nach jeder gespeicherten Änderung ungültig.
- Änderung nicht sichtbar: Speicherbestätigung prüfen, neue Vorschau öffnen, Status/Blockierungen kontrollieren, erneut prüfen und übernehmen.
- „Vorschau nicht mehr aktuell“: Entwurf wurde danach geändert. Neue Vorschau öffnen und **Änderungen erneut prüfen** wählen.
- Technische Details: `System → Veröffentlichung → Technische Details` nur für Administrator/Support. Keine Hashes, Pfade oder Logs in redaktionelle Inhalte übernehmen.

## Suche

Die Suche oben durchsucht owner-relevante CMS-Seiten, Projekte, Tracks, Medien, CV-Einträge und Hilfeartikel. Ergebnisse sind gruppiert und führen direkt zur Seite oder zum Datensatz. Sie versteht Groß-/Kleinschreibung, Umlaute (`ä/ae`, `ö/oe`, `ü/ue`, `ß/ss`), mehrere Wörter, Präfixe, gebräuchliche Synonyme und kleine Tippfehler wie `verofentlichen`, `galeri`, `analytycs` oder `suchmachine`.

Die Suche innerhalb `Hilfe / Handbuch` durchsucht ausschließlich Hilfeartikel. Sie zeigt keine Projekte, Medien oder Tracks. Passwörter, Reset-Tokens, private Empfänger, Zugangsdaten, Dateipfade, Hashes, Logs und Quellcode werden nicht indexiert.

## Wenn etwas anders aussieht

Nicht raten. Einen Screenshot der vollständigen CMS-Seite erstellen, den genauen Pfad und die beabsichtigte Änderung nennen und Support fragen. Keine Production-Aktion, DNS-Änderung, SMTP-Einrichtung, reale Analytics-Aktivierung oder Deployment ohne ausdrückliche separate Freigabe durchführen.
