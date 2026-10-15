<?php

use App\Modules\Platform\Http\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/v1/health', HealthController::class);
