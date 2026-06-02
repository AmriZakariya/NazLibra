<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\Locale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLibraireProLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = null;
        $user = $request->user();

        if ($user) {
            $tenant = $user->currentTenant ?: Tenant::query()->first();
        }

        Locale::apply($tenant);

        return $next($request);
    }
}
