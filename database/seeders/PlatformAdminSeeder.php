<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Creates (or promotes) the Castl-it-POS platform admin — the account that can
 * reach /castlit-admin on the master install.
 *
 * Run on prod:
 *   php artisan db:seed --class=Database\\Seeders\\PlatformAdminSeeder --force
 *
 * Defaults below are the platform login. They can be overridden without editing
 * code via PLATFORM_ADMIN_EMAIL / PLATFORM_ADMIN_PASSWORD / PLATFORM_ADMIN_NAME.
 * NOTE: this password lives in the repo — change it after first login.
 *
 * Idempotent: re-running updates the existing user (handy to reset the password).
 */
class PlatformAdminSeeder extends Seeder
{
    private const DEFAULT_EMAIL    = 'zakariya.etudes@gmail.com';
    private const DEFAULT_PASSWORD = 'sniper0553508194@@';
    private const DEFAULT_NAME     = 'Admin';

    public function run(): void
    {
        $email    = trim((string) env('PLATFORM_ADMIN_EMAIL', self::DEFAULT_EMAIL));
        $password = (string) env('PLATFORM_ADMIN_PASSWORD', self::DEFAULT_PASSWORD);
        $name     = trim((string) env('PLATFORM_ADMIN_NAME', self::DEFAULT_NAME)) ?: self::DEFAULT_NAME;

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name'              => $name,
                'password'          => $password, // auto-hashed by the model's "hashed" cast
                'is_active'         => true,
                'is_platform_admin' => true,
                'current_tenant_id' => null,
            ],
        );

        $this->command?->info("Platform admin ready: {$user->email} (login at /connexion → /castlit-admin)");
    }
}
