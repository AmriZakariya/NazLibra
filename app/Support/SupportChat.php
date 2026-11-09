<?php

namespace App\Support;

use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\TenantInstall;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Contacter le support": the rules both sides follow — the app and the
 * website on one side, the platform admin on the other.
 *
 * A conversation is reached with its public id and a secret the device
 * keeps; only the secret's hash is stored. Attachments sit on the private
 * disk and are only ever streamed through a checked route.
 */
class SupportChat
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** A voice note longer than this is a phone call. */
    public const MAX_AUDIO_SECONDS = 180;

    /** mime => kind. Anything else is refused. */
    private const MIMES = [
        'image/jpeg' => 'image', 'image/png' => 'image', 'image/webp' => 'image', 'image/gif' => 'image',
        'image/heic' => 'image', 'image/heif' => 'image',
        'audio/mp4' => 'audio', 'audio/x-m4a' => 'audio', 'audio/m4a' => 'audio', 'audio/aac' => 'audio',
        'audio/mpeg' => 'audio', 'audio/ogg' => 'audio', 'audio/webm' => 'audio', 'audio/wav' => 'audio',
        'audio/x-wav' => 'audio', 'audio/3gpp' => 'audio',
        'application/pdf' => 'file', 'text/plain' => 'file', 'text/csv' => 'file',
        'application/msword' => 'file', 'application/vnd.ms-excel' => 'file',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'file',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'file',
        'application/zip' => 'file', 'application/x-zip-compressed' => 'file',
    ];

    /** A recording's container is often sniffed as video: it is still a voice note when the sender says so. */
    private const AUDIO_CONTAINERS = ['video/mp4', 'video/webm', 'video/3gpp', 'application/ogg'];

    /**
     * @param  array{name: string, phone?: ?string, email?: ?string, user_name?: ?string, channel: string, platform?: ?string, app_version?: ?string, locale?: ?string}  $who
     * @return array{0: SupportConversation, 1: string}  the conversation and its secret
     */
    public static function start(array $who, ?TenantInstall $install = null): array
    {
        $secret = Str::random(48);
        $conversation = SupportConversation::create([
            'public_id' => (string) Str::uuid(),
            'token_hash' => hash('sha256', $secret),
            'tenant_install_id' => $install?->id,
            'name' => $who['name'],
            'phone' => $who['phone'] ?? null,
            'email' => $who['email'] ?? null,
            'user_name' => $who['user_name'] ?? null,
            'channel' => $who['channel'],
            'platform' => $who['platform'] ?? null,
            'app_version' => $who['app_version'] ?? null,
            'locale' => $who['locale'] ?? null,
            'status' => 'open',
        ]);

        return [$conversation, $secret];
    }

    /**
     * The conversation for [$publicId] when [$secret] opens it: the secret of
     * the device that started it, or the shop key any of the shop's logged-in
     * devices is given by [join]. Null otherwise.
     */
    public static function find(string $publicId, ?string $secret): ?SupportConversation
    {
        if (! $secret || ! Str::isUuid($publicId)) {
            return null;
        }
        $conversation = SupportConversation::where('public_id', $publicId)->first();
        if (! $conversation) {
            return null;
        }

        $ownSecret = hash_equals($conversation->token_hash, hash('sha256', $secret));
        $shopKey = $conversation->tenant_install_id !== null
            && hash_equals(self::shopKey($conversation), $secret);

        return $ownSecret || $shopKey ? $conversation : null;
    }

    /**
     * What opens a shop's conversation on every one of its logged-in devices.
     * Derived, never stored: only [join] hands it out, after the shop's own
     * server has vouched for the device.
     */
    public static function shopKey(SupportConversation $conversation): string
    {
        return hash_hmac('sha256', 'support-shop:'.$conversation->public_id.':'.$conversation->tenant_install_id, (string) config('app.key'));
    }

    /** The shop's conversation: the one most recently written in. */
    public static function shopThread(TenantInstall $install): ?SupportConversation
    {
        return SupportConversation::where('tenant_install_id', $install->id)
            ->orderByRaw('COALESCE(last_message_at, created_at) DESC')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The team writes to a shop first: into its conversation, or a new one
     * that its devices will join.
     */
    public static function writeToShop(TenantInstall $install, string $body, ?string $author): SupportMessage
    {
        $conversation = self::shopThread($install);
        if (! $conversation) {
            [$conversation] = self::start([
                'name' => $install->subscription?->business_name ?: $install->subdomain,
                'email' => $install->owner_email ?: $install->subscription?->email,
                'phone' => $install->subscription?->phone,
                'channel' => 'support',
            ], $install);
        }

        $message = self::post($conversation, 'support', $body, null, null, $author);
        // The team wrote it: nothing of theirs is left unread.
        self::markRead($conversation, 'support');

        return $message;
    }

    public static function post(
        SupportConversation $conversation,
        string $sender,
        ?string $body,
        ?UploadedFile $file = null,
        ?int $duration = null,
        ?string $author = null,
        bool $voice = false,
    ): SupportMessage {
        $body = $body !== null ? trim($body) : null;
        if (($body === null || $body === '') && ! $file) {
            throw ValidationException::withMessages(['body' => __('castlit.support_empty')]);
        }

        $attachment = $file ? self::store($conversation, $file, $voice) : [];
        if (($attachment['attachment_kind'] ?? null) === 'audio' && $duration !== null && $duration > self::MAX_AUDIO_SECONDS) {
            Storage::disk('local')->delete($attachment['attachment_path']);
            throw ValidationException::withMessages(['attachment' => __('castlit.support_audio_too_long')]);
        }

        $message = $conversation->messages()->create([
            'sender' => $sender,
            'author_name' => $author,
            'body' => $body ?: null,
            'duration_seconds' => ($attachment['attachment_kind'] ?? null) === 'audio' ? $duration : null,
        ] + $attachment);

        // A client writing again reopens a closed thread.
        $conversation->forceFill([
            'last_message_at' => $message->created_at,
            'last_message_preview' => Str::limit($body ?: self::attachmentLabel($message), 180),
            'status' => $sender === 'client' ? 'open' : $conversation->status,
        ])->save();

        return $message;
    }

    /** [$reader] has seen everything the other side wrote. */
    public static function markRead(SupportConversation $conversation, string $reader): void
    {
        $conversation->messages()
            ->where('sender', $reader === 'support' ? 'client' : 'support')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /** Support wants to come back to it: the client's last message counts as new again. */
    public static function markUnread(SupportConversation $conversation): void
    {
        $conversation->messages()->where('sender', 'client')->latest('id')->limit(1)->update(['read_at' => null]);
    }

    /** Conversations with at least one client message support has not read. */
    public static function unreadConversations(): int
    {
        return SupportConversation::whereHas('messages', fn ($q) => $q->where('sender', 'client')->whereNull('read_at'))->count();
    }

    /** @return array<string, mixed> */
    public static function toApi(SupportMessage $m, ?string $attachmentUrl): array
    {
        return [
            'id' => $m->id,
            'sender' => $m->sender,
            'author_name' => $m->author_name,
            'body' => $m->body,
            'attachment' => $m->attachment_kind ? [
                'kind' => $m->attachment_kind,
                'name' => $m->attachment_name,
                'mime' => $m->attachment_mime,
                'size' => $m->attachment_size,
                'duration_seconds' => $m->duration_seconds,
                'url' => $attachmentUrl,
            ] : null,
            'read' => $m->read_at !== null,
            'created_at' => $m->created_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    public static function attachmentLabel(SupportMessage $m): string
    {
        return match ($m->attachment_kind) {
            'image' => '📷 '.__('castlit.support_photo'),
            'audio' => '🎤 '.__('castlit.support_voice'),
            'file' => '📎 '.($m->attachment_name ?: __('castlit.support_file')),
            default => '',
        };
    }

    /** @return array<string, mixed> */
    private static function store(SupportConversation $conversation, UploadedFile $file, bool $voice): array
    {
        if (! $file->isValid() || $file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages(['attachment' => __('castlit.support_file_too_big')]);
        }
        $mime = strtolower((string) $file->getMimeType());
        $kind = self::MIMES[$mime] ?? ($voice && in_array($mime, self::AUDIO_CONTAINERS, true) ? 'audio' : null);
        if ($kind === null) {
            throw ValidationException::withMessages(['attachment' => __('castlit.support_file_type')]);
        }
        if ($voice && $kind !== 'audio') {
            throw ValidationException::withMessages(['attachment' => __('castlit.support_file_type')]);
        }

        $extension = strtolower((string) ($file->guessExtension() ?: $file->getClientOriginalExtension()));
        $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?: 'bin';
        $path = $file->storeAs('support/'.$conversation->public_id, Str::uuid().'.'.$extension, 'local');
        $name = Str::limit(preg_replace('/[^\pL\pN ._()-]/u', '_', $file->getClientOriginalName()) ?: 'fichier.'.$extension, 180, '');

        return [
            'attachment_path' => $path,
            'attachment_name' => $name,
            'attachment_mime' => $kind === 'audio' && str_starts_with($mime, 'video/') ? 'audio/'.substr($mime, 6) : $mime,
            'attachment_size' => $file->getSize(),
            'attachment_kind' => $kind,
        ];
    }

    // ── Who is writing: signed by the client's own server ─────────────────────

    /**
     * On a client install: who the logged-in user is, signed with this
     * install's APP_KEY, which the master can read and check.
     *
     * @return array{subdomain: string, payload: string, signature: string}
     */
    public static function signIdentity(string $shop, ?string $userName, ?string $email): array
    {
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $subdomain = explode('.', $host)[0];
        $payload = base64_encode((string) json_encode([
            'sub' => $subdomain, 'shop' => $shop, 'user' => $userName, 'email' => $email, 'ts' => time(),
        ]));

        return [
            'subdomain' => $subdomain,
            'payload' => $payload,
            'signature' => hash_hmac('sha256', $payload, (string) config('app.key')),
        ];
    }

    /**
     * On the master: the install and the person a signed identity names, or
     * null when the signature is not that install's or is over a day old.
     *
     * @return array{0: TenantInstall, 1: array<string, mixed>}|null
     */
    public static function verifyIdentity(string $subdomain, string $payload, string $signature): ?array
    {
        $install = TenantInstall::where('subdomain', strtolower($subdomain))->first();
        $key = $install ? self::installKey($install) : null;
        if (! $key || ! hash_equals(hash_hmac('sha256', $payload, $key), $signature)) {
            return null;
        }
        $data = json_decode((string) base64_decode($payload, true), true);
        if (! is_array($data) || ($data['sub'] ?? null) !== $install->subdomain || abs(time() - (int) ($data['ts'] ?? 0)) > 86400) {
            return null;
        }

        return [$install, $data];
    }

    /** Replaced in tests: there is no client folder to read the key from. */
    public static ?\Closure $keyResolver = null;

    /** A client's APP_KEY, read from its .env — only inside the hosting folder clients live in. */
    private static function installKey(TenantInstall $install): ?string
    {
        if (self::$keyResolver) {
            return (self::$keyResolver)($install);
        }
        $base = realpath((string) config('castlit.provision.public_html'));
        $docroot = $install->docroot ?: ($base ? $base.'/'.$install->domain : null);
        $root = $docroot ? realpath($docroot) : false;
        if (! $base || ! $root || ! str_starts_with($root.'/', rtrim($base, '/').'/') || ! is_readable($root.'/.env')) {
            return null;
        }
        foreach (file($root.'/.env', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_starts_with($line, 'APP_KEY=')) {
                return trim(substr($line, 8), " \t\"'\r") ?: null;
            }
        }

        return null;
    }
}
