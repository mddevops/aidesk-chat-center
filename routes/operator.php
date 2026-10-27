<?php

use App\Http\Controllers\Chat\OperatorController;
use App\Http\Controllers\Chat\RoutingController;
use App\Http\Controllers\Chat\WorkflowController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['auth', 'tenant', 'throttle:operator-api'])->group(function () {
    Route::get('/routing', [RoutingController::class, 'index']);
    Route::put('/routing/channels/{channel}', [RoutingController::class, 'rule']);
    Route::put('/routing/operators/{userId}', [RoutingController::class, 'profile']);
    Route::get('/availability', [RoutingController::class, 'presence']);
    Route::post('/availability', [RoutingController::class, 'heartbeat']);
    Route::get('/quick-replies', [WorkflowController::class, 'replies']);
    Route::post('/quick-replies', [WorkflowController::class, 'storeReply']);
    Route::patch('/quick-replies/{quickReply}', [WorkflowController::class, 'updateReply']);
    Route::get('/conversations/{conversation}/activities', [WorkflowController::class, 'activities']);
    foreach (['accept', 'assign', 'transition'] as $operation) {
        Route::post('/conversations/{conversation}/'.$operation, [WorkflowController::class, 'change'])->name($operation);
    }
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
