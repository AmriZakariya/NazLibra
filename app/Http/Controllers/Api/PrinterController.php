<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Printer;
use App\Models\PrinterGroup;
use App\Models\PrinterGroupCategory;
use App\Models\PrinterGroupPrinter;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PrinterController extends Controller
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Resolve the virtual device ID from the X-Virtual-Device-Id header.
     * Returns null when the header is absent (scopes to all devices for tenant).
     */
    private function resolveVirtualDeviceId(Request $request): ?int
    {
        $header = trim((string) $request->header('X-Virtual-Device-Id', ''));
        if ($header === '') {
            return null;
        }

        return ctype_digit($header) ? (int) $header : null;
    }

    /**
     * Build the full config payload for the given tenant + virtual_device_id.
     */
    private function buildConfig(int $tenantId, ?int $virtualDeviceId): array
    {
        $printers = Printer::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('virtual_device_id', $virtualDeviceId)
            ->orderBy('name')
            ->get()
            ->toArray();

        $printerGroupIds = PrinterGroup::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('virtual_device_id', $virtualDeviceId)
            ->pluck('id');

        $printerGroups = PrinterGroup::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('virtual_device_id', $virtualDeviceId)
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
            'printers'                => $printers,
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
        ]);

        $printer = Printer::withTrashed()->updateOrCreate(
            ['id' => $data['id'], 'tenant_id' => $tenant->id],
            array_merge($data, ['tenant_id' => $tenant->id, 'virtual_device_id' => $virtualDeviceId, 'deleted_at' => null])
        );

        return response()->json(['ok' => true, 'printer' => $printer], 201);
    }

    /** PUT /api/v1/printers/{id} */
    public function update(Request $request, string $id): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('api_tenant');

        $printer = Printer::withTrashed()
            ->where('tenant_id', $tenant->id)
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
        ]);

        $printer->update($data);

        return response()->json(['ok' => true, 'printer' => $printer->fresh()]);
    }

    /** DELETE /api/v1/printers/{id} */
    public function destroy(Request $request, string $id): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('api_tenant');

        $printer = Printer::where('tenant_id', $tenant->id)->findOrFail($id);
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

            'printer_groups'                      => ['sometimes', 'array'],
            'printer_groups.*.id'                 => ['required', 'string', 'size:36'],
            'printer_groups.*.name'               => ['required', 'string', 'max:255'],
            'printer_groups.*.is_receipt_group'   => ['sometimes', 'boolean'],
            'printer_groups.*.print_mode'         => ['sometimes', Rule::in(['primary_fallback', 'simultaneous'])],

            'printer_group_printers'              => ['sometimes', 'array'],
            'printer_group_printers.*.group_id'   => ['required', 'string', 'size:36'],
            'printer_group_printers.*.printer_id' => ['required', 'string', 'size:36'],
            'printer_group_printers.*.priority'   => ['sometimes', 'integer', 'min:0', 'max:255'],

            'printer_group_categories'              => ['sometimes', 'array'],
            'printer_group_categories.*.group_id'   => ['required', 'string', 'size:36'],
            'printer_group_categories.*.category_id'=> ['nullable', 'integer'],
        ]);

        DB::transaction(function () use ($tenant, $virtualDeviceId, $data): void {
            // Get group IDs belonging to this scope before deleting
            $existingGroupIds = PrinterGroup::withTrashed()
                ->where('tenant_id', $tenant->id)
                ->where('virtual_device_id', $virtualDeviceId)
                ->pluck('id');

            // Delete pivot data for existing groups
            if ($existingGroupIds->isNotEmpty()) {
                PrinterGroupPrinter::whereIn('group_id', $existingGroupIds)->delete();
                PrinterGroupCategory::whereIn('group_id', $existingGroupIds)->delete();
            }

            // Hard-delete existing printers and groups for this scope
            Printer::withTrashed()
                ->where('tenant_id', $tenant->id)
                ->where('virtual_device_id', $virtualDeviceId)
                ->forceDelete();

            PrinterGroup::withTrashed()
                ->where('tenant_id', $tenant->id)
                ->where('virtual_device_id', $virtualDeviceId)
                ->forceDelete();

            $now = now()->toDateTimeString();

            // Insert printers
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
                ]), $data['printers']);

                Printer::insert($rows);
            }

            // Insert printer groups
            if (! empty($data['printer_groups'])) {
                $rows = array_map(fn ($g) => array_merge([
                    'is_receipt_group' => false,
                    'print_mode'       => 'primary_fallback',
                ], $g, [
                    'tenant_id'        => $tenant->id,
                    'virtual_device_id'=> $virtualDeviceId,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                    'deleted_at'       => null,
                ]), $data['printer_groups']);

                PrinterGroup::insert($rows);
            }

            // Insert pivot: printer_group_printers
            if (! empty($data['printer_group_printers'])) {
                $rows = array_map(fn ($gp) => [
                    'group_id'   => $gp['group_id'],
                    'printer_id' => $gp['printer_id'],
                    'priority'   => $gp['priority'] ?? 0,
                ], $data['printer_group_printers']);

                PrinterGroupPrinter::insert($rows);
            }

            // Insert pivot: printer_group_categories
            if (! empty($data['printer_group_categories'])) {
                $rows = array_map(fn ($gc) => [
                    'group_id'    => $gc['group_id'],
                    'category_id' => $gc['category_id'] ?? null,
                ], $data['printer_group_categories']);

                PrinterGroupCategory::insert($rows);
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
            $existingGroupIds = PrinterGroup::withTrashed()
                ->where('tenant_id', $tenant->id)
                ->where('virtual_device_id', $virtualDeviceId)
                ->pluck('id');

            if ($existingGroupIds->isNotEmpty()) {
                PrinterGroupPrinter::whereIn('group_id', $existingGroupIds)->delete();
                PrinterGroupCategory::whereIn('group_id', $existingGroupIds)->delete();
            }

            Printer::withTrashed()
                ->where('tenant_id', $tenant->id)
                ->where('virtual_device_id', $virtualDeviceId)
                ->forceDelete();

            PrinterGroup::withTrashed()
                ->where('tenant_id', $tenant->id)
                ->where('virtual_device_id', $virtualDeviceId)
                ->forceDelete();
        });

        return response()->json(['ok' => true]);
    }
}
