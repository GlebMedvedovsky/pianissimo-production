<?php
namespace App\Providers\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->default()->id('admin')->path('admin')->login()->brandName('Pianissimo · CMS')
            ->passwordReset(\App\Filament\Auth\RequestPasswordReset::class, \App\Filament\Auth\ResetPassword::class)
            ->themeSwitcher(false)
            ->defaultAvatarProvider(\App\Providers\LocalAvatarProvider::class)
            ->favicon(fn () => asset('avatar.svg'))
            ->colors(['primary'=>[50=>'#faf5ef',100=>'#f1e5d6',200=>'#e3caae',300=>'#d2ac84',400=>'#bd895c',500=>'#A66A3F',600=>'#925831',700=>'#76452a',800=>'#603b27',900=>'#503324',950=>'#2b190f']])->defaultThemeMode(\Filament\Enums\ThemeMode::Dark)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->navigationGroups(['Inhalte','Website','Rechtliches & Datenschutz','System'])
            ->globalSearch(\App\Services\OwnerGlobalSearchProvider::class)
            ->renderHook('panels::head.end', fn () => view('cms.theme'))
            ->renderHook(\Filament\View\PanelsRenderHook::GLOBAL_SEARCH_AFTER, fn () => view('cms.theme-switcher'))
            ->middleware([EncryptCookies::class,AddQueuedCookiesToResponse::class,StartSession::class,AuthenticateSession::class,ShareErrorsFromSession::class,ValidateCsrfToken::class,SubstituteBindings::class,DisableBladeIconComponents::class,DispatchServingFilamentEvent::class])
            ->authMiddleware([Authenticate::class]);
    }
}
