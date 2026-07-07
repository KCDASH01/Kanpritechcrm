<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts access to Business-plan organizations only.
 * Returns 403 with a clear upgrade prompt for free-plan orgs.
 */
class BusinessOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $user->organization?->isPaidPlan()) {
            return response()->json([
                'message' => 'This feature requires a Business or Enterprise plan. Please upgrade.',
                'code'    => 'UPGRADE_REQUIRED',
            ], 403);
        }

        return $next($request);
    }
}
