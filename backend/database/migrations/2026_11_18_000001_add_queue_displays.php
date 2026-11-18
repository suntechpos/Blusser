<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::selectOne('SELECT current_user AS name')->name !== 'blusser_owner') {
            throw new RuntimeException('Display migration requires owner credentials.');
        }
        DB::unprepared(<<<'SQL'
CREATE TABLE public.queue_displays (
 id uuid PRIMARY KEY, tenant_id uuid NOT NULL REFERENCES public.tenants(id),
 branch_id uuid NOT NULL, name text NOT NULL CHECK(length(btrim(name)) BETWEEN 1 AND 70),
 zone text NOT NULL CHECK(length(btrim(zone)) BETWEEN 1 AND 70),
 floor text NOT NULL CHECK(length(btrim(floor)) BETWEEN 1 AND 70),
 room_ids uuid[] NOT NULL CHECK(cardinality(room_ids) BETWEEN 1 AND 100),
 sound boolean NOT NULL DEFAULT true, theme text NOT NULL CHECK(theme IN ('dark','light')),
 language text NOT NULL CHECK(language IN ('uz','ru')),
 version bigint NOT NULL DEFAULT 1, created_at timestamptz NOT NULL DEFAULT now(),
 updated_at timestamptz NOT NULL DEFAULT now(),
 FOREIGN KEY(tenant_id,branch_id) REFERENCES public.branches(tenant_id,id)
);
ALTER TABLE public.queue_displays ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.queue_displays FORCE ROW LEVEL SECURITY;
CREATE POLICY display_owner ON public.queue_displays TO blusser_owner USING(true) WITH CHECK(true);
CREATE POLICY display_security ON public.queue_displays TO blusser_security USING(true) WITH CHECK(true);
REVOKE ALL ON public.queue_displays FROM PUBLIC,blusser_app;
GRANT SELECT,INSERT,UPDATE,DELETE ON public.queue_displays TO blusser_security;
CREATE INDEX queue_displays_branch ON public.queue_displays(tenant_id,branch_id);

CREATE FUNCTION governance.display_branch_allowed(code text,bid uuid) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog AS $$
 SELECT governance.scheduling_identity() AND code IN ('display.view','display.preview','display.create','display.update','display.delete')
 AND EXISTS(SELECT 1 FROM public.branches b WHERE (b.tenant_id,b.id)=(public.context_tenant(),bid) AND b.active)
 AND CASE public.permission_scope(code) WHEN 'entire_clinic' THEN true WHEN 'assigned_branches' THEN public.branch_assigned(bid) ELSE false END
$$;

CREATE FUNCTION governance.display_record(d public.queue_displays) RETURNS jsonb
LANGUAGE sql STABLE SET search_path=pg_catalog AS $$
 SELECT jsonb_build_object('id',d.id,'branch_id',d.branch_id,'name',d.name,'zone',d.zone,'floor',d.floor,'room_ids',d.room_ids,
 'sound',d.sound,'theme',d.theme,'language',d.language,'version',d.version)
$$;

CREATE FUNCTION governance.display_index(bid uuid) RETURNS jsonb
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path=pg_catalog AS $$
BEGIN
 IF NOT governance.display_branch_allowed('display.view',bid) THEN RAISE EXCEPTION 'Denied' USING ERRCODE='42501'; END IF;
 RETURN jsonb_build_object(
 'data',coalesce((SELECT jsonb_agg(governance.display_record(d) ORDER BY d.created_at,d.id) FROM public.queue_displays d WHERE (d.tenant_id,d.branch_id)=(public.context_tenant(),bid)),'[]'::jsonb),
 'rooms',coalesce((SELECT jsonb_agg(jsonb_build_object('id',r.id,'number',r.number,'active',r.active) ORDER BY r.number,r.id) FROM public.rooms r WHERE (r.tenant_id,r.branch_id)=(public.context_tenant(),bid)),'[]'::jsonb));
END $$;

CREATE FUNCTION governance.display_save(bid uuid,rid uuid,expected bigint,body jsonb) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE tid uuid:=public.context_tenant(); d public.queue_displays; ids uuid[];
BEGIN
 PERFORM governance.lock_users(ARRAY[public.context_user()]); PERFORM governance.actor();
 IF rid IS NULL OR expected IS NULL OR expected<0 OR NOT governance.display_branch_allowed('display.view',bid)
 OR NOT governance.display_branch_allowed(CASE WHEN expected=0 THEN 'display.create' ELSE 'display.update' END,bid)
 THEN RAISE EXCEPTION 'Denied' USING ERRCODE='42501'; END IF;
 IF body IS NULL OR jsonb_typeof(body)<>'object' OR NOT body ?& ARRAY['name','zone','floor','room_ids','sound','theme','language']
 OR (SELECT count(*) FROM jsonb_object_keys(body))<>7 OR jsonb_typeof(body->'room_ids')<>'array'
 OR jsonb_typeof(body->'sound')<>'boolean' OR jsonb_typeof(body->'name')<>'string'
 OR jsonb_typeof(body->'zone')<>'string' OR jsonb_typeof(body->'floor')<>'string'
 OR jsonb_typeof(body->'theme')<>'string' OR jsonb_typeof(body->'language')<>'string'
 THEN RAISE EXCEPTION 'Invalid display' USING ERRCODE='22023'; END IF;
 SELECT array_agg(DISTINCT value::uuid ORDER BY value::uuid) INTO ids FROM jsonb_array_elements_text(body->'room_ids');
 IF coalesce(cardinality(ids),0) NOT BETWEEN 1 AND 100 OR
 (SELECT count(*) FROM public.rooms WHERE tenant_id=tid AND branch_id=bid AND active AND id=ANY(ids))<>cardinality(ids)
 THEN RAISE EXCEPTION 'Invalid rooms' USING ERRCODE='22023'; END IF;
 PERFORM id FROM public.tenants WHERE id=tid FOR UPDATE;
 SELECT * INTO d FROM public.queue_displays WHERE (tenant_id,branch_id,id)=(tid,bid,rid) FOR UPDATE;
 IF expected=0 THEN
  IF d.id IS NOT NULL THEN
   IF d.version=1 AND d.name=btrim(body->>'name') AND d.zone=btrim(body->>'zone') AND d.floor=btrim(body->>'floor') AND d.room_ids=ids AND d.sound=(body->>'sound')::boolean AND d.theme=body->>'theme' AND d.language=body->>'language' THEN RETURN governance.display_record(d); END IF;
   RAISE EXCEPTION 'Conflict' USING ERRCODE='40001';
  END IF;
  INSERT INTO public.queue_displays(id,tenant_id,branch_id,name,zone,floor,room_ids,sound,theme,language)
   VALUES(rid,tid,bid,btrim(body->>'name'),btrim(body->>'zone'),btrim(body->>'floor'),ids,(body->>'sound')::boolean,body->>'theme',body->>'language') RETURNING * INTO d;
 ELSE
  IF d.id IS NULL THEN RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002'; END IF;
  IF d.version<>expected THEN RAISE EXCEPTION 'Stale' USING ERRCODE='40001'; END IF;
  UPDATE public.queue_displays SET name=btrim(body->>'name'),zone=btrim(body->>'zone'),floor=btrim(body->>'floor'),room_ids=ids,sound=(body->>'sound')::boolean,theme=body->>'theme',language=body->>'language',version=version+1,updated_at=clock_timestamp()
  WHERE (tenant_id,branch_id,id)=(tid,bid,rid) RETURNING * INTO d;
 END IF;
 PERFORM governance.event('display.save','queue_display',d.id,tid);
 RETURN governance.display_record(d);
END $$;

CREATE FUNCTION governance.display_delete(bid uuid,rid uuid,expected bigint) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE tid uuid:=public.context_tenant(); d public.queue_displays;
BEGIN
 PERFORM governance.lock_users(ARRAY[public.context_user()]); PERFORM governance.actor();
 IF NOT governance.display_branch_allowed('display.view',bid) OR NOT governance.display_branch_allowed('display.delete',bid) THEN RAISE EXCEPTION 'Denied' USING ERRCODE='42501'; END IF;
 SELECT * INTO d FROM public.queue_displays WHERE (tenant_id,branch_id,id)=(tid,bid,rid) FOR UPDATE;
 IF d.id IS NULL THEN RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002'; END IF;
 IF d.version IS DISTINCT FROM expected THEN RAISE EXCEPTION 'Stale' USING ERRCODE='40001'; END IF;
 DELETE FROM public.queue_displays WHERE (tenant_id,branch_id,id)=(tid,bid,rid);
 PERFORM governance.event('display.delete','queue_display',rid,tid);
 RETURN jsonb_build_object('deleted',true);
END $$;

-- Resolve a walk-in room only if the doctor's effective work schedule yields
-- one unique room at the relevant instant. Never guess or select an arbitrary room.
CREATE FUNCTION governance.display_scheduled_room(tid uuid,did uuid,bid uuid,day_local date,at_local time) RETURNS uuid
LANGUAGE sql STABLE SET search_path=pg_catalog AS $$
 WITH candidates AS (
  SELECT e.room_id FROM public.schedule_exceptions e
  WHERE (e.tenant_id,e.doctor_id,e.branch_id,e.local_date)=(tid,did,bid,day_local) AND e.kind='replace'
  AND at_local>=e.local_start AND at_local<e.local_end
  AND NOT EXISTS(SELECT 1 FROM public.schedule_exception_breaks x WHERE (x.tenant_id,x.schedule_exception_id)=(tid,e.id) AND at_local>=x.local_start AND at_local<x.local_end)
  UNION
  SELECT s.room_id FROM public.schedule_rules s
  WHERE (s.tenant_id,s.doctor_id,s.branch_id)=(tid,did,bid) AND s.active
  AND s.weekday=extract(isodow FROM day_local) AND day_local>=s.effective_from AND (s.effective_to IS NULL OR day_local<=s.effective_to)
  AND at_local>=s.local_start AND at_local<s.local_end
  AND NOT EXISTS(SELECT 1 FROM public.schedule_exceptions e WHERE (e.tenant_id,e.doctor_id,e.branch_id,e.local_date)=(tid,did,bid,day_local))
  AND NOT EXISTS(SELECT 1 FROM public.schedule_breaks x WHERE (x.tenant_id,x.schedule_rule_id)=(tid,s.id) AND at_local>=x.local_start AND at_local<x.local_end)
 )
 SELECT CASE WHEN count(*)=1 THEN (array_agg(room_id))[1] ELSE NULL END FROM candidates
$$;

CREATE FUNCTION governance.display_feed(bid uuid,rid uuid) RETURNS jsonb
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

ALTER FUNCTION governance.display_branch_allowed(text,uuid) OWNER TO blusser_security;
ALTER FUNCTION governance.display_record(public.queue_displays) OWNER TO blusser_security;
ALTER FUNCTION governance.display_index(uuid) OWNER TO blusser_security;
ALTER FUNCTION governance.display_save(uuid,uuid,bigint,jsonb) OWNER TO blusser_security;
ALTER FUNCTION governance.display_delete(uuid,uuid,bigint) OWNER TO blusser_security;
ALTER FUNCTION governance.display_scheduled_room(uuid,uuid,uuid,date,time) OWNER TO blusser_security;
ALTER FUNCTION governance.display_feed(uuid,uuid) OWNER TO blusser_security;
REVOKE ALL ON FUNCTION governance.display_branch_allowed(text,uuid), governance.display_record(public.queue_displays), governance.display_index(uuid), governance.display_save(uuid,uuid,bigint,jsonb), governance.display_delete(uuid,uuid,bigint), governance.display_scheduled_room(uuid,uuid,uuid,date,time), governance.display_feed(uuid,uuid) FROM PUBLIC,blusser_app;
GRANT EXECUTE ON FUNCTION governance.display_index(uuid), governance.display_save(uuid,uuid,bigint,jsonb), governance.display_delete(uuid,uuid,bigint), governance.display_feed(uuid,uuid) TO blusser_app;
SQL);
    }

    public function down(): void
    {
        if (DB::selectOne('SELECT current_user AS name')->name !== 'blusser_owner' || DB::table('queue_displays')->exists()) {
            throw new RuntimeException('Display configurations exist; use a forward migration.');
        }
        DB::unprepared('DROP FUNCTION governance.display_feed(uuid,uuid), governance.display_save(uuid,uuid,bigint,jsonb), governance.display_delete(uuid,uuid,bigint), governance.display_index(uuid), governance.display_scheduled_room(uuid,uuid,uuid,date,time), governance.display_record(public.queue_displays), governance.display_branch_allowed(text,uuid); DROP TABLE public.queue_displays;');
    }
};
