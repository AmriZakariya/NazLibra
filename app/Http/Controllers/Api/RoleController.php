<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    /**
     * GET /api/v1/roles
     * List all roles for the current tenant.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('api_tenant');

        $roles = $tenant->roles()->get()->map(fn ($r) => $this->formatRole($r));

        return response()->json(['ok' => true, 'roles' => $roles]);
    }

    /**
     * POST /api/v1/roles
     * Create a new role. Requires settings.roles ability.
     */
    public function store(Request $request): JsonResponse
    {
        $this->requireAbility($request);

        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('api_tenant');

        $data = $request->validate([
            'name'          => ['required', 'string', 'max:100'],
            'key'           => ['required', 'string', 'max:50', 'alpha_dash'],
            'permissions'   => ['required', 'array'],
            'permissions.*' => ['string'],
        ]);

        $exists = $tenant->roles()->where('key', $data['key'])->exists();
        if ($exists) {
            return response()->json(['ok' => false, 'message' => 'Une clé de rôle identique existe déjà.'], 422);
        }

        $role = $tenant->roles()->create([
            'name'        => $data['name'],
            'key'         => $data['key'],
            'permissions' => $data['permissions'],
        ]);

        return response()->json(['ok' => true, 'role' => $this->formatRole($role)], 201);
    }

    /**
     * PUT /api/v1/roles/{role}
     * Update an existing role. Requires settings.roles ability.
     * System roles (is_system = true) cannot be modified.
     */
    public function update(Request $request, Role $role): JsonResponse
    {
        $this->requireAbility($request);

        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('api_tenant');

        if ((int) $role->tenant_id !== (int) $tenant->id) {
            return response()->json(['ok' => false, 'message' => 'Rôle introuvable.'], 404);
        }

        if ($role->isProtected()) {
            return response()->json(['ok' => false, 'message' => 'Ce rôle système ne peut pas être modifié.'], 403);
        }

        $data = $request->validate([
            'name'          => ['sometimes', 'string', 'max:100'],
            'permissions'   => ['sometimes', 'array'],
            'permissions.*' => ['string'],
        ]);

        $role->update($data);

        return response()->json(['ok' => true, 'role' => $this->formatRole($role->fresh())]);
    }

    /**
     * DELETE /api/v1/roles/{role}
     * Delete a role. Requires settings.roles ability.
     * System roles (is_system = true) cannot be deleted.
     */
    public function destroy(Request $request, Role $role): JsonResponse
    {
        $this->requireAbility($request);

        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('api_tenant');

        if ((int) $role->tenant_id !== (int) $tenant->id) {
            return response()->json(['ok' => false, 'message' => 'Rôle introuvable.'], 404);
        }

        if ($role->isProtected()) {
            return response()->json(['ok' => false, 'message' => 'Ce rôle système ne peut pas être supprimé.'], 403);
        }

        $role->delete();

        return response()->json(['ok' => true]);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function requireAbility(Request $request): void
    {
        $user = $request->user();
        if (! $user->tokenCan('*') && ! $user->tokenCan('settings.roles')) {
            abort(403, 'Accès refusé.');
        }
    }

    private function formatRole(Role $role): array
    {
        return [
            'id'          => $role->id,
            'name'        => $role->name,
            'key'         => $role->key,
            'permissions' => $role->permissions ?? [],
            'is_system'   => (bool) $role->is_system,
        ];
    }
}
