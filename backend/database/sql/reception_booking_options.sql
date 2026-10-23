-- Narrow read model for booking only. No account/login/PIN data is returned.
CREATE FUNCTION governance.reception_booking_options(bid uuid,page_number integer,page_size integer) RETURNS jsonb
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path=pg_catalog AS $$
DECLARE result jsonb;
BEGIN
 IF page_number<1 OR page_size NOT BETWEEN 1 AND 100 THEN RAISE EXCEPTION 'Invalid page' USING ERRCODE='22023'; END IF;
 IF NOT governance.scheduling_identity() OR public.permission_scope('appointments.view') IS NULL
 OR public.permission_scope('services.view') IS DISTINCT FROM 'entire_clinic'
 OR NOT governance.scheduling_branch('doctors.view',bid) OR NOT governance.scheduling_branch('rooms.view',bid)
 OR NOT governance.scheduling_branch('schedules.view',bid) THEN RAISE EXCEPTION 'Denied' USING ERRCODE='42501'; END IF;
 IF NOT EXISTS(SELECT 1 FROM public.branches WHERE tenant_id=public.context_tenant() AND id=bid AND active) THEN RETURN NULL; END IF;
 WITH eligible AS (
  SELECT d.id,u.name,s.id specialty_id,s.name specialty_name,
  coalesce((SELECT jsonb_agg(jsonb_build_object('id',sv.id,'name',sv.name,'duration_minutes',ds.duration_minutes) ORDER BY sv.name,sv.id)
   FROM public.doctor_services ds JOIN public.services sv ON (sv.tenant_id,sv.id)=(ds.tenant_id,ds.service_id) AND sv.active
   WHERE (ds.tenant_id,ds.doctor_id)=(d.tenant_id,d.id)),'[]'::jsonb) services
  FROM public.doctor_profiles d JOIN public.tenant_memberships m ON (m.tenant_id,m.id)=(d.tenant_id,d.membership_id) AND m.active
  JOIN public.users u ON u.id=m.user_id AND u.active JOIN public.specialties s ON (s.tenant_id,s.id)=(d.tenant_id,d.specialty_id) AND s.active
  WHERE d.tenant_id=public.context_tenant() AND governance.doctor_branch_live(d.id,bid)
  AND governance.scheduling_doctor_branch_visible(d.id,bid) AND governance.booking_scope('appointments.view',d.id,bid)
 ), paged AS (SELECT * FROM eligible ORDER BY name,id OFFSET (page_number-1)*page_size LIMIT page_size)
 SELECT jsonb_build_object('data',coalesce((SELECT jsonb_agg(to_jsonb(p) ORDER BY p.name,p.id) FROM paged p),'[]'::jsonb),
 'timezone',(SELECT coalesce(b.timezone,t.timezone) FROM public.branches b JOIN public.tenants t ON t.id=b.tenant_id WHERE b.tenant_id=public.context_tenant() AND b.id=bid),
 'meta',jsonb_build_object('page',page_number,'per_page',page_size,'total',(SELECT count(*) FROM eligible))) INTO result;
 RETURN result;
END $$;
ALTER FUNCTION governance.reception_booking_options(uuid,integer,integer) OWNER TO blusser_security;
REVOKE ALL ON FUNCTION governance.reception_booking_options(uuid,integer,integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION governance.reception_booking_options(uuid,integer,integer) TO blusser_app;
