<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class SetLocale
{
    public function handle(Request $request, Closure $next): mixed
    {
        $locale = session('locale');

        if (!$locale && auth()->check()) {
            $locale = auth()->user()->language_preference;
        }

        if ($locale && in_array($locale, ['en', 'sr'])) {
            App::setLocale($locale);
        }

        return $next($request);
    }
}
