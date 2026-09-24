<?php

namespace Tests\Feature;

use App\Filament\Auth\{RequestPasswordReset, ResetPassword};
use App\Filament\Pages;
use App\Filament\Resources\Pages\{CreateUser, EditUser, ListUsers};
use App\Models\{Publication, Setting, User};
use App\Services\ManifestExporter;
use Filament\Actions\DeleteAction;
use Filament\Auth\Notifications\ResetPassword as ResetNotification;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Hash, Mail, Notification, Password};
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CmsAuthUiTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Synthetic-QA-only-2026';

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config(['mail.default' => 'array', 'queue.default' => 'sync']);
    }

    private function user(string $email = 'cms-qa@example.test'): User
    {
        return User::create(['name' => 'CMS QA', 'email' => $email, 'password' => self::PASSWORD]);
    }

    public function test_user_create_page_renders(): void
    {
        $user = $this->user();
        $this->actingAs($user)->get('/admin/users')->assertOk();
        $this->actingAs($user)->get('/admin/users/create')->assertOk();
    }

    public function test_create_login_edit_and_delete_temporary_user(): void
    {
        $admin = $this->user();
        $adminBefore = $admin->refresh()->getAttributes();
        $this->actingAs($admin);
        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Temporary CMS QA', 'email' => 'temporary@example.test', 'password' => self::PASSWORD, 'admin_theme' => 'system'])
            ->call('create')->assertHasNoFormErrors();
        $temporary = User::where('email', 'temporary@example.test')->firstOrFail();
        $this->assertTrue(Hash::check(self::PASSWORD, $temporary->password));
        $hash = $temporary->password;

        $this->post('/admin/logout')->assertRedirect();
        $this->assertGuest();
        Livewire::test(Login::class)->fillForm(['email' => $temporary->email, 'password' => self::PASSWORD])
            ->call('authenticate')->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($temporary);
        $this->post('/admin/logout')->assertRedirect();

        $this->actingAs($admin)->get('/admin/users/'.$temporary->id.'/edit')->assertOk();
        Livewire::test(EditUser::class, ['record' => $temporary->id])
            ->assertFormSet(['password' => ''])
            ->fillForm(['name' => 'Edited CMS QA', 'email' => 'edited@example.test', 'password' => '', 'admin_theme' => 'light'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('Edited CMS QA', $temporary->fresh()->name);
        $this->assertSame($hash, $temporary->fresh()->password);
        Livewire::test(EditUser::class, ['record' => $temporary->id])
            ->fillForm(['password' => 'Changed-synthetic-only-2026'])->call('save')->assertHasNoFormErrors();
        $this->assertTrue(Hash::check('Changed-synthetic-only-2026', $temporary->fresh()->password));
        Livewire::test(ListUsers::class)->callTableAction(DeleteAction::class, $temporary);
        $this->assertModelMissing($temporary);
        $this->assertEquals($adminBefore, $admin->fresh()->getAttributes());
    }

    public function test_user_validation_is_reported_in_the_form(): void
    {
        $admin = $this->user();
        $this->actingAs($admin);
        Livewire::test(CreateUser::class)->fillForm(['name' => '', 'email' => 'invalid', 'password' => ''])
            ->call('create')->assertHasFormErrors(['name' => 'required', 'email' => 'email', 'password' => 'required']);
        Livewire::test(CreateUser::class)->fillForm(['name' => str_repeat('A', 256), 'email' => $admin->email, 'password' => 'short'])
            ->call('create')->assertHasFormErrors(['name' => 'max', 'email' => 'unique', 'password' => 'min']);
        $this->assertSame(1, User::count());
    }

    public function test_german_login_request_and_reset_pages_render(): void
    {
        $this->get('/admin/login')->assertOk()->assertSee('Passwort vergessen?');
        $this->get(Filament::getRequestPasswordResetUrl())->assertOk()->assertSee('E-Mail zusenden');
        $user = $this->user();
        $token = Password::broker()->createToken($user);
        $url = Filament::getResetPasswordUrl($token, $user);
        $this->get($url)->assertOk()->assertSee('Passwort zurücksetzen');
        $this->get($url.'&token=tampered')->assertForbidden();
    }

    public function test_reset_request_has_identical_feedback_for_known_unknown_and_throttled_addresses(): void
    {
        Notification::fake();
        $user = $this->user();
        $feedback = [];
        foreach ([$user->email, 'unknown@example.test', $user->email] as $email) {
            // Isolate the account lookup result from the separate per-IP request limiter.
            app('cache')->flush();
            session()->forget('filament.notifications');
            $page = Livewire::test(RequestPasswordReset::class)->fillForm(['email' => $email])->call('request')
                ->assertHasNoFormErrors()->assertFormSet(['email' => null]);
            $notice = collect(session('filament.claimed_notifications') ?? session('filament.notifications'))->last();
            $page->assertNotified('Anfrage erhalten');
            $feedback[] = array_intersect_key($notice, array_flip(['title', 'body', 'status']));
        }
        $this->assertSame($feedback[0], $feedback[1]);
        $this->assertSame($feedback[0], $feedback[2]);
        Notification::assertSentToTimes($user, ResetNotification::class, 1);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'unknown@example.test']);
        $token = Notification::sent($user, ResetNotification::class)->first()->token;
        $stored = DB::table('password_reset_tokens')->where('email', $user->email)->value('token');
        $this->assertNotSame($token, $stored);
        $this->assertTrue(Hash::check($token, $stored));
    }

    public function test_reset_request_validation_and_rate_limit(): void
    {
        Livewire::test(RequestPasswordReset::class)->fillForm(['email' => 'invalid'])->call('request')
            ->assertHasFormErrors(['email' => 'email']);
        app('cache')->flush();
        $page = Livewire::test(RequestPasswordReset::class);
        for ($i = 0; $i < 3; $i++) {
            $page->fillForm(['email' => 'unknown@example.test'])->call('request');
        }
        $page->assertNotified('Zu viele Versuche.');
    }

    public function test_reset_mail_and_complete_token_flow_without_smtp(): void
    {
        $user = $this->user();
        $originalHash = $user->password;
        Livewire::test(RequestPasswordReset::class)->fillForm(['email' => $user->email])->call('request')->assertHasNoFormErrors();
        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $mail = $messages->first()->getOriginalMessage();
        $this->assertSame('Passwort zurücksetzen', $mail->getSubject());
        $this->assertSame($user->email, $mail->getTo()[0]->getAddress());
        preg_match('~https?://[^\s<>]+/admin/password-reset/reset[^\s<>]*~', $mail->getTextBody(), $match);
        $this->assertNotEmpty($match);
        parse_str(parse_url($match[0], PHP_URL_QUERY), $query);
        $this->assertSame($user->email, $query['email']);
        $this->get($match[0])->assertOk();

        Livewire::test(ResetPassword::class, $query)->fillForm(['password' => 'short', 'passwordConfirmation' => 'different'])
            ->call('resetPassword')->assertHasFormErrors(['password']);
        app('cache')->flush();
        $newPassword = 'Reset-synthetic-only-2026';
        Livewire::test(ResetPassword::class, $query)->fillForm(['password' => $newPassword, 'passwordConfirmation' => $newPassword])
            ->call('resetPassword')->assertHasNoFormErrors()->assertRedirect(Filament::getLoginUrl());
        $this->assertTrue(Hash::check($newPassword, $user->fresh()->password));
        $this->assertNotSame($originalHash, $user->fresh()->password);
        $this->assertNotEmpty($user->fresh()->remember_token);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        app('cache')->flush();
        Livewire::test(ResetPassword::class, $query)->fillForm(['password' => self::PASSWORD, 'passwordConfirmation' => self::PASSWORD])
            ->call('resetPassword')->assertNotified(__('passwords.token'));
        $this->assertTrue(Hash::check($newPassword, $user->fresh()->password));
        Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => self::PASSWORD])
            ->call('authenticate')->assertHasFormErrors(['email']);
        Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => $newPassword])
            ->call('authenticate')->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($user);
        $this->post('/admin/logout')->assertRedirect();
        $this->assertGuest();
    }

    public function test_invalid_and_expired_reset_tokens_cannot_change_password(): void
    {
        $user = $this->user();
        $hash = $user->password;
        $validToken = Password::broker()->createToken($user);
        DB::table('password_reset_tokens')->where('email', $user->email)->update(['created_at' => now()->subMinutes(61)]);
        foreach (['invalid-token', $validToken] as $token) {
            app('cache')->flush();
            Livewire::test(ResetPassword::class, ['email' => $user->email, 'token' => $token])
                ->fillForm(['password' => 'Never-applied-only-2026', 'passwordConfirmation' => 'Never-applied-only-2026'])
                ->call('resetPassword')->assertNotified(__('passwords.token'));
            $this->assertSame($hash, $user->fresh()->password);
        }
    }

    public static function settingsPages(): array
    {
        return [
            'Startseite' => [Pages\Home::class, 'home', ['loop' => true, 'autoplayMuted' => true]],
            'Film Music' => [Pages\Music::class, 'music', ['label' => 'Film Music QA']],
            'Directing' => [Pages\Directing::class, 'directing', ['label' => 'Directing QA']],
            'Site-Einstellungen' => [Pages\Site::class, 'site', ['title' => 'CMS QA']],
            'Kontakt' => [Pages\Contact::class, 'contact', ['email' => 'public@example.test']],
            'Social Links' => [Pages\Social::class, 'social', ['artistLinks' => ['spotify' => null, 'tidal' => null, 'deezer' => null, 'appleMusic' => null], 'profileLinks' => ['imdb' => null, 'crewUnited' => null, 'musicHub' => null]]],
            'SEO' => [Pages\Seo::class, 'seo', ['titleTemplate' => '%s QA', 'robots' => 'noindex, nofollow']],
            'Website-Farben' => [Pages\Appearance::class, 'appearance', ['dark' => '#111111', 'light' => '#ffffff', 'accent' => '#aa6633']],
            'About' => [Pages\About::class, 'about', ['title' => 'About QA', 'theme' => 'follow-section']],
            'Impressum' => [Pages\Impressum::class, 'impressum', ['text' => 'QA', 'status' => 'draft']],
            'Datenschutz' => [Pages\Privacy::class, 'privacy', ['text' => 'QA', 'status' => 'draft']],
            'Cookie / Consent' => [Pages\Consent::class, 'consent', ['essential' => true, 'externalMedia' => true, 'privacyLink' => '/datenschutz', 'status' => 'draft']],
            'Lizenzen' => [Pages\Licenses::class, 'licenses', ['text' => 'QA', 'status' => 'draft']],
            'Analytics settings' => [Pages\Analytics::class, 'analytics', ['provider' => 'none', 'enabled' => false]],
        ];
    }

    #[DataProvider('settingsPages')]
    public function test_shared_header_action_saves_only_settings_drafts(string $page, string $key, array $values): void
    {
        $this->actingAs($this->user());
        $this->mock(ManifestExporter::class)->shouldNotReceive('export', 'package');
        Setting::put($key, ['unmodeledKey' => 'preserve me']);
        $response = $this->get($page::getUrl())->assertOk();
        $dom = new \DOMDocument();
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $saveButtons = $xpath->query('//button[contains(normalize-space(.), "Entwurf speichern")]');
        $this->assertCount(1, $saveButtons);
        $this->assertSame(1, $xpath->query('ancestor::header', $saveButtons->item(0))->length);
        Livewire::test($page)->fillForm($values)->callAction('save')->assertHasNoFormErrors()->assertNotified('Entwurf gespeichert');
        foreach ($values as $field => $value) {
            $this->assertSame($value, Setting::read($key)[$field]);
        }
        $this->assertSame('preserve me', Setting::read($key)['unmodeledKey']);
        $this->assertSame(0, Publication::count());
    }

    public function test_header_save_still_validates_fields(): void
    {
        $this->actingAs($this->user());
        Setting::put('site', ['title' => 'Original']);
        Livewire::test(Pages\Site::class)->fillForm(['title' => ''])->callAction('save')->assertHasFormErrors(['title' => 'required']);
        $this->assertSame('Original', Setting::read('site')['title']);
    }

    public function test_native_theme_controls_are_in_topbar_with_no_dropdown_duplicate(): void
    {
        $this->actingAs($this->user());
        $response = $this->get('/admin/users')->assertOk()->assertSee('Abmelden');
        $dom = new \DOMDocument();
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $switcher = $xpath->query('//div[@class="fi-theme-switcher"]');
        $this->assertCount(1, $switcher);
        $this->assertSame(1, $xpath->query('ancestor::*[contains(@class, "fi-topbar-end")]', $switcher->item(0))->length);
        $this->assertSame(3, $xpath->query('.//button[@type="button"]', $switcher->item(0))->length);
        $this->assertSame(1, $xpath->query('//*[contains(@class, "fi-topbar-end")]//input[@type="search"]')->length);
        $this->assertFalse(Filament::getCurrentPanel()->hasThemeSwitcher());
        $this->assertTrue(Filament::getCurrentPanel()->hasDarkMode());
    }

    public function test_each_theme_persists_across_navigation_and_login_and_is_per_user(): void
    {
        $user = $this->user();
        $other = $this->user('other@example.test');
        Setting::put('appearance', ['dark' => '#123456']);
        $this->actingAs($user);
        foreach (['light', 'dark', 'system'] as $theme) {
            $this->postJson('/admin-theme', ['theme' => $theme])->assertOk();
            $this->assertSame($theme, $user->fresh()->admin_theme);
            $this->get(Pages\Home::getUrl())->assertOk()->assertSee("localStorage.setItem('theme', '{$theme}')", false);
            $this->post('/admin/logout')->assertRedirect();
            $this->assertGuest();
            Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => self::PASSWORD])->call('authenticate')->assertHasNoFormErrors();
            $this->assertAuthenticatedAs($user);
            $this->get('/admin/users')->assertOk()->assertSee("localStorage.setItem('theme', '{$theme}')", false);
        }
        $this->postJson('/admin-theme', ['theme' => 'invalid'])->assertUnprocessable();
        $this->assertSame('dark', $other->fresh()->admin_theme);
        $this->assertSame(['dark' => '#123456'], Setting::read('appearance'));
        $this->post('/admin/logout')->assertRedirect();
        $this->postJson('/admin-theme', ['theme' => 'light'])->assertUnauthorized();
    }
}
