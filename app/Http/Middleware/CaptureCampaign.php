<?php

namespace App\Http\Middleware;

use App\Support\CampaignAttribution;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Remembers which ad or site brought the visitor (see CampaignAttribution). */
class CaptureCampaign
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET')) {
            CampaignAttribution::capture($request);
        }

        return $next($request);
    }
}
