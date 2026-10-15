<?php

namespace Tests\Feature;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\FoundationTestCase;

final class AuthProviderTest extends FoundationTestCase
{
    public function test_web_auth_provider_resolves_the_modular_identity_model(): void
    {
        $provider = Auth::guard('web')->getProvider();

        $this->assertInstanceOf(EloquentUserProvider::class, $provider);
        $this->assertSame(User::class, config('auth.providers.users.model'));
        $this->assertSame(User::class, $provider->getModel());
        $this->assertInstanceOf(User::class, $provider->createModel());
        $this->assertSame('pgsql', $provider->createModel()->getConnection()->getName());
    }

    public function test_auth_provider_validates_password_hash_instead_of_legacy_password_field(): void
    {
        $provider = Auth::guard('web')->getProvider();
        // Test the real Laravel verifier without granting it database hash access.
        $user = $provider->createModel()->forceFill([
            'password_hash' => Hash::make('Synthetic-correct-password-42!'),
            'password' => Hash::make('Synthetic-legacy-decoy-42!'),
        ]);

        $this->assertSame('password_hash', $user->getAuthPasswordName());
        $this->assertTrue($provider->validateCredentials($user, ['password' => 'Synthetic-correct-password-42!']));
        $this->assertFalse($provider->validateCredentials($user, ['password' => 'Synthetic-wrong-password-42!']));
        $this->assertFalse($provider->validateCredentials($user, ['password' => 'Synthetic-legacy-decoy-42!']));
        $this->assertArrayNotHasKey('password_hash', $user->toArray());
    }

    public function test_default_auth_lookup_cannot_read_hashes_or_fall_back_to_owner_connection(): void
    {
        $this->fixture();
        $provider = Auth::guard('web')->getProvider();
        $connection = $provider->createModel()->getConnection();

        $this->assertSame('blusser_app', $connection->selectOne('SELECT current_user AS name')->name);
        $this->assertSqlState('42501', fn () => $provider->retrieveByCredentials([
            'login' => 'synthetic.a', 'password' => 'Synthetic-password-42!',
        ]));
        $this->assertSame(0, DB::connection('pgsql')->transactionLevel());
    }

    public function test_provider_queries_with_allowed_columns_still_obey_users_rls(): void
    {
        $ids = $this->fixture();
        // Narrow the projection only in this test to distinguish RLS row filtering
        // from the independent column-level denial of password_hash / SELECT *.
        $provider = Auth::guard('web')->getProvider()->withQuery(
            fn ($query) => $query->select(['id', 'login', 'name']),
        );

        $this->assertNull($provider->retrieveById($ids['a']['user']));
        app(TenantContext::class)->run($this->actor($ids['a']['user']), $ids['a']['tenant'], function () use ($provider, $ids) {
            $self = $provider->retrieveById($ids['a']['user']);
            $this->assertInstanceOf(User::class, $self);
            $this->assertSame($ids['a']['user'], $self->getAuthIdentifier());
            $this->assertNull($provider->retrieveById($ids['b']['user']));
            $this->assertNull($provider->retrieveByCredentials(['login' => 'synthetic.b', 'password' => 'Synthetic-password-42!']));
            $this->assertNull($self->getAuthPassword());
            $this->assertFalse($provider->validateCredentials($self, ['password' => 'Synthetic-password-42!']));
        });
        $this->assertNull($provider->retrieveById($ids['a']['user']));
    }
}
