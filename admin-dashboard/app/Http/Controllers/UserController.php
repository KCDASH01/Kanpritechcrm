<?php

namespace App\Http\Controllers;

use App\Models\User;

class UserController extends Controller
{
    public function index()
    {
        $users = User::with('organization')
                     ->orderBy('id', 'desc')
                     ->paginate(25);

        return view('users.index', compact('users'));
    }

    public function show(User $user)
    {
        $user->load(['organization.subscriptions' => fn ($q) => $q->orderBy('id', 'desc')]);

        return view('users.show', compact('user'));
    }

    public function toggleActive(User $user)
    {
        if ($user->role === 'owner' && $user->is_active) {
            return back()->with('error', 'Cannot deactivate an organization owner directly. Deactivate the organization instead.');
        }

        $user->update(['is_active' => ! $user->is_active]);

        $status = $user->is_active ? 'activated' : 'deactivated';

        return back()->with('success', "User {$status} successfully.");
    }
}
