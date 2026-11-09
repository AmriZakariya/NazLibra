<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Runs INSIDE a client space (called by the master every 15 minutes): prints,
 * as one JSON line, when and how the shop was last active, so castlit-admin
 * can tell who uses the product and who has gone quiet.
 *
 * Four signals, each with who and when: the app (a Sanctum token used), the
 * web back office (a session), the last sale, the last back-office change.
 * The platform's own logins (support) are left out with --ignore: our visits
 * are not the client's activity.
 *
 * Plain queries rather than models: no tenant scope applies in a console
 * process, and a space one version behind may lack a column a model expects.
 */
class ActivityCommand extends Command
{
    protected $signature = 'castlit:activity {--ignore=* : Logins (emails) whose actions do not count}';

    protected $description = 'Print this space\'s latest activity as JSON (called by the master).';

    /** @var array<int, string> user id => name */
    private array $names = [];

    public function handle(): int
    {
        $ignored = $this->ignoredUserIds();

        $signals = array_filter([
            'app' => $this->app($ignored),
            'web' => $this->web($ignored),
            'sale' => $this->sale($ignored),
            'change' => $this->change($ignored),
        ]);

        $last = null;
        foreach ($signals as $kind => $signal) {
            if ($last === null || $signal['at'] > $last['at']) {
                $last = ['kind' => $kind] + $signal;
            }
        }

        $this->line(json_encode([
            'status' => 'success',
            'at' => $last['at'] ?? null,
            'last' => $last,
        ] + $signals + $this->saleCounts($ignored), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /** @return array<int, int> */
    private function ignoredUserIds(): array
    {
        $emails = array_values(array_filter(array_map(
            fn ($e) => strtolower(trim((string) $e)),
            (array) $this->option('ignore'),
        )));

        return $emails === [] ? [] : DB::table('users')
            ->whereIn(DB::raw('LOWER(email)'), $emails)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @param array<int, int> $ignored */
    private function app(array $ignored): ?array
    {
        if (! Schema::hasTable('personal_access_tokens')) {
            return null;
        }

        $row = DB::table('personal_access_tokens')
            ->where('tokenable_type', \App\Models\User::class)
            ->whereNotNull('last_used_at')
            ->when($ignored, fn ($q) => $q->whereNotIn('tokenable_id', $ignored))
            ->orderByDesc('last_used_at')
            ->first(['tokenable_id', 'name', 'last_used_at']);

        return $row ? [
            'at' => $this->iso($row->last_used_at),
            'user' => $this->name((int) $row->tokenable_id),
            'device' => (string) $row->name,
        ] : null;
    }

    /** @param array<int, int> $ignored */
    private function web(array $ignored): ?array
    {
        if (! Schema::hasTable('sessions')) {
            return null;
        }

        $row = DB::table('sessions')
            ->whereNotNull('user_id')
            ->when($ignored, fn ($q) => $q->whereNotIn('user_id', $ignored))
            ->orderByDesc('last_activity')
            ->first(['user_id', 'last_activity']);

        return $row ? [
            'at' => Carbon::createFromTimestamp((int) $row->last_activity)->utc()->format('Y-m-d\TH:i:s\Z'),
            'user' => $this->name((int) $row->user_id),
        ] : null;
    }

    /** @param array<int, int> $ignored */
    private function sale(array $ignored): ?array
    {
        if (! Schema::hasTable('sales')) {
            return null;
        }

        $row = $this->sales($ignored)
            ->orderByRaw('COALESCE(sold_at, created_at) DESC')
            ->first(['number', 'total_amount', 'user_id', 'actor_name_snapshot', 'sold_at', 'created_at']);

        return $row ? [
            'at' => $this->iso($row->sold_at ?: $row->created_at),
            'number' => (string) $row->number,
            'total' => (float) $row->total_amount,
            'currency' => (string) (DB::table('tenants')->orderBy('id')->value('currency') ?: ''),
            'user' => $row->actor_name_snapshot ?: ($row->user_id ? $this->name((int) $row->user_id) : null),
        ] : null;
    }

    /** @param array<int, int> $ignored */
    private function change(array $ignored): ?array
    {
        if (! Schema::hasTable('audit_logs')) {
            return null;
        }

        $row = DB::table('audit_logs')
            ->when($ignored, fn ($q) => $q->where(fn ($w) => $w->whereNull('user_id')->orWhereNotIn('user_id', $ignored)))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->first(['user_id', 'action', 'friendly_action', 'subject_reference_snapshot', 'created_at']);

        return $row ? [
            'at' => $this->iso($row->created_at),
            'label' => trim(($row->friendly_action ?: $row->action).' '.($row->subject_reference_snapshot ?? '')),
            'user' => $row->user_id ? $this->name((int) $row->user_id) : null,
        ] : null;
    }

    /**
     * Sales today (in the shop's own timezone) and over the last 7 days.
     *
     * @param array<int, int> $ignored
     * @return array{sales_today: int, sales_7d: int}
     */
    private function saleCounts(array $ignored): array
    {
        if (! Schema::hasTable('sales')) {
            return ['sales_today' => 0, 'sales_7d' => 0];
        }

        $tz = (string) (DB::table('tenants')->orderBy('id')->value('timezone') ?: config('app.timezone'));
        $today = now($tz)->startOfDay()->setTimezone(config('app.timezone'));
        $count = fn (Carbon $from) => $this->sales($ignored)
            ->whereRaw('COALESCE(sold_at, created_at) >= ?', [$from->format('Y-m-d H:i:s')])
            ->count();

        return ['sales_today' => $count($today), 'sales_7d' => $count($today->copy()->subDays(6))];
    }

    /** @param array<int, int> $ignored */
    private function sales(array $ignored): \Illuminate\Database\Query\Builder
    {
        return DB::table('sales')
            ->whereNull('deleted_at')
            ->when($ignored, fn ($q) => $q->where(fn ($w) => $w->whereNull('user_id')->orWhereNotIn('user_id', $ignored)));
    }

    private function name(int $id): ?string
    {
        if (! array_key_exists($id, $this->names)) {
            $this->names[$id] = (string) (DB::table('users')->where('id', $id)->value('name') ?? '');
        }

        return $this->names[$id] ?: null;
    }

    private function iso(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->utc()->format('Y-m-d\TH:i:s\Z') : null;
    }
}
