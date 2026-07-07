<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class MeController extends Controller
{
    // ── GET /api/me ───────────────────────────────────────────────────────────

    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->load('organization');

        return response()->json(['data' => new UserResource($user)]);
    }

    // ── PUT /api/me ───────────────────────────────────────────────────────────

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name'   => ['sometimes', 'string', 'max:191'],
            'phone'  => ['nullable', 'string', 'max:30'],
            'avatar' => ['nullable', 'string', 'max:500'],
        ]);

        $user->update($data);

        return response()->json(['data' => new UserResource($user->fresh('organization'))]);
    }

    // ── PUT /api/me/password ──────────────────────────────────────────────────

    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->is_sso_user) {
            return response()->json(['message' => 'SSO users cannot change their password here.'], 403);
        }

        $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! Hash::check($request->input('current_password'), $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $user->update(['password' => Hash::make($request->input('password'))]);

        // Revoke all other tokens (force re-login on other devices)
        $user->tokens()->where('id', '!=', $request->user()->currentAccessToken()->id)->delete();

        return response()->json(['message' => 'Password changed successfully.']);
    }

    // ── POST /api/me/set-password ─────────────────────────────────────────────
    // For SSO users who have never set a password — no current_password required.

    public function setPassword(Request $request): JsonResponse
    {
        $user = $request->user();

        // Only SSO users with no password yet may use this endpoint
        if (! $user->is_sso_user || ! is_null($user->password)) {
            return response()->json(['message' => 'Not allowed.'], 403);
        }

        $request->validate([
            'password'              => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required'],
        ]);

        $user->update(['password' => Hash::make($request->input('password'))]);

        return response()->json(['data' => new UserResource($user->load('organization'))]);
    }

    // ── GET /api/organization/receipt-settings ────────────────────────────────
    // Returns the receipt settings stored in the org's settings JSON column.

    public function getReceiptSettings(Request $request): JsonResponse
    {
        $org      = $request->user()->organization;
        $settings = $org->settings ?? [];

        return response()->json(['data' => $settings['receipt'] ?? (object) []]);
    }

    // ── PUT /api/organization/receipt-settings ────────────────────────────────
    // Persists receipt configuration inside organization.settings['receipt'].
    // Owner/admin only.

    public function updateReceiptSettings(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! in_array($user->role, ['owner', 'admin'])) {
            return response()->json(['message' => 'Only managers can update receipt settings.'], 403);
        }

        $data = $request->validate([
            'company_name'  => ['nullable', 'string', 'max:191'],
            'tagline'       => ['nullable', 'string', 'max:255'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city'          => ['nullable', 'string', 'max:100'],
            'state'         => ['nullable', 'string', 'max:100'],
            'pincode'       => ['nullable', 'string', 'max:20'],
            'phone'         => ['nullable', 'string', 'max:30'],
            'email'         => ['nullable', 'string', 'max:191'],
            'website'       => ['nullable', 'string', 'max:255'],
            'gstin'         => ['nullable', 'string', 'max:50'],
            'pan'           => ['nullable', 'string', 'max:50'],
            'footer_note'   => ['nullable', 'string', 'max:500'],
            'logo'          => ['nullable', 'string'], // base64 data URI
        ]);

        $org            = $user->organization;
        $settings       = $org->settings ?? [];
        $existing       = $settings['receipt'] ?? [];
        // Merge so that fields not sent are preserved
        $settings['receipt'] = array_merge($existing, $data);
        $org->update(['settings' => $settings]);

        return response()->json(['data' => $settings['receipt']]);
    }
}
