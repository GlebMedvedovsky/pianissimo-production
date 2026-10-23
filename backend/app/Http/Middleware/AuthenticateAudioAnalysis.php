<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\{JsonResponse, Request};
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateAudioAnalysis
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->isProduction() && ! $request->isSecure()) {
            return $this->reject(403, 'Audio analysis transport rejected.');
        }
        $configured = config('audio-analysis.api_token');
        $authorization = $request->header('Authorization');
        $provided = is_string($authorization)
            && preg_match('/^Bearer ([^\s]{32,512})$/D', $authorization, $matches) === 1
                ? $matches[1]
                : null;
        $valid = is_string($configured) && strlen($configured) >= 32 && strlen($configured) <= 512
            && preg_match('/[\x00-\x20\x7f]/', $configured) !== 1;
        if (! $valid || $provided === null || ! hash_equals($configured, $provided)) {
            return $this->reject(401, 'Audio analysis authentication failed.');
        }
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        return $response;
    }

    private function reject(int $status, string $message): JsonResponse
    {
        return new JsonResponse(['message' => $message], $status, [
            'WWW-Authenticate' => 'Bearer',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
