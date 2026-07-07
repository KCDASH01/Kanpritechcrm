<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();              // 'free', 'business'
            $table->text('description')->nullable();

            // Pricing
            $table->decimal('monthly_price', 10, 2)->default(0);
            $table->decimal('yearly_price',  10, 2)->default(0);
            $table->string('currency', 3)->default('INR');

            // Limits (-1 = unlimited)
            $table->integer('leads_limit')->default(100);
            $table->integer('deals_limit')->default(50);
            $table->integer('pipelines_limit')->default(1);
            $table->integer('team_members_limit')->default(0);  // 0 = no team
            $table->integer('departments_limit')->default(0);   // 0 = no departments

            // Feature flags
            $table->boolean('bulk_import')->default(false);
            $table->boolean('sso_access')->default(false);
            $table->boolean('api_access')->default(false);
            $table->boolean('advanced_reports')->default(false);
            $table->boolean('priority_support')->default(false);
            $table->boolean('custom_pipelines')->default(false);

            // Feature list shown on pricing cards (JSON array of strings)
            $table->json('feature_list')->nullable();

            // Display
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);     // highlights the card
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
        });

        // ── Seed default plans ────────────────────────────────────────────────
        DB::table('plans')->insert([
            [
                'name'              => 'Free',
                'slug'              => 'free',
                'description'       => 'Perfect for individuals and small teams just getting started with CRM.',
                'monthly_price'     => 0,
                'yearly_price'      => 0,
                'currency'          => 'INR',
                'leads_limit'       => 100,
                'deals_limit'       => 50,
                'pipelines_limit'   => 1,
                'team_members_limit'=> 0,
                'departments_limit' => 0,
                'bulk_import'       => false,
                'sso_access'        => false,
                'api_access'        => false,
                'advanced_reports'  => false,
                'priority_support'  => false,
                'custom_pipelines'  => false,
                'feature_list'      => json_encode([
                    'Up to 100 leads',
                    'Up to 50 deals',
                    '1 sales pipeline',
                    'Basic activities & notes',
                    'Email & password login',
                    'Community support',
                ]),
                'is_active'    => true,
                'is_featured'  => false,
                'sort_order'   => 1,
                'created_at'   => now(),
                'updated_at'   => now(),
            ],
            [
                'name'              => 'Business',
                'slug'              => 'business',
                'description'       => 'For growing teams that need unlimited CRM power, collaboration, and integrations.',
                'monthly_price'     => 999,
                'yearly_price'      => 9999,
                'currency'          => 'INR',
                'leads_limit'       => -1,
                'deals_limit'       => -1,
                'pipelines_limit'   => -1,
                'team_members_limit'=> -1,
                'departments_limit' => -1,
                'bulk_import'       => true,
                'sso_access'        => true,
                'api_access'        => true,
                'advanced_reports'  => true,
                'priority_support'  => true,
                'custom_pipelines'  => true,
                'feature_list'      => json_encode([
                    'Unlimited leads & deals',
                    'Unlimited pipelines',
                    'Team members & departments',
                    'Bulk lead import (CSV)',
                    'SSO via Lead Scraping App',
                    'Advanced reports & analytics',
                    'API access',
                    'Priority support',
                ]),
                'is_active'    => true,
                'is_featured'  => true,
                'sort_order'   => 2,
                'created_at'   => now(),
                'updated_at'   => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
