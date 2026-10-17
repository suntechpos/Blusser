<?php

namespace App\Modules\Appointments\Models;

use Illuminate\Database\Eloquent\Model;

/** Protected reference reads; runtime writes always use the audited command. */
abstract class OperationRecord extends Model
{
    protected $connection = 'pgsql';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['*'];
}
