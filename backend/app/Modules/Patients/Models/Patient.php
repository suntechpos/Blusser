<?php

namespace App\Modules\Patients\Models;

use Illuminate\Database\Eloquent\Model;

final class Patient extends Model
{
    protected $connection = 'pgsql';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected $hidden = ['tenant_id', 'version'];

    protected function casts(): array
    {
        return ['archived_at' => 'immutable_datetime', 'date_of_birth' => 'immutable_date'];
    }
}
