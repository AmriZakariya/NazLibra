<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\ResetPinNotification;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
            'demoLoginEmail' => \App\Models\User::where('is_active', true)->orderBy('id')->value('email'),
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
        $request->session()->forget(['pos_session_locked', 'pos_session_locked_at']);

        return redirect()->intended(route('dashboard'))->with('status', 'Connexion réussie.');
    }

    public function lockSession(Request $request): RedirectResponse
    {
        $request->session()->put('pos_session_locked', true);
        $request->session()->put('pos_session_locked_at', now()->toIso8601String());

        return redirect()->route('session.locked');
    }

    public function lockedScreen(Request $request): View|RedirectResponse
    {
        if (! (bool) $request->session()->get('pos_session_locked', false)) {
            return redirect()->route('dashboard');
        }

        $user = $request->user();
        $tenant = $user?->currentTenant ?: Tenant::query()->first();

        return view('auth.locked', [
            'tenant' => $tenant,
            'user' => $user,
            'lockedAt' => $request->session()->get('pos_session_locked_at'),
            'hasPin' => filled($user?->pin_hash),
        ]);
    }

    public function unlockSession(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'pin' => ['required', 'digits_between:4,8'],
        ]);

        $user = $request->user();

        if (! $user?->pin_hash || ! Hash::check($data['pin'], $user->pin_hash)) {
            throw ValidationException::withMessages([
                'pin' => 'PIN incorrect.',
            ]);
        }

        $request->session()->forget(['pos_session_locked', 'pos_session_locked_at']);

        return redirect()->intended(route('dashboard'))->with('status', 'Session déverrouillée.');
    }

    public function unlockWithPassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'Mot de passe incorrect.',
            ]);
        }

        $request->session()->forget(['pos_session_locked', 'pos_session_locked_at']);

        return redirect()->route('dashboard')->with('status', 'Session déverrouillée. Demandez au propriétaire de définir ou réinitialiser votre PIN.');
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
            'isOwner' => $this->isOwner($tenant, $user),
            'recentAuditLogs' => $this->isOwner($tenant, $user)
                ? AuditLog::where('tenant_id', $tenant->id)->with('user')->latest()->take(5)->get()
                : collect(),
        ]);
    }

    public function activity(Request $request): View
    {
        $user = $request->user();
        $tenant = $user?->currentTenant ?: Tenant::query()->firstOrFail();

        if (! $this->isOwner($tenant, $user)) {
            abort(403, 'Seul le propriétaire peut consulter le journal d’activité.');
        }

        $filters = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'method' => ['nullable', 'in:POST,PUT,PATCH,DELETE'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $query = AuditLog::query()
            ->where('tenant_id', $tenant->id)
            ->with('user')
            ->latest();

        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', \Carbon\Carbon::parse($filters['from'])->startOfDay());
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', \Carbon\Carbon::parse($filters['to'])->endOfDay());
        }

        if (! empty($filters['method'])) {
            $query->where('properties->method', $filters['method']);
        }

        if (! empty($filters['q'])) {
            $search = trim((string) $filters['q']);
            $query->where(function ($builder) use ($search): void {
                $builder->where('action', 'like', '%'.$search.'%')
                    ->orWhere('subject_type', 'like', '%'.$search.'%')
                    ->orWhere('properties->path', 'like', '%'.$search.'%')
                    ->orWhere('properties->url', 'like', '%'.$search.'%');
            });
        }

        $logs = $query->paginate(20)->withQueryString();

        return view('auth.activity', [
            'tenant' => $tenant,
            'active' => 'settings',
            'user' => $user,
            'logs' => $logs,
            'users' => $tenant->users()->orderBy('name')->get(),
            'filters' => [
                'user_id' => $filters['user_id'] ?? '',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
                'method' => $filters['method'] ?? '',
                'q' => $filters['q'] ?? '',
            ],
            'totals' => [
                'all' => AuditLog::where('tenant_id', $tenant->id)->count(),
                'today' => AuditLog::where('tenant_id', $tenant->id)->whereDate('created_at', now()->toDateString())->count(),
                'users' => AuditLog::where('tenant_id', $tenant->id)->whereNotNull('user_id')->distinct('user_id')->count('user_id'),
            ],
            'actionLabels' => $this->actionLabels(),
        ]);
    }

    /**
     * Map technical action names to user-friendly labels.
     */
    private function actionLabels(): array
    {
        return [
            'catalog.categories.store' => 'Création catégorie',
            'catalog.categories.update' => 'Modification catégorie',
            'catalog.categories.destroy' => 'Suppression catégorie',
            'catalog.items.store' => 'Création article',
            'catalog.items.update' => 'Modification article',
            'catalog.items.destroy' => 'Suppression article',
            'catalog.labels.print' => 'Impression étiquette',
            'catalog.import' => 'Importation',
            'pos.store' => 'Encaissement',
            'pos.refund' => 'Remboursement',
            'sales.store' => 'Création vente',
            'sales.update' => 'Modification vente',
            'sales.destroy' => 'Suppression vente',
            'contacts.customers.store' => 'Création client',
            'contacts.customers.update' => 'Modification client',
            'contacts.customers.destroy' => 'Suppression client',
            'contacts.suppliers.store' => 'Création fournisseur',
            'contacts.suppliers.update' => 'Modification fournisseur',
            'contacts.suppliers.destroy' => 'Suppression fournisseur',
            'finance.expenses.store' => 'Création dépense',
            'finance.expenses.update' => 'Modification dépense',
            'finance.expenses.destroy' => 'Suppression dépense',
            'finance.expense-categories.store' => 'Création catégorie dépense',
            'finance.expense-categories.update' => 'Modification catégorie dépense',
            'finance.expense-categories.destroy' => 'Suppression catégorie dépense',
            'finance.advances.store' => 'Création avance',
            'finance.advances.update' => 'Modification avance',
            'finance.advances.destroy' => 'Suppression avance',
            'finance.coupons.store' => 'Création coupon',
            'finance.coupons.update' => 'Modification coupon',
            'finance.coupons.destroy' => 'Suppression coupon',
            'finance.accounts.store' => 'Création compte',
            'finance.accounts.update' => 'Modification compte',
            'finance.accounts.destroy' => 'Suppression compte',
            'finance.transfers.store' => 'Création transfert',
            'finance.transfers.update' => 'Modification transfert',
            'finance.transfers.destroy' => 'Suppression transfert',
            'finance.deposits.store' => 'Création dépôt',
            'finance.deposits.update' => 'Modification dépôt',
            'finance.deposits.destroy' => 'Suppression dépôt',
            'purchases.store' => 'Création achat',
            'purchases.update' => 'Modification achat',
            'purchases.destroy' => 'Suppression achat',
            'stock.adjustments.store' => 'Ajustement stock',
            'stock.adjustments.update' => 'Modification ajustement',
            'stock.adjustments.destroy' => 'Suppression ajustement',
            'stock.transfers.store' => 'Transfert stock',
            'stock.transfers.update' => 'Modification transfert',
            'stock.transfers.destroy' => 'Suppression transfert',
            'sales.quotes.store' => 'Création devis',
            'sales.quotes.update' => 'Modification devis',
            'sales.quotes.destroy' => 'Suppression devis',
            'sales.quotes.convert' => 'Conversion devis',
            'sales.delivery.store' => 'Création livraison',
            'sales.delivery.update' => 'Modification livraison',
            'sales.delivery.destroy' => 'Suppression livraison',
            'sales.returns.store' => 'Création retour',
            'sales.returns.update' => 'Modification retour',
            'sales.returns.destroy' => 'Suppression retour',
            'settings.users.store' => 'Création utilisateur',
            'settings.users.update' => 'Modification utilisateur',
            'settings.users.destroy' => 'Suppression utilisateur',
            'settings.roles.store' => 'Création rôle',
            'settings.roles.update' => 'Modification rôle',
            'settings.roles.destroy' => 'Suppression rôle',
            'settings.taxes.store' => 'Création taxe',
            'settings.taxes.update' => 'Modification taxe',
            'settings.taxes.destroy' => 'Suppression taxe',
            'settings.units.store' => 'Création unité',
            'settings.units.update' => 'Modification unité',
            'settings.units.destroy' => 'Suppression unité',
            'settings.payment-types.store' => 'Création type paiement',
            'settings.payment-types.update' => 'Modification type paiement',
            'settings.payment-types.destroy' => 'Suppression type paiement',
            'settings.countries.store' => 'Création pays',
            'settings.countries.update' => 'Modification pays',
            'settings.countries.destroy' => 'Suppression pays',
            'settings.states.store' => 'Création état',
            'settings.states.update' => 'Modification état',
            'settings.states.destroy' => 'Suppression état',
            'settings.warehouses.store' => 'Création magasin',
            'settings.warehouses.update' => 'Modification magasin',
            'settings.warehouses.destroy' => 'Suppression magasin',
            'cash-register.store' => 'Ouverture tiroir caisse',
            'cash-register.update' => 'Clôture tiroir caisse',
            'cash-register.deposits.store' => 'Dépôt caisse',
            'cash-register.withdrawals.store' => 'Retrait caisse',
            'profile.update' => 'Mise à jour profil',
            'settings.company.update' => 'Mise à jour société',
            'settings.store.update' => 'Mise à jour magasin',
            'settings.pos.update' => 'Mise à jour paramètres caisse',
            'settings.theme.update' => 'Mise à jour thème',
            'settings.sms.update' => 'Mise à jour SMS',
            'settings.hardware.update' => 'Mise à jour matériel',
            'session.lock' => 'Verrouillage session',
            'session.unlock' => 'Déverrouillage session',
            'logout' => 'Déconnexion',
            'login' => 'Connexion',
            'messaging.store' => 'Envoi message',
            'messaging.templates.store' => 'Création modèle',
            'messaging.templates.update' => 'Modification modèle',
            'messaging.templates.destroy' => 'Suppression modèle',
        ];
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $user = $request->user();
        $tenant = $user?->currentTenant ?: Tenant::query()->firstOrFail();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:60'],
            'avatar_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'profile_photo' => ['nullable', 'image', 'max:2048'],
            'remove_profile_photo' => ['nullable', 'boolean'],
            'current_password' => ['nullable', 'required_with:password', 'string'],
            'password' => ['nullable', 'confirmed', 'min:8', 'max:120'],
            'pin' => ['nullable', 'digits_between:4,8'],
            'clear_pin' => ['nullable', 'boolean'],
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

        if ($request->hasFile('profile_photo')) {
            if ($user->profile_photo_path) {
                Storage::disk('public')->delete($user->profile_photo_path);
            }

            $user->profile_photo_path = $request->file('profile_photo')->store('users/profile-photos', 'public');
        } elseif ($request->boolean('remove_profile_photo') && $user->profile_photo_path) {
            Storage::disk('public')->delete($user->profile_photo_path);
            $user->profile_photo_path = null;
        }

        // PIN: only owner can set or clear
        if (! empty($data['pin']) || $request->boolean('clear_pin')) {
            if (! $this->isOwner($tenant, $user)) {
                abort(403, 'Seul le propriétaire peut définir ou réinitialiser un PIN.');
            }

            if ($request->boolean('clear_pin')) {
                $user->pin_hash = null;
            } elseif (! empty($data['pin'])) {
                $user->pin_hash = Hash::make($data['pin']);
            }
        }

        $user->save();

        return back()->with('status', 'Profil mis à jour.');
    }

    public function showForgotPassword(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return view('auth.password.forgot', [
            'tenant' => Tenant::query()->first(),
        ]);
    }

    public function sendPasswordResetLink(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
        ]);

        $user = User::where('email', $request->email)->first();
        $token = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $request->email],
            ['token' => Hash::make($token), 'created_at' => now()]
        );

        $user->notify(new ResetPasswordNotification($token, $request->email));

        return back()->with('status', 'Un lien de réinitialisation vous a été envoyé par email.');
    }

    public function showResetPassword(Request $request): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return view('auth.password.reset', [
            'tenant' => Tenant::query()->first(),
            'token' => $request->token,
            'email' => $request->email,
        ]);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', 'min:8', 'max:120'],
        ]);

        $record = DB::table('password_reset_tokens')->where('email', $data['email'])->first();

        if (! $record || ! Hash::check($data['token'], $record->token)) {
            return back()->withErrors(['email' => 'Lien de réinitialisation invalide ou expiré.']);
        }

        if (now()->diffInMinutes($record->created_at) > 60) {
            DB::table('password_reset_tokens')->where('email', $data['email'])->delete();

            return back()->withErrors(['email' => 'Ce lien a expiré. Veuillez refaire une demande.']);
        }

        $user = User::where('email', $data['email'])->first();

        if (! $user) {
            return back()->withErrors(['email' => 'Utilisateur introuvable.']);
        }

        $user->password = $data['password'];
        $user->save();

        DB::table('password_reset_tokens')->where('email', $data['email'])->delete();

        return redirect()->route('login')->with('status', 'Mot de passe réinitialisé. Connectez-vous avec votre nouveau mot de passe.');
    }

    public function sendPinResetEmail(Request $request): RedirectResponse
    {
        $user = $request->user();
        $token = Str::random(64);

        DB::table('pin_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            ['token' => Hash::make($token), 'created_at' => now()]
        );

        $user->notify(new ResetPinNotification($token, $user->email));

        return back()->with('status', 'Un lien de réinitialisation du PIN vous a été envoyé par email.');
    }

    public function showResetPin(Request $request): View|RedirectResponse
    {
        return view('auth.pin.reset', [
            'tenant' => Tenant::query()->first(),
            'token' => $request->token,
            'email' => $request->email,
        ]);
    }

    public function updatePin(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'pin' => ['required', 'digits_between:4,8', 'confirmed'],
        ]);

        $record = DB::table('pin_reset_tokens')->where('email', $data['email'])->first();

        if (! $record || ! Hash::check($data['token'], $record->token)) {
            return back()->withErrors(['pin' => 'Lien de réinitialisation invalide ou expiré.']);
        }

        if (now()->diffInMinutes($record->created_at) > 60) {
            DB::table('pin_reset_tokens')->where('email', $data['email'])->delete();

            return back()->withErrors(['pin' => 'Ce lien a expiré. Veuillez refaire une demande.']);
        }

        $user = User::where('email', $data['email'])->first();

        if (! $user) {
            return back()->withErrors(['pin' => 'Utilisateur introuvable.']);
        }

        $user->pin_hash = Hash::make($data['pin']);
        $user->save();

        DB::table('pin_reset_tokens')->where('email', $data['email'])->delete();

        return redirect()->route('login')->with('status', 'PIN réinitialisé. Vous pouvez maintenant vous connecter et verrouiller votre session.');
    }

    private function isOwner(Tenant $tenant, mixed $user): bool
    {
        if (! $user) {
            return false;
        }

        $tenantUser = $tenant->users()->whereKey($user->id)->first();

        return (string) ($tenantUser?->pivot?->role ?? '') === 'owner';
    }
}
