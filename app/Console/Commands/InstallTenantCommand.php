<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\TenantProvisioningService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Seed the single tenant + owner for a freshly-provisioned CastLit client
 * install. Run once by the provisioning script, immediately after
 * `php artisan migrate --force`, inside the new install's own database.
 *
 * The subscription payload is passed as base64-encoded JSON so it survives the
 * shell safely. On success the command prints a single JSON line (owner email +
 * generated password) that the provisioning script captures for the welcome
 * email.
 *
 * Usage:
 *   php artisan castlit:install-tenant --payload="<base64 json>"
 *   php artisan castlit:install-tenant --payload="<...>" --password="known-pass"
 */
class InstallTenantCommand extends Command
{
    protected $signature = 'castlit:install-tenant
        {--payload= : Base64-encoded JSON with business_name, activity, currency, contact_name, email, phone}
        {--password= : Optional explicit owner password; generated when omitted}
        {--subdomain= : Client subdomain, used to build the deterministic admin email (admin@{sub}.com)}';

    protected $description = 'Seed the single tenant + owner for a fresh CastLit client install from a subscription payload.';

    public function handle(TenantProvisioningService $service): int
    {
        // Idempotency: a client install holds exactly one tenant.
        if (Tenant::exists()) {
            $this->outputJson(['status' => 'skipped', 'message' => 'Tenant already exists on this install.']);
            return self::SUCCESS;
        }

        $data = $this->decodePayload();
        if ($data === null) {
            $this->outputJson(['status' => 'error', 'message' => 'Invalid or missing --payload.']);
            return self::FAILURE;
        }

        foreach (['business_name', 'contact_name', 'email'] as $required) {
            if (empty($data[$required])) {
                $this->outputJson(['status' => 'error', 'message' => "Missing required field: {$required}."]);
                return self::FAILURE;
            }
        }

        $password = (string) ($this->option('password') ?: Str::password(12, symbols: false));
        $admin = $this->defaultAdmin($data);

        try {
            $result = $service->install(
                store: [
                    'name'          => $data['business_name'],
                    'activity'      => $data['activity'] ?? null,
                    'currency'      => $data['currency'] ?? 'MAD',
                    'phone'         => $data['phone'] ?? null,
                    'email'         => $data['email'],
                    'language'      => $data['language'] ?? 'fr',
                ],
                owner: [
                    'name'     => $data['contact_name'],
                    'email'    => $data['email'],
                    'password' => $password,
                ],
                admin: $admin,
            );
        } catch (\Throwable $e) {
            $this->outputJson(['status' => 'error', 'message' => $e->getMessage()]);
            return self::FAILURE;
        }

        $this->outputJson(array_filter([
            'status'         => 'success',
            'tenant_id'      => $result['tenant']->id,
            'tenant_slug'    => $result['tenant']->slug,
            'owner_email'    => $result['owner']->email,
            'owner_password' => $password,
            'admin_email'    => $result['admin']?->email,
            'admin_password' => $result['admin'] ? ($admin['password'] ?? null) : null,
        ], static fn ($v) => $v !== null));

        return self::SUCCESS;
    }

    /**
     * Build the deterministic super-admin credentials from config, or null when
     * disabled / no subdomain available to key the email on.
     *
     * @return array{name:string,email:string,password:string}|null
     */
    private function defaultAdmin(array $data): ?array
    {
        $cfg = config('castlit.provision.default_admin', []);
        if (! ($cfg['enabled'] ?? false)) {
            return null;
        }

        // Prefer the passed subdomain; fall back to a slug of the business name.
        $sub = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $this->option('subdomain')));
        if ($sub === '') {
            $sub = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) ($data['business_name'] ?? '')));
        }
        if ($sub === '') {
            return null;
        }

        $email = str_replace('{sub}', $sub, (string) ($cfg['email_pattern'] ?? 'admin@{sub}.com'));

        return [
            'name'     => (string) ($cfg['name'] ?? 'Administrateur'),
            'email'    => $email,
            'password' => (string) ($cfg['password'] ?? 'admin'),
        ];
    }

    private function decodePayload(): ?array
    {
        $raw = (string) $this->option('payload');
        if ($raw === '') {
            return null;
        }
        $json = base64_decode($raw, true);
        if ($json === false) {
            return null;
        }
        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    /** Emit exactly one machine-readable JSON line (last line the script parses). */
    private function outputJson(array $data): void
    {
        $this->line(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
