<?php

// AI asistent (modul ai-agent). U routes/web.php, unutar grupe middleware('auth'):  require __DIR__ . '/ai.php';
// a javni asistent sajta (varijanta B) VAN te grupe:                                    require __DIR__ . '/ai-public.php';

use App\Http\Controllers\AiController;
use Illuminate\Support\Facades\Route;

Route::get('/ai', [AiController::class, 'index'])->name('ai.index');
Route::get('/ai/conversations', [AiController::class, 'conversations'])->name('ai.conversations');
Route::get('/ai/conversations/{id}', [AiController::class, 'show'])->name('ai.show')->whereNumber('id');
Route::delete('/ai/conversations/{id}', [AiController::class, 'destroy'])->whereNumber('id');
Route::post('/ai/ask', [AiController::class, 'ask'])->name('ai.ask')->middleware('throttle:30,1');
Route::post('/ai/proposals/{id}', [AiController::class, 'decide'])->name('ai.decide')->whereNumber('id');
