<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

class SSOService
{
    private string $secret;
    private string $algo;

    public function __construct()
    {
        $this->secret = config('sso.secret');
        $this->algo   = config('sso.algo', 'HS256');
    }

    // ── JWT Validation ─────────────────────────────────────────────────────────

    /**
     * Decode and validate the incoming JWT from the Lead Scraping App.
     * Returns the payload array or throws on failure.
     */
    public function validateToken(string $token): array
    {
        if (empty($this->secret)) {
            throw new \RuntimeException('SSO secret is not configured.');
        }

        try {
            $decoded = JWT::decode($token, new Key($this->secret, $this->algo));
            return (array) $decoded;
        } catch (Throwable $e) {
            throw new \InvalidArgumentException('Invalid SSO token: ' . $e->getMessage());
        }
    }

    // ── User Resolution ────────────────────────────────────────────────────────

    /**
     * Find or create a user from the SSO payload, then sync their subscription.
     * Returns the User model.
     */
    public function resolveUser(array $payload): User
    {
        $this->validatePayload($payload);

        // 1. Try external_id first, then email
        $user = User::where('external_id', $payload['external_id'])
                    ->first()
              ?? User::where('email', $payload['email'])
                    ->whereNull('external_id')     // direct user with same email
                    ->first();

        if ($user) {
            // Update SSO markers in case this was previously a direct user
            $user->update([
                'external_id'  => $payload['external_id'],
                'is_sso_user'  => true,
                'sso_provider' => 'lead_scraping_app',
                'name'         => $payload['name'] ?? $user->name,
            ]);
        } else {
            // Create new SSO user + organization
            $user = $this->createSSOUser($payload);
        }

        // 2. Sync subscription from SSO payload
        $this->syncSubscription($user, $payload);

        return $user->fresh(['organization']);
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private function validatePayload(array $payload): void
    {
        $required = ['external_id', 'email', 'plan'];
        foreach ($required as $field) {
            if (empty($payload[$field])) {
                throw new \InvalidArgumentException("SSO payload missing required field: {$field}");
            }
        }

        if (! in_array($payload['plan'], ['free', 'business', 'enterprise'])) {
            throw new \InvalidArgumentException("Unknown plan in SSO payload: {$payload['plan']}");
        }
    }

    private function createSSOUser(array $payload): User
    {
        // Create organization first
        $org = Organization::create([
            'name'     => $payload['company'] ?? ($payload['name'] . "'s Organization"),
            'slug'     => Organization::generateSlug($payload['name']),
            'email'    => $payload['email'],
            'timezone' => $payload['timezone'] ?? 'UTC',
        ]);

        // Create user
        $user = User::create([
            'organization_id' => $org->id,
            'name'            => $payload['name'],
            'email'           => $payload['email'],
            'password'        => null,              // SSO users have no password
            'external_id'     => $payload['external_id'],
            'is_sso_user'     => true,
            'sso_provider'    => 'lead_scraping_app',
            'role'            => 'owner',
            'email_verified_at' => now(),           // trusted — verified by lead app
        ]);

        return $user;
    }

    /**
     * Sync the subscription from the SSO payload to the organization.
     *
     * Rules:
     * - plan = 'business'/'enterprise' AND valid dates → activate that plan (external)
     * - plan = 'free' OR expired                       → downgrade to FREE (external)
     */
    public function syncSubscription(User $user, array $payload): Subscription
    {
        $org  = $user->organization;
        $plan = strtolower($payload['plan'] ?? 'free');

        $startDate = isset($payload['start_date'])
            ? \Carbon\Carbon::parse($payload['start_date'])
            : now();

        $endDate = isset($payload['end_date'])
            ? \Carbon\Carbon::parse($payload['end_date'])
            : null;

        // Determine if the external subscription is currently valid
        $isExpired = $endDate && $endDate->isPast();

        if (! in_array($plan, ['business', 'enterprise']) || $isExpired) {
            $plan = 'free';
        }

        // Deactivate all current external subscriptions for this org
        Subscription::where('organization_id', $org->id)
            ->where('subscription_source', 'external')
            ->update(['is_active' => false, 'status' => 'cancelled']);

        // Create the synced subscription
        $sub = Subscription::create([
            'organization_id'     => $org->id,
            'user_id'             => $user->id,
            'plan'                => $plan,
            'subscription_source' => 'external',
            'start_date'          => $startDate,
            'end_date'            => $endDate,
            'is_active'           => true,
            'status'              => 'active',
            'external_metadata'   => $payload,
        ]);

        return $sub;
    }
}
