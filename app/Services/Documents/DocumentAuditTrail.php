<?php

namespace App\Services\Documents;

use App\Models\AuditLog;
use App\Models\DocumentStatusHistory;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;

class DocumentAuditTrail
{
    public function record(Tenant $tenant, Model $document, string $action, ?string $fromStatus = null, ?string $toStatus = null, array $changes = [], ?string $reason = null): void
    {
        $type = class_basename($document) === 'Invoice' ? 'invoice' : 'estimate';

        DocumentStatusHistory::create([
            'tenant_id' => $tenant->id,
            'document_type' => $type,
            'document_id' => $document->getKey(),
            'user_id' => auth()->id(),
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'changes' => $changes ?: null,
            'reason' => $reason,
            'metadata' => [
                'number' => $document->getAttribute('number'),
                'ip' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ],
        ]);

        AuditLog::create([
            'tenant_id' => $tenant->id,
            'user_id' => auth()->id(),
            'action' => $type.'.'.$action,
            'subject_type' => $document::class,
            'subject_id' => $document->getKey(),
            'properties' => [
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'changes' => $changes,
                'reason' => $reason,
            ],
            'real_device_ip' => request()?->ip(),
            'real_device_user_agent' => request()?->userAgent(),
            'friendly_action' => ucfirst($type).' '.$action,
            'subject_name_snapshot' => $document->getAttribute('number'),
            'subject_reference_snapshot' => $document->getAttribute('number'),
        ]);
    }
}
