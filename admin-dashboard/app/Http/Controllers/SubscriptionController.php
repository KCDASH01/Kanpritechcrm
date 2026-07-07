<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function index()
    {
        $subscriptions = Subscription::with(['organization', 'user'])
                                     ->orderBy('id', 'desc')
                                     ->paginate(25);

        return view('subscriptions.index', compact('subscriptions'));
    }

    public function showActivate(Organization $organization)
    {
        $organization->load(['owner', 'activeSubscription']);

        return view('subscriptions.activate', compact('organization'));
    }

    public function activate(Request $request, Organization $organization)
    {
        $data = $request->validate([
            'plan'       => ['required', 'in:free,business,enterprise'],
            'source'     => ['required', 'in:internal,external'],
            'amount'     => ['nullable', 'numeric', 'min:0'],
            'currency'   => ['nullable', 'string', 'size:3'],
            'start_date' => ['required', 'date'],
            'end_date'   => ['nullable', 'date', 'after:start_date'],
            'notes'      => ['nullable', 'string', 'max:500'],
        ]);

        // Deactivate existing subscriptions of same source
        Subscription::where('organization_id', $organization->id)
                    ->where('subscription_source', $data['source'])
                    ->update(['is_active' => false, 'status' => 'cancelled']);

        $owner = $organization->owner ?? $organization->users()->first();

        Subscription::create([
            'organization_id'     => $organization->id,
            'user_id'             => $owner?->id,
            'plan'                => $data['plan'],
            'subscription_source' => $data['source'],
            'start_date'          => Carbon::parse($data['start_date']),
            'end_date'            => isset($data['end_date']) ? Carbon::parse($data['end_date']) : null,
            'is_active'           => true,
            'status'              => 'active',
            'amount'              => $data['amount'] ?? null,
            'currency'            => $data['currency'] ?? 'INR',
            'external_metadata'   => ['admin_notes' => $data['notes'] ?? null],
        ]);

        return redirect()->route('organizations.show', $organization)
                         ->with('success', 'Subscription activated successfully.');
    }

    public function cancel(Subscription $subscription)
    {
        $subscription->update(['is_active' => false, 'status' => 'cancelled']);

        return back()->with('success', 'Subscription cancelled.');
    }
}
