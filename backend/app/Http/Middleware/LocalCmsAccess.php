<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;

class LocalCmsAccess
{
    public function handle(Request $request, Closure $next)
    {
        // Run after Laravel's TrustProxies, before sessions / Filament. This is
        // the local CMS, not a production proxy policy or wildcard tunnel trust.
        abort_unless(in_array($request->getHost(), config('dev-access.hosts', []), true), 400);
        config(['session.secure' => $request->isSecure()]);
        return $next($request);
    }
}
