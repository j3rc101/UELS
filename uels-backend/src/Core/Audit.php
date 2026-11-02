<?php
declare(strict_types=1);

namespace App\Core;

class Audit
{
    public static function log(?int $userId, string $action, ?string $entity = null, ?int $entityId = null, ?string $details = null): void
    {
        try {
            Database::execute(
                'INSERT INTO audit_logs (user_id, action, entity, entity_id, details, ip_address) VALUES (?,?,?,?,?,?)',
                [$userId, $action, $entity, $entityId, $details, $_SERVER['REMOTE_ADDR'] ?? null]
            );
        } catch (\Throwable $e) {
            error_log('UELS audit log failed: ' . $e->getMessage());
        }
    }
}
