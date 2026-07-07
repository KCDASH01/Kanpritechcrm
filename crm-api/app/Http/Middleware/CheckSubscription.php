<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures the authenticated user's organization has an active subscription.
 * Both 'free' and 'business' plans pass — this blocks orgs with NO active sub at all.
 */
class CheckSubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $org = $user->organization;

        if (! $org || ! $org->is_active) {
            return response()->json(['message' => 'Your organization is inactive.'], 403);
        }

        $sub = $org->activeSubscription();

        if (! $sub) {
            return response()->json([
                'message' => 'No active subscription found. Please subscribe to continue.',
                'code'    => 'NO_SUBSCRIPTION',
            ], 403);
        }

        return $next($request);
    }
}
