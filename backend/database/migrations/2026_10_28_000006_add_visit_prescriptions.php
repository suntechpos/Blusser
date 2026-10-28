<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up(): void {
  if(DB::selectOne('SELECT current_user AS name')->name !== 'blusser_owner') throw new RuntimeException('Owner credentials required.');
  DB::unprepared(<<<'SQL'
CREATE TABLE public.visit_prescriptions (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),tenant_id uuid NOT NULL,
 visit_id uuid NOT NULL, patient_id uuid NOT NULL, doctor_id uuid NOT NULL,
 branch_id uuid NOT NULL, note text NOT NULL DEFAULT '' CHECK(length(note)<=4000),
 medicines jsonb NOT NULL CHECK(jsonb_typeof(medicines)='array' AND jsonb_array_length(medicines) BETWEEN 1 AND 30),
 version bigint NOT NULL DEFAULT 1 CHECK(version>0),created_by uuid NOT NULL REFERENCES public.users(id),
 updated_by uuid NOT NULL REFERENCES public.users(id),
 created_at timestamptz NOT NULL DEFAULT now(),updated_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id), FOREIGN KEY(tenant_id,visit_id) REFERENCES public.visits(tenant_id,id)
);
CREATE INDEX visit_prescriptions_visit ON public.visit_prescriptions(tenant_id,visit_id,created_at);
ALTER TABLE public.visit_prescriptions ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.visit_prescriptions FORCE ROW LEVEL SECURITY;
CREATE POLICY prescription_owner ON public.visit_prescriptions TO blusser_owner USING(true) WITH CHECK(true);
CREATE POLICY prescription_security ON public.visit_prescriptions TO blusser_security USING(true) WITH CHECK(true);
REVOKE ALL ON public.visit_prescriptions FROM PUBLIC,blusser_app;
GRANT SELECT,INSERT,UPDATE ON public.visit_prescriptions TO blusser_security;
CREATE FUNCTION governance.doctor_prescription_list(vid uuid)
RETURNS jsonb LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path=pg_catalog AS $$
BEGIN
 IF vid IS NULL OR NOT governance.scheduling_identity()
 OR public.permission_scope('workspace.view') IS NULL
 OR NOT governance.booking_visible('visits',vid,'visits.view') THEN
 RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002'; END IF;
 RETURN jsonb_build_object('data',coalesce((
 SELECT jsonb_agg(jsonb_build_object('id',r.id,'visit_id',r.visit_id,
 'medicines',r.medicines,'note',r.note,'version',r.version,
 'created_at',to_char(r.created_at AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"'))
 ORDER BY r.created_at,r.id)
 FROM public.visit_prescriptions r WHERE (r.tenant_id,r.visit_id)=(public.context_tenant(),vid)
 ),'[]'::jsonb));
END $$;
ALTER FUNCTION governance.doctor_prescription_list(uuid) OWNER TO blusser_security;
REVOKE ALL ON FUNCTION governance.doctor_prescription_list(uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION governance.doctor_prescription_list(uuid) TO blusser_app;
CREATE FUNCTION governance.doctor_prescription_save(vid uuid,rid uuid,expected bigint,body jsonb)
RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE
 tid uuid:=public.context_tenant();v public.visits;r public.visit_prescriptions;
 med jsonb;item jsonb;note_value text;key text;
BEGIN
 IF vid IS NULL OR expected IS NULL OR expected<0 OR body IS NULL OR jsonb_typeof(body)<>'object'
 OR (SELECT count(*) FROM jsonb_object_keys(body))<>2
 OR NOT body ?& ARRAY['medicines','note']
 OR jsonb_typeof(body->'medicines')<>'array'
 OR jsonb_typeof(body->'note')<>'string'
 OR jsonb_array_length(body->'medicines') NOT BETWEEN 1 AND 30 THEN
 RAISE EXCEPTION 'Invalid prescription' USING ERRCODE='22023'; END IF;
 note_value:=btrim(body->>'note');med:=body->'medicines';
 IF length(note_value)>4000 THEN RAISE EXCEPTION 'Invalid note' USING ERRCODE='22023'; END IF;
 FOR item IN SELECT value FROM jsonb_array_elements(med) LOOP
  IF jsonb_typeof(item)<>'object'
   OR (SELECT count(*) FROM jsonb_object_keys(item))<>9
   OR NOT item ?& ARRAY['name','form','strength','dose','unit','route','frequency','duration','instruction'] THEN
   RAISE EXCEPTION 'Invalid medicine' USING ERRCODE='22023'; END IF;
  FOREACH key IN ARRAY ARRAY['name','form','strength','dose','unit','route','frequency','duration','instruction'] LOOP
   IF jsonb_typeof(item->key)<>'string' OR length(btrim(item->>key))>500 THEN
    RAISE EXCEPTION 'Invalid medicine field' USING ERRCODE='22023'; END IF;
  END LOOP;
  IF btrim(item->>'name')='' OR btrim(item->>'dose')='' OR btrim(item->>'unit')=''
   OR btrim(item->>'frequency')='' OR btrim(item->>'duration')='' THEN
    RAISE EXCEPTION 'Required medicine fields missing' USING ERRCODE='22023'; END IF;
 END LOOP;
 PERFORM governance.lock_users(ARRAY[public.context_user()]);
 PERFORM governance.actor();
 SELECT * INTO v FROM public.visits WHERE (tenant_id,id)=(tid,vid) FOR UPDATE;
 IF v.id IS NULL OR v.status<>'in_progress'
 OR NOT governance.booking_visible('visits',vid,'visits.view')
 OR public.permission_scope('workspace.view') IS NULL
 OR public.permission_scope('workspace.consult') IS NULL
 OR NOT governance.queue_scope('queues.view',v.doctor_id,v.branch_id)
 OR NOT governance.doctor_branch_live(v.doctor_id,v.branch_id)
 OR (public.permission_scope('workspace.consult')='assigned_branches' AND NOT public.branch_assigned(v.branch_id))
 OR NOT EXISTS(SELECT 1 FROM public.queue_tickets q WHERE (q.tenant_id,q.visit_id)=(tid,vid) AND q.status='in_progress')
 OR NOT EXISTS(SELECT 1 FROM public.doctor_profiles d JOIN public.tenant_memberships m
 ON (m.tenant_id,m.id)=(d.tenant_id,d.membership_id)
 WHERE (d.tenant_id,d.id)=(tid,v.doctor_id) AND m.user_id=public.context_user() AND m.active)
 THEN RAISE EXCEPTION 'Not authorized for this consultation' USING ERRCODE='42501'; END IF;
 IF rid IS NULL THEN
  IF expected<>0 THEN RAISE EXCEPTION 'Stale prescription' USING ERRCODE='40001'; END IF;
  INSERT INTO public.visit_prescriptions(tenant_id,visit_id,patient_id,doctor_id,branch_id,note,medicines,created_by,updated_by)
  VALUES(tid,vid,v.patient_id,v.doctor_id,v.branch_id,note_value,med,public.context_user(),public.context_user())
  RETURNING * INTO r;
 ELSE
  SELECT * INTO r FROM public.visit_prescriptions WHERE (tenant_id,id,visit_id)=(tid,rid,vid) FOR UPDATE;
  IF r.id IS NULL THEN RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002'; END IF;
  IF r.version<>expected THEN RAISE EXCEPTION 'Stale prescription' USING ERRCODE='40001'; END IF;
  UPDATE public.visit_prescriptions SET medicines=med,note=note_value,version=version+1,updated_at=clock_timestamp(),
  updated_by=public.context_user() WHERE (tenant_id,id)=(tid,rid) RETURNING * INTO r;
 END IF;
 PERFORM governance.event('prescription.save','visit_prescription',r.id,tid);
 RETURN jsonb_build_object('data',jsonb_build_object('id',r.id,'visit_id',r.visit_id,
  'medicines',r.medicines,'note',r.note,'version',r.version,
  'created_at',to_char(r.created_at AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"')));
END $$;
ALTER FUNCTION governance.doctor_prescription_save(uuid,uuid,bigint,jsonb) OWNER TO blusser_security;
REVOKE ALL ON FUNCTION governance.doctor_prescription_save(uuid,uuid,bigint,jsonb) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION governance.doctor_prescription_save(uuid,uuid,bigint,jsonb) TO blusser_app;
SQL);
 }
 public function down(): void { throw new RuntimeException('Forward-only clinical data migration.'); }
};