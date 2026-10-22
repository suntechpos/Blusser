<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->requireOwner();
        // Legacy 000012 writes did not advance profile ETags. No old token can
        // therefore prove that its form saw the latest membership state.
        // Lock both write paths and advance beyond every currently issued token,
        // including installations that already applied 000013.
        // Governance commands lock their tenant before taking profile row locks.
        // Quiesce them in that order before the table locks to avoid a cycle.
        DB::select('SELECT id FROM tenants ORDER BY id FOR UPDATE');
        DB::statement('LOCK TABLE tenant_memberships, employee_profiles IN SHARE ROW EXCLUSIVE MODE');
        DB::statement('UPDATE tenant_memberships m SET employee_version=greatest(m.employee_version,coalesce((SELECT p.version FROM employee_profiles p WHERE p.tenant_id=m.tenant_id AND p.membership_id=m.id),0))+1');
        DB::statement('UPDATE employee_profiles p SET version=CASE WHEN p.membership_id IS NULL THEN p.version+1 ELSE (SELECT m.employee_version FROM tenant_memberships m WHERE m.tenant_id=p.tenant_id AND m.id=p.membership_id) END');
    }

    public function down(): void
    {
        $this->requireOwner();
        // Never make an invalidated ETag valid again. There is no schema to undo.
        // A deliberate rollback/reapply advances tokens again; normal migrate
        // reruns are a no-op because Laravel records this migration once.
    }

    private function requireOwner(): void
    {
        if (DB::selectOne('SELECT current_user AS name')->name !== 'blusser_owner') {
            throw new RuntimeException('Employee ETag invalidation requires migration credentials.');
        }
    }
};
