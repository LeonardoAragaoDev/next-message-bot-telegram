<?php
// routes/api.php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TelegramBotController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Rota principal para receber webhooks do Telegram
Route::post('telegram/webhook', [TelegramBotController::class, 'handleWebhook']);
