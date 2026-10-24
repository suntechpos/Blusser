<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::selectOne('SELECT current_user AS name')->name !== 'blusser_owner') {
            throw new RuntimeException('Room assignments require migration credentials.');
        }
        DB::statement('CREATE TABLE governance.room_assignment_function_backup (signature text PRIMARY KEY, definition text NOT NULL)');
        DB::statement('REVOKE ALL ON governance.room_assignment_function_backup FROM PUBLIC, blusser_app, blusser_security');
        foreach (['governance.scheduling_validate_payload(text,jsonb)', 'governance.scheduling_command(text,text,uuid,jsonb,uuid)', 'governance.scheduling_projection(text,uuid)'] as $signature) {
            DB::insert('INSERT INTO governance.room_assignment_function_backup VALUES (?, pg_get_functiondef(?::regprocedure))', [$signature, $signature]);
        }
        DB::unprepared(file_get_contents(database_path('sql/room_assignments.sql')));
        $definition = fn (string $signature) => DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS sql', [$signature])->sql;
        $live = $definition('governance.doctor_branch_live(uuid,uuid)');
        $config = str_replace(['doctor_branch_live', ' AND NOT u.must_change_password'], ['doctor_branch_configurable', ''], $live);
        DB::unprepared($config);
        DB::statement('ALTER FUNCTION governance.doctor_branch_configurable(uuid,uuid) OWNER TO blusser_security');
        DB::statement('REVOKE ALL ON FUNCTION governance.doctor_branch_configurable(uuid,uuid) FROM PUBLIC, blusser_app');

        $validate = $definition('governance.scheduling_validate_payload(text,jsonb)');
        $validate = $this->replace($validate, "WHEN 'rooms' THEN ARRAY['branch_id','number','service_type','active','availability']", "WHEN 'rooms' THEN ARRAY['branch_id','number','service_type','active','availability','usage_mode','exclusive_doctor_id']");
        $validate = $this->replace($validate, "'effective_to','breaks']", "'effective_to','breaks','room_usage']");
        $validate = $this->replace($validate, "IF k='active' THEN", "IF k IN ('room_usage','usage_mode') THEN\n   IF jsonb_typeof(item)<>'string' OR p->>k NOT IN ('shared','private') THEN RAISE EXCEPTION 'Invalid room usage' USING ERRCODE='22023'; END IF;\n  ELSIF k='exclusive_doctor_id' THEN\n   IF item<>'null'::jsonb AND (jsonb_typeof(item)<>'string' OR p->>k !~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$') THEN RAISE EXCEPTION 'Invalid owner' USING ERRCODE='22023'; END IF;\n  ELSIF k='active' THEN");
        $validate = $this->replace($validate, "required_fields:=CASE WHEN kind='schedules' THEN array_remove(fields,'effective_to') ELSE fields END;", "required_fields:=CASE WHEN kind='schedules' THEN array_remove(array_remove(fields,'effective_to'),'room_usage') WHEN kind='rooms' THEN array_remove(array_remove(fields,'usage_mode'),'exclusive_doctor_id') ELSE fields END;");
        DB::unprepared($validate);
        $command = $definition('governance.scheduling_command(text,text,uuid,jsonb,uuid)');
        // Only target configuration changes: actor identity and availability still require password rotation.
        $command = $this->replace($command, 'u.id=m.user_id AND u.active AND NOT u.is_platform_admin AND NOT u.must_change_password', 'u.id=m.user_id AND u.active AND NOT u.is_platform_admin');
        $command = $this->replace($command, 'IF NOT governance.doctor_branch_live(did,bid)', 'IF NOT governance.doctor_branch_configurable(did,bid)');
        $command = $this->replace($command, " ELSE\n  bid:=(payload->>'branch_id')::uuid; did:=", "  UPDATE public.rooms SET usage_mode=coalesce(payload->>'usage_mode',usage_mode),\n   exclusive_doctor_id=CASE WHEN coalesce(payload->>'usage_mode',usage_mode)='shared' THEN NULL WHEN payload?'exclusive_doctor_id' THEN (payload->>'exclusive_doctor_id')::uuid ELSE exclusive_doctor_id END WHERE tenant_id=tid AND id=result_id;\n ELSE\n  bid:=(payload->>'branch_id')::uuid; did:=");
        $command = $this->replace($command, "IF NOT FOUND THEN RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002'; END IF;\n   IF governance.scheduling_interval_conflict", "IF NOT FOUND THEN RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002'; END IF;\n   IF EXISTS(SELECT 1 FROM public.rooms WHERE tenant_id=tid AND id=(val->>'room_id')::uuid AND usage_mode='private' AND exclusive_doctor_id<>did) THEN RAISE EXCEPTION 'Private room conflict' USING ERRCODE='23505'; END IF;\n   IF kind='schedules' AND payload?'room_usage' AND EXISTS(SELECT 1 FROM public.rooms WHERE tenant_id=tid AND id=(val->>'room_id')::uuid AND usage_mode IS DISTINCT FROM payload->>'room_usage') THEN\n    IF public.permission_scope('rooms.update') IS DISTINCT FROM 'entire_clinic' OR public.permission_scope('rooms.view') IS DISTINCT FROM 'entire_clinic' THEN RAISE EXCEPTION 'Denied' USING ERRCODE='42501'; END IF;\n    UPDATE public.rooms SET usage_mode=payload->>'room_usage',exclusive_doctor_id=CASE WHEN payload->>'room_usage'='private' THEN did ELSE NULL END,updated_at=now() WHERE tenant_id=tid AND id=(val->>'room_id')::uuid;\n    PERFORM governance.event('rooms.update','rooms',(val->>'room_id')::uuid,tid);\n   END IF;\n   IF governance.scheduling_interval_conflict");
        DB::unprepared($command);
        $projection = $definition('governance.scheduling_projection(text,uuid)');
        $projection = $this->replace($projection, "'availability',r.availability)", "'availability',r.availability,'usage_mode',r.usage_mode,'exclusive_doctor_id',r.exclusive_doctor_id)");
        DB::unprepared($projection);
    }

    private function replace(string $sql, string $old, string $new): string
    {
        if (substr_count($sql, $old) !== 1) {
            throw new RuntimeException('Scheduling definition changed; review room assignment migration.');
        }

        return str_replace($old, $new, $sql);
    }

    public function down(): void
    {
        if (DB::table('rooms')->where('usage_mode', 'private')->exists()) {
            throw new RuntimeException('Room assignments are retained. Use a reviewed forward migration.');
        }
        foreach (DB::table('governance.room_assignment_function_backup')->get() as $row) {
            DB::unprepared($row->definition);
        }
        DB::unprepared('DROP TRIGGER room_owner_guard ON public.rooms; DROP TRIGGER schedule_room_owner_guard ON public.schedule_rules; DROP TRIGGER exception_room_owner_guard ON public.schedule_exceptions; DROP FUNCTION governance.enforce_room_assignment(); DROP FUNCTION governance.doctor_branch_configurable(uuid,uuid); ALTER TABLE public.rooms DROP COLUMN usage_mode, DROP COLUMN exclusive_doctor_id; DROP TABLE governance.room_assignment_function_backup;');
    }
};
