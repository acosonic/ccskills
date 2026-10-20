<?php

// Javni asistent sajta (varijanta B). U routes/web.php VAN grupe auth:  require __DIR__ . '/ai-public.php';
// i u bootstrap/app.php izuzmi od CSRF-a:  $middleware->validateCsrfTokens(except: ['ai/site/*']);
// Ako se widget ugrađuje na drugi domen: CORS za 'ai/site/*' (config/cors.php → paths, allowed_origins = domen sajta).

use App\Http\Controllers\AiSiteController;
use Illuminate\Support\Facades\Route;

Route::post('/ai/site/ask', [AiSiteController::class, 'ask'])->name('ai.site.ask');
