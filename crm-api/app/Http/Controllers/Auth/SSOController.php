<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SSOLoginRequest;
use App\Http\Resources\UserResource;
use App\Services\SSOService;
use Illuminate\Http\JsonResponse;

class SSOController extends Controller
{
    public function __construct(private readonly SSOService $ssoService) {}

    /**
     * POST /api/sso/login
     *
     * Accepts a JWT from the Lead Scraping App, resolves/creates a local user,
     * syncs their subscription, and returns a Sanctum token.
     */
    public function login(SSOLoginRequest $request): JsonResponse
    {
        try {
            $payload = $this->ssoService->validateToken($request->input('token'));
            $user    = $this->ssoService->resolveUser($payload);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }

        // Revoke existing tokens and issue a fresh one
        $user->tokens()->delete();
        $token = $user->createToken('sso_token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => new UserResource($user),
        ]);
    }
}
