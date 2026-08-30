<?php

namespace App\Actions\Auth\Concerns;

/**
 * Shared `inet_pton` helper for packing a request IP into auth_sessions.ip_address
 * (a `varbinary`). Extracted so IssuesAuthSession (Customer) and
 * IssuesAdminAuthSession (Admin) - both session-issuance traits, sometimes
 * combined in the same test class via their respective
 * Tests\Support\Authenticates*ForTests wrappers - don't each define their
 * own private packIp() and collide when composed together.
 */
trait PacksIpAddress
{
    private function packIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        $packed = inet_pton($ip);

        return $packed === false ? null : $packed;
    }
}
