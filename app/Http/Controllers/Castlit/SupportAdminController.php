<?php

namespace App\Http\Controllers\Castlit;

use App\Http\Controllers\Api\SupportController;
use App\Http\Controllers\Controller;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Support\SupportChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The support team's side: every conversation, unread first, and the thread
 * to answer with text, files, photos and voice notes. Opening a thread does
 * not mark it read; the team says so itself, or by replying.
 */
class SupportAdminController extends Controller
{
    public const FILTERS = ['unread' => 'Non lus', 'open' => 'Ouvertes', 'closed' => 'Fermées', 'all' => 'Toutes'];

    public function index(Request $request): View
    {
        $filter = array_key_exists((string) $request->query('filtre'), self::FILTERS) ? (string) $request->query('filtre') : 'open';
        $search = trim((string) $request->query('q', ''));

        $conversations = SupportConversation::query()
            ->with('install:id,subdomain,domain')
            ->withCount(['messages as unread_count' => fn ($q) => $q->where('sender', 'client')->whereNull('read_at')])
            ->when($filter === 'unread', fn ($q) => $q->whereHas('messages', fn ($m) => $m->where('sender', 'client')->whereNull('read_at')))
            ->when(in_array($filter, ['open', 'closed'], true), fn ($q) => $q->where('status', $filter))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$search}%")
                ->orWhere('user_name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhereHas('install', fn ($i) => $i->where('subdomain', 'like', "%{$search}%"))))
            ->orderByDesc('unread_count')
            ->orderByDesc('last_message_at')
            ->limit(200)
            ->get();

        $selected = $request->query('c')
            ? SupportConversation::with('install')->find((int) $request->query('c'))
            : null;

        return view('castlit.admin.support', [
            'conversations' => $conversations,
            'selected' => $selected,
            'messages' => $selected?->messages()->orderBy('id')->get() ?? collect(),
            'filter' => $filter,
            'search' => $search,
            'unreadTotal' => SupportChat::unreadConversations(),
        ]);
    }

    public function reply(Request $request, SupportConversation $conversation): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:4000'],
            'attachment' => ['nullable', 'file', 'max:'.(SupportChat::MAX_BYTES / 1024)],
            'voice' => ['nullable', 'boolean'],
            'duration' => ['nullable', 'integer', 'min:0', 'max:3600'],
        ]);
        $message = SupportChat::post(
            $conversation, 'support', $data['body'] ?? null, $request->file('attachment'),
            isset($data['duration']) ? (int) $data['duration'] : null,
            $request->user()?->name, $request->boolean('voice'),
        );
        // Answering is reading.
        SupportChat::markRead($conversation, 'support');

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => $this->toApi($conversation, $message)]);
        }

        return redirect()->route('castlit.admin.support', ['c' => $conversation->id, 'filtre' => $request->input('filtre')]);
    }

    /** read=1 marks everything read; read=0 brings the last client message back as new. */
    public function read(Request $request, SupportConversation $conversation): RedirectResponse
    {
        $request->boolean('read')
            ? SupportChat::markRead($conversation, 'support')
            : SupportChat::markUnread($conversation);

        return back()->with('success', $request->boolean('read') ? 'Conversation marquée comme lue.' : 'Conversation marquée comme non lue.');
    }

    public function status(Request $request, SupportConversation $conversation): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'in:open,closed']]);
        $conversation->update(['status' => $data['status']]);

        return back()->with('success', $data['status'] === 'closed' ? 'Conversation fermée.' : 'Conversation rouverte.');
    }

    /** New messages in the open thread, and the unread total for the badge. */
    public function poll(Request $request, SupportConversation $conversation): JsonResponse
    {
        $after = (int) $request->query('after', 0);

        return response()->json([
            'messages' => $conversation->messages()->where('id', '>', $after)->orderBy('id')->get()
                ->map(fn (SupportMessage $m) => $this->toApi($conversation, $m))->values(),
            'read_up_to' => (int) $conversation->messages()->where('sender', 'support')->whereNotNull('read_at')->max('id'),
            'unread_total' => SupportChat::unreadConversations(),
        ]);
    }

    public function attachment(SupportConversation $conversation, int $message): StreamedResponse
    {
        return SupportController::stream(
            $conversation->messages()->whereKey($message)->whereNotNull('attachment_path')->firstOrFail()
        );
    }

    /** @return array<string, mixed> */
    private function toApi(SupportConversation $c, SupportMessage $m): array
    {
        return SupportChat::toApi($m, $m->attachment_kind
            ? route('castlit.admin.support.attachment', ['conversation' => $c->id, 'message' => $m->id])
            : null);
    }
}
