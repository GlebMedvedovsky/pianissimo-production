<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateProductionContentSync
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->isProduction() && ! $request->isSecure()) {
            return new JsonResponse(
                ['message' => 'Content sync transport rejected.'],
                403,
                ['Cache-Control' => 'private, no-store'],
            );
        }

        $configured = config('publisher.content_sync_api_token');
        $authorization = $request->header('Authorization');
        $validConfiguration = is_string($configured)
            && strlen($configured) >= 32
            && strlen($configured) <= 512
            && ! preg_match('/[\x00-\x20\x7f]/', $configured);
        $provided = is_string($authorization)
            && preg_match('/^Bearer ([^\s]{32,512})$/D', $authorization, $matches) === 1
                ? $matches[1]
                : null;

        if (! $validConfiguration || $provided === null || ! hash_equals($configured, $provided)) {
            return new JsonResponse(
                ['message' => 'Content sync authentication failed.'],
                401,
                ['WWW-Authenticate' => 'Bearer', 'Cache-Control' => 'private, no-store'],
            );
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
