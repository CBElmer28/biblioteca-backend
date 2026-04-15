<?php

use App\Http\Controllers\Api\NotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware('jwt:admin,bibliotecario')->prefix('admin')->group(function () {
    Route::get('notifications',              [NotificationController::class, 'index']);
    Route::get('notifications/stats',        [NotificationController::class, 'stats']);
    Route::post('notifications/{id}/retry',  [NotificationController::class, 'retry']);
});

Route::get('/health', function () {
    $redisOk = false;
    try {
        \Illuminate\Support\Facades\Redis::ping();
        $redisOk = true;
    } catch (\Throwable) {}

    return response()->json([
        'service'      => 'notification-service',
        'status'       => $redisOk ? 'ok' : 'degraded',
        'redis'        => $redisOk ? 'connected' : 'disconnected',
        'queue_driver' => config('queue.default'),
        'pending_retry'=> \App\Models\NotificationLog::where('status', 'failed')
                            ->where('attempt', '<', 3)->count(),
        'time'         => now()->toIso8601String(),
    ], $redisOk ? 200 : 503);
});