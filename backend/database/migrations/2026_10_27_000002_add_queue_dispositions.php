<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::selectOne('SELECT current_user AS name')->name !== 'blusser_owner') {
            throw new RuntimeException('Queue disposition migration requires owner credentials.');
        }

        DB::unprepared(file_get_contents(database_path('sql/queue_dispositions.sql')));

        $signature = 'governance.queue_command(text,uuid,jsonb,uuid)';
        $definition = DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS sql', [$signature])->sql;
        $changes = [
            'result jsonb; internal_key uuid;' => 'result jsonb; internal_key uuid; disposition_context jsonb; rebook_id uuid;',
            "ELSE PERFORM governance.booking_validate_payload('queue',payload); END IF;" => "ELSIF action_code IN ('skip','cancel') THEN PERFORM governance.queue_disposition_validate(payload);\n ELSE PERFORM governance.booking_validate_payload('queue',payload); END IF;",
            "digest_value:=encode(sha256(convert_to(jsonb_build_object('id',rid,'payload',payload)::text,'UTF8')),'hex');" => "IF action_code IN ('skip','cancel') THEN\n  disposition_context:=governance.queue_disposition_context(rid,action_code,payload ? 'rebook');\n END IF;\n digest_value:=encode(sha256(convert_to(jsonb_build_object('id',rid,'payload',payload)::text,'UTF8')),'hex');",
            "id IN ((payload->>'room_id')::uuid,(SELECT rr.room_id" => "id IN (coalesce(payload->'rebook'->>'room_id',payload->>'room_id')::uuid,(SELECT rr.room_id",
            "IF q.version IS DISTINCT FROM (payload->>'expected_version')::bigint THEN RAISE EXCEPTION 'Stale version' USING ERRCODE='23505'; END IF;" => "IF q.version IS DISTINCT FROM (payload->>'expected_version')::bigint THEN RAISE EXCEPTION 'Stale version' USING ERRCODE='23505'; END IF;\n".
                " IF q.rebooked_appointment_id IS NOT NULL AND (action_code='requeue' OR (action_code IN ('skip','cancel') AND payload ? 'rebook')) THEN\n".
                "  RAISE EXCEPTION 'Attendance already rebooked' USING ERRCODE='23505';\n END IF;",
            'UPDATE public.queue_tickets SET status=next_q,version=version+1' => "IF action_code IN ('skip','cancel') THEN\n".
                "  rebook_id:=q.rebooked_appointment_id;\n".
                "  IF payload ? 'rebook' THEN\n".
                "   IF action_code='cancel' THEN UPDATE public.resource_reservations SET active=false,updated_at=now() WHERE (tenant_id,id)=(tid,v.reservation_id); END IF;\n".
                "   rebook_id:=governance.queue_rebooking_insert(disposition_context,payload->'rebook',actor_id);\n".
                "  END IF;\n".
                "  UPDATE public.queue_tickets SET reason_code=btrim(payload->>'reason_code'),note=nullif(btrim(payload->>'note'),''),rebooked_appointment_id=rebook_id WHERE (tenant_id,id)=(tid,rid);\n".
                "  UPDATE public.visits SET rebooked_appointment_id=rebook_id WHERE (tenant_id,id)=(tid,v.id);\n".
                " END IF;\n UPDATE public.queue_tickets SET status=next_q,version=version+1",
        ];
        foreach ($changes as $before => $after) {
            if (substr_count($definition, $before) !== 1) {
                throw new RuntimeException('Queue command changed; review disposition migration anchor.');
            }
            $definition = str_replace($before, $after, $definition);
        }
        DB::unprepared($definition);

        $integrity = DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS sql', ['governance.queue_integrity()'])->sql;
        $marker = "OR (v.status='in_progress' AND v.reservation_id IS NULL)";
        if (substr_count($integrity, $marker) !== 1) {
            throw new RuntimeException('Queue integrity changed; review superseded attendance linkage.');
        }
        DB::unprepared(str_replace($marker, $marker.' OR q.rebooked_appointment_id IS DISTINCT FROM v.rebooked_appointment_id', $integrity));

        foreach ([
            'queue_disposition_validate(jsonb)',
            'queue_disposition_context(uuid,text,boolean)',
            'queue_rebooking_options(uuid,text,date)',
            'queue_rebooking_insert(jsonb,jsonb,uuid)',
            'queue_attendance(uuid)',
            'queue_projection(uuid)',
        ] as $function) {
            DB::statement('ALTER FUNCTION governance.'.$function.' OWNER TO blusser_security');
            DB::statement('REVOKE ALL ON FUNCTION governance.'.$function.' FROM PUBLIC,blusser_app');
        }
        DB::statement('GRANT EXECUTE ON FUNCTION governance.queue_rebooking_options(uuid,text,date) TO blusser_app');
        DB::statement('GRANT EXECUTE ON FUNCTION governance.queue_projection(uuid) TO blusser_app');
    }

    public function down(): void
    {
        throw new RuntimeException('Queue disposition history is forward-only; review before rollback.');
    }
};
