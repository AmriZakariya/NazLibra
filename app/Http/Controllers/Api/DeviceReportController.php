<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\DeviceReports;
use App\Support\SupportChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/support/logs — « Envoyer les journaux au support », on
 * castlitpos.com. The shop is known from the identity its server signs; the
 * support conversation, when the device holds one, from its id and secret.
 */
class DeviceReportController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'log' => ['required', 'file', 'max:'.(DeviceReports::MAX_BYTES / 1024)],
            'note' => ['nullable', 'string', 'max:1000'],
            'user_name' => ['nullable', 'string', 'max:120'],
            'platform' => ['nullable', 'string', 'max:20'],
            'device' => ['nullable', 'string', 'max:160'],
            'app_version' => ['nullable', 'string', 'max:20'],
            'identity.subdomain' => ['nullable', 'string', 'max:63'],
            'identity.payload' => ['nullable', 'string', 'max:2000'],
            'identity.signature' => ['nullable', 'string', 'max:128'],
            'conversation_id' => ['nullable', 'string', 'max:40'],
            'conversation_token' => ['nullable', 'string', 'max:80'],
        ]);

        $identity = $data['identity'] ?? null;
        [$install, $who] = ! empty($identity['subdomain']) && ! empty($identity['payload']) && ! empty($identity['signature'])
            ? (SupportChat::verifyIdentity($identity['subdomain'], $identity['payload'], $identity['signature']) ?? [null, []])
            : [null, []];
        $conversation = ! empty($data['conversation_id'])
            ? SupportChat::find($data['conversation_id'], $data['conversation_token'] ?? null)
            : null;

        $report = DeviceReports::store($request->file('log'), [
            'user_name' => $who['user'] ?? ($data['user_name'] ?? null),
            'platform' => $data['platform'] ?? null,
            'device' => $data['device'] ?? null,
            'app_version' => $data['app_version'] ?? null,
            'note' => $data['note'] ?? null,
        ], $install ?? $conversation?->install, $conversation);

        return response()->json(['ok' => true, 'reference' => $report->reference], 201);
    }
}
