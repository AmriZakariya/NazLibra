<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Rules\FourDigitPin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class UserController extends Controller
{
    /**
     * GET /api/v1/users
     * List all users belonging to the current tenant.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('api_tenant');

        $roles = $tenant->roles()->get()->keyBy('key');

        $users = $tenant->users()
            ->withPivot(['role', 'permissions'])
            ->get()
            ->map(fn ($u) => $this->formatUser($u, $roles));

        return response()->json(['ok' => true, 'users' => $users]);
    }

    /**
     * PUT /api/v1/users/{user}
     * Update a user's role and/or permissions. Requires wildcard ability (*).
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $authUser = $request->user();
        if (! $authUser->tokenCan('*') && ! $authUser->tokenCan('settings.users')) {
            return response()->json(['ok' => false, 'message' => 'Accès refusé.'], 403);
        }

        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('api_tenant');

        if (! $tenant->users()->whereKey($user->id)->exists()) {
            return response()->json(['ok' => false, 'message' => 'Utilisateur introuvable.'], 404);
        }

        $data = $request->validate([
            'role' => ['nullable', 'string', 'max:50'],
        ]);

        if (array_key_exists('role', $data)) {
            $tenant->users()->updateExistingPivot($user->id, ['role' => $data['role']]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/v1/auth/pin-verify
     * Verify a tenant operator's PIN and replace the current POS credential.
     */
    public function pinVerify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'pin'     => ['required', 'string', new FourDigitPin],
        ]);

        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('api_tenant');

        $user = $tenant->users()
            ->withPivot(['role', 'permissions'])
            ->where('users.id', $data['user_id'])
            ->first();

        if (! $user || ! $user->is_active || is_null($user->pin_hash) || ! Hash::check($data['pin'], $user->pin_hash)) {
            return response()->json(['ok' => false, 'message' => 'PIN ou utilisateur invalide.'], 422);
        }

        [$role, $abilities] = $this->roleAndAbilities($user, $tenant);

        $currentToken = PersonalAccessToken::findToken((string) $request->bearerToken())
            ?? $request->user()->currentAccessToken();
        $baseName = method_exists($currentToken, 'getAttribute')
            ? (string) ($currentToken->getAttribute('name') ?: 'pos')
            : 'pos';
        $tokenName = preg_replace('/\/operator:\d+$/', '', $baseName).'/operator:'.$user->id;
        $newToken = $user->createToken($tokenName, $abilities);

        // Revoke only the credential used for this switch. Other browser/mobile
        // sessions belonging to either user remain untouched.
        $previousTokenRevoked = $currentToken instanceof PersonalAccessToken;
        if ($previousTokenRevoked) {
            $currentToken->delete();
        }

        $user->update(['current_tenant_id' => $tenant->id]);

        return response()->json([
            'ok'        => true,
            'token'     => $newToken->plainTextToken,
            'token_type'=> 'Bearer',
            'user'      => [
                'id'           => $user->id,
                'name'         => $user->name,
                'role'         => $role,
                'avatar_color' => $user->avatar_color ?? '#0D9488',
            ],
            'abilities' => $abilities,
            'previous_token_revoked' => $previousTokenRevoked,
        ]);
    }

    /**
     * POST /api/v1/users/set-pin
     * Set or update the calling user's own PIN (exactly 4 ASCII digits).
     */
    public function setPin(Request $request): JsonResponse
    {
        $request->validate([
            'pin'              => ['required', 'string', new FourDigitPin, 'confirmed'],
            'pin_confirmation' => ['required', 'string', new FourDigitPin],
        ]);

        $request->user()->update(['pin_hash' => Hash::make($request->input('pin'))]);

        return response()->json(['ok' => true]);
    }

    /**
     * DELETE /api/v1/users/pin
     * Remove the calling user's PIN.
     */
    public function removePin(Request $request): JsonResponse
    {
        $request->user()->update(['pin_hash' => null]);

        return response()->json(['ok' => true]);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function formatUser(User $user, ?\Illuminate\Support\Collection $roles = null): array
    {
        [$role, $abilities] = $this->roleAndAbilities($user, null, $roles);

        return [
            'id'           => $user->id,
            'name'         => $user->name,
            'email'        => $user->email,
            'role'         => $role,
            'avatar_color' => $user->avatar_color ?? '#0D9488',
            'is_active'    => (bool) $user->is_active,
            'has_pin'      => ! is_null($user->pin_hash),
            'abilities'    => $abilities,
        ];
    }

    /**
     * Returns [roleKey, abilities] derived exclusively from the Role model.
     * Falls back to a minimal read-only set if no matching role is found.
     */
    private function roleAndAbilities(User $user, ?Tenant $tenant = null, ?\Illuminate\Support\Collection $roles = null): array
    {
        $roleKey = $user->pivot?->role;

        // Resolve the Role model — prefer the pre-loaded collection to avoid N+1.
        $role = null;
        if ($roles !== null) {
            $role = $roles->get($roleKey);
        } elseif ($tenant !== null && $roleKey) {
            $role = $tenant->roles()->where('key', $roleKey)->first();
        }

        $permissions = $role?->permissions ?? [];

        if (in_array('*', $permissions, true)) {
            return [$roleKey, ['*']];
        }

        $abilities = ! empty($permissions)
            ? $permissions
            : ['sales.create', 'sales.view', 'items.view', 'stock.view'];

        return [$roleKey, $abilities];
    }
}
