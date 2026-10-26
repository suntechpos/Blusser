<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::selectOne('SELECT current_user AS name')->name !== 'blusser_owner') {
            throw new RuntimeException('Clinical notes migration requires owner credentials.');
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE public.visit_clinical_notes (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id uuid NOT NULL,
  visit_id uuid NOT NULL,
  patient_id uuid NOT NULL,
  doctor_id uuid NOT NULL,
  branch_id uuid NOT NULL,
  complaints text NOT NULL DEFAULT '' CHECK (length(complaints) <= 10000),
  anamnesis text NOT NULL DEFAULT '' CHECK (length(anamnesis) <= 10000),
  examination text NOT NULL DEFAULT '' CHECK (length(examination) <= 10000),
  version bigint NOT NULL DEFAULT 1 CHECK (version > 0),
  created_by uuid NOT NULL REFERENCES public.users(id),
  updated_by uuid NOT NULL REFERENCES public.users(id),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (tenant_id,id),
  UNIQUE (tenant_id,visit_id),
  FOREIGN KEY (tenant_id,visit_id) REFERENCES public.visits(tenant_id,id),
  FOREIGN KEY (tenant_id,patient_id,branch_id) REFERENCES public.patient_branches(tenant_id,patient_id,branch_id),
  FOREIGN KEY (tenant_id,doctor_id,branch_id) REFERENCES public.doctor_branches(tenant_id,doctor_id,branch_id)
);

ALTER TABLE public.visit_clinical_notes ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.visit_clinical_notes FORCE ROW LEVEL SECURITY;
CREATE POLICY clinical_note_security_definer ON public.visit_clinical_notes
  TO blusser_security USING (true) WITH CHECK (true);
CREATE POLICY clinical_note_migration_owner ON public.visit_clinical_notes
  TO blusser_owner USING (true) WITH CHECK (true);
REVOKE ALL ON public.visit_clinical_notes FROM PUBLIC,blusser_app;
GRANT SELECT,INSERT,UPDATE ON public.visit_clinical_notes TO blusser_security;

CREATE FUNCTION governance.doctor_clinical_note_read(vid uuid)
RETURNS jsonb LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE
 tid uuid:=public.context_tenant();
 note public.visit_clinical_notes;
BEGIN
 IF vid IS NULL OR NOT governance.scheduling_identity()
   OR public.permission_scope('workspace.view') IS NULL
   OR NOT governance.booking_visible('visits',vid,'visits.view') THEN
   RAISE EXCEPTION 'Unavailable' USING ERRCODE='P0002';
 END IF;
 SELECT * INTO note FROM public.visit_clinical_notes
   WHERE tenant_id=tid AND visit_id=vid;
 IF NOT FOUND THEN RETURN jsonb_build_object('data',NULL); END IF;
 RETURN jsonb_build_object('data',jsonb_build_object(
   'id',note.id,'visit_id',note.visit_id,'patient_id',note.patient_id,
   'complaints',note.complaints,'anamnesis',note.anamnesis,
   'examination',note.examination,'version',note.version,
   'updated_at',to_char(note.updated_at AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"')));
END $$;
ALTER FUNCTION governance.doctor_clinical_note_read(uuid) OWNER TO blusser_security;
REVOKE ALL ON FUNCTION governance.doctor_clinical_note_read(uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION governance.doctor_clinical_note_read(uuid) TO blusser_app;

CREATE FUNCTION governance.doctor_clinical_note_save(vid uuid, expected bigint, body jsonb)
RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE
 tid uuid:=public.context_tenant();
 v public.visits;
 note public.visit_clinical_notes;
 complaints_text text:=btrim(body->>'complaints');
 anamnesis_text text:=btrim(body->>'anamnesis');
 examination_text text:=btrim(body->>'examination');
BEGIN
 IF vid IS NULL OR expected IS NULL OR expected<0 OR body IS NULL
   OR jsonb_typeof(body)<>'object'
   OR (SELECT count(*) FROM jsonb_object_keys(body))<>3
   OR NOT (body ?& ARRAY['complaints','anamnesis','examination'])
   OR jsonb_typeof(body->'complaints')<>'string'
   OR jsonb_typeof(body->'anamnesis')<>'string'
   OR jsonb_typeof(body->'examination')<>'string'
   OR complaints_text IS NULL OR length(complaints_text)>10000
   OR anamnesis_text IS NULL OR length(anamnesis_text)>10000
   OR examination_text IS NULL OR length(examination_text)>10000 THEN
   RAISE EXCEPTION 'Invalid clinical note' USING ERRCODE='22023';
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
       AND m.user_id=public.context_user() AND m.active)
   OR (public.permission_scope('workspace.consult')='assigned_branches'
       AND NOT public.branch_assigned(v.branch_id)) THEN
   RAISE EXCEPTION 'Not authorized for this active consultation' USING ERRCODE='42501';
 END IF;

 SELECT * INTO note FROM public.visit_clinical_notes
 WHERE tenant_id=tid AND visit_id=vid FOR UPDATE;
 IF note.id IS NULL THEN
   IF expected<>0 THEN RAISE EXCEPTION 'Stale clinical note' USING ERRCODE='40001'; END IF;
   INSERT INTO public.visit_clinical_notes(
     tenant_id,visit_id,patient_id,doctor_id,branch_id,
     complaints,anamnesis,examination,created_by,updated_by)
   VALUES(tid,vid,v.patient_id,v.doctor_id,v.branch_id,
     complaints_text,anamnesis_text,examination_text,public.context_user(),public.context_user())
   RETURNING * INTO note;
 ELSE
   IF note.version<>expected THEN RAISE EXCEPTION 'Stale clinical note' USING ERRCODE='40001'; END IF;
   UPDATE public.visit_clinical_notes SET
     complaints=complaints_text,anamnesis=anamnesis_text,examination=examination_text,
     version=version+1,updated_by=public.context_user(),updated_at=clock_timestamp()
   WHERE (tenant_id,id)=(tid,note.id) RETURNING * INTO note;
 END IF;
 PERFORM governance.event('clinical_note.save','visit_clinical_note',note.id,tid);
 RETURN governance.doctor_clinical_note_read(vid);
END $$;
ALTER FUNCTION governance.doctor_clinical_note_save(uuid,bigint,jsonb) OWNER TO blusser_security;
REVOKE ALL ON FUNCTION governance.doctor_clinical_note_save(uuid,bigint,jsonb) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION governance.doctor_clinical_note_save(uuid,bigint,jsonb) TO blusser_app;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Clinical notes are retained; use a reviewed forward migration.');
    }
};
