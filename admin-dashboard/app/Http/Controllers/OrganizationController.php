<?php

namespace App\Http\Controllers;

use App\Models\Organization;

class OrganizationController extends Controller
{
    public function index()
    {
        $organizations = Organization::withCount('users')
                                     ->with(['owner', 'activeSubscription'])
                                     ->orderBy('id', 'desc')
                                     ->paginate(20);

        return view('organizations.index', compact('organizations'));
    }

    public function show(Organization $organization)
    {
        $organization->load(['owner', 'users', 'subscriptions' => fn ($q) => $q->orderBy('id', 'desc')]);

        return view('organizations.show', compact('organization'));
    }

    public function toggleActive(Organization $organization)
    {
        $organization->update(['is_active' => ! $organization->is_active]);

        $status = $organization->is_active ? 'activated' : 'deactivated';

        return back()->with('success', "Organization {$status} successfully.");
    }
}
