<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\SupportChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * On a client install: the logged-in user, signed with this install's key,
 * for the app to hand to castlitpos.com when it opens a support chat. The
 * master checks the signature, so a support thread is known to come from
 * this shop rather than from someone typing its name.
 */
class SupportIdentityController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var Tenant|null $tenant */
        $tenant = $request->attributes->get('api_tenant');
        $user = $request->user();

        return response()->json(['ok' => true] + SupportChat::signIdentity(
            (string) ($tenant?->name ?? config('app.name')),
            $user?->name,
            $user?->email,
        ));
    }
}
