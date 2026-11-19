<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION governance.display_feed(bid uuid,rid uuid) RETURNS jsonb
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE tid uuid:=public.context_tenant(); d public.queue_displays; z text; clinic_name text; local_today date;
BEGIN
 IF NOT governance.display_branch_allowed('display.view',bid) OR NOT governance.display_branch_allowed('display.preview',bid) THEN RAISE EXCEPTION 'Denied' USING ERRCODE='42501'; END IF;
 SELECT * INTO d FROM public.queue_displays WHERE (tenant_id,branch_id,id)=(tid,bid,rid);
 IF d.id IS NULL THEN RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002'; END IF;
 SELECT coalesce(b.timezone,t.timezone),t.name INTO z,clinic_name FROM public.branches b JOIN public.tenants t ON t.id=b.tenant_id WHERE (b.tenant_id,b.id)=(tid,bid);
 local_today:=(now() AT TIME ZONE z)::date;
 RETURN (
 WITH resolved AS (
 SELECT q.id,q.number,q.kind,q.status,q.called_at,q.started_at,q.created_at,
 coalesce(res.room_id,governance.display_scheduled_room(tid,v.doctor_id,bid,local_today,
 (CASE WHEN q.status IN ('called','in_progress') THEN coalesce(q.started_at,q.called_at,now()) ELSE now() END AT TIME ZONE z)::time)) room_id
 FROM public.queue_tickets q JOIN public.visits v ON (v.tenant_id,v.id)=(q.tenant_id,q.visit_id)
 LEFT JOIN public.resource_reservations res ON (res.tenant_id,res.id)=(v.tenant_id,v.reservation_id) AND res.active
 WHERE (q.tenant_id,q.branch_id,q.local_date)=(tid,bid,local_today) AND q.status IN ('waiting','called','in_progress')
 AND governance.doctor_branch_live(v.doctor_id,bid)
 ), rows AS (
 SELECT a.*,r.number room, (CASE WHEN a.kind='walk_in' THEN 'J-' ELSE 'O-' END) || lpad(a.number::text,greatest(3,length(a.number::text)),'0') ticket
 FROM resolved a JOIN public.rooms r ON (r.tenant_id,r.branch_id,r.id)=(tid,bid,a.room_id) AND r.active AND r.availability='available'
 WHERE a.room_id=ANY(d.room_ids)
 ), waiting AS (
 SELECT *,row_number() OVER(PARTITION BY room_id ORDER BY created_at,id) AS rank FROM rows WHERE status='waiting'
 )
 SELECT jsonb_build_object('clinic',clinic_name,'timezone',z,'local_date',local_today,
 'called',coalesce((SELECT jsonb_agg(jsonb_build_object('ticket',ticket,'room',room,'call_key',id::text||':'||called_at::text,'status',status) ORDER BY coalesce(started_at,called_at) DESC NULLS LAST,id) FROM rows WHERE status IN ('called','in_progress')),'[]'::jsonb),
 'next',coalesce((SELECT jsonb_agg(jsonb_build_object('ticket',ticket,'room',room) ORDER BY created_at,id) FROM waiting WHERE rank=1),'[]'::jsonb))
 );
END $$;
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION governance.display_feed(bid uuid,rid uuid) RETURNS jsonb
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE tid uuid:=public.context_tenant(); d public.queue_displays; z text; clinic_name text; local_today date;
BEGIN
 IF NOT governance.display_branch_allowed('display.view',bid) OR NOT governance.display_branch_allowed('display.preview',bid) THEN RAISE EXCEPTION 'Denied' USING ERRCODE='42501'; END IF;
 SELECT * INTO d FROM public.queue_displays WHERE (tenant_id,branch_id,id)=(tid,bid,rid);
 IF d.id IS NULL THEN RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002'; END IF;
 SELECT coalesce(b.timezone,t.timezone),t.name INTO z,clinic_name FROM public.branches b JOIN public.tenants t ON t.id=b.tenant_id WHERE (b.tenant_id,b.id)=(tid,bid);
 local_today:=(now() AT TIME ZONE z)::date;
 RETURN (
 WITH resolved AS (
 SELECT q.id,q.number,q.kind,q.status,q.called_at,q.created_at,
 coalesce(res.room_id,governance.display_scheduled_room(tid,v.doctor_id,bid,local_today,
 (CASE WHEN q.status='called' THEN coalesce(q.called_at,now()) ELSE now() END AT TIME ZONE z)::time)) room_id
 FROM public.queue_tickets q JOIN public.visits v ON (v.tenant_id,v.id)=(q.tenant_id,q.visit_id)
 LEFT JOIN public.resource_reservations res ON (res.tenant_id,res.id)=(v.tenant_id,v.reservation_id) AND res.active
 WHERE (q.tenant_id,q.branch_id,q.local_date)=(tid,bid,local_today) AND q.status IN ('waiting','called')
 AND governance.doctor_branch_live(v.doctor_id,bid)
 ), rows AS (
 SELECT a.*,r.number room, (CASE WHEN a.kind='walk_in' THEN 'J-' ELSE 'O-' END) || lpad(a.number::text,greatest(3,length(a.number::text)),'0') ticket
 FROM resolved a JOIN public.rooms r ON (r.tenant_id,r.branch_id,r.id)=(tid,bid,a.room_id) AND r.active AND r.availability='available'
 WHERE a.room_id=ANY(d.room_ids)
 ), waiting AS (
 SELECT *,row_number() OVER(PARTITION BY room_id ORDER BY created_at,id) AS rank FROM rows WHERE status='waiting'
 )
 SELECT jsonb_build_object('clinic',clinic_name,'timezone',z,'local_date',local_today,
 'called',coalesce((SELECT jsonb_agg(jsonb_build_object('ticket',ticket,'room',room,'call_key',id::text||':'||called_at::text) ORDER BY called_at DESC NULLS LAST,id) FROM rows WHERE status='called'),'[]'::jsonb),
 'next',coalesce((SELECT jsonb_agg(jsonb_build_object('ticket',ticket,'room',room) ORDER BY created_at,id) FROM waiting WHERE rank=1),'[]'::jsonb))
 );
END $$;
SQL);
    }
};
