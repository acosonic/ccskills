<?php

namespace App\Http\Controllers;

use App\Models\User;

// Starter dashboard — replace the figures with the application's own data.
class DashboardController extends Controller
{
    public function index()
    {
        return view('dashboard', [
            'usersTotal'  => User::count(),
            'usersActive' => User::where('is_active', true)->count(),
            'usersAd'     => User::where('ldap_user', true)->count(),
            'recentUsers' => User::latest()->limit(5)->get(),
        ]);
    }
}
