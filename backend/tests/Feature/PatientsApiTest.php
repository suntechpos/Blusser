<?php

namespace Tests\Feature;

use App\Modules\Access\Services\GovernanceDenied;
use App\Modules\Patients\Services\Patients;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\FoundationTestCase;

final class PatientsApiTest extends FoundationTestCase
{
    private function data(array $clinic, string $name = 'Synthetic Patient'): array
    {
        return ['name' => $name, 'phone' => '+998901234567', 'date_of_birth' => '1990-01-02', 'gender' => null, 'branch_id' => $clinic['branch']];
    }

    private function command(array $clinic, string $action = 'create', ?string $id = null, ?array $data = null, ?string $key = null): array
    {
        return app(Patients::class)->command($this->actor($clinic['user']), $clinic['tenant'], $action, $id, $data ?? $this->data($clinic), $key ?? (string) Str::uuid());
    }

    private function grant(array $clinic, string $code, ?string $scope): void
    {
        $query = DB::connection('migration')->table('role_permissions')->where('role_id', $clinic['role'])->where('permission_id', DB::connection('migration')->table('permissions')->where('code', $code)->value('id'));
        $scope === null ? $query->delete() : $query->update(['scope' => $scope]);
    }

    private function denied(callable $call, string $reason): void
    {
        try {
            $call();
            $this->fail('Expected denial.');
        } catch (GovernanceDenied $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    private function browser(array &$jar, string $method, string $path, array $body = [], bool $csrf = true, ?string $key = null, ?string $ifMatch = '"1"'): TestResponse
    {
        Auth::forgetGuards();
        $this->app->make('session')->forgetDrivers();
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTPS' => 'on', 'SERVER_PORT' => 443, 'REMOTE_ADDR' => '192.0.2.15', 'HTTP_ORIGIN' => 'https://localhost'];
        if ($csrf && isset($jar['XSRF-TOKEN'])) {
            $server['HTTP_X_XSRF_TOKEN'] = $jar['XSRF-TOKEN'];
        }
        if ($method === 'PATCH' && $ifMatch !== null) {
            $server['HTTP_IF_MATCH'] = $ifMatch;
        }
        $server['HTTP_IDEMPOTENCY_KEY'] = $key ?? (string) Str::uuid();
        $response = $this->call($method, $path, [], $jar, [], $server, json_encode($body));
        foreach ($response->headers->getCookies() as $cookie) {
            $jar[$cookie->getName()] = $cookie->getValue();
        }

        return $response;
    }

    private function patientSession(array $clinic): array
    {
        config(['session.driver' => 'versioned', 'session.secure' => true, 'session.encrypt' => true, 'session.lottery' => [0, 100]]);
        $this->app->detectEnvironment(fn () => 'local');
        $jar = [];
        $this->browser($jar, 'GET', '/sanctum/csrf-cookie')->assertNoContent();
        $login = DB::connection('migration')->table('users')->where('id', $clinic['user'])->value('login');
        $this->browser($jar, 'POST', '/api/v1/auth/login', ['login' => $login, 'password' => 'Synthetic-password-42!'])->assertOk();
        $this->browser($jar, 'POST', '/api/v1/auth/active-clinic', ['tenant_id' => $clinic['tenant']])->assertNoContent();

        return $jar;
    }

    public function test_http_crud_idempotency_archive_restore_normalization_and_secret_free_audit(): void
    {
        $a = $this->fixture()['a'];
        $jar = $this->patientSession($a);
        $data = $this->data($a);
        $data['phone'] = '+998 (90) 123-45-67';
        $key = (string) Str::uuid();
        $created = $this->browser($jar, 'POST', '/api/v1/clinic/patients', $data, key: $key)->assertCreated()->assertHeader('ETag', '"1"')->assertJsonPath('phone', '+998901234567');
        $id = $created->json('id');
        $this->assertArrayNotHasKey('tenant_id', $created->json());
        $this->assertArrayNotHasKey('version', $created->json());
        $document = json_decode(file_get_contents(base_path('docs/openapi.yaml')), true, flags: JSON_THROW_ON_ERROR);
        $actualKeys = array_keys($created->json());
        $expectedKeys = array_keys($document['components']['schemas']['Patient']['properties']);
        sort($actualKeys);
        sort($expectedKeys);
        $this->assertSame($expectedKeys, $actualKeys);
        $this->browser($jar, 'POST', '/api/v1/clinic/patients', $data, key: $key)->assertCreated()->assertJsonPath('id', $id);
        $this->browser($jar, 'POST', '/api/v1/clinic/patients', [...$data, 'name' => 'Different synthetic'], key: $key)->assertConflict();
        $this->browser($jar, 'PATCH', '/api/v1/clinic/patients/'.$id, ['name' => 'Changed synthetic'])->assertOk()->assertHeader('ETag', '"2"');
        $this->browser($jar, 'POST', '/api/v1/clinic/patients/'.$id.'/archive', ['expected_version' => 1])->assertConflict();
        $archiveKey = (string) Str::uuid();
        $this->browser($jar, 'POST', '/api/v1/clinic/patients/'.$id.'/archive', ['expected_version' => 2], key: $archiveKey)->assertOk()->assertHeader('ETag', '"3"');
        $this->browser($jar, 'POST', '/api/v1/clinic/patients/'.$id.'/archive', ['expected_version' => 2], key: $archiveKey)->assertOk();
        $this->browser($jar, 'GET', '/api/v1/clinic/patients')->assertJsonPath('meta.total', 0);
        $this->browser($jar, 'POST', '/api/v1/clinic/patients/'.$id.'/restore', ['expected_version' => 3])->assertOk()->assertHeader('ETag', '"4"');
        $this->browser($jar, 'GET', '/api/v1/clinic/patients?q=Changed&per_page=1')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);
        $logs = DB::connection('migration')->table('audit_logs')->where('resource_type', 'patient')->get()->toJson();
        $this->assertStringNotContainsString('Changed synthetic', $logs);
        $this->assertStringNotContainsString('99890', $logs);
        $this->assertStringNotContainsString($key, $logs);
    }

    public function test_tenants_foreign_ids_headers_and_payload_spoofing_are_rejected(): void
    {
        $ids = $this->fixture();
        $a = $ids['a'];
        $b = $ids['b'];
        $foreign = $this->command($b)['patient']['id'];
        $jar = $this->patientSession($a);
        $this->browser($jar, 'GET', '/api/v1/clinic/patients/'.$foreign)->assertNotFound();
        $this->browser($jar, 'PATCH', '/api/v1/clinic/patients/'.$foreign, ['name' => 'Synthetic attack'])->assertNotFound();
        $this->browser($jar, 'GET', '/api/v1/clinic/patients?q=Synthetic')->assertJsonPath('meta.total', 0);
        foreach (['tenant_id', 'actor_id', 'owner_id', 'patient_number', 'archived_at'] as $field) {
            $this->browser($jar, 'POST', '/api/v1/clinic/patients', [...$this->data($a), $field => $b['tenant']])->assertUnprocessable();
        }
        $this->browser($jar, 'POST', '/api/v1/clinic/patients', $this->data($b))->assertNotFound();
        $this->browser($jar, 'POST', '/api/v1/clinic/patients', $this->data($a), false)->assertStatus(419);
        $this->browser($jar, 'POST', '/api/v1/auth/logout')->assertNoContent();
        $this->browser($jar, 'GET', '/api/v1/clinic/patients')->assertUnauthorized();
    }

    public function test_branch_visibility_linking_and_live_revocation(): void
    {
        $a = $this->fixture()['a'];
        $owner = DB::connection('migration');
        $other = (string) Str::uuid();
        $owner->table('branches')->insert(['id' => $other, 'tenant_id' => $a['tenant'], 'code' => 'other', 'name' => 'Synthetic Other']);
        $first = $this->command($a)['patient']['id'];
        $hidden = $this->command($a, data: [...$this->data($a), 'branch_id' => $other])['patient']['id'];
        foreach (['view', 'create', 'update', 'link_branch'] as $action) {
            $this->grant($a, 'patients.'.$action, 'assigned_branches');
        }
        $jar = $this->patientSession($a);
        $this->browser($jar, 'GET', '/api/v1/clinic/patients')->assertJsonPath('meta.total', 1);
        $this->browser($jar, 'GET', '/api/v1/clinic/patients/'.$hidden)->assertNotFound();
        $this->browser($jar, 'POST', '/api/v1/clinic/patients/'.$hidden.'/branches', ['branch_id' => $a['branch']])->assertNotFound();
        $this->browser($jar, 'POST', '/api/v1/clinic/patients/'.$first.'/branches', ['branch_id' => $other])->assertNotFound();
        $this->grant($a, 'patients.link_branch', 'entire_clinic');
        $this->browser($jar, 'POST', '/api/v1/clinic/patients/'.$first.'/branches', ['branch_id' => $other])->assertOk()->assertJsonPath('branch_ids', [$a['branch']]);
        $this->assertSame(2, $owner->table('patients')->count());
        $this->grant($a, 'patients.link_branch', null);
        $this->browser($jar, 'POST', '/api/v1/clinic/patients/'.$first.'/branches', ['branch_id' => $a['branch']])->assertForbidden();
        $owner->table('membership_branches')->where('membership_id', $a['membership'])->delete();
        $this->browser($jar, 'GET', '/api/v1/clinic/patients/'.$first)->assertNotFound();
    }

    public function test_own_scope_platform_inactive_branch_membership_and_permission_fail_closed(): void
    {
        $a = $this->fixture()['a'];
        $id = $this->command($a)['patient']['id'];
        $jar = $this->patientSession($a);
        $this->grant($a, 'patients.view', 'own');
        $this->grant($a, 'patients.update', 'own');
        $this->browser($jar, 'GET', '/api/v1/clinic/patients')->assertJsonPath('meta.total', 0);
        $this->browser($jar, 'GET', '/api/v1/clinic/patients/'.$id)->assertNotFound();
        $this->browser($jar, 'PATCH', '/api/v1/clinic/patients/'.$id, ['name' => 'Synthetic forbidden'])->assertNotFound();
        $this->grant($a, 'patients.view', 'assigned_branches');
        DB::connection('migration')->table('branches')->where('id', $a['branch'])->update(['active' => false]);
        $this->browser($jar, 'GET', '/api/v1/clinic/patients/'.$id)->assertNotFound();
        $this->grant($a, 'patients.view', null);
        $this->browser($jar, 'GET', '/api/v1/clinic/patients')->assertForbidden();
        DB::connection('migration')->table('users')->where('id', $a['user'])->update(['is_platform_admin' => true]);
        $this->browser($jar, 'GET', '/api/v1/clinic/patients')->assertNotFound();
        DB::connection('migration')->table('users')->where('id', $a['user'])->update(['is_platform_admin' => false]);
        DB::connection('migration')->table('tenant_memberships')->where('id', $a['membership'])->update(['active' => false]);
        $this->browser($jar, 'GET', '/api/v1/clinic/patients')->assertNotFound();
    }

    public function test_idempotency_replay_cannot_return_data_after_revocation(): void
    {
        $a = $this->fixture()['a'];
        $key = (string) Str::uuid();
        $this->command($a, key: $key);
        $this->grant($a, 'patients.create', null);
        $this->denied(fn () => $this->command($a, key: $key), 'forbidden');
        $this->assertSame(1, DB::connection('migration')->table('patients')->count());
    }

    public function test_csv_archive_capabilities_and_formula_injection(): void
    {
        $a = $this->fixture()['a'];
        $id = $this->command($a, data: $this->data($a, "\t=1+1"))['patient']['id'];
        $jar = $this->patientSession($a);
        $csv = $this->browser($jar, 'GET', '/api/v1/clinic/patients/export')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->getContent();
        $this->assertStringContainsString("'\t=1+1", $csv);
        $this->assertStringContainsString("'+998901234567", $csv);
        $this->grant($a, 'patients.export', null);
        $this->browser($jar, 'GET', '/api/v1/clinic/patients/export')->assertForbidden();
        foreach (['archive', 'restore'] as $action) {
            $this->grant($a, 'patients.'.$action, null);
            $this->browser($jar, 'POST', '/api/v1/clinic/patients/'.$id.'/'.$action, ['expected_version' => 1])->assertForbidden();
        }
    }

    public function test_runtime_rls_composite_foreign_keys_and_direct_writes(): void
    {
        $ids = $this->fixture();
        $a = $ids['a'];
        $b = $ids['b'];
        $id = $this->command($a)['patient']['id'];
        $this->command($b);
        $this->assertSame('blusser_app', DB::selectOne('SELECT current_user AS name')->name);
        $this->assertSame(0, DB::table('patients')->count());
        config(['database.default' => 'migration']);
        $this->assertSame(1, app(Patients::class)->listing($this->actor($a['user']), $a['tenant'], [])['meta']['total']);
        config(['database.default' => 'pgsql']);
        foreach (['INSERT', 'UPDATE', 'DELETE'] as $privilege) {
            $this->assertFalse(DB::selectOne("SELECT has_table_privilege(current_user,'public.patients',?) AS ok", [$privilege])->ok);
        }
        app(TenantContext::class)->run($this->actor($a['user']), $a['tenant'], function () use ($id, $b) {
            $this->assertSame([$id], DB::table('patients')->pluck('id')->all());
            $this->assertFalse(DB::table('patients')->where('tenant_id', $b['tenant'])->exists());
        });
        foreach (['patients', 'patient_branches', 'patient_counters', 'patient_idempotency'] as $table) {
            $this->assertSqlState('42501', fn () => app(TenantContext::class)->run($this->actor($a['user']), $a['tenant'], fn () => DB::statement('DELETE FROM '.$table)));
        }
        $owner = DB::connection('migration');
        $owner->statement('GRANT INSERT, UPDATE, DELETE ON patients TO blusser_app');
        try {
            $this->assertSqlState('42501', fn () => app(TenantContext::class)->run($this->actor($a['user']), $a['tenant'], fn () => DB::table('patients')->insert(['tenant_id' => $b['tenant'], 'patient_number' => 'forged', 'name' => 'Synthetic forbidden', 'date_of_birth' => '1990-01-01'])));
            app(TenantContext::class)->run($this->actor($a['user']), $a['tenant'], function () use ($id) {
                $this->assertSame(0, DB::table('patients')->where('id', $id)->update(['name' => 'Synthetic forbidden']));
                $this->assertSame(0, DB::table('patients')->where('id', $id)->delete());
            });
        } finally {
            $owner->statement('REVOKE INSERT, UPDATE, DELETE ON patients FROM blusser_app');
        }
        foreach (['patient_counters', 'patient_idempotency', 'patient_grant_provenance', 'patient_permission_provenance'] as $table) {
            $this->assertSqlState('42501', fn () => DB::table($table)->get());
        }
        $this->assertSqlState('23503', fn () => DB::connection('migration')->table('patient_branches')->insert(['tenant_id' => $a['tenant'], 'patient_id' => $id, 'branch_id' => $b['branch'], 'linked_by' => $a['membership']]));
        $this->assertSqlState('42501', fn () => DB::select('SELECT governance.patient_projection(?)', [$id]));
        foreach (['patients', 'patient_branches', 'patient_counters', 'patient_idempotency'] as $table) {
            $this->assertTrue(DB::selectOne('SELECT relrowsecurity AND relforcerowsecurity AS ok FROM pg_class WHERE oid=?::regclass', [$table])->ok);
        }
    }

    public function test_failed_create_rolls_back_patient_counter_and_audit(): void
    {
        $a = $this->fixture()['a'];
        $this->denied(fn () => $this->command($a, data: [...$this->data($a), 'name' => '']), 'invalid_input');
        $this->assertSame(0, DB::connection('migration')->table('patient_counters')->count());
        $this->assertSame(0, DB::connection('migration')->table('audit_logs')->where('resource_type', 'patient')->count());
        $this->assertSame('P-000001', $this->command($a)['patient']['patient_number']);
    }

    public function test_switching_clinics_never_reuses_other_tenant_patient_permissions(): void
    {
        $ids = $this->fixture();
        $a = $ids['a'];
        $b = $ids['b'];
        $first = $this->command($a)['patient']['id'];
        $second = $this->command($b)['patient']['id'];
        DB::connection('migration')->table('tenant_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $b['tenant'], 'user_id' => $a['user'], 'role_id' => $b['role']]);
        $jar = $this->patientSession($a);
        $this->browser($jar, 'GET', '/api/v1/clinic/patients')->assertJsonPath('data.0.id', $first);
        $this->browser($jar, 'POST', '/api/v1/auth/active-clinic', ['tenant_id' => $b['tenant']])->assertNoContent();
        $this->browser($jar, 'GET', '/api/v1/clinic/patients')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $second);
        $this->browser($jar, 'GET', '/api/v1/clinic/patients/'.$first)->assertNotFound();
        $this->grant($b, 'patients.view', null);
        $this->browser($jar, 'GET', '/api/v1/clinic/patients')->assertForbidden();
        $this->browser($jar, 'POST', '/api/v1/auth/active-clinic', ['tenant_id' => $a['tenant']])->assertNoContent();
        $this->browser($jar, 'GET', '/api/v1/clinic/patients')->assertJsonPath('data.0.id', $first);
        $this->browser($jar, 'GET', '/api/v1/clinic/patients/'.$first.'/visits')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
    }

    public function test_read_database_errors_do_not_propagate_sensitive_query_bindings(): void
    {
        $a = $this->fixture()['a'];
        DB::connection('migration')->statement('REVOKE SELECT ON patients FROM blusser_app');
        try {
            app(Patients::class)->listing($this->actor($a['user']), $a['tenant'], ['q' => 'SYNTHETIC-PRIVATE-SEARCH']);
            $this->fail('Query must be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('SYNTHETIC-PRIVATE-SEARCH', $exception->getMessage());
        } finally {
            DB::connection('migration')->statement('GRANT SELECT ON patients TO blusser_app');
        }
    }

    public function test_rfc3339_filters_and_export_limit_are_enforced_without_audit_on_failure(): void
    {
        $a = $this->fixture()['a'];
        $this->command($a);
        $jar = $this->patientSession($a);
        $this->browser($jar, 'GET', '/api/v1/clinic/patients?to=2099-01-01T00:00:00Z')->assertOk()->assertJsonPath('meta.total', 1);
        $this->browser($jar, 'GET', '/api/v1/clinic/patients?from=2000-01-01T00:00:00Z&to=2099-01-01T00:00:00Z')->assertOk();
        $this->browser($jar, 'GET', '/api/v1/clinic/patients?doctor_id='.(string) Str::uuid())->assertUnprocessable();
        DB::connection('migration')->statement("INSERT INTO patients(tenant_id,patient_number,name,date_of_birth) SELECT ?::uuid,'limit-'||n,'Synthetic export limit',DATE '1990-01-01' FROM generate_series(1,10001) n", [$a['tenant']]);
        $this->browser($jar, 'GET', '/api/v1/clinic/patients/export')->assertUnprocessable();
        $this->assertSame(0, DB::connection('migration')->table('audit_logs')->where('action', 'patient.export')->count());
    }

    public function test_patch_requires_strong_if_match_and_preserves_replay_and_version_guards(): void
    {
        $a = $this->fixture()['a'];
        $id = $this->command($a)['patient']['id'];
        $jar = $this->patientSession($a);
        $uri = '/api/v1/clinic/patients/'.$id;
        $etag = $this->browser($jar, 'GET', $uri)->assertOk()->headers->get('ETag');
        foreach ([null, '1', 'W/"1"', '*', '"0"', '"01"', '"-1"', '"1", "2"', '"9223372036854775808"', '"'.str_repeat('9', 40).'"'] as $invalid) {
            $this->browser($jar, 'PATCH', $uri, ['name' => 'Synthetic rejected'], ifMatch: $invalid)->assertUnprocessable();
        }
        $this->browser($jar, 'PATCH', $uri, ['name' => 'Synthetic rejected', 'expected_version' => 1], ifMatch: $etag)->assertUnprocessable();
        $this->browser($jar, 'GET', $uri)->assertHeader('ETag', $etag)->assertJsonPath('name', 'Synthetic Patient');
        $key = (string) Str::uuid();
        $this->browser($jar, 'PATCH', $uri, ['name' => 'Synthetic changed'], key: $key, ifMatch: $etag)->assertOk()->assertHeader('ETag', '"2"');
        $this->browser($jar, 'PATCH', $uri, ['name' => 'Synthetic changed'], key: $key, ifMatch: $etag)->assertOk()->assertHeader('ETag', '"2"');
        $this->browser($jar, 'PATCH', $uri, ['name' => 'Synthetic lost update'], ifMatch: $etag)->assertConflict();
        $this->browser($jar, 'PATCH', $uri, ['name' => 'Synthetic changed'], key: $key, ifMatch: '"2"')->assertConflict();
        $this->browser($jar, 'GET', $uri)->assertJsonPath('name', 'Synthetic changed')->assertHeader('ETag', '"2"');
        $this->assertSame(1, DB::connection('migration')->table('audit_logs')->where('resource_id', $id)->where('action', 'patient.update')->count());
        $this->assertSame(1, DB::connection('migration')->table('patient_idempotency')->where('patient_id', $id)->where('operation', 'update')->count());
        $this->browser($jar, 'PATCH', $uri, ['name' => 'Synthetic latest'], ifMatch: '"2"')->assertOk()->assertHeader('ETag', '"3"');
        $this->browser($jar, 'PATCH', $uri, ['name' => 'Synthetic changed'], key: $key, ifMatch: $etag)->assertOk()->assertJsonPath('name', 'Synthetic latest')->assertHeader('ETag', '"3"');
        $this->grant($a, 'patients.update', null);
        $this->browser($jar, 'PATCH', $uri, ['name' => 'Synthetic changed'], key: $key, ifMatch: $etag)->assertForbidden();
    }

    public function test_parallel_http_patches_and_committed_row_changes_cannot_lose_updates(): void
    {
        $ids = $this->fixture();
        $a = $ids['a'];
        $second = [...$a, 'user' => $ids['b']['user'], 'membership' => (string) Str::uuid()];
        DB::connection('migration')->table('tenant_memberships')->insert(['id' => $second['membership'], 'tenant_id' => $a['tenant'], 'user_id' => $second['user'], 'role_id' => $a['role']]);
        $jars = [$this->patientSession($a), $this->patientSession($second)];
        $code = <<<'CODE'
require 'vendor/autoload.php'; $app=require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app->detectEnvironment(fn ()=>'local');
$app['config']->set(['session.driver'=>'versioned','session.secure'=>true,'session.encrypt'=>true,'session.lottery'=>[0,100]]);
$i=json_decode(stream_get_contents(STDIN),true);
Illuminate\Support\Facades\DB::connection('pgsql')->select("SELECT set_config('application_name',?,false)",[$i['tag']]);
$r=Illuminate\Http\Request::create('/api/v1/clinic/patients/'.$i['id'],'PATCH',[],$i['jar'],[],[
'HTTP_ACCEPT'=>'application/json','CONTENT_TYPE'=>'application/json','HTTPS'=>'on','SERVER_PORT'=>443,
'HTTP_ORIGIN'=>'https://localhost','REMOTE_ADDR'=>'192.0.2.15','HTTP_IF_MATCH'=>'"1"',
'HTTP_X_XSRF_TOKEN'=>$i['jar']['XSRF-TOKEN'],'HTTP_IDEMPOTENCY_KEY'=>$i['key']],json_encode(['name'=>$i['name']]));
$kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);$response=$kernel->handle($r);$kernel->terminate($r,$response);
echo json_encode(['status'=>$response->getStatusCode(),'etag'=>$response->headers->get('ETag'),'body'=>json_decode($response->getContent(),true)]);
CODE;
        foreach ([false, true] as $interveningCommit) {
            $id = $this->command($a)['patient']['id'];
            $owner = DB::connection('migration');
            $owner->beginTransaction();
            $owner->select('SELECT id FROM patients WHERE id=? FOR UPDATE', [$id]);
            $workers = [];
            try {
                foreach ([0, 1] as $i) {
                    $workers[$i] = new Process([PHP_BINARY, '-r', $code], base_path(), ['APP_ENV' => 'testing']);
                    $workers[$i]->setInput(json_encode(['jar' => $jars[$i], 'id' => $id, 'key' => (string) Str::uuid(), 'name' => 'Synthetic writer '.$i, 'tag' => 'patient-patch-race-'.$i]));
                    $workers[$i]->setTimeout(20)->start();
                }
                $blocked = 0;
                for ($attempt = 0; $attempt < 150; $attempt++) {
                    $blocked = (int) DB::selectOne("SELECT count(*) AS n FROM pg_stat_activity WHERE application_name LIKE 'patient-patch-race-%' AND wait_event_type='Lock'")->n;
                    if ($blocked === 2) {
                        break;
                    }
                    usleep(20000);
                }
                $this->assertSame(2, $blocked, 'Both HTTP requests must be blocked on real PostgreSQL locks.');
                if ($interveningCommit) {
                    $owner->table('patients')->where('id', $id)->update(['name' => 'Synthetic intervening commit', 'version' => 2]);
                }
                $owner->commit();
                $results = [];
                foreach ($workers as $worker) {
                    $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
                    $results[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                }
                $statuses = array_column($results, 'status');
                sort($statuses);
                $this->assertSame($interveningCommit ? [409, 409] : [200, 409], $statuses);
                $patient = $owner->table('patients')->where('id', $id)->first();
                $this->assertSame(2, (int) $patient->version);
                if ($interveningCommit) {
                    $this->assertSame('Synthetic intervening commit', $patient->name);
                } else {
                    $winner = array_values(array_filter($results, fn ($r) => $r['status'] === 200))[0];
                    $this->assertSame('"2"', $winner['etag']);
                    $this->assertSame($winner['body']['name'], $patient->name);
                }
                $this->assertSame($interveningCommit ? 0 : 1, $owner->table('audit_logs')->where('resource_id', $id)->where('action', 'patient.update')->count());
                $this->assertSame($interveningCommit ? 0 : 1, $owner->table('patient_idempotency')->where('patient_id', $id)->where('operation', 'update')->count());
            } finally {
                if ($owner->transactionLevel()) {
                    $owner->rollBack();
                }
                foreach ($workers as $worker) {
                    if ($worker->isRunning()) {
                        $worker->stop();
                    }
                }
            }
        }
    }

    public function test_parallel_allocations_and_duplicate_requests_use_real_runtime_transactions(): void
    {
        $a = $this->fixture()['a'];
        $code = <<<'CODE'
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$input=json_decode(stream_get_contents(STDIN),true);
$c=Illuminate\Support\Facades\DB::connection('pgsql');
$c->select("SELECT set_config('application_name',?,false)",[$input['tag']]);
$value=$c->transaction(function()use($c,$input){
$c->select("SELECT set_config('app.user_id',?,true),set_config('app.tenant_id',?,true),set_config('app.credential_version','1',true)",[$input['actor'],$input['tenant']]);
return $c->selectOne('SELECT governance.patient_command(\'create\',NULL,?::jsonb,?::uuid) AS value',[json_encode($input['data']),$input['key']])->value;
}); echo $value;
CODE;
        foreach ([false, true] as $duplicate) {
            $owner = DB::connection('migration');
            $owner->beginTransaction();
            $owner->select('SELECT id FROM tenants WHERE id=? FOR UPDATE', [$a['tenant']]);
            $key = (string) Str::uuid();
            $workers = [];
            try {
                foreach ([0, 1] as $i) {
                    $workers[$i] = new Process([PHP_BINARY, '-r', $code], base_path(), ['APP_ENV' => 'testing']);
                    $workers[$i]->setInput(json_encode(['actor' => $a['user'], 'tenant' => $a['tenant'], 'tag' => 'patient-concurrency-'.$i, 'data' => $this->data($a), 'key' => $duplicate ? $key : (string) Str::uuid()]));
                    $workers[$i]->setTimeout(15)->start();
                }
                $blocked = 0;
                for ($attempt = 0; $attempt < 100; $attempt++) {
                    $blocked = (int) DB::selectOne("SELECT count(*) AS n FROM pg_stat_activity WHERE application_name LIKE 'patient-concurrency-%' AND wait_event_type='Lock'")->n;
                    if ($blocked === 2) {
                        break;
                    }
                    usleep(20000);
                }
                $this->assertSame(2, $blocked, 'Both real runtime requests must overlap while waiting for locks.');
                $owner->commit();
                $results = [];
                foreach ($workers as $worker) {
                    $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
                    $results[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR)['patient'];
                }
                $this->assertSame($duplicate, $results[0]['id'] === $results[1]['id']);
                $this->assertSame($duplicate, $results[0]['patient_number'] === $results[1]['patient_number']);
            } finally {
                if ($owner->transactionLevel()) {
                    $owner->rollBack();
                }
                foreach ($workers as $worker) {
                    if ($worker->isRunning()) {
                        $worker->stop();
                    }
                }
            }
        }
        $this->assertSame(3, DB::connection('migration')->table('patients')->count());
    }
}
