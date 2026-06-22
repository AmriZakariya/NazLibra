<?php

namespace App\Support;

use App\Models\Location;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VirtualDevice;

final readonly class ApiActionContext
{
    public function __construct(
        public Tenant $tenant,
        public User $actor,
        public ?Location $location,
        public ?VirtualDevice $virtualDevice,
    ) {}

    public function attribution(): array
    {
        return [
            'user_id' => $this->actor->id,
            'actor_name_snapshot' => $this->actor->name,
            'virtual_device_id' => $this->virtualDevice?->id,
            'terminal_name_snapshot' => $this->virtualDevice?->name,
        ];
    }
}
