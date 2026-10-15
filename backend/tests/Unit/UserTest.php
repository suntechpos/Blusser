<?php

namespace Tests\Unit;

use App\Modules\Identity\Models\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function test_password_hash_is_auth_field_but_is_never_serialized_or_mass_assigned(): void
    {
        $user = (new User)->forceFill(['id' => '00000000-0000-4000-8000-000000000001', 'password_hash' => 'synthetic-hash']);
        $this->assertSame('password_hash', $user->getAuthPasswordName());
        $this->assertSame('synthetic-hash', $user->getAuthPassword());
        $this->assertArrayNotHasKey('password_hash', $user->toArray());
        foreach (['tenant_id', 'password_hash', 'is_platform_admin', 'credential_version', 'role_id'] as $field) {
            $this->assertFalse($user->isFillable($field));
        }
    }
}
