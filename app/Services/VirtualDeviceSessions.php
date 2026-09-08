<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\VirtualDevice;
use App\Models\VirtualDeviceSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Owns the rule that a terminal is used by one device at a time.
 *
 * Occupancy is decided by `disconnected_at IS NULL` and NOT by the `live()`
 * heartbeat scope: a claim is released only when the device itself logs out or
 * an admin frees it. A tablet that goes silent therefore keeps its terminal —
 * deliberate, so a claim can never lapse quietly and let two devices ring up
 * sales on the same terminal. The cost is that a dead tablet blocks its
 * terminal until someone frees it from the back office.
 */
class VirtualDeviceSessions
{
    /**
     * The holding session for each occupied device, keyed by device id.
     *
     * @return array<int, VirtualDeviceSession>
     */
    public function holders(Tenant $tenant): array
    {
        return VirtualDeviceSession::query()
            ->where('tenant_id', $tenant->id)
            ->whereNull('disconnected_at')
            ->with('user:id,name')
            ->orderByDesc('connected_at')
            ->get()
            ->keyBy('virtual_device_id')
            ->all();
    }

    public function holderOf(Tenant $tenant, int $deviceId): ?VirtualDeviceSession
    {
        return VirtualDeviceSession::query()
            ->where('tenant_id', $tenant->id)
            ->where('virtual_device_id', $deviceId)
            ->whereNull('disconnected_at')
            ->with('user:id,name')
            ->first();
    }

    /**
     * The session this user/installation already holds, if any.
     *
     * Matched on the installation id when given, so relaunching the app
     * reclaims its own terminal instead of being told it is occupied.
     */
    public function existingFor(Tenant $tenant, int $userId, ?string $installId): ?VirtualDeviceSession
    {
        $query = VirtualDeviceSession::query()
            ->where('tenant_id', $tenant->id)
            ->whereNull('disconnected_at')
            ->with('virtualDevice');

        if ($installId !== null && $installId !== '') {
            $byInstall = (clone $query)
                ->where('metadata->install_id', $installId)
                ->first();
            if ($byInstall) {
                return $byInstall;
            }
        }

        return $query->where('user_id', $userId)->first();
    }

    /** The session this installation holds, ignoring which user is signed in. */
    public function existingForInstall(Tenant $tenant, ?string $installId): ?VirtualDeviceSession
    {
        if ($installId === null || $installId === '') {
            return null;
        }

        return VirtualDeviceSession::query()
            ->where('tenant_id', $tenant->id)
            ->whereNull('disconnected_at')
            ->where('metadata->install_id', $installId)
            ->with('virtualDevice')
            ->first();
    }

    /**
     * Claims [device] for this user and installation.
     *
     * Re-claiming a terminal this installation already holds is a no-op that
     * returns the same session, so an app restart is not a conflict.
     *
     * @throws RuntimeException when another installation holds it
     */
    public function claim(
        Tenant $tenant,
        int $userId,
        VirtualDevice $device,
        array $info = [],
    ): VirtualDeviceSession {
        if (! $device->is_active) {
            throw new RuntimeException('Cet appareil est désactivé.');
        }

        return DB::transaction(function () use ($tenant, $userId, $device, $info) {
            // Lock the device row so two tablets claiming at the same moment
            // cannot both pass the occupancy check.
            VirtualDevice::whereKey($device->id)->lockForUpdate()->first();

            $installId = $info['install_id'] ?? null;
            $holder = $this->holderOf($tenant, (int) $device->id);

            if ($holder) {
                // Only the SAME installation may reclaim. Matching on the user
                // instead would let one cashier log in on a second tablet and
                // silently take over the terminal — the exact thing this is
                // meant to stop. A web session (no install_id) therefore also
                // blocks the app until it disconnects or an admin frees it.
                $sameInstall = $installId !== null
                    && $installId !== ''
                    && data_get($holder->metadata, 'install_id') === $installId;

                if ($sameInstall) {
                    $holder->forceFill(['last_seen_at' => now()])->save();

                    return $holder;
                }

                throw new RuntimeException($this->occupiedMessage($holder));
            }

            // One terminal per installation: release whatever else it held, or
            // a tablet that switches terminal would hold two at once.
            $previous = $this->existingForInstall($tenant, $installId);
            if ($previous) {
                $this->release($previous, 'switched_device');
            }

            return VirtualDeviceSession::create([
                'tenant_id' => $tenant->id,
                'virtual_device_id' => $device->id,
                'user_id' => $userId,
                'connection_token' => (string) Str::uuid(),
                'user_agent' => $info['user_agent'] ?? null,
                'platform' => $info['platform'] ?? null,
                'ip_address' => $info['ip_address'] ?? null,
                'metadata' => array_filter([
                    // What identifies the physical tablet, so the back office
                    // can see which device holds which terminal.
                    'install_id' => $installId,
                    'device_label' => $info['device_label'] ?? null,
                    'app_version' => $info['app_version'] ?? null,
                    'source' => $info['source'] ?? 'mobile',
                ], fn ($value) => $value !== null && $value !== ''),
                'connected_at' => now(),
                'last_seen_at' => now(),
            ]);
        });
    }

    public function release(VirtualDeviceSession $session, string $reason): void
    {
        $session->forceFill([
            'disconnected_at' => now(),
            'disconnect_reason' => $reason,
        ])->save();
    }

    /** Frees every claim on a device. Used by the admin "release" action. */
    public function releaseDevice(Tenant $tenant, int $deviceId, string $reason = 'admin_released'): int
    {
        return VirtualDeviceSession::query()
            ->where('tenant_id', $tenant->id)
            ->where('virtual_device_id', $deviceId)
            ->whereNull('disconnected_at')
            ->update([
                'disconnected_at' => now(),
                'disconnect_reason' => $reason,
            ]);
    }

    public function touch(VirtualDeviceSession $session): void
    {
        $session->forceFill(['last_seen_at' => now()])->save();
    }

    /** Names the holder, so the refusal tells staff who to ask. */
    public function occupiedMessage(VirtualDeviceSession $holder): string
    {
        $who = $holder->user?->name ?: 'un autre utilisateur';
        $label = data_get($holder->metadata, 'device_label');

        return $label
            ? "Ce terminal est utilisé par {$who} sur {$label}."
            : "Ce terminal est utilisé par {$who}.";
    }

    /** Shape returned to the app for each device. */
    public function describeHolder(?VirtualDeviceSession $holder): ?array
    {
        if (! $holder) {
            return null;
        }

        return [
            'user_id' => (string) $holder->user_id,
            'user_name' => $holder->user?->name,
            'device_label' => data_get($holder->metadata, 'device_label'),
            'install_id' => data_get($holder->metadata, 'install_id'),
            'connected_at' => $holder->connected_at?->toIso8601String(),
            'last_seen_at' => $holder->last_seen_at?->toIso8601String(),
        ];
    }
}
