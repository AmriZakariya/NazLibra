<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\Role;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function showLogin(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return view('auth.login', [
            'tenant' => Tenant::query()->first(),
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials + ['is_active' => true], $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'Identifiants invalides ou compte désactivé.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'))->with('status', 'Connexion réussie.');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Déconnexion réussie.');
    }

    public function profile(): View
    {
        $user = Auth::user();
        $tenant = $user?->currentTenant ?: Tenant::query()->firstOrFail();
        $tenantUser = $tenant->users()->whereKey($user?->id)->first();
        $roleKey = (string) ($tenantUser?->pivot?->role ?? '');

        return view('auth.profile', [
            'tenant' => $tenant,
            'active' => 'settings',
            'user' => $user,
            'roleName' => Role::where('tenant_id', $tenant->id)->where('key', $roleKey)->value('name') ?: ucfirst($roleKey ?: 'Aucun rôle'),
            'roleKey' => $roleKey,
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:60'],
            'avatar_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'current_password' => ['nullable', 'required_with:password', 'string'],
            'password' => ['nullable', 'confirmed', 'min:8', 'max:120'],
        ]);

        if (! empty($data['password']) && ! Hash::check((string) $data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Le mot de passe actuel est incorrect.',
            ]);
        }

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'avatar_color' => $data['avatar_color'] ?? $user->avatar_color,
        ]);

        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }

        $user->save();

        return back()->with('status', 'Profil mis à jour.');
    }
}
