<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up(): void {
  if (DB::selectOne('SELECT current_user AS name')->name !== 'blusser_owner') {
   throw new RuntimeException('Doctor referral migration requires owner credentials.');
  }
  DB::unprepared(<<<'SQL'
CREATE TABLE public.visit_referrals (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 tenant_id uuid NOT NULL,
 visit_id uuid NOT NULL,
 patient_id uuid NOT NULL,
 doctor_id uuid NOT NULL,
 branch_id uuid NOT NULL,
 location text NOT NULL CHECK(location IN ('internal','external')),
 referral_type text NOT NULL CHECK(referral_type IN ('laboratory','diagnostic','consultation')),
 service_id uuid NOT NULL,
 target_branch_id uuid,
 external_clinic text NOT NULL DEFAULT '' CHECK(length(external_clinic)<=255),
 note text NOT NULL DEFAULT '' CHECK(length(note)<=4000),
 status text NOT NULL DEFAULT 'ordered' CHECK(status IN ('ordered','cancelled')),
 created_by uuid NOT NULL REFERENCES public.users(id),
 created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id),
 FOREIGN KEY(tenant_id,visit_id) REFERENCES public.visits(tenant_id,id),
 FOREIGN KEY(tenant_id,service_id) REFERENCES public.services(tenant_id,id),
 FOREIGN KEY(tenant_id,branch_id) REFERENCES public.branches(tenant_id,id),
 FOREIGN KEY(tenant_id,target_branch_id) REFERENCES public.branches(tenant_id,id)
);
CREATE INDEX visit_referrals_visit ON public.visit_referrals(tenant_id,visit_id,created_at);
ALTER TABLE public.visit_referrals ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.visit_referrals FORCE ROW LEVEL SECURITY;
CREATE POLICY referral_owner ON public.visit_referrals TO blusser_owner USING(true) WITH CHECK(true);
CREATE POLICY referral_security ON public.visit_referrals TO blusser_security USING(true) WITH CHECK(true);
REVOKE ALL ON public.visit_referrals FROM PUBLIC,blusser_app;
GRANT SELECT,INSERT ON public.visit_referrals TO blusser_security;

CREATE FUNCTION governance.doctor_referral_list(vid uuid)
RETURNS jsonb LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE tid uuid:=public.context_tenant();
BEGIN
 IF vid IS NULL OR NOT governance.scheduling_identity()
 OR public.permission_scope('workspace.view') IS NULL
 OR NOT governance.booking_visible('visits',vid,'visits.view') THEN
  RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002';
 END IF;
 RETURN jsonb_build_object('data',coalesce((
  SELECT jsonb_agg(jsonb_build_object(
   'id',r.id,'visit_id',r.visit_id,'location',r.location,'referral_type',r.referral_type,
   'service_id',r.service_id,'service_name',s.name,'target_branch_id',r.target_branch_id,
   'target_branch_name',b.name,'external_clinic',r.external_clinic,'note',r.note,
   'status',r.status,'created_at',to_char(r.created_at AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"'))
    ORDER BY r.created_at,r.id)
  FROM public.visit_referrals r
  JOIN public.services s ON (s.tenant_id,s.id)=(r.tenant_id,r.service_id)
  LEFT JOIN public.branches b ON (b.tenant_id,b.id)=(r.tenant_id,r.target_branch_id)
  WHERE (r.tenant_id,r.visit_id)=(tid,vid)
 ),'[]'::jsonb));
END $$;
ALTER FUNCTION governance.doctor_referral_list(uuid) OWNER TO blusser_security;
REVOKE ALL ON FUNCTION governance.doctor_referral_list(uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION governance.doctor_referral_list(uuid) TO blusser_app;

CREATE FUNCTION governance.doctor_referral_options(vid uuid)
RETURNS jsonb LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE tid uuid:=public.context_tenant();
BEGIN
 IF vid IS NULL OR NOT governance.scheduling_identity()
 OR public.permission_scope('workspace.view') IS NULL
 OR NOT governance.booking_visible('visits',vid,'visits.view') THEN
  RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002';
 END IF;
 RETURN jsonb_build_object(
 'services',coalesce((SELECT jsonb_agg(jsonb_build_object(
   'id',s.id,'name',s.name,'type',s.service_type,'branch_ids',
   coalesce((SELECT jsonb_agg(sb.branch_id) FROM public.service_branches sb
    WHERE (sb.tenant_id,sb.service_id)=(s.tenant_id,s.id)),'[]'::jsonb))
   ORDER BY s.name)
   FROM public.services s WHERE s.tenant_id=tid AND s.active
   AND s.service_type IN ('laboratory','diagnostic','consultation')),'[]'::jsonb),
 'branches',coalesce((SELECT jsonb_agg(jsonb_build_object('id',b.id,'name',b.name) ORDER BY b.name)
   FROM public.branches b WHERE b.tenant_id=tid AND b.active),'[]'::jsonb));
END $$;
ALTER FUNCTION governance.doctor_referral_options(uuid) OWNER TO blusser_security;
REVOKE ALL ON FUNCTION governance.doctor_referral_options(uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION governance.doctor_referral_options(uuid) TO blusser_app;

CREATE FUNCTION governance.doctor_referral_create(vid uuid,body jsonb)
RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE
 tid uuid:=public.context_tenant(); v public.visits; r public.visit_referrals;
 sid uuid; bid uuid; loc text; kind text; clinic text; comments text;
BEGIN
 IF body IS NULL OR jsonb_typeof(body)<>'object'
 OR (SELECT count(*) FROM jsonb_object_keys(body))<>6
 OR NOT body ?& ARRAY['location','referral_type','service_id','target_branch_id','external_clinic','note']
 OR jsonb_typeof(body->'location')<>'string'
 OR jsonb_typeof(body->'referral_type')<>'string'
 OR jsonb_typeof(body->'service_id')<>'string'
 OR jsonb_typeof(body->'note')<>'string'
 OR jsonb_typeof(body->'external_clinic')<>'string' THEN
  RAISE EXCEPTION 'Invalid referral' USING ERRCODE='22023';
 END IF;
 loc:=body->>'location'; kind:=body->>'referral_type';
 sid:=(body->>'service_id')::uuid;
 bid:=nullif(body->>'target_branch_id','')::uuid;
 clinic:=btrim(body->>'external_clinic');
 comments:=btrim(body->>'note');
 IF loc NOT IN ('internal','external') OR kind NOT IN ('laboratory','diagnostic','consultation')
 OR length(clinic)>255 OR length(comments)>4000
 OR (loc='internal' AND bid IS NULL)
 OR (loc='external' AND bid IS NOT NULL) THEN
  RAISE EXCEPTION 'Invalid referral target' USING ERRCODE='22023';
 END IF;
 PERFORM governance.lock_users(ARRAY[public.context_user()]);
 PERFORM governance.actor();
 SELECT * INTO v FROM public.visits WHERE (tenant_id,id)=(tid,vid) FOR UPDATE;
 IF v.id IS NULL OR v.status<>'in_progress'
 OR NOT governance.booking_visible('visits',vid,'visits.view')
 OR public.permission_scope('workspace.view') IS NULL
 OR public.permission_scope('workspace.consult') IS NULL
 OR NOT governance.queue_scope('queues.view',v.doctor_id,v.branch_id)
 OR NOT governance.doctor_branch_live(v.doctor_id,v.branch_id)
 OR NOT EXISTS(SELECT 1 FROM public.queue_tickets q WHERE (q.tenant_id,q.visit_id)=(tid,vid) AND q.status='in_progress')
 OR NOT EXISTS(SELECT 1 FROM public.doctor_profiles d JOIN public.tenant_memberships m
   ON (m.tenant_id,m.id)=(d.tenant_id,d.membership_id)
   WHERE (d.tenant_id,d.id)=(tid,v.doctor_id) AND m.user_id=public.context_user() AND m.active)
 THEN RAISE EXCEPTION 'Not authorized for this active consultation' USING ERRCODE='42501'; END IF;
 IF NOT EXISTS(SELECT 1 FROM public.services s WHERE (s.tenant_id,s.id)=(tid,sid)
  AND s.active AND s.service_type=kind)
 OR (loc='internal' AND (NOT EXISTS(SELECT 1 FROM public.branches b
   WHERE (b.tenant_id,b.id)=(tid,bid) AND b.active)
  OR NOT governance.catalog_service_available(sid,bid))) THEN
  RAISE EXCEPTION 'Service or branch unavailable' USING ERRCODE='22023';
 END IF;
 INSERT INTO public.visit_referrals(tenant_id,visit_id,patient_id,doctor_id,branch_id,
  location,referral_type,service_id,target_branch_id,external_clinic,note,created_by)
 VALUES(tid,vid,v.patient_id,v.doctor_id,v.branch_id,loc,kind,sid,bid,clinic,comments,public.context_user())
 RETURNING * INTO r;
 PERFORM governance.event('referral.create','visit_referral',r.id,tid);
 RETURN jsonb_build_object('data',jsonb_build_object('id',r.id,'visit_id',r.visit_id));
END $$;
ALTER FUNCTION governance.doctor_referral_create(uuid,jsonb) OWNER TO blusser_security;
REVOKE ALL ON FUNCTION governance.doctor_referral_create(uuid,jsonb) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION governance.doctor_referral_create(uuid,jsonb) TO blusser_app;
SQL);
 }
 public function down(): void { throw new RuntimeException('Referral data is retained; use reviewed forward migration.'); }
};