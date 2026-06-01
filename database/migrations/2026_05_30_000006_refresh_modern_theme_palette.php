<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $modernDefault = [
        'primary' => '#3157D5',
        'accent' => '#0F9F8A',
        'success' => '#16A34A',
        'warning' => '#D97706',
        'danger' => '#E11D48',
        'info' => '#0284C7',
        'background' => '#F4F7FB',
        'surface_color' => '#FFFFFF',
        'surface_muted' => '#EEF3F8',
        'text' => '#101828',
        'muted' => '#64748B',
        'border' => '#D7DEE9',
        'font_scale' => '1',
        'density' => 'comfortable',
        'radius' => '12',
    ];

    private array $previousDefault = [
        'primary' => '#2563EB',
        'accent' => '#0D9488',
        'success' => '#16A34A',
        'background' => '#F6F8FB',
        'surface_color' => '#FFFFFF',
        'surface_muted' => '#EEF4FF',
        'text' => '#111827',
        'muted' => '#667085',
        'border' => '#D8E1EE',
        'font_scale' => '1',
        'density' => 'comfortable',
        'radius' => '12',
    ];

    public function up(): void
    {
        $this->replaceDefaultTheme($this->modernDefault);
    }

    public function down(): void
    {
        $this->replaceDefaultTheme($this->previousDefault);
    }

    private function replaceDefaultTheme(array $theme): void
    {
        DB::table('tenants')->orderBy('id')->each(function (object $tenant) use ($theme): void {
            $settings = json_decode((string) $tenant->settings, true) ?: [];

            if (($settings['theme_preset'] ?? 'default') !== 'default') {
                return;
            }

            $settings['theme_preset'] = 'default';
            $settings['theme'] = $theme;

            DB::table('tenants')->where('id', $tenant->id)->update([
                'settings' => json_encode($settings),
            ]);
        });
    }
};
