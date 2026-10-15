<?php

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;

final class User extends Authenticatable
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['password_hash'];

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'is_platform_admin' => 'boolean',
            'must_change_password' => 'boolean',
            'credential_version' => 'integer',
            'temporary_password_expires_at' => 'immutable_datetime',
            'password_changed_at' => 'immutable_datetime',
        ];
    }
}
