<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // The theme styles Bootstrap markup; Laravel's default pagination view is Tailwind.
        Paginator::useBootstrapFive();
    }
}
