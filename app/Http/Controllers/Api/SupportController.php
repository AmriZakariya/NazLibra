<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Support\Phone;
use App\Support\SupportChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The client's side of "Contacter le support", on castlitpos.com: the app
 * (before and after login) and the website's chat bubble.
 *
 * No account: a conversation is reached with its id and the secret handed
 * out when it was started (header X-Support-Token, or ?token= for an <img>).
 */
class SupportController extends Controller
{
    /** POST /api/v1/support/conversations */
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^\\+?[0-9\\s().-]{8,24}$/'],
            'email' => ['nullable', 'email', 'max:160'],
            'channel' => ['required', 'in:app,web'],
            'platform' => ['nullable', 'string', 'max:20'],
            'app_version' => ['nullable', 'string', 'max:20'],
            'locale' => ['nullable', 'string', 'max:8'],
            // A logged-in app: who it is, signed by the client's own server.
            'identity.subdomain' => ['nullable', 'string', 'max:63'],
            'identity.payload' => ['nullable', 'string', 'max:2000'],
            'identity.signature' => ['nullable', 'string', 'max:128'],
        ]);

        [$install, $who] = $this->identity($data['identity'] ?? null);
        if (! $install && empty($data['phone']) && empty($data['email'])) {
            // A visitor must leave a way to be reached.
            return response()->json(['ok' => false, 'errors' => ['phone' => [__('castlit.support_phone_required')]]], 422);
        }

        [$conversation, $secret] = SupportChat::start([
            'name' => $who['shop'] ?? $data['name'],
            'user_name' => $who['user'] ?? ($install ? $data['name'] : null),
            'phone' => ! empty($data['phone']) ? Phone::internationalize($data['phone'], \App\Support\GeoCountry::guess($request)) : null,
            'email' => $data['email'] ?? ($who['email'] ?? null),
            'channel' => $data['channel'],
            'platform' => $data['platform'] ?? null,
            'app_version' => $data['app_version'] ?? null,
            'locale' => $data['locale'] ?? null,
        ], $install);

        return response()->json([
            'ok' => true,
            'conversation_id' => $conversation->public_id,
            'token' => $secret,
            'client' => $install?->subdomain,
        ], 201);
    }

    /**
     * POST /api/v1/support/conversations/join — a logged-in device of a shop
     * (its identity signed by the shop's server) gets the shop's conversation,
     * so a message from the team reaches whichever device opens next.
     */
    public function join(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identity.subdomain' => ['required', 'string', 'max:63'],
            'identity.payload' => ['required', 'string', 'max:2000'],
            'identity.signature' => ['required', 'string', 'max:128'],
        ]);
        [$install] = $this->identity($data['identity']);
        if (! $install) {
            return response()->json(['ok' => false, 'message' => 'Identité invalide.'], 403);
        }

        $conversation = SupportChat::shopThread($install);

        return response()->json($conversation ? [
            'ok' => true,
            'conversation_id' => $conversation->public_id,
            'token' => SupportChat::shopKey($conversation),
            'unread' => $conversation->unreadFor('client'),
        ] : ['ok' => true, 'conversation_id' => null]);
    }

    /** POST /api/v1/support/conversations/{id}/identify — a visitor who has since logged in. */
    public function identify(Request $request, string $id): JsonResponse
    {
        $conversation = $this->conversation($request, $id);
        $data = $request->validate([
            'identity.subdomain' => ['required', 'string', 'max:63'],
            'identity.payload' => ['required', 'string', 'max:2000'],
            'identity.signature' => ['required', 'string', 'max:128'],
        ]);
        [$install, $who] = $this->identity($data['identity']);
        abort_unless($install !== null, 422, 'Identité invalide.');

        $conversation->forceFill([
            'tenant_install_id' => $install->id,
            'user_name' => $who['user'] ?? $conversation->user_name,
            'email' => $conversation->email ?: ($who['email'] ?? null),
        ])->save();

        return response()->json(['ok' => true, 'client' => $install->subdomain]);
    }

    /** GET /api/v1/support/conversations/{id}?after=<message id> */
    public function show(Request $request, string $id): JsonResponse
    {
        $conversation = $this->conversation($request, $id);
        $after = (int) $request->query('after', 0);
        $messages = $conversation->messages()
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit(200)
            ->get();

        return response()->json([
            'ok' => true,
            'status' => $conversation->status,
            'unread' => $conversation->unreadFor('client'),
            // What the client wrote that support has read: the ticks.
            'read_up_to' => (int) $conversation->messages()->where('sender', 'client')->whereNotNull('read_at')->max('id'),
            'messages' => $messages->map(fn (SupportMessage $m) => SupportChat::toApi($m, $this->attachmentUrl($conversation, $m)))->values(),
        ]);
    }

    /** POST /api/v1/support/conversations/{id}/messages — multipart: body, attachment, voice, duration */
    public function post(Request $request, string $id): JsonResponse
    {
        $conversation = $this->conversation($request, $id);
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:4000'],
            'attachment' => ['nullable', 'file', 'max:'.(SupportChat::MAX_BYTES / 1024)],
            'voice' => ['nullable', 'boolean'],
            'duration' => ['nullable', 'integer', 'min:0', 'max:3600'],
        ]);

        $message = SupportChat::post(
            $conversation, 'client', $data['body'] ?? null, $request->file('attachment'),
            isset($data['duration']) ? (int) $data['duration'] : null, null, $request->boolean('voice'),
        );

        return response()->json([
            'ok' => true,
            'message' => SupportChat::toApi($message, $this->attachmentUrl($conversation, $message)),
        ], 201);
    }

    /** POST /api/v1/support/conversations/{id}/read — the client saw support's replies. */
    public function read(Request $request, string $id): JsonResponse
    {
        SupportChat::markRead($this->conversation($request, $id), 'client');

        return response()->json(['ok' => true]);
    }

    /** GET /api/v1/support/conversations/{id}/messages/{message}/attachment */
    public function attachment(Request $request, string $id, int $message): StreamedResponse
    {
        $conversation = $this->conversation($request, $id);
        $m = $conversation->messages()->whereKey($message)->whereNotNull('attachment_path')->firstOrFail();

        return self::stream($m);
    }

    /** The file, inline for images and voice notes, as a download otherwise. */
    public static function stream(SupportMessage $m): StreamedResponse
    {
        $disk = Storage::disk('local');
        abort_unless($disk->exists($m->attachment_path), 404);
        $inline = in_array($m->attachment_kind, ['image', 'audio'], true);

        return $disk->response($m->attachment_path, $m->attachment_name, [
            'Content-Type' => $m->attachment_mime,
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ], $inline ? 'inline' : 'attachment');
    }

    private function conversation(Request $request, string $id): SupportConversation
    {
        $secret = $request->header('X-Support-Token') ?: $request->query('token');
        $conversation = SupportChat::find($id, is_string($secret) ? $secret : null);
        abort_unless($conversation !== null, 404);

        return $conversation;
    }

    private function attachmentUrl(SupportConversation $c, SupportMessage $m): ?string
    {
        return $m->attachment_kind
            ? route('api.support.attachment', ['id' => $c->public_id, 'message' => $m->id])
            : null;
    }

    /** @return array{0: ?\App\Models\TenantInstall, 1: array<string, mixed>} */
    private function identity(?array $identity): array
    {
        if (! $identity || empty($identity['subdomain']) || empty($identity['payload']) || empty($identity['signature'])) {
            return [null, []];
        }

        return SupportChat::verifyIdentity($identity['subdomain'], $identity['payload'], $identity['signature']) ?? [null, []];
    }
}
