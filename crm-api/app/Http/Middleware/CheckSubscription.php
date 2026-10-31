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
            $latest = $org->subscriptions()
                ->where('subscription_source', 'internal')
                ->whereNotNull('authorized_at')
                ->latest('id')
                ->first();

            // Expired modern subscriptions retain read-only access. A pending
            // authorization has no authorized_at and therefore receives no CRM access.
            if ($latest && in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
                return $next($request);
            }

            return response()->json([
                'message' => 'No active subscription found. Please subscribe to continue.',
                'code'    => 'NO_SUBSCRIPTION',
            ], 403);
        }

        if ($sub->isReadOnly() && ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return response()->json([
                'message' => 'Your subscription is read-only. Update your payment method or subscription to continue editing.',
                'code' => 'SUBSCRIPTION_READ_ONLY',
            ], 402);
        }

        return $next($request);
    }
}
