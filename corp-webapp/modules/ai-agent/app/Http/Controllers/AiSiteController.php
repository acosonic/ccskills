<?php

namespace App\Http\Controllers;

use Anthropic\Client;
use App\Services\Ai\Agent;
use App\Services\Ai\Tools\SitePageTool;
use App\Services\Ai\Tools\SiteSearchTool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Varijanta B — javni asistent sajta: bez prijave, samo alati nad indeksom sajta, ograničenja po IP-u i dnevnom
 * broju tokena (svako može da troši). Istorija dolazi iz pregledača (nema naloga) — skraćena na 6 poruka i 1000 znakova.
 */
class AiSiteController extends Controller
{
    public function ask(Request $request)
    {
        abort_unless(config('ai.enabled') && config('ai.site.enabled'), 404);
        $data = $request->validate(['q' => 'required|string|max:1000', 'history' => 'nullable|array|max:6',
            'history.*.role' => 'in:user,assistant', 'history.*.content' => 'string|max:2000']);

        $key = 'ai-site:' . $request->ip();
        $dayKey = 'ai-site-tokens:' . now()->toDateString();
        if (RateLimiter::tooManyAttempts($key, config('ai.site.per_ip_per_hour')) || Cache::get($dayKey, 0) > config('ai.site.daily_token_limit')) {
            return response()->json(['error' => __('ai.site_limit')], 429);
        }
        RateLimiter::hit($key, 3600);

        $agent = new Agent(new Client(apiKey: config('ai.key')), config('ai.site.model'), 'low', [new SiteSearchTool(), new SitePageTool()], 6);
        try {
            $r = $agent->answer(strtr(config('ai.site.prompt'), [':org' => config('brand.org')]), 'Danas je ' . now()->format('d.m.Y.') . '.',
                $data['q'], $data['history'] ?? []);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        Cache::put($dayKey, Cache::get($dayKey, 0) + $r['usage']['input'] + $r['usage']['output'], now()->endOfDay());

        return response()->json(['ok' => true, 'answer' => $r['answer']]);
    }
}
