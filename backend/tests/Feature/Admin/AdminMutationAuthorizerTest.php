<?php

namespace Tests\Feature\Admin;

use App\Support\Admin\AdminMutationAuthorizationOutcome;
use App\Support\Admin\AdminMutationAuthorizer;
use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\Concerns\CreatesAdminFixtures;
use Tests\TestCase;

class AdminMutationAuthorizerTest extends TestCase
{
    use CreatesAdminFixtures;
    use DatabaseTransactions;

    private function authorizer(): AdminMutationAuthorizer
    {
        return app(AdminMutationAuthorizer::class);
    }

    public function test_active_admin_is_authorized_for_privileged_mutation(): void
    {
        $admin = $this->createAndLoginAdmin();

        $result = $this->authorizer()->checkBinary(
            UuidBinary::toBinary($admin['user_uuid'])
        );

        $this->assertSame(
            AdminMutationAuthorizationOutcome::AUTHORIZED,
            $result
        );
    }

    public function test_active_super_admin_is_authorized_for_privileged_mutation(): void
    {
        $admin = $this->createAndLoginAdmin(['SUPER_ADMIN']);

        $result = $this->authorizer()->checkBinary(
            UuidBinary::toBinary($admin['user_uuid'])
        );

        $this->assertSame(
            AdminMutationAuthorizationOutcome::AUTHORIZED,
            $result
        );
    }

    public function test_unknown_actor_is_rejected(): void
    {
        $result = $this->authorizer()->checkBinary(
            UuidBinary::toBinary(UuidBinary::generate())
        );

        $this->assertSame(
            AdminMutationAuthorizationOutcome::ACTOR_NOT_FOUND,
            $result
        );
    }

    public function test_non_active_admin_account_is_rejected(): void
    {
        $admin = $this->createAndLoginAdmin();

        $suspendedStatusId = DB::table('user_account_statuses')
            ->where('code', 'SUSPENDED')
            ->value('id');

        DB::table('users')
            ->where('id', UuidBinary::toBinary($admin['user_uuid']))
            ->update([
                'account_status_id' => $suspendedStatusId,
            ]);

        $result = $this->authorizer()->checkBinary(
            UuidBinary::toBinary($admin['user_uuid'])
        );

        $this->assertSame(
            AdminMutationAuthorizationOutcome::ACTOR_NOT_AUTHORIZED,
            $result
        );
    }

    public function test_removed_admin_membership_is_rejected(): void
    {
        $admin = $this->createAndLoginAdmin();

        $adminRoleId = DB::table('roles')
            ->where('code', 'ADMIN')
            ->value('id');

        DB::table('user_roles')
            ->where('user_id', UuidBinary::toBinary($admin['user_uuid']))
            ->where('role_id', $adminRoleId)
            ->delete();

        $result = $this->authorizer()->checkBinary(
            UuidBinary::toBinary($admin['user_uuid'])
        );

        $this->assertSame(
            AdminMutationAuthorizationOutcome::ACTOR_NOT_AUTHORIZED,
            $result
        );
    }

    public function test_globally_deactivated_admin_role_is_rejected(): void
    {
        $admin = $this->createAndLoginAdmin();

        DB::table('roles')
            ->where('code', 'ADMIN')
            ->update([
                'is_active' => 0,
            ]);

        $result = $this->authorizer()->checkBinary(
            UuidBinary::toBinary($admin['user_uuid'])
        );

        $this->assertSame(
            AdminMutationAuthorizationOutcome::ACTOR_NOT_AUTHORIZED,
            $result
        );
    }

    public function test_one_active_admin_role_is_enough_when_actor_has_both_roles(): void
    {
        $admin = $this->createAndLoginAdmin([
            'ADMIN',
            'SUPER_ADMIN',
        ]);

        DB::table('roles')
            ->where('code', 'ADMIN')
            ->update([
                'is_active' => 0,
            ]);

        $result = $this->authorizer()->checkBinary(
            UuidBinary::toBinary($admin['user_uuid'])
        );

        $this->assertSame(
            AdminMutationAuthorizationOutcome::AUTHORIZED,
            $result
        );
    }

    public function test_dual_role_actor_stays_authorized_when_only_one_role_membership_is_removed(): void
    {
        $admin = $this->createAndLoginAdmin([
            'ADMIN',
            'SUPER_ADMIN',
        ]);

        $adminRoleId = DB::table('roles')
            ->where('code', 'ADMIN')
            ->value('id');

        DB::table('user_roles')
            ->where('user_id', UuidBinary::toBinary($admin['user_uuid']))
            ->where('role_id', $adminRoleId)
            ->delete();

        $result = $this->authorizer()->checkBinary(
            UuidBinary::toBinary($admin['user_uuid'])
        );

        $this->assertSame(
            AdminMutationAuthorizationOutcome::AUTHORIZED,
            $result
        );
    }

    public function test_dual_role_actor_is_rejected_when_both_roles_are_globally_deactivated(): void
    {
        $admin = $this->createAndLoginAdmin([
            'ADMIN',
            'SUPER_ADMIN',
        ]);

        DB::table('roles')
            ->whereIn('code', ['ADMIN', 'SUPER_ADMIN'])
            ->update([
                'is_active' => 0,
            ]);

        $result = $this->authorizer()->checkBinary(
            UuidBinary::toBinary($admin['user_uuid'])
        );

        $this->assertSame(
            AdminMutationAuthorizationOutcome::ACTOR_NOT_AUTHORIZED,
            $result
        );
    }
}
