<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('tenants')->orderBy('id')->each(function (object $tenant) use ($now): void {
            DB::table('taxes')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'name' => 'TVA 7%'],
                ['rate' => 7, 'description' => 'Taux réduit', 'is_active' => false, 'updated_at' => $now, 'created_at' => $now],
            );

            $settings = json_decode((string) $tenant->settings, true) ?: [];
            $settings['payment_types'] ??= [
                ['key' => 'cash', 'name' => 'Espèces', 'code' => 'cash', 'description' => 'Paiement comptoir en espèces', 'is_active' => true],
                ['key' => 'card', 'name' => 'Carte', 'code' => 'card', 'description' => 'TPE / carte bancaire', 'is_active' => true],
                ['key' => 'transfer', 'name' => 'Virement', 'code' => 'transfer', 'description' => 'Paiement par virement', 'is_active' => true],
                ['key' => 'cheque', 'name' => 'Chèque', 'code' => 'cheque', 'description' => 'Paiement par chèque', 'is_active' => true],
                ['key' => 'advance', 'name' => 'Avance client', 'code' => 'advance', 'description' => 'Déduction sur avance client', 'is_active' => true],
            ];
            $settings['countries'] ??= [
                ['key' => 'maroc', 'name' => 'Maroc', 'code' => 'MA', 'description' => 'Pays par défaut', 'is_active' => true],
            ];
            $settings['states'] ??= [
                ['key' => 'casablanca-settat', 'name' => 'Casablanca-Settat', 'code' => 'CAS', 'country' => 'Maroc', 'description' => null, 'is_active' => true],
                ['key' => 'rabat-sale-kenitra', 'name' => 'Rabat-Salé-Kénitra', 'code' => 'RSK', 'country' => 'Maroc', 'description' => null, 'is_active' => true],
            ];
            $settings['tax_groups'] ??= [];

            DB::table('tenants')
                ->where('id', $tenant->id)
                ->update(['settings' => json_encode($settings, JSON_UNESCAPED_UNICODE), 'updated_at' => $now]);
        });
    }

    public function down(): void
    {
        DB::table('taxes')->where('name', 'TVA 7%')->where('is_active', false)->delete();

        DB::table('tenants')->orderBy('id')->each(function (object $tenant): void {
            $settings = json_decode((string) $tenant->settings, true) ?: [];

            foreach (['payment_types', 'countries', 'states', 'tax_groups'] as $key) {
                unset($settings[$key]);
            }

            DB::table('tenants')
                ->where('id', $tenant->id)
                ->update(['settings' => json_encode($settings, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        });
    }
};
