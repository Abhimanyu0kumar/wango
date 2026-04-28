<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\User\{
    AuthController
};

// User routes
Route::get('/login', [AuthController::class, 'login']);
Route::post('/signup', [AuthController::class, 'signup']);
