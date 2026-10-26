<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::selectOne('SELECT current_user AS name')->name !== 'blusser_owner') {
            throw new RuntimeException('Diagnosis migration requires owner credentials.');
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE public.visit_diagnoses (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  record_number bigint GENERATED ALWAYS AS IDENTITY UNIQUE,
  tenant_id uuid NOT NULL,
  visit_id uuid NOT NULL,
  patient_id uuid NOT NULL,
  doctor_id uuid NOT NULL,
  branch_id uuid NOT NULL,
  diagnosis_text text NOT NULL CHECK (length(btrim(diagnosis_text)) BETWEEN 1 AND 4000),
  diagnosis_type text NOT NULL CHECK (diagnosis_type IN ('preliminary','final')),
  icd_system text CHECK (icd_system IS NULL OR icd_system = 'ICD-10'),
  icd_code text CHECK (icd_code IS NULL OR icd_code ~ '^[A-Z][0-9]{2}(\.[0-9A-Z]{1,4})?$'),
  version bigint NOT NULL DEFAULT 1 CHECK (version > 0),
  created_by uuid NOT NULL REFERENCES public.users(id),
  updated_by uuid NOT NULL REFERENCES public.users(id),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (tenant_id,id),
  UNIQUE (tenant_id,visit_id),
  FOREIGN KEY (tenant_id,visit_id) REFERENCES public.visits(tenant_id,id),
  FOREIGN KEY (tenant_id,patient_id,branch_id) REFERENCES public.patient_branches(tenant_id,patient_id,branch_id),
  FOREIGN KEY (tenant_id,doctor_id,branch_id) REFERENCES public.doctor_branches(tenant_id,doctor_id,branch_id),
  CHECK ((icd_system IS NULL) = (icd_code IS NULL))
);
CREATE INDEX visit_diagnoses_patient_idx ON public.visit_diagnoses(tenant_id,patient_id,created_at DESC);

ALTER TABLE public.visit_diagnoses ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.visit_diagnoses FORCE ROW LEVEL SECURITY;
CREATE POLICY diagnosis_security_definer ON public.visit_diagnoses
  TO blusser_security USING (true) WITH CHECK (true);
CREATE POLICY diagnosis_migration_owner ON public.visit_diagnoses
  TO blusser_owner USING (true) WITH CHECK (true);
REVOKE ALL ON public.visit_diagnoses FROM PUBLIC,blusser_app;
GRANT SELECT,INSERT,UPDATE ON public.visit_diagnoses TO blusser_security;
GRANT USAGE,SELECT ON SEQUENCE public.visit_diagnoses_record_number_seq TO blusser_security;

-- No runtime table access is granted: all reads/writes pass these scoped functions.
CREATE FUNCTION governance.doctor_diagnosis_read(vid uuid)
RETURNS jsonb LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE
 tid uuid:=public.context_tenant();
 d public.visit_diagnoses;
BEGIN
 IF vid IS NULL OR NOT governance.scheduling_identity()
   OR public.permission_scope('workspace.view') IS NULL
   OR NOT governance.booking_visible('visits',vid,'visits.view') THEN
   RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002';
 END IF;
 SELECT * INTO d FROM public.visit_diagnoses
 WHERE tenant_id=tid AND visit_id=vid;
 IF NOT FOUND THEN RETURN jsonb_build_object('data',NULL); END IF;
 RETURN jsonb_build_object('data',jsonb_build_object(
   'id',d.id,
   'number','DX-'||CASE WHEN d.record_number<1000000 THEN lpad(d.record_number::text,6,'0') ELSE d.record_number::text END,
   'visit_id',d.visit_id,
   'patient_id',d.patient_id,
   'diagnosis_text',d.diagnosis_text,
   'diagnosis_type',d.diagnosis_type,
   'icd_system',d.icd_system,
   'icd_code',d.icd_code,
   'version',d.version,
   'updated_at',to_char(d.updated_at AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"')));
END $$;
ALTER FUNCTION governance.doctor_diagnosis_read(uuid) OWNER TO blusser_security;
REVOKE ALL ON FUNCTION governance.doctor_diagnosis_read(uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION governance.doctor_diagnosis_read(uuid) TO blusser_app;

CREATE FUNCTION governance.doctor_diagnosis_save(vid uuid, expected bigint, body jsonb)
RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE
 tid uuid:=public.context_tenant();
 v public.visits;
 d public.visit_diagnoses;
 code text:=nullif(upper(btrim(body->>'icd_code')),'');
 content text:=btrim(body->>'diagnosis_text');
 kind text:=body->>'diagnosis_type';
BEGIN
 IF vid IS NULL OR expected IS NULL OR expected<0 OR body IS NULL
   OR jsonb_typeof(body)<>'object'
   OR (SELECT count(*) FROM jsonb_object_keys(body))<>3
   OR NOT (body ?& ARRAY['diagnosis_text','diagnosis_type','icd_code'])
   OR content IS NULL OR length(content) NOT BETWEEN 1 AND 4000
   OR kind IS NULL OR kind NOT IN ('preliminary','final')
   OR (code IS NOT NULL AND code !~ '^[A-Z][0-9]{2}(\.[0-9A-Z]{1,4})?$') THEN
   RAISE EXCEPTION 'Invalid diagnosis' USING ERRCODE='22023';
 END IF;

 PERFORM governance.lock_users(ARRAY[public.context_user()]);
 PERFORM governance.actor();
 SELECT * INTO v FROM public.visits WHERE tenant_id=tid AND id=vid FOR UPDATE;
 IF v.id IS NULL OR v.status<>'in_progress'
   OR NOT governance.booking_visible('visits',vid,'visits.view')
   OR public.permission_scope('workspace.view') IS NULL
   OR public.permission_scope('workspace.consult') IS NULL
   OR NOT governance.queue_scope('queues.view',v.doctor_id,v.branch_id)
   OR NOT governance.doctor_branch_live(v.doctor_id,v.branch_id)
   OR NOT EXISTS(
     SELECT 1 FROM public.queue_tickets q
     WHERE (q.tenant_id,q.visit_id)=(tid,vid) AND q.status='in_progress')
   OR NOT EXISTS(
     SELECT 1 FROM public.doctor_profiles doc
     JOIN public.tenant_memberships m
       ON (m.tenant_id,m.id)=(doc.tenant_id,doc.membership_id)
     WHERE (doc.tenant_id,doc.id)=(tid,v.doctor_id)
       AND m.user_id=public.context_user() AND m.active
   )
   OR (public.permission_scope('workspace.consult')='assigned_branches'
       AND NOT public.branch_assigned(v.branch_id)) THEN
   RAISE EXCEPTION 'Not authorized for this active consultation' USING ERRCODE='42501';
 END IF;
 SELECT * INTO d FROM public.visit_diagnoses WHERE tenant_id=tid AND visit_id=vid FOR UPDATE;
 IF d.id IS NULL THEN
   IF expected<>0 THEN RAISE EXCEPTION 'Stale diagnosis' USING ERRCODE='40001'; END IF;
   INSERT INTO public.visit_diagnoses(
     tenant_id,visit_id,patient_id,doctor_id,branch_id,
     diagnosis_text,diagnosis_type,icd_system,icd_code,created_by,updated_by)
   VALUES (tid,vid,v.patient_id,v.doctor_id,v.branch_id,content,kind,
     CASE WHEN code IS NULL THEN NULL ELSE 'ICD-10' END,code,
     public.context_user(),public.context_user())
   RETURNING * INTO d;
 ELSE
   IF d.version<>expected THEN RAISE EXCEPTION 'Stale diagnosis' USING ERRCODE='40001'; END IF;
   UPDATE public.visit_diagnoses SET
     diagnosis_text=content,diagnosis_type=kind,
     icd_system=CASE WHEN code IS NULL THEN NULL ELSE 'ICD-10' END,
     icd_code=code,version=version+1,
     updated_by=public.context_user(),updated_at=clock_timestamp()
   WHERE (tenant_id,id)=(tid,d.id) RETURNING * INTO d;
 END IF;
 PERFORM governance.event('diagnosis.save','visit_diagnosis',d.id,tid);
 RETURN governance.doctor_diagnosis_read(vid);
END $$;
ALTER FUNCTION governance.doctor_diagnosis_save(uuid,bigint,jsonb) OWNER TO blusser_security;
REVOKE ALL ON FUNCTION governance.doctor_diagnosis_save(uuid,bigint,jsonb) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION governance.doctor_diagnosis_save(uuid,bigint,jsonb) TO blusser_app;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Clinical diagnoses are retained. Rollback needs a reviewed forward migration.');
    }
};
