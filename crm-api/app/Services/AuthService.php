<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class AuthService
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService
    ) {}

    /**
     * Register a new direct user:
     * 1. Create Organization
     * 2. Create owner User
     * 3. Assign free Subscription
     * 4. Create default Pipeline
     * 5. Return Sanctum token
     */
    public function register(array $data): array
    {
        return DB::transaction(function () use ($data) {
            // 1. Organization
            $org = Organization::create([
                'name'     => $data['organization_name'] ?? ($data['name'] . "'s Organization"),
                'slug'     => Organization::generateSlug($data['organization_name'] ?? $data['name']),
                'email'    => $data['email'],
                'timezone' => $data['timezone'] ?? 'Asia/Kolkata',
            ]);

            // 2. Owner user
            $user = User::create([
                'organization_id'   => $org->id,
                'name'              => $data['name'],
                'email'             => $data['email'],
                'password'          => Hash::make($data['password']),
                'role'              => 'owner',
                'is_active'         => true,
                'email_verified_at' => now(),
            ]);

            // 3. Free subscription
            $this->subscriptionService->assignFree($org, $user);

            // 4. Default pipeline
            $this->subscriptionService->createDefaultPipeline($org);

            // 5. Token
            $token = $user->createToken('auth_token')->plainTextToken;

            return [
                'user'  => $user->fresh(['organization']),
                'token' => $token,
            ];
        });
    }

    /**
     * Authenticate a direct user by email + password.
     */
    public function login(string $email, string $password): array
    {
        $user = User::where('email', $email)
                    ->where('is_active', true)
                    ->first();

        if (! $user) {
            throw new \InvalidArgumentException('Invalid credentials.');
        }

        // SSO user with no password set yet — guide them to set one
        if ($user->is_sso_user && is_null($user->password)) {
            throw new \InvalidArgumentException(
                'This account was created via Lead Scraping App. Use "Forgot Password" to set a password for direct login.'
            );
        }

        if (! Hash::check($password, $user->password)) {
            throw new \InvalidArgumentException('Invalid credentials.');
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return [
            'user'  => $user->load('organization'),
            'token' => $token,
        ];
    }

    /**
     * Revoke all tokens for the user (logout).
     */
    public function logout(User $user): void
    {
        $user->tokens()->delete();
    }

    /**
     * Send password reset link email.
     */
    public function sendResetLink(string $email): string
    {
        return Password::sendResetLink(['email' => $email]);
    }

    /**
     * Reset the password using a valid token.
     */
    public function resetPassword(array $data): string
    {
        $status = Password::reset(
            $data,
            function (User $user, string $password) {
                $user->forceFill([
                    'password'       => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        return $status;
    }
}
