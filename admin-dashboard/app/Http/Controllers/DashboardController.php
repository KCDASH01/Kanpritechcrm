<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;

class DashboardController extends Controller
{
    public function index()
    {
        $stats = [
            'total_organizations'    => Organization::count(),
            'active_organizations'   => Organization::where('is_active', true)->count(),
            'total_users'            => User::count(),
            'active_users'           => User::where('is_active', true)->count(),
            'sso_users'              => User::where('is_sso_user', true)->count(),
            'total_subscriptions'    => Subscription::where('is_active', true)->count(),
            'business_subscriptions' => Subscription::where('is_active', true)->where('plan', 'business')->count(),
            'external_subscriptions' => Subscription::where('is_active', true)->where('subscription_source', 'external')->count(),
            'new_orgs_this_month'    => Organization::whereMonth('created_at', now()->month)
                                                    ->whereYear('created_at', now()->year)
                                                    ->count(),
        ];

        $recentOrgs = Organization::with(['owner', 'activeSubscription'])
                                  ->orderBy('id', 'desc')
                                  ->limit(8)
                                  ->get();

        return view('dashboard.index', compact('stats', 'recentOrgs'));
    }
}
