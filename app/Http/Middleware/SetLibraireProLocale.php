<?php

namespace App\Http\Middleware;

use App\Support\Locale;
use App\Support\TenantClock;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLibraireProLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = null;
        $user = $request->user();

        $tenant = TenantContext::resolve($request, $user);

        Locale::apply($tenant);
        TenantClock::apply($tenant);

        return $next($request);
    }
}
