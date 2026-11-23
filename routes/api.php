<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::get('/rooms', [RoomController::class, 'index']);
    Route::post('/rooms', [RoomController::class, 'store']);
    Route::post('/rooms/{room}/join', [RoomController::class, 'join']);

    Route::get('/rooms/{room}/messages', [MessageController::class, 'index']);
    Route::post('/rooms/{room}/messages', [MessageController::class, 'store']);

    Route::post('/logout', [AuthController::class,'logout']);
});
