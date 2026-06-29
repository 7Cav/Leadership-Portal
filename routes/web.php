<?php

use App\Http\Controllers\SampleController;
use Illuminate\Support\Facades\Route;

Route::get('/', [SampleController::class, 'show']);
