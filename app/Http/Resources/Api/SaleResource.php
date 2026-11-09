<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ...$this->resource->attributesToArray(),
            'created_by' => [
                'id' => $this->user_id,
                'name' => $this->actor_name_snapshot ?: ($this->resource->relationLoaded('user') ? $this->user?->name : null),
            ],
            'virtual_device' => $this->virtual_device_id ? [
                'id' => $this->virtual_device_id,
                'name' => $this->terminal_name_snapshot ?: ($this->resource->relationLoaded('virtualDevice') ? $this->virtualDevice?->name : null),
            ] : null,
            'location' => $this->location_id ? [
                'id' => $this->location_id,
                'name' => $this->resource->relationLoaded('location') ? $this->location?->name : null,
            ] : null,
            'contact' => $this->whenLoaded('contact'),
            'items' => $this->whenLoaded('items'),
            // Where the ticket was rung up, for the till's sale detail.
            'origin' => $this->resource->origin(),
            'payments' => $this->whenLoaded('payments'),
            'returns' => $this->whenLoaded('returns', fn () => $this->returns->map(fn ($r) => [
                'id' => $r->id,
                'number' => $r->number,
                'status' => $r->status,
                'refund_method' => $r->refund_method,
                'refund_scope' => $r->refund_scope,
                'total_amount' => (float) $r->total_amount,
                'lines' => $r->lines ?? [],
                'reason' => $r->reason,
                'stock_disposition' => $r->stock_disposition,
                'returned_at' => $r->returned_at?->utc()->format('Y-m-d\TH:i:s\Z'),
                'idempotency_key' => $r->idempotency_key,
                'by' => $r->actor_name_snapshot,
                'terminal' => $r->terminal_name_snapshot,
            ])->values()),
            'refunded_amount' => $this->whenLoaded('returns', fn () => $this->resource->refundedAmount()),
            'deleted_at' => $this->deleted_at,
        ];
    }
}
