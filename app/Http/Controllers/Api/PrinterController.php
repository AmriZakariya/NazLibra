<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Printer;
use App\Models\PrinterGroup;
use App\Models\PrinterGroupCategory;
use App\Models\PrinterGroupPrinter;
use App\Models\Tenant;
use App\Models\VirtualDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PrinterController extends Controller
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Resolve the virtual device ID from the X-Virtual-Device-Id header.
     * Printers are terminal-owned, so every printer endpoint must be scoped.
     */
    private function resolveVirtualDeviceId(Request $request): int
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('api_tenant');
        $header = trim((string) $request->header('X-Virtual-Device-Id', ''));

        if ($header === '' || ! ctype_digit($header)) {
            abort(response()->json([
                'ok' => false,
                'error' => 'virtual_device_required',
                'message' => 'Un terminal valide est requis pour gérer les imprimantes.',
            ], 422));
        }

        $id = (int) $header;

        $exists = VirtualDevice::query()
            ->where('tenant_id', $tenant->id)
            ->whereKey($id)
            ->where('is_active', true)
            ->exists();

        if (! $exists) {
            abort(response()->json([
                'ok' => false,
                'error' => 'invalid_virtual_device',
                'message' => 'Le terminal sélectionné est invalide ou inactif.',
            ], 422));
        }

        return $id;
    }

    /**
     * Build the full config payload for the given tenant + virtual_device_id.
     */
    private function buildConfig(int $tenantId, int $virtualDeviceId): array
    {
        $printerModels = Printer::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('virtual_device_id', $virtualDeviceId)
            ->orderBy('name')
            ->get();

        $printerGroupIds = PrinterGroup::withTrashed()
            ->where('tenant_id', $tenantId)
            ->pluck('id');

        $printerGroups = PrinterGroup::withTrashed()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $printerGroupIds)
            ->orderBy('name')
            ->get()
            ->toArray();

        $printerGroupPrinters = $printerGroupIds->isNotEmpty()
            ? PrinterGroupPrinter::whereIn('group_id', $printerGroupIds)->get()->toArray()
            : [];

        $printerGroupCategories = $printerGroupIds->isNotEmpty()
            ? PrinterGroupCategory::whereIn('group_id', $printerGroupIds)->get()->toArray()
            : [];

        return [
            'printers'                => $printerModels->toArray(),
            'printer_groups'          => $printerGroups,
            'printer_group_printers'  => $printerGroupPrinters,
            'printer_group_categories'=> $printerGroupCategories,
        ];
    }

    // ── Printers CRUD ─────────────────────────────────────────────────────────

    /** GET /api/v1/printers */
    public function index(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant          = $request->attributes->get('api_tenant');
        $virtualDeviceId = $this->resolveVirtualDeviceId($request);

        $config = $this->buildConfig($tenant->id, $virtualDeviceId);

        return response()->json(['ok' => true, ...$config]);
    }

    /** POST /api/v1/printers */
    public function store(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant          = $request->attributes->get('api_tenant');
        $virtualDeviceId = $this->resolveVirtualDeviceId($request);

        $data = $request->validate([
            'id'                     => ['required', 'string', 'size:36'],
            'name'                   => ['required', 'string', 'max:255'],
            'connection_type'        => ['sometimes', Rule::in(['tcp', 'bluetooth', 'usb'])],
            'address'                => ['nullable', 'string', 'max:255'],
            'port'                   => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'paper_width'            => ['sometimes', 'integer', 'min:1', 'max:255'],
            'encoding'               => ['sometimes', 'string', 'max:20'],
            'cut_paper'              => ['sometimes', 'boolean'],
            'copies'                 => ['sometimes', 'integer', 'min:1', 'max:255'],
            'auto_print_on_checkout' => ['sometimes', 'boolean'],
            'group_ids'              => ['sometimes', 'array'],
            'group_ids.*'            => ['string', 'size:36'],
        ]);

        $groupIds = $this->validatedPrinterGroupIds($tenant->id, $data['group_ids'] ?? []);
        unset($data['group_ids']);

        $printer = DB::transaction(function () use ($tenant, $virtualDeviceId, $data, $groupIds): Printer {
            $printer = Printer::withTrashed()->updateOrCreate(
                ['id' => $data['id'], 'tenant_id' => $tenant->id, 'virtual_device_id' => $virtualDeviceId],
                array_merge($data, ['tenant_id' => $tenant->id, 'virtual_device_id' => $virtualDeviceId, 'deleted_at' => null])
            );

            $this->replacePrinterGroups($tenant->id, $printer->id, $groupIds);

            return $printer;
        });

        return response()->json(['ok' => true, 'printer' => $printer], 201);
    }

    /** PUT /api/v1/printers/{id} */
    public function update(Request $request, string $id): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('api_tenant');
        $virtualDeviceId = $this->resolveVirtualDeviceId($request);

        $printer = Printer::withTrashed()
            ->where('tenant_id', $tenant->id)
            ->where('virtual_device_id', $virtualDeviceId)
            ->findOrFail($id);

        $data = $request->validate([
            'name'                   => ['sometimes', 'string', 'max:255'],
            'connection_type'        => ['sometimes', Rule::in(['tcp', 'bluetooth', 'usb'])],
            'address'                => ['nullable', 'string', 'max:255'],
            'port'                   => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'paper_width'            => ['sometimes', 'integer', 'min:1', 'max:255'],
            'encoding'               => ['sometimes', 'string', 'max:20'],
            'cut_paper'              => ['sometimes', 'boolean'],
            'copies'                 => ['sometimes', 'integer', 'min:1', 'max:255'],
            'auto_print_on_checkout' => ['sometimes', 'boolean'],
            'group_ids'              => ['sometimes', 'array'],
            'group_ids.*'            => ['string', 'size:36'],
        ]);

        $groupIds = array_key_exists('group_ids', $data)
            ? $this->validatedPrinterGroupIds($tenant->id, $data['group_ids'] ?? [])
            : null;
        unset($data['group_ids']);

        DB::transaction(function () use ($tenant, $printer, $data, $groupIds): void {
            $printer->update($data);

            if ($groupIds !== null) {
                $this->replacePrinterGroups($tenant->id, $printer->id, $groupIds);
            }
        });

        return response()->json(['ok' => true, 'printer' => $printer->fresh()]);
    }

    /** DELETE /api/v1/printers/{id} */
    public function destroy(Request $request, string $id): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('api_tenant');
        $virtualDeviceId = $this->resolveVirtualDeviceId($request);

        $printer = Printer::where('tenant_id', $tenant->id)
            ->where('virtual_device_id', $virtualDeviceId)
            ->findOrFail($id);
        $printer->delete();

        return response()->json(['ok' => true]);
    }

    // ── Printer Groups CRUD ───────────────────────────────────────────────────

    /** GET /api/v1/printer-groups */
    public function indexGroups(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant          = $request->attributes->get('api_tenant');
        $virtualDeviceId = $this->resolveVirtualDeviceId($request);

        $config = $this->buildConfig($tenant->id, $virtualDeviceId);

        return response()->json(['ok' => true, ...$config]);
    }

    public function readOnlyGroupMutation(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error' => 'mobile_printer_groups_read_only',
            'message' => 'La gestion des groupes d’impression se fait depuis le web.',
        ], 405);
    }

    /** POST /api/v1/printer-groups */
    public function storeGroup(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant          = $request->attributes->get('api_tenant');
        $virtualDeviceId = $this->resolveVirtualDeviceId($request);

        $data = $request->validate([
            'id'               => ['required', 'string', 'size:36'],
            'name'             => ['required', 'string', 'max:255'],
            'is_receipt_group' => ['sometimes', 'boolean'],
            'print_mode'       => ['sometimes', Rule::in(['primary_fallback', 'simultaneous'])],
        ]);

        $group = PrinterGroup::withTrashed()->updateOrCreate(
            ['id' => $data['id'], 'tenant_id' => $tenant->id],
            array_merge($data, ['tenant_id' => $tenant->id, 'virtual_device_id' => $virtualDeviceId, 'deleted_at' => null])
        );

        return response()->json(['ok' => true, 'printer_group' => $group], 201);
    }

    /** PUT /api/v1/printer-groups/{id} */
    public function updateGroup(Request $request, string $id): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('api_tenant');

        $group = PrinterGroup::withTrashed()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($id);

        $data = $request->validate([
            'name'             => ['sometimes', 'string', 'max:255'],
            'is_receipt_group' => ['sometimes', 'boolean'],
            'print_mode'       => ['sometimes', Rule::in(['primary_fallback', 'simultaneous'])],
            'printer_ids'      => ['sometimes', 'array'],
            'printer_ids.*'    => ['string', 'size:36'],
            'group_printers'   => ['sometimes', 'array'],
            'group_printers.*.printer_id' => ['required_with:group_printers', 'string', 'size:36'],
            'group_printers.*.priority'   => ['sometimes', 'integer', 'min:0', 'max:255'],
            'category_ids'     => ['sometimes', 'array'],
            'category_ids.*'   => ['nullable', 'integer'],
        ]);

        DB::transaction(function () use ($group, $data): void {
            $group->update(array_intersect_key($data, array_flip(['name', 'is_receipt_group', 'print_mode'])));

            // Replace group_printers if provided
            if (isset($data['group_printers'])) {
                $group->printerGroupPrinters()->delete();
                foreach ($data['group_printers'] as $gp) {
                    PrinterGroupPrinter::create([
                        'group_id'   => $group->id,
                        'printer_id' => $gp['printer_id'],
                        'priority'   => $gp['priority'] ?? 0,
                    ]);
                }
            }

            // Replace category mappings if provided
            if (isset($data['category_ids'])) {
                $group->printerGroupCategories()->delete();
                foreach ($data['category_ids'] as $categoryId) {
                    PrinterGroupCategory::create([
                        'group_id'    => $group->id,
                        'category_id' => $categoryId,
                    ]);
                }
            }
        });

        return response()->json(['ok' => true, 'printer_group' => $group->fresh()]);
    }

    /** DELETE /api/v1/printer-groups/{id} */
    public function destroyGroup(Request $request, string $id): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('api_tenant');

        $group = PrinterGroup::where('tenant_id', $tenant->id)->findOrFail($id);
        $group->delete();

        return response()->json(['ok' => true]);
    }

    // ── Full-replace push ─────────────────────────────────────────────────────

    /** POST /api/v1/printers/push-config */
    public function pushConfig(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant          = $request->attributes->get('api_tenant');
        $virtualDeviceId = $this->resolveVirtualDeviceId($request);

        $data = $request->validate([
            'printers'                            => ['sometimes', 'array'],
            'printers.*.id'                       => ['required', 'string', 'size:36'],
            'printers.*.name'                     => ['required', 'string', 'max:255'],
            'printers.*.connection_type'          => ['sometimes', Rule::in(['tcp', 'bluetooth', 'usb'])],
            'printers.*.address'                  => ['nullable', 'string', 'max:255'],
            'printers.*.port'                     => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'printers.*.paper_width'              => ['sometimes', 'integer', 'min:1', 'max:255'],
            'printers.*.encoding'                 => ['sometimes', 'string', 'max:20'],
            'printers.*.cut_paper'                => ['sometimes', 'boolean'],
            'printers.*.copies'                   => ['sometimes', 'integer', 'min:1', 'max:255'],
            'printers.*.auto_print_on_checkout'   => ['sometimes', 'boolean'],
            'printers.*.group_ids'                => ['sometimes', 'array'],
            'printers.*.group_ids.*'              => ['string', 'size:36'],
        ]);

        DB::transaction(function () use ($tenant, $virtualDeviceId, $data): void {
            // Mobile owns only the terminal printer list. Groups/routing stay web-managed.
            Printer::withTrashed()
                ->where('tenant_id', $tenant->id)
                ->where('virtual_device_id', $virtualDeviceId)
                ->forceDelete();

            $now = now()->toDateTimeString();

            if (! empty($data['printers'])) {
                $rows = array_map(fn ($p) => array_merge([
                    'connection_type'        => 'tcp',
                    'address'                => null,
                    'port'                   => 9100,
                    'paper_width'            => 80,
                    'encoding'               => 'CP437',
                    'cut_paper'              => true,
                    'copies'                 => 1,
                    'auto_print_on_checkout' => false,
                ], $p, [
                    'tenant_id'        => $tenant->id,
                    'virtual_device_id'=> $virtualDeviceId,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                    'deleted_at'       => null,
                ], collect($p)->except('group_ids')->all()), $data['printers']);

                Printer::insert($rows);

                foreach ($data['printers'] as $printerData) {
                    $groupIds = $this->validatedPrinterGroupIds($tenant->id, $printerData['group_ids'] ?? []);
                    $this->replacePrinterGroups($tenant->id, $printerData['id'], $groupIds);
                }
            }
        });

        $config = $this->buildConfig($tenant->id, $virtualDeviceId);

        return response()->json(['ok' => true, ...$config]);
    }

    /** DELETE /api/v1/printers/clear-config */
    public function clearConfig(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant          = $request->attributes->get('api_tenant');
        $virtualDeviceId = $this->resolveVirtualDeviceId($request);

        DB::transaction(function () use ($tenant, $virtualDeviceId): void {
            Printer::withTrashed()
                ->where('tenant_id', $tenant->id)
                ->where('virtual_device_id', $virtualDeviceId)
                ->forceDelete();
        });

        return response()->json(['ok' => true]);
    }

    private function validatedPrinterGroupIds(int $tenantId, array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }

        $groupIds = array_values(array_unique($groupIds));

        $validIds = PrinterGroup::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $groupIds)
            ->pluck('id')
            ->all();

        if (count($validIds) !== count($groupIds)) {
            throw ValidationException::withMessages([
                'group_ids' => 'Un groupe d’impression est invalide.',
            ]);
        }

        return $groupIds;
    }

    private function replacePrinterGroups(int $tenantId, string $printerId, array $groupIds): void
    {
        $tenantGroupIds = PrinterGroup::query()
            ->where('tenant_id', $tenantId)
            ->pluck('id');

        PrinterGroupPrinter::query()
            ->where('printer_id', $printerId)
            ->whereIn('group_id', $tenantGroupIds)
            ->delete();

        foreach ($groupIds as $index => $groupId) {
            PrinterGroupPrinter::create([
                'group_id' => $groupId,
                'printer_id' => $printerId,
                'priority' => $index,
            ]);
        }
    }
}
