<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::selectOne('SELECT current_user AS name')->name !== 'blusser_owner') {
            throw new RuntimeException('Doctor workspace migration requires owner credentials.');
        }
        DB::unprepared(<<<'SQL'

CREATE FUNCTION governance.doctor_workspace_feed(requested_day date, requested_doctor uuid, requested_branch uuid)
RETURNS jsonb
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog
AS $workspace$
DECLARE
    tid uuid := public.context_tenant();
    own_mid uuid;
    own_doctor uuid;
    did uuid;
    bid uuid;
    role_code text;
    elevated boolean := false;
    day_local date;
    zone text;
    doctor_data jsonb;
    branch_data jsonb;
    doctor_options jsonb := '[]'::jsonb;
    scheduled_rows jsonb := '[]'::jsonb;
    ticket_rows jsonb := '[]'::jsonb;
BEGIN
    IF NOT governance.scheduling_identity() OR public.permission_scope('workspace.view') IS NULL THEN
        RAISE EXCEPTION 'Doctor workspace denied' USING ERRCODE = '42501';
    END IF;

    SELECT m.id, r.code,
           (r.code = 'ceo' AND r.protected)
           OR (public.permission_scope('queues.view') = 'entire_clinic'
               AND public.permission_scope('patients.view') = 'entire_clinic')
    INTO own_mid, role_code, elevated
    FROM public.tenant_memberships m
    JOIN public.tenant_roles r ON r.tenant_id = m.tenant_id AND r.id = m.role_id AND r.active
    WHERE m.tenant_id = tid AND m.user_id = public.context_user() AND m.active;

    IF own_mid IS NULL THEN
        RAISE EXCEPTION 'Inactive membership' USING ERRCODE = '42501';
    END IF;

    SELECT d.id INTO own_doctor
    FROM public.doctor_profiles d
    WHERE d.tenant_id = tid AND d.membership_id = own_mid AND d.active;

    IF NOT elevated THEN
        IF own_doctor IS NULL OR (requested_doctor IS NOT NULL AND requested_doctor <> own_doctor) THEN
            RAISE EXCEPTION 'Not your doctor profile' USING ERRCODE = '42501';
        END IF;
        did := own_doctor;
    ELSE
        did := requested_doctor;
        SELECT coalesce(jsonb_agg(
            jsonb_build_object('id', d.id,
                'name', coalesce(nullif(trim(concat_ws(' ', p.profile->>'first', p.profile->>'last')), ''), u.login),
                'specialty', coalesce(nullif(p.profile->>'specialty',''), s.name, 'Shifokor'))
            ORDER BY coalesce(nullif(trim(concat_ws(' ', p.profile->>'first', p.profile->>'last')), ''), u.login)
        ), '[]'::jsonb) INTO doctor_options
        FROM public.doctor_profiles d
        JOIN public.tenant_memberships m ON m.tenant_id=d.tenant_id AND m.id=d.membership_id AND m.active
        JOIN public.users u ON u.id=m.user_id AND u.active AND NOT u.must_change_password
        LEFT JOIN public.employee_profiles p ON p.tenant_id=d.tenant_id AND p.membership_id=d.membership_id
        LEFT JOIN public.specialties s ON s.tenant_id=d.tenant_id AND s.id=d.specialty_id
        WHERE d.tenant_id=tid AND d.active AND (p.id IS NULL OR p.active)
          AND EXISTS (SELECT 1 FROM public.doctor_branches db JOIN public.branches b
                      ON b.tenant_id=db.tenant_id AND b.id=db.branch_id AND b.active
                      WHERE db.tenant_id=tid AND db.doctor_id=d.id);
        IF did IS NULL THEN
            SELECT (item->>'id')::uuid INTO did
            FROM jsonb_array_elements(doctor_options) AS selected(item) LIMIT 1;
        END IF;
    END IF;

    IF did IS NULL THEN
        RETURN jsonb_build_object('manager', elevated, 'doctors', doctor_options,
            'doctor', NULL, 'branch', NULL, 'date', NULL,
            'scheduled', '[]'::jsonb, 'tickets', '[]'::jsonb);
    END IF;

    SELECT jsonb_build_object('id',d.id, 'name',
        coalesce(nullif(trim(concat_ws(' ',p.profile->>'first',p.profile->>'last')), ''),u.login),
        'specialty',coalesce(nullif(p.profile->>'specialty',''),s.name,'Shifokor'))
    INTO doctor_data
    FROM public.doctor_profiles d
    JOIN public.tenant_memberships m ON m.tenant_id=d.tenant_id AND m.id=d.membership_id AND m.active
    JOIN public.users u ON u.id=m.user_id AND u.active AND NOT u.must_change_password
    LEFT JOIN public.employee_profiles p ON p.tenant_id=d.tenant_id AND p.membership_id=d.membership_id
    LEFT JOIN public.specialties s ON s.tenant_id=d.tenant_id AND s.id=d.specialty_id
    WHERE d.tenant_id=tid AND d.id=did AND d.active AND (p.id IS NULL OR p.active);

    IF doctor_data IS NULL THEN
        RAISE EXCEPTION 'Doctor profile unavailable' USING ERRCODE = 'P0002';
    END IF;

    SELECT db.branch_id INTO bid
    FROM public.doctor_branches db
    JOIN public.branches b ON b.tenant_id=db.tenant_id AND b.id=db.branch_id AND b.active
    WHERE db.tenant_id=tid AND db.doctor_id=did AND (requested_branch IS NULL OR db.branch_id=requested_branch)
    ORDER BY b.name,db.branch_id LIMIT 1;

    IF bid IS NULL THEN
        RAISE EXCEPTION 'Doctor branch unavailable' USING ERRCODE = 'P0002';
    END IF;

    SELECT jsonb_build_object('id',b.id,'name',b.name, 'timezone',coalesce(b.timezone,t.timezone)),
           coalesce(b.timezone,t.timezone)
    INTO branch_data,zone
    FROM public.branches b JOIN public.tenants t ON t.id=b.tenant_id
    WHERE b.tenant_id=tid AND b.id=bid;
    day_local := coalesce(requested_day,(clock_timestamp() AT TIME ZONE zone)::date);

    SELECT jsonb_build_object('id',did, 'name',doctor_data->>'name',
        'specialty',doctor_data->>'specialty', 'room',coalesce((
            SELECT r.number FROM public.schedule_rules sr
            JOIN public.rooms r ON r.tenant_id=sr.tenant_id AND r.id=sr.room_id
            WHERE sr.tenant_id=tid AND sr.doctor_id=did AND sr.branch_id=bid
              AND sr.weekday=extract(isodow from day_local)::integer
              AND sr.effective_from<=day_local AND (sr.effective_to IS NULL OR sr.effective_to>=day_local)
            ORDER BY sr.updated_at DESC,sr.id LIMIT 1
        ),''), 'branches',coalesce((
            SELECT jsonb_agg(jsonb_build_object('id',b.id,'name',b.name,'timezone',coalesce(b.timezone,t.timezone)) ORDER BY b.name)
            FROM public.doctor_branches db
            JOIN public.branches b ON b.tenant_id=db.tenant_id AND b.id=db.branch_id AND b.active
            JOIN public.tenants t ON t.id=b.tenant_id
            WHERE db.tenant_id=tid AND db.doctor_id=did
        ),'[]'::jsonb))
    INTO doctor_data;

    SELECT coalesce(jsonb_agg(jsonb_build_object(
        'id',a.id,'patient_id',p.id,'patient_number',p.patient_number,'name',p.name,
        'dob',p.date_of_birth,'gender',p.gender,'phone',p.phone,'service',s.name,
        'starts_at',a.starts_at,'status',a.status,'appointment_id',a.id)
        ORDER BY a.starts_at,a.id),'[]'::jsonb) INTO scheduled_rows
    FROM public.appointments a
    JOIN public.patients p ON p.tenant_id=a.tenant_id AND p.id=a.patient_id
    JOIN public.services s ON s.tenant_id=a.tenant_id AND s.id=a.service_id
    WHERE a.tenant_id=tid AND a.doctor_id=did AND a.branch_id=bid
      AND (a.starts_at AT TIME ZONE zone)::date=day_local
      AND a.status NOT IN ('cancelled','no_show')
      AND NOT EXISTS (SELECT 1 FROM public.visits v
                      WHERE v.tenant_id=a.tenant_id AND v.appointment_id=a.id);

    SELECT coalesce(jsonb_agg(jsonb_build_object(
        'id',q.id,'visit_id',v.id,'patient_id',p.id,'patient_number',p.patient_number,
        'name',p.name,'dob',p.date_of_birth,'gender',p.gender,'phone',p.phone,
        'service',s.name,'number',q.number,'kind',q.kind,
        'status',q.status,'appointment_id',v.appointment_id,'created_at',q.created_at)
        ORDER BY q.number,q.created_at,q.id),'[]'::jsonb) INTO ticket_rows
    FROM public.queue_tickets q
    JOIN public.visits v ON v.tenant_id=q.tenant_id AND v.id=q.visit_id
    JOIN public.patients p ON p.tenant_id=v.tenant_id AND p.id=v.patient_id
    JOIN public.services s ON s.tenant_id=v.tenant_id AND s.id=v.service_id
    WHERE q.tenant_id=tid AND v.doctor_id=did AND q.branch_id=bid AND q.local_date=day_local
      AND q.status <> 'cancelled';

    RETURN jsonb_build_object('manager',elevated,'doctors',doctor_options,
        'doctor',doctor_data,'branch',branch_data,'date',day_local,
        'scheduled',scheduled_rows,'tickets',ticket_rows);
END
$workspace$;
ALTER FUNCTION governance.doctor_workspace_feed(date,uuid,uuid) OWNER TO blusser_security;
REVOKE ALL ON FUNCTION governance.doctor_workspace_feed(date,uuid,uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION governance.doctor_workspace_feed(date,uuid,uuid) TO blusser_app;
SQL
        );
    }

    public function down(): void
    {
        if (DB::selectOne('SELECT current_user AS name')->name !== 'blusser_owner') {
            throw new RuntimeException('Doctor workspace rollback requires owner credentials.');
        }
        DB::statement('DROP FUNCTION governance.doctor_workspace_feed(date,uuid,uuid)');
    }
};
