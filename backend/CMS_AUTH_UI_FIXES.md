# Local CMS auth and UI fixes — 2026-09-20

Branch: `feature/cms-auth-and-ui-fixes`. Backend only; no commit, push or deployment.

## Root cause and user forms

`GET /admin/users/create` returned HTTP 500 because `Fields::user()` called
`TextInput::requiredOn('create')`, which does not exist in the installed Filament 5.
The existing Laravel log and a failing HTTP regression test both identified
`app/Filament/Fields.php:72` as the source. The corrected form uses a condition on
the Filament operation. It clears the password field on hydration, preserves an
existing password when left blank, and uses the existing model's Laravel `hashed`
cast when a password is supplied. Name/email length and email uniqueness errors
remain form validation errors.

## Password reset

The login page now links to **Passwort vergessen?**. Filament supplies the request
and reset forms, signed reset URL, rate limiting, notification, password broker,
token checking and password reset. Custom subclasses only normalize request
feedback and preserve the existing minimum password length of 12 characters.
Known, unknown and recently requested addresses receive identical German feedback.
The model now uses `Notifiable`; the new migration adds `password_reset_tokens`.
Tokens are hashed in the database, expire after 60 minutes and are single use.

Local configuration remains `MAIL_MAILER=log`, `QUEUE_CONNECTION=sync`.
Requesting a reset writes the email, including its link, to
`storage/logs/laravel.log`. Open that link locally to set and confirm the new
password. No real email is sent. Do not share reset links or logs containing them.
The migration was applied to the local database only, with existing user rows
compared before and after and confirmed unchanged.

Production SMTP is a separate deployment task. Laravel's existing mail transport
configuration will accept `MAIL_MAILER=smtp`, provider-supplied `MAIL_HOST`,
`MAIL_PORT`, `MAIL_SCHEME`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`
and `MAIL_FROM_NAME`. The production `APP_URL` must match the HTTPS CMS address
used for signed links. No production credentials or configuration were added.
See the [Filament authentication documentation](https://filamentphp.com/docs/5.x/users/overview#authentication-features)
and [Laravel password-reset documentation](https://laravel.com/framework/docs/13.x/passwords).

## Shared save action and theme controls

`SettingsPage::getHeaderActions()` defines the single **Entwurf speichern** header
action, calling the existing `save()` method. The bottom button was removed from
`cms/settings.blade.php`; form submission still calls `save()`. All 14 subclasses
inherit the change, including Website, legal/consent and Site settings. Validation,
retention of settings not present in the form, and draft-only persistence remain
unchanged. Tests prohibit exporter calls and check that no publication is created.

The `GLOBAL_SEARCH_AFTER` hook renders `cms/theme-switcher.blade.php`, which reuses
Filament's native Light/Dark/System component. Its icons, selected state,
localStorage events and system preference listener are unchanged. A no-op
`close()` supplies the dropdown callback expected by the native buttons.
`themeSwitcher(false)` hides the old dropdown controls without disabling dark
mode or removing logout. Existing `/admin-theme` persistence is unchanged.
Compact CSS keeps search flexible at narrow widths. Public website colors are
unaffected by the admin switcher.

## Changed files

All paths are relative to `backend/`:

- `app/Filament/Fields.php`
- `app/Filament/SettingsPage.php`
- `app/Filament/Auth/RequestPasswordReset.php`
- `app/Filament/Auth/ResetPassword.php`
- `app/Models/User.php`
- `app/Providers/Filament/AdminPanelProvider.php`
- `database/migrations/2026_09_20_000001_create_password_reset_tokens.php`
- `lang/de.json`
- `lang/de/auth.php`
- `lang/de/passwords.php`
- `lang/de/validation.php`
- `resources/views/cms/settings.blade.php`
- `resources/views/cms/theme.blade.php`
- `resources/views/cms/theme-switcher.blade.php`
- `tests/Feature/CmsAuthUiTest.php`
- `CMS_AUTH_UI_FIXES.md`

## Verification and remaining checks

`php vendor/bin/phpunit tests/Feature/CmsAuthUiTest.php` passed:
**25 tests, 448 assertions**. The original reproduction failed with HTTP 500 before
the fix and passed with HTTP 200 afterward. Direct requests to the running local
login and forgot-password pages both returned HTTP 200 with German reset UI.

Three existing `CmsTest` cases were also run with an explicit `--filter`:
`test_authentication_theme_isolation_and_private_routes`,
`test_settings_forms_save_about_seo_legal_and_consent_as_drafts`, and
`test_03b_admin_theme_is_independent_from_website_colors_and_manifest`.
They passed: **3 tests, 40 assertions**. Final passing runs total **28 tests,
488 assertions**. `git diff --check` passed. No full historical suite was run.

The new targeted suite covers user list/create/edit/delete, login/logout,
password hashing and preservation, validation, German auth pages, reset mail,
privacy-safe feedback, rate limiting, signed URLs, invalid/expired/reused tokens,
all 14 settings header actions, draft-only saving, native topbar controls and
per-user theme persistence through navigation and re-login.

A separate local smoke check used the actual request/reset/login page methods,
the local database and the `log` mail transport. It passed reset, token consumption
and login with the new password. Its temporary user and token were rolled back,
its isolated mail log was removed, and existing user rows remained identical.
The final cleanup query found zero temporary local users or reset tokens.

Visual browser verification remains open: both available browser entry points
reported that the browser was unavailable. HTTP, Livewire and rendered DOM checks
cover behavior and placement; actual desktop/mobile appearance, browser clicks
and operating-system theme changes still require a browser check. Production SMTP
delivery will be verified separately when deployment is authorized.
