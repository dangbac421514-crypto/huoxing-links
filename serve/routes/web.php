<?php

use App\Http\Controllers\Controller;
use App\Http\Controllers\JumpController;
use Illuminate\Support\Facades\Route;

Route::get('/', [Controller::class, 'welcome']);
Route::get('/j/{code}', [JumpController::class, 'show']);
