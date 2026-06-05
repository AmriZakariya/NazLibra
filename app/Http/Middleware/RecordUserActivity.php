<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class RecordUserActivity
{
    private const RECORDED_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user();
        $tenantId = $this->tenantId($request, $actor);
        $response = $next($request);

        if ($this->shouldRecord($request, $response)) {
            $this->record($request, $response, $tenantId, $actor?->id);
        }

        return $response;
    }

    private function shouldRecord(Request $request, Response $response): bool
    {
        return in_array($request->method(), self::RECORDED_METHODS, true)
            && $response->getStatusCode() < 500
            && ! $request->is('livewire/*');
    }

    private function record(Request $request, Response $response, ?int $tenantId, ?int $userId): void
    {
        try {
            if (! Schema::hasTable('audit_logs')) {
                return;
            }

            $subject = $this->subject($request);

            DB::table('audit_logs')->insert([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'action' => $request->route()?->getName() ?: $request->method().' '.$request->path(),
                'subject_type' => $subject['type'],
                'subject_id' => $subject['id'],
                'properties' => json_encode([
                    'method' => $request->method(),
                    'url' => $request->fullUrl(),
                    'path' => $request->path(),
                    'route' => $request->route()?->getName(),
                    'status_code' => $response->getStatusCode(),
                    'ip' => $request->ip(),
                    'user_agent' => str($request->userAgent() ?? '')->limit(240)->value(),
                    'payload' => $this->sanitizedPayload($request),
                    'route_parameters' => $this->routeParameters($request),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable) {
            report_if(app()->hasDebugModeEnabled(), new \RuntimeException('Unable to record user activity.'));
        }
    }

    private function tenantId(Request $request, mixed $actor = null): ?int
    {
        if ($actor?->current_tenant_id) {
            return (int) $actor->current_tenant_id;
        }

        if ($request->user()?->current_tenant_id) {
            return (int) $request->user()->current_tenant_id;
        }

        return Tenant::query()->value('id');
    }

    private function subject(Request $request): array
    {
        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof Model) {
                return [
                    'type' => $parameter::class,
                    'id' => $parameter->getKey(),
                ];
            }
        }

        return ['type' => null, 'id' => null];
    }

    private function routeParameters(Request $request): array
    {
        return collect($request->route()?->parameters() ?? [])
            ->mapWithKeys(function ($value, string $key): array {
                if ($value instanceof Model) {
                    return [$key => [
                        'type' => $value::class,
                        'id' => $value->getKey(),
                    ]];
                }

                return [$key => $value];
            })
            ->all();
    }

    private function sanitizedPayload(Request $request): array
    {
        return collect($request->except([
            '_token',
            '_method',
            'password',
            'password_confirmation',
            'current_password',
            'token',
            'api_key',
            'secret',
        ]))
            ->reject(fn ($value) => $value instanceof \Illuminate\Http\UploadedFile)
            ->map(fn ($value) => is_string($value) ? str($value)->limit(500)->value() : $value)
            ->all();
    }
}
