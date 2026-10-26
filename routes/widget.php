<?php

use App\Http\Controllers\Chat\VisitorController;
use Illuminate\Support\Facades\Route;

Route::prefix('widget/v1/channels/{channelPublicId}')->middleware('widget.channel')->group(function () {
    Route::options('/{path?}', fn () => response()->noContent())->where('path', '.*');
    Route::post('/sessions', [VisitorController::class, 'session'])->middleware('throttle:widget-sessions');
    Route::middleware('visitor.credential')->group(function () {
        Route::post('/identify', [VisitorController::class, 'identify'])->middleware('throttle:widget-identify');
        Route::get('/conversations/current', [VisitorController::class, 'current']);
        Route::get('/messages', [VisitorController::class, 'messages']);
        Route::post('/messages', [VisitorController::class, 'send'])->middleware('throttle:widget-messages');
        Route::post('/read', [VisitorController::class, 'read']);
        Route::post('/revoke', [VisitorController::class, 'revoke']);
        Route::post('/attachments', [VisitorController::class, 'upload'])->middleware('throttle:chat-uploads');
        Route::get('/attachments/{attachment}', [VisitorController::class, 'download']);
    });
});
