<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSessionUnlocked
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() || ! (bool) $request->session()->get('pos_session_locked', false)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Session verrouillée.'], 423);
        }

        return redirect()->route('session.locked');
    }
}
