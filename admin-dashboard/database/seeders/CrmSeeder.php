<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Carbon\Carbon;

class CrmSeeder extends Seeder
{
    public function run(): void
    {
        // ── Plan definitions ────────────────────────────────────────────────
        // Free plan  → no end date, source: internal, amount: null
        // Business   → yearly, source: internal, amount: 9999 INR
        // Business External → synced from SSO, source: external

        $organizations = [
            [
                'org'  => ['name' => 'Acme Corp',          'email' => 'admin@acmecorp.com',       'city' => 'Mumbai',    'country' => 'India'],
                'user' => ['name' => 'Raj Patel',           'email' => 'raj@acmecorp.com'],
                'plan' => 'business', 'source' => 'internal',
                'amount' => 9999, 'currency' => 'INR',
                'end_date' => Carbon::now()->addYear(),
            ],
            [
                'org'  => ['name' => 'TechNova Solutions',  'email' => 'info@technova.io',         'city' => 'Bangalore', 'country' => 'India'],
                'user' => ['name' => 'Priya Sharma',        'email' => 'priya@technova.io'],
                'plan' => 'business', 'source' => 'internal',
                'amount' => 9999, 'currency' => 'INR',
                'end_date' => Carbon::now()->addMonths(8),
            ],
            [
                'org'  => ['name' => 'Bright Leads Ltd',    'email' => 'contact@brightleads.com',  'city' => 'Delhi',     'country' => 'India'],
                'user' => ['name' => 'Ankit Verma',         'email' => 'ankit@brightleads.com'],
                'plan' => 'business', 'source' => 'external',
                'amount' => null, 'currency' => null,
                'end_date' => Carbon::now()->addMonths(5),
            ],
            [
                'org'  => ['name' => 'GrowthHub Inc',       'email' => 'hello@growthhub.com',      'city' => 'Pune',      'country' => 'India'],
                'user' => ['name' => 'Sneha Joshi',         'email' => 'sneha@growthhub.com'],
                'plan' => 'free', 'source' => 'internal',
                'amount' => null, 'currency' => null,
                'end_date' => null,
            ],
            [
                'org'  => ['name' => 'SalesForge',          'email' => 'team@salesforge.in',       'city' => 'Hyderabad', 'country' => 'India'],
                'user' => ['name' => 'Vikram Rao',          'email' => 'vikram@salesforge.in'],
                'plan' => 'free', 'source' => 'internal',
                'amount' => null, 'currency' => null,
                'end_date' => null,
            ],
            [
                'org'  => ['name' => 'Nexus Digital',       'email' => 'ops@nexusdigital.com',     'city' => 'Chennai',   'country' => 'India'],
                'user' => ['name' => 'Meera Nair',          'email' => 'meera@nexusdigital.com'],
                'plan' => 'business', 'source' => 'external',
                'amount' => null, 'currency' => null,
                'end_date' => Carbon::now()->addMonths(11),
            ],
            [
                'org'  => ['name' => 'Orbit Ventures',      'email' => 'admin@orbitventures.co',   'city' => 'Ahmedabad', 'country' => 'India'],
                'user' => ['name' => 'Rahul Gupta',         'email' => 'rahul@orbitventures.co'],
                'plan' => 'free', 'source' => 'internal',
                'amount' => null, 'currency' => null,
                'end_date' => null,
            ],
            [
                'org'  => ['name' => 'PipelinePro',         'email' => 'support@pipelinepro.com',  'city' => 'Kolkata',   'country' => 'India'],
                'user' => ['name' => 'Ayesha Khan',         'email' => 'ayesha@pipelinepro.com'],
                'plan' => 'business', 'source' => 'internal',
                'amount' => 9999, 'currency' => 'INR',
                'end_date' => Carbon::now()->addMonths(3),
            ],
        ];

        foreach ($organizations as $entry) {
            // 1. Create organization
            $orgId = DB::table('organizations')->insertGetId([
                'name'       => $entry['org']['name'],
                'slug'       => Str::slug($entry['org']['name']) . '-' . Str::random(4),
                'email'      => $entry['org']['email'],
                'city'       => $entry['org']['city'],
                'country'    => $entry['org']['country'],
                'timezone'   => 'Asia/Kolkata',
                'is_active'  => true,
                'created_at' => now()->subDays(rand(1, 90)),
                'updated_at' => now(),
            ]);

            // 2. Create owner user
            $userId = DB::table('users')->insertGetId([
                'organization_id'   => $orgId,
                'name'              => $entry['user']['name'],
                'email'             => $entry['user']['email'],
                'password'          => Hash::make('password'),
                'role'              => 'owner',
                'is_active'         => true,
                'is_sso_user'       => $entry['source'] === 'external',
                'sso_provider'      => $entry['source'] === 'external' ? 'lead_scraping_app' : null,
                'external_id'       => $entry['source'] === 'external' ? 'ext_' . Str::random(8) : null,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);

            // 3. Create subscription
            DB::table('subscriptions')->insert([
                'organization_id'     => $orgId,
                'user_id'             => $userId,
                'plan'                => $entry['plan'],
                'subscription_source' => $entry['source'],
                'start_date'          => now()->subDays(rand(1, 30)),
                'end_date'            => $entry['end_date'],
                'is_active'           => true,
                'status'              => 'active',
                'amount'              => $entry['amount'],
                'currency'            => $entry['currency'],
                'external_metadata'   => $entry['source'] === 'external'
                                            ? json_encode(['synced_at' => now()->toISOString()])
                                            : null,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);

            // 4. Create default pipeline for each org
            $pipelineId = DB::table('pipelines')->insertGetId([
                'organization_id' => $orgId,
                'name'            => 'Sales Pipeline',
                'is_default'      => true,
                'sort_order'      => 0,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            // 5. Create default stages
            $stages = [
                ['New',       '#6366f1', 0,  10, false, false],
                ['Contacted', '#3b82f6', 1,  20, false, false],
                ['Qualified', '#f59e0b', 2,  40, false, false],
                ['Proposal',  '#8b5cf6', 3,  60, false, false],
                ['Won',       '#10b981', 4, 100, true,  false],
                ['Lost',      '#ef4444', 5,   0, false, true ],
            ];

            foreach ($stages as [$name, $color, $order, $prob, $won, $lost]) {
                DB::table('stages')->insert([
                    'pipeline_id'     => $pipelineId,
                    'organization_id' => $orgId,
                    'name'            => $name,
                    'color'           => $color,
                    'sort_order'      => $order,
                    'probability'     => $prob,
                    'is_won'          => $won,
                    'is_lost'         => $lost,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
            }
        }

        $this->command->info('✅ CRM seed complete: 8 organizations, mixed Free & Business plans.');
    }
}
