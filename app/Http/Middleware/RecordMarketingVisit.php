<?php

namespace App\Http\Middleware;

use App\Support\MarketingVisits;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Counts a visit to the castlitpos.com landing page (see MarketingVisits). */
class RecordMarketingVisit
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            // Before the page, so the page can carry the visit's token.
            $visit = MarketingVisits::open($request);
            if ($visit) {
                $request->attributes->set('marketing_visit', $visit);
            }
        } catch (\Throwable $e) {
            report($e); // counting must never break the page
        }

        return $next($request);
    }
}
