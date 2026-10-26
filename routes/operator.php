<?php

use App\Http\Controllers\Chat\OperatorController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['auth', 'tenant', 'throttle:120,1'])->group(function () {
    Route::get('/channels', [OperatorController::class, 'channels']);
    Route::post('/channels', [OperatorController::class, 'storeChannel']);
    Route::get('/conversations', [OperatorController::class, 'index']);
    Route::get('/conversations/{conversation}', [OperatorController::class, 'show']);
    Route::get('/conversations/{conversation}/messages', [OperatorController::class, 'messages']);
    Route::post('/conversations/{conversation}/messages', [OperatorController::class, 'send']);
    Route::post('/conversations/{conversation}/read', [OperatorController::class, 'read']);
    Route::post('/attachments', [OperatorController::class, 'upload'])->middleware('throttle:chat-uploads');
    Route::get('/attachments/{attachment}', [OperatorController::class, 'download']);
});
