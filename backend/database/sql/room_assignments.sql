-- Tenant FK and CHECK prevent invalid owners; all existing rooms remain shared.
ALTER TABLE public.rooms ADD COLUMN usage_mode text NOT NULL DEFAULT 'shared' CHECK(usage_mode IN ('shared','private')),
 ADD COLUMN exclusive_doctor_id uuid,
 ADD FOREIGN KEY(tenant_id,exclusive_doctor_id,branch_id) REFERENCES public.doctor_branches(tenant_id,doctor_id,branch_id),
 ADD CHECK((usage_mode='shared' AND exclusive_doctor_id IS NULL) OR (usage_mode='private' AND exclusive_doctor_id IS NOT NULL));
CREATE FUNCTION governance.enforce_room_assignment() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE r public.rooms;
BEGIN
 IF TG_TABLE_NAME='rooms' THEN
  IF NEW.usage_mode='private' THEN
   IF NOT EXISTS(SELECT 1 FROM public.doctor_branches WHERE tenant_id=NEW.tenant_id AND doctor_id=NEW.exclusive_doctor_id AND branch_id=NEW.branch_id)
    OR EXISTS(SELECT 1 FROM public.schedule_rules WHERE tenant_id=NEW.tenant_id AND room_id=NEW.id AND doctor_id<>NEW.exclusive_doctor_id)
    OR EXISTS(SELECT 1 FROM public.schedule_exceptions WHERE tenant_id=NEW.tenant_id AND room_id=NEW.id AND doctor_id<>NEW.exclusive_doctor_id)
   THEN RAISE EXCEPTION 'Room ownership conflict' USING ERRCODE='23505'; END IF;
  END IF;
 ELSE
  IF NEW.room_id IS NULL THEN RETURN NEW; END IF;
  SELECT * INTO r FROM public.rooms WHERE tenant_id=NEW.tenant_id AND id=NEW.room_id FOR UPDATE;
  IF r.usage_mode='private' AND r.exclusive_doctor_id<>NEW.doctor_id THEN RAISE EXCEPTION 'Private room conflict' USING ERRCODE='23505'; END IF;
 END IF;
 RETURN NEW;
END $$;
ALTER FUNCTION governance.enforce_room_assignment() OWNER TO blusser_security;
REVOKE ALL ON FUNCTION governance.enforce_room_assignment() FROM PUBLIC, blusser_app;
CREATE TRIGGER room_owner_guard BEFORE INSERT OR UPDATE ON public.rooms FOR EACH ROW EXECUTE FUNCTION governance.enforce_room_assignment();
CREATE TRIGGER schedule_room_owner_guard BEFORE INSERT OR UPDATE ON public.schedule_rules FOR EACH ROW EXECUTE FUNCTION governance.enforce_room_assignment();
CREATE TRIGGER exception_room_owner_guard BEFORE INSERT OR UPDATE ON public.schedule_exceptions FOR EACH ROW EXECUTE FUNCTION governance.enforce_room_assignment();
