<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const FUNCTIONS = ['scheduling_visible', 'scheduling_projection', 'scheduling_validate_payload', 'scheduling_command'];

    public function up(): void
    {
        if (DB::selectOne('SELECT current_user AS name')->name !== 'blusser_owner') {
            throw new RuntimeException('Booking configuration requires migration credentials.');
        }
        DB::statement('ALTER TABLE scheduling_idempotency ADD COLUMN service_id uuid, ADD FOREIGN KEY(tenant_id,service_id) REFERENCES services(tenant_id,id)');
        DB::statement('ALTER TABLE scheduling_idempotency DROP CONSTRAINT scheduling_idempotency_check');
        DB::statement('ALTER TABLE scheduling_idempotency ADD CONSTRAINT scheduling_idempotency_check CHECK(num_nonnulls(specialty_id,doctor_id,room_id,schedule_rule_id,schedule_exception_id,service_id)=1 AND resource_id=coalesce(specialty_id,doctor_id,room_id,schedule_rule_id,schedule_exception_id,service_id))');
        DB::statement('GRANT INSERT ON services TO blusser_security');
        foreach ($this->definitions() as $name => $sql) {
            if ($name === 'scheduling_visible') {
                $sql = str_replace("WHEN 'specialties' THEN", "WHEN 'services' THEN EXISTS(SELECT 1 FROM public.services s WHERE s.tenant_id=public.context_tenant() AND s.id=rid AND public.permission_scope('services.view')='entire_clinic')\n WHEN 'specialties' THEN", $sql);
            } elseif ($name === 'scheduling_projection') {
                $sql = str_replace("WHEN 'specialties' THEN", "WHEN 'services' THEN (SELECT jsonb_build_object('id',s.id,'code',s.code,'name',s.name,'active',s.active) FROM public.services s WHERE s.tenant_id=public.context_tenant() AND s.id=rid)\n WHEN 'specialties' THEN", $sql);
            } elseif ($name === 'scheduling_validate_payload') {
                $sql = str_replace("WHEN 'specialties' THEN ARRAY", "WHEN 'services' THEN ARRAY['code','name','active'] WHEN 'specialties' THEN ARRAY", $sql);
                $sql = str_replace("kind='specialties' AND", "kind IN ('specialties','services') AND", $sql);
            } else {
                $sql = str_replace("kind NOT IN ('specialties',", "kind NOT IN ('services','specialties',", $sql);
                $sql = str_replace('-- Resolve only', "IF kind='services' AND action_code<>'create' THEN RAISE EXCEPTION 'Invalid command' USING ERRCODE='22023'; END IF;\n -- Resolve only", $sql);
                $sql = str_replace('section:=CASE WHEN', "section:=CASE WHEN kind='services' THEN 'services' WHEN", $sql);
                $sql = str_replace("IF kind='specialties' THEN\n", "IF kind='services' THEN\n  INSERT INTO public.services(id,tenant_id,code,name,active) VALUES(result_id,tid,payload->>'code',payload->>'name',(payload->>'active')::boolean);\n ELSIF kind='specialties' THEN\n", $sql);
                $sql = str_replace('resource_id,specialty_id,doctor_id,room_id,schedule_rule_id,schedule_exception_id)', 'resource_id,specialty_id,doctor_id,room_id,schedule_rule_id,schedule_exception_id,service_id)', $sql);
                $sql = str_replace("CASE WHEN kind='schedule-exceptions' THEN result_id END);", "CASE WHEN kind='schedule-exceptions' THEN result_id END,CASE WHEN kind='services' THEN result_id END);", $sql);
            }
            DB::unprepared($sql);
        }
    }

    private function definitions(): array
    {
        $source = file_get_contents(database_path('sql/scheduling.sql'));
        $result = [];
        foreach (self::FUNCTIONS as $name) {
            if (! preg_match('/CREATE FUNCTION governance\\.'.$name.'.*?\n(?:END )?\$\$;/s', $source, $match)) {
                throw new RuntimeException('Scheduling baseline changed; review configuration migration.');
            }
            $result[$name] = str_replace('CREATE FUNCTION', 'CREATE OR REPLACE FUNCTION', $match[0]);
        }

        return $result;
    }

    public function down(): void
    {
        // Preserve all configured services. Only remove the new command surface.
        DB::table('scheduling_idempotency')->whereNotNull('service_id')->delete();
        foreach ($this->definitions() as $sql) {
            DB::unprepared($sql);
        }
        DB::statement('ALTER TABLE scheduling_idempotency DROP CONSTRAINT scheduling_idempotency_check');
        DB::statement('ALTER TABLE scheduling_idempotency DROP COLUMN service_id');
        DB::statement('ALTER TABLE scheduling_idempotency ADD CONSTRAINT scheduling_idempotency_check CHECK(num_nonnulls(specialty_id,doctor_id,room_id,schedule_rule_id,schedule_exception_id)=1 AND resource_id=coalesce(specialty_id,doctor_id,room_id,schedule_rule_id,schedule_exception_id))');
        DB::statement('REVOKE INSERT ON services FROM blusser_security');
    }
};
