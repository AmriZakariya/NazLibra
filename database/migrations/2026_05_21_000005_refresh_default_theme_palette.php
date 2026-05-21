<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $newDefault = [
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

    private array $oldDefault = [
        'primary' => '#4F46E5',
        'accent' => '#0EA5E9',
        'success' => '#16A34A',
        'background' => '#F8FAFC',
        'surface_color' => '#FFFFFF',
        'surface_muted' => '#F1F5F9',
        'text' => '#0F172A',
        'muted' => '#64748B',
        'border' => '#E2E8F0',
        'font_scale' => '1',
        'density' => 'comfortable',
        'radius' => '12',
    ];

    public function up(): void
    {
        $this->replaceDefaultTheme($this->newDefault);
    }

    public function down(): void
    {
        $this->replaceDefaultTheme($this->oldDefault);
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
