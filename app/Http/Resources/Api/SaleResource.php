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
            'payments' => $this->whenLoaded('payments'),
            'deleted_at' => $this->deleted_at,
        ];
    }
}
