<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::selectOne('SELECT current_user AS name')->name !== 'blusser_owner') {
            throw new RuntimeException('Work schedule migration requires owner credentials.');
        }
        DB::unprepared(file_get_contents(database_path('sql/employee_work_schedules.sql')));
        DB::statement('ALTER TABLE doctor_profiles ALTER COLUMN specialty_id DROP NOT NULL');
        DB::statement('ALTER TABLE schedule_rules ADD COLUMN active boolean NOT NULL DEFAULT true');
        DB::statement('CREATE TABLE governance.work_schedule_function_backup(signature text PRIMARY KEY, definition text NOT NULL)');
        DB::statement('REVOKE ALL ON governance.work_schedule_function_backup FROM PUBLIC,blusser_app,blusser_security');
        $functions = ['governance.scheduling_validate_payload(text,jsonb)', 'governance.scheduling_command(text,text,uuid,jsonb,uuid)', 'governance.scheduling_projection(text,uuid)', 'governance.doctor_branch_configurable(uuid,uuid)', 'governance.scheduling_interval_conflict(jsonb,uuid,date)', 'governance.booking_slot_valid(uuid,uuid,uuid,uuid,timestamptz,timestamptz,boolean)'];
        foreach ($functions as $signature) {
            $sql = DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS sql', [$signature])->sql;
            DB::insert('INSERT INTO governance.work_schedule_function_backup VALUES (?,?)', [$signature, $sql]);
            if (str_contains($signature, 'scheduling_validate_payload')) {
                $sql = $this->replace($sql, "'breaks','room_usage']", "'breaks','room_usage','active']");
                $sql = $this->replace($sql, "array_remove(array_remove(fields,'effective_to'),'room_usage')", "array_remove(array_remove(array_remove(fields,'effective_to'),'room_usage'),'active')");
                $sql = $this->replace($sql, "ELSIF k IN ('branch_ids','service_ids') THEN", "ELSIF k='specialty_id' AND item='null'::jsonb THEN NULL;\n  ELSIF k IN ('branch_ids','service_ids') THEN");
                $sql = $this->replace($sql, 'jsonb_array_length(item) NOT BETWEEN 1 AND 100', "jsonb_array_length(item) NOT BETWEEN (CASE WHEN k='service_ids' THEN 0 ELSE 1 END) AND 100");
            } elseif (str_contains($signature, 'scheduling_command')) {
                $sql = $this->replace($sql, "OR NOT EXISTS(SELECT 1 FROM public.specialties WHERE (tenant_id,id)=(tid,(payload->>'specialty_id')::uuid) AND active)", "OR (payload->>'specialty_id' IS NOT NULL AND NOT EXISTS(SELECT 1 FROM public.specialties WHERE (tenant_id,id)=(tid,(payload->>'specialty_id')::uuid) AND active))");
                $sql = $this->replace($sql, 'IF governance.scheduling_interval_conflict(val,rid,', "IF coalesce((payload->>'active')::boolean,true) AND governance.scheduling_interval_conflict(val,rid,");
                $sql = $this->replace($sql, '   INSERT INTO public.schedule_breaks(tenant_id,schedule_rule_id,local_start,local_end)', "   UPDATE public.schedule_rules SET active=coalesce((payload->>'active')::boolean,true) WHERE tenant_id=tid AND id=result_id;\n   INSERT INTO public.schedule_breaks(tenant_id,schedule_rule_id,local_start,local_end)");
            } elseif (str_contains($signature, 'scheduling_projection')) {
                $sql = $this->replace($sql, "'weekday',r.weekday,", "'active',r.active,'weekday',r.weekday,");
            } elseif (str_contains($signature, 'doctor_branch_configurable')) {
                $sql = $this->replace($sql, 'JOIN public.specialties s ON', 'LEFT JOIN public.specialties s ON');
                $sql = $this->replace($sql, 'WHERE d.tenant_id=', 'WHERE (d.specialty_id IS NULL OR s.id IS NOT NULL) AND d.tenant_id=');
            } elseif (str_contains($signature, 'scheduling_interval_conflict')) {
                $sql = $this->replace($sql, 'sr.tenant_id=tid AND sr.id', 'sr.active AND sr.tenant_id=tid AND sr.id');
            } else {
                $sql = $this->replace($sql, 'FOR w IN SELECT * FROM public.schedule_rules WHERE tenant_id=', 'FOR w IN SELECT * FROM public.schedule_rules WHERE (active OR NOT check_live) AND tenant_id=');
            }
            DB::unprepared($sql);
        }
    }

    private function replace(string $sql, string $old, string $new): string
    {
        if (substr_count($sql, $old) !== 1) {
            throw new RuntimeException('Scheduling definition changed; review work migration.');
        }

        return str_replace($old, $new, $sql);
    }

    public function down(): void
    {
        if (DB::table('employee_work_calendars')->exists() || DB::table('doctor_profiles')->whereNull('specialty_id')->exists() || DB::table('schedule_rules')->where('active', false)->exists()) {
            throw new RuntimeException('Work data retained; use a forward migration.');
        }
        foreach (DB::table('governance.work_schedule_function_backup')->get() as $r) {
            DB::unprepared($r->definition);
        }
        DB::unprepared('DROP TABLE employee_work_schedules; DROP TABLE employee_work_calendars; DROP FUNCTION governance.employee_work_save(uuid,uuid,jsonb,jsonb,bigint,uuid); DROP FUNCTION governance.employee_work_read(uuid,uuid); DROP FUNCTION governance.employee_work_visible(uuid,uuid); DROP TABLE governance.work_schedule_function_backup; ALTER TABLE doctor_profiles ALTER COLUMN specialty_id SET NOT NULL; ALTER TABLE schedule_rules DROP COLUMN active;');
    }
};
