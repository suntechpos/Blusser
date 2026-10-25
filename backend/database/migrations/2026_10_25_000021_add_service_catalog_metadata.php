<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::selectOne('SELECT current_user AS name')->name !== 'blusser_owner') {
            throw new RuntimeException('Service catalog migration requires owner credentials.');
        }
        DB::unprepared(file_get_contents(database_path('sql/service_catalog.sql')));
        DB::statement('CREATE TABLE governance.service_catalog_function_backup(signature text PRIMARY KEY,definition text NOT NULL)');
        DB::statement('REVOKE ALL ON governance.service_catalog_function_backup FROM PUBLIC,blusser_app,blusser_security');
        foreach (['governance.scheduling_availability_context(uuid,uuid,uuid)', 'governance.reception_booking_options(uuid,integer,integer)', 'governance.booking_slot_valid(uuid,uuid,uuid,uuid,timestamptz,timestamptz,boolean)', 'governance.booking_command(text,uuid,jsonb,uuid)', 'governance.queue_command(text,uuid,jsonb,uuid)'] as $signature) {
            $sql = DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS sql', [$signature])->sql;
            DB::insert('INSERT INTO governance.service_catalog_function_backup VALUES (?,?)', [$signature, $sql]);
            if (str_contains($signature, 'scheduling_availability_context')) {
                $sql = $this->replace($sql, "'duration_minutes',ds.duration_minutes", "'duration_minutes',coalesce(ds.duration_minutes,s.duration_minutes)");
                $sql = $this->replace($sql, 'AND s.active', 'AND s.active AND governance.catalog_service_available(sid,bid)');
            } elseif (str_contains($signature, 'reception_booking_options')) {
                $sql = $this->replace($sql, "'duration_minutes',ds.duration_minutes", "'duration_minutes',coalesce(ds.duration_minutes,sv.duration_minutes)");
                $sql = $this->replace($sql, 'AND sv.active', 'AND sv.active AND governance.catalog_service_available(sv.id,bid)');
            } elseif (str_contains($signature, 'booking_command')) {
                $sql = $this->replace($sql, 'OR NOT governance.doctor_branch_live(did,bid) OR NOT EXISTS', 'OR NOT governance.doctor_branch_live(did,bid) OR NOT governance.catalog_service_available(sid,bid) OR NOT EXISTS');
            } elseif (str_contains($signature, 'queue_command')) {
                $sql = $this->replace($sql, "OR NOT governance.doctor_branch_live((payload->>'doctor_id')::uuid,(payload->>'branch_id')::uuid)", "OR NOT governance.doctor_branch_live((payload->>'doctor_id')::uuid,(payload->>'branch_id')::uuid) OR NOT governance.catalog_service_available((payload->>'service_id')::uuid,(payload->>'branch_id')::uuid)");
                // Patch the single existing start/requeue predicate before the call/recall prefix adds another OR.
                $sql = $this->replace($sql, 'OR NOT governance.doctor_branch_live(v.doctor_id,v.branch_id)', 'OR NOT governance.doctor_branch_live(v.doctor_id,v.branch_id) OR NOT governance.catalog_service_available(v.service_id,v.branch_id)');
                $sql = $this->replace($sql, "IF action_code IN ('call','recall') AND (", "IF action_code IN ('call','recall') AND (NOT governance.catalog_service_available(v.service_id,v.branch_id) OR ");
            } else {
                $sql = $this->replace($sql, 'SELECT ds.duration_minutes INTO override_minutes', 'SELECT coalesce(ds.duration_minutes,s.duration_minutes) INTO override_minutes');
                $sql = $this->replace($sql, 'AND (s.active OR NOT check_live)', 'AND (NOT check_live OR (s.active AND governance.catalog_service_available(sid,bid)))');
            }
            DB::unprepared($sql);
        }
    }

    private function replace(string $sql, string $old, string $new): string
    {
        if (substr_count($sql, $old) !== 1) {
            throw new RuntimeException('Scheduling definition changed; review catalog migration.');
        }

        return str_replace($old, $new, $sql);
    }

    public function down(): void
    {
        if (DB::table('service_categories')->exists() || DB::table('service_branches')->exists() || DB::table('service_catalog_counters')->exists() || DB::table('services')->whereNotNull('price')->orWhere('version', '>', 1)->exists()) {
            throw new RuntimeException('Service catalog data retained; use a forward migration.');
        }
        foreach (DB::table('governance.service_catalog_function_backup')->get() as $row) {
            DB::unprepared($row->definition);
        }
        DB::unprepared('DROP FUNCTION governance.catalog_command(text,text,uuid,jsonb,bigint,uuid); DROP FUNCTION governance.catalog_lookups(); DROP FUNCTION governance.catalog_export_audit(); DROP FUNCTION governance.catalog_projection(text,uuid); DROP FUNCTION governance.catalog_service_available(uuid,uuid); DROP TABLE public.service_branches; DROP TABLE public.service_catalog_counters; ALTER TABLE public.services DROP CONSTRAINT services_timed_duration; ALTER TABLE public.services DROP COLUMN category_id,DROP COLUMN service_type,DROP COLUMN price,DROP COLUMN timed,DROP COLUMN duration_minutes,DROP COLUMN turnaround,DROP COLUMN turnaround_unit,DROP COLUMN version; DROP TABLE public.service_categories; DROP TABLE governance.service_catalog_function_backup; REVOKE UPDATE ON public.services FROM blusser_security;');
    }
};
