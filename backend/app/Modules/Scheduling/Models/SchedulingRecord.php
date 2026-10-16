<?php

namespace App\Modules\Scheduling\Models;

use Illuminate\Database\Eloquent\Model;

/** Runtime reads are always subject to PostgreSQL RLS; writes use commands. */
abstract class SchedulingRecord extends Model
{
    protected $connection = 'pgsql';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['*'];
}
