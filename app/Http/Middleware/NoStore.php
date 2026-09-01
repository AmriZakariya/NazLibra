<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks responses as non-cacheable so the shared-host edge cache (LiteSpeed /
 * LWS "Edge Cache") never stores them. Without this the edge can cache a
 * response for a URL — including a 404 from a stray GET to a POST-only admin
 * action — and then serve that stale 404 to real requests.
 */
class NoStore
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        // LiteSpeed-specific opt-out of its cache engine.
        $response->headers->set('X-LiteSpeed-Cache-Control', 'no-cache');

        return $response;
    }
}
