<?php

namespace App\Services;

use App\Models\TenantInstall;

/** Disk queue consumed by deploy/process-manage-queue.sh from cron. */
class ClientManageQueue
{
    public static function enqueue(TenantInstall $install, string $action): bool
    {
        $dir = storage_path('app/castlit-manage-queue');
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Impossible de créer la file de déploiement.');
        }

        $request = $dir.'/'.$install->id.'.json';
        if (is_file($request) || is_file($request.'.running')) {
            return false;
        }

        $payload = json_encode([
            'install_id' => $install->id,
            'action' => $action,
            'subdomain' => $install->subdomain,
            'requested_at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $tmp = tempnam($dir, 'request-');
        if ($tmp === false || @file_put_contents($tmp, $payload) === false || ! @rename($tmp, $request)) {
            @unlink($tmp ?: '');
            throw new \RuntimeException('Impossible d’enregistrer la demande de déploiement.');
        }

        return true;
    }
}
