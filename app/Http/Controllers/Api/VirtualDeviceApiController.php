<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VirtualDevice;
use App\Models\VirtualDeviceSession;
use App\Services\VirtualDeviceSessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Terminal (virtual device) selection for the POS app.
 *
 * A terminal is held by one installation at a time. The claim is released only
 * when that device logs out or an admin frees it from the back office — never
 * by a lapsed heartbeat — so two tablets can never both be ringing up sales on
 * the same terminal.
 */
class VirtualDeviceApiController extends Controller
{
    public function __construct(private readonly VirtualDeviceSessions $sessions)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('api_tenant');

        if (! (bool) data_get($tenant->settings, 'features.virtual_devices', false)) {
            return response()->json(['ok' => true, 'devices' => []]);
        }

        $devices = VirtualDevice::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->where(function ($query) use ($request): void {
                $query->whereNull('location_id')
                    ->orWhere('location_id', $request->attributes->get('api_location_id'));
            })
            ->orderBy('name')
            ->get(['id', 'location_id', 'name', 'code', 'type', 'description']);

        $holders = $this->sessions->holders($tenant);
        $installId = (string) $request->header('X-Castlit-Install-Id', '');

        $payload = $devices->map(function (VirtualDevice $device) use ($holders, $installId) {
            $holder = $holders[$device->id] ?? null;
            $heldByThisDevice = $holder !== null
                && $installId !== ''
                && data_get($holder->metadata, 'install_id') === $installId;

            return [
                ...$device->only(['id', 'location_id', 'name', 'code', 'type', 'description']),
                // The app greys out anything occupied by somebody else, and
                // still offers the terminal this installation already holds.
                'is_occupied' => $holder !== null && ! $heldByThisDevice,
                'held_by_this_device' => $heldByThisDevice,
                'holder' => $this->sessions->describeHolder($holder),
            ];
        });

        return response()->json(['ok' => true, 'devices' => $payload]);
    }

    public function connect(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('api_tenant');

        $data = $request->validate([
            'virtual_device_id' => [
                'required',
                'integer',
                Rule::exists('virtual_devices', 'id')->where('tenant_id', $tenant->id),
            ],
            'install_id' => ['required', 'string', 'max:100'],
            'device_label' => ['nullable', 'string', 'max:120'],
            'app_version' => ['nullable', 'string', 'max:40'],
        ]);

        $device = VirtualDevice::where('tenant_id', $tenant->id)
            ->findOrFail($data['virtual_device_id']);

        try {
            $session = $this->sessions->claim(
                $tenant,
                (int) $request->user()->id,
                $device,
                [
                    'install_id' => $data['install_id'],
                    'device_label' => $data['device_label'] ?? null,
                    'app_version' => $data['app_version'] ?? null,
                    'platform' => 'mobile',
                    'user_agent' => $request->userAgent(),
                    'ip_address' => $request->ip(),
                    'source' => 'mobile',
                ],
            );
        } catch (RuntimeException $e) {
            // 409: the terminal is taken. The app shows the message as-is,
            // which names the holder so staff know who to ask.
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
                'holder' => $this->sessions->describeHolder(
                    $this->sessions->holderOf($tenant, (int) $device->id)
                ),
            ], 409);
        }

        return response()->json([
            'ok' => true,
            'session_token' => $session->connection_token,
            'device' => $device->only(['id', 'name', 'code', 'type', 'location_id']),
        ]);
    }

    public function disconnect(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('api_tenant');

        $data = $request->validate([
            'install_id' => ['required', 'string', 'max:100'],
        ]);

        $session = $this->sessions->existingFor(
            $tenant,
            (int) $request->user()->id,
            $data['install_id'],
        );

        if ($session) {
            $this->sessions->release($session, 'device_logout');
        }

        // Idempotent: logging out twice, or after an admin already freed the
        // terminal, must not fail the app's logout flow.
        return response()->json(['ok' => true, 'released' => $session !== null]);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('api_tenant');

        $data = $request->validate([
            'install_id' => ['required', 'string', 'max:100'],
        ]);

        $session = $this->sessions->existingFor(
            $tenant,
            (int) $request->user()->id,
            $data['install_id'],
        );

        if (! $session) {
            // The admin freed this terminal underneath us. The app reacts by
            // sending the user back to terminal selection.
            return response()->json(['ok' => false, 'reason' => 'released'], 409);
        }

        $this->sessions->touch($session);

        return response()->json([
            'ok' => true,
            'virtual_device_id' => (string) $session->virtual_device_id,
        ]);
    }
}
