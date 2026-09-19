# CMS settings extension 1.0.0

`ManifestExporter` is the single package producer. Core project/track/media content remains the existing shared manifest contract; it is not duplicated in this extension. `SettingsExporter` explicitly projects and validates additional public fields using Laravel's validation rules. The current frontend does not read the extension.

| Field | Public shape / rule |
|---|---|
| `schemaVersion` | Literal `cms-settings-1.0.0` |
| `scope`, `deploymentAuthorized` | Literal `next_draft`, `false` |
| `home` | `loop: true`, `autoplayMuted: true` |
| `about` | title, visible boolean, theme `follow-section/dark/light`, getInTouch plain text |
| `appearance` | dark/light/accent hex colors; independent from per-user admin theme |
| `navigation` | music/directing plain labels |
| `footer`, `legalIdentity` | Public plain text only |
| `seo` | titleTemplate, description, nullable HTTP(S) canonicalBase/OG asset ID, robots enum, sitemap/index booleans |
| `projectSeo` | Object keyed by stable project ID: title, description, HTTP(S) canonical, image asset ID, noindex boolean |
| `projectMedia` | Object keyed by stable project ID: ordered additional soundtrack_cover/other media IDs and order; assets remain in core manifest |
| `consent` | essential/externalMedia true, analytics/marketing booleans default false, bannerText, relative privacyLink, draft/reviewed/published status |
| `externalServices` | Ordered typed records: name, fixed provider, externalMedia/analytics/marketing category, purpose/description, HTTP(S) privacy_url, storage_description, requires_consent true, enabled boolean, constrained provider_id, sort_order |
| `analytics` | enabled false initially; provider none/ga4/plausible; public measurementId only. Enabling configuration requires matching consent and ID; no script is loaded in 03A |
| `licenses` | Editorial public text and draft/reviewed/published status |

Provider allowlist: youtube, vimeo, spotify, tidal, deezer, appleMusic, ga4, plausible. Configuration is a public identifier (letters, digits, dot, underscore, hyphen), not executable code. Unknown/private source fields are omitted, not copied recursively. Administrative credentials, contact.recipient and private asset source/license notes have no public DTO fields.

The extension is preparatory; adding frontend consumption will need its own explicit integration and release tests. Saving these values in CMS does not silently change the existing visitor experience.

04A legal `reviewDraft` is a private editorial field, excluded from this companion and the core manifest. Draft legal status does not grant release approval. Standalone library selection, temporary-cover state and CV link text belong to the core manifest, not a second content schema.
