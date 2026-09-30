<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;

class LocalCmsAccess
{
    public function handle(Request $request, Closure $next)
    {
        // TrustProxies runs first. A forwarded host can therefore affect this
        // value only when the direct peer matches the explicit proxy allowlist.
        $host = strtolower(rtrim($request->getHost(), '.'));
        abort_unless(in_array($host, config('dev-access.hosts', []), true), 400, 'Unapproved host.');
        // Production cookies remain secure even if a hosting layer fails to
        // communicate the original scheme. Local behavior remains dynamic.
        config(['session.secure' => app()->isProduction() ? true : $request->isSecure()]);
        return $next($request);
    }
}
