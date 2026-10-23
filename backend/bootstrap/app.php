<?php
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        // The proxy list is read from config/trustedproxy.php by Laravel's
        // middleware after environment or cached configuration is loaded.
        $middleware->trustProxies(headers:
            \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST |
            \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO |
            \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT);
        $middleware->append(\App\Http\Middleware\LocalCmsAccess::class);
        $middleware->alias([
            'publisher.auth' => \App\Http\Middleware\AuthenticateProductionPublisher::class,
            'content-sync.auth' => \App\Http\Middleware\AuthenticateProductionContentSync::class,
            'audio-analysis.auth' => \App\Http\Middleware\AuthenticateAudioAnalysis::class,
        ]);
        $middleware->redirectGuestsTo('/admin/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {})
    ->create();
