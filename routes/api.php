<?php

use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

// Users
Route::get('/users', [UserController::class, 'index']);
Route::post('/users', [UserController::class, 'store']);

// Rooms
Route::get('/rooms', [RoomController::class, 'index']);
Route::post('/rooms', [RoomController::class, 'store']);
// to joining specific room
Route::post('/rooms/{room}/join', [RoomController::class, 'join']);

// Messages dalam room
Route::get('/rooms/{room}/messages', [MessageController::class, 'index']);
Route::post('/rooms/{room}/messages', [MessageController::class, 'store']);
