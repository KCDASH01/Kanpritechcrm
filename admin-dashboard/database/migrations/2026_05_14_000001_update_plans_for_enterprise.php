<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add extra_member_price column to plans
        Schema::table('plans', function (Blueprint $table) {
            $table->decimal('extra_member_price', 10, 2)->default(0)->after('team_members_limit');
        });

        // Update Business plan limits (was -1 / unlimited for everything)
        DB::table('plans')->where('slug', 'business')->update([
            'leads_limit'         => 5000,
            'deals_limit'         => 2000,
            'pipelines_limit'     => 30,
            'team_members_limit'  => 3,
            'extra_member_price'  => 299,
            'is_featured'         => false,   // Enterprise will be featured
            'feature_list'        => json_encode([
                'Up to 5,000 leads',
                'Up to 2,000 deals',
                '30 sales pipelines',
                '3 team members included',
                'Extra seats at ₹299/member/month',
                'Unlimited departments',
                'Bulk lead import (CSV)',
                'SSO via Lead Scraping App',
                'Advanced reports & analytics',
                'API access',
                'Priority support',
                'Custom pipelines',
            ]),
            'updated_at' => now(),
        ]);

        // Insert Enterprise plan
        DB::table('plans')->insert([
            'name'               => 'Enterprise',
            'slug'               => 'enterprise',
            'description'        => 'For large teams that need unlimited everything, maximum seats, and top-tier support.',
            'monthly_price'      => 1999,
            'yearly_price'       => 19999,
            'currency'           => 'INR',
            'leads_limit'        => -1,
            'deals_limit'        => -1,
            'pipelines_limit'    => -1,
            'team_members_limit' => 10,
            'departments_limit'  => -1,
            'extra_member_price' => 299,
            'bulk_import'        => true,
            'sso_access'         => true,
            'api_access'         => true,
            'advanced_reports'   => true,
            'priority_support'   => true,
            'custom_pipelines'   => true,
            'feature_list'       => json_encode([
                'Unlimited leads & deals',
                'Unlimited pipelines',
                '10 team members included',
                'Extra seats at ₹299/member/month',
                'Unlimited departments',
                'Bulk lead import (CSV)',
                'SSO via Lead Scraping App',
                'Advanced reports & analytics',
                'API access',
                'Priority support',
                'Custom pipelines',
            ]),
            'is_active'   => true,
            'is_featured' => true,
            'sort_order'  => 3,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    public function down(): void
    {
        // Remove Enterprise plan
        DB::table('plans')->where('slug', 'enterprise')->delete();

        // Restore Business plan to original unlimited values
        DB::table('plans')->where('slug', 'business')->update([
            'leads_limit'         => -1,
            'deals_limit'         => -1,
            'pipelines_limit'     => -1,
            'team_members_limit'  => -1,
            'extra_member_price'  => 0,
            'is_featured'         => true,
            'feature_list'        => json_encode([
                'Unlimited leads & deals',
                'Unlimited pipelines',
                'Team members & departments',
                'Bulk lead import (CSV)',
                'SSO via Lead Scraping App',
                'Advanced reports & analytics',
                'API access',
                'Priority support',
            ]),
            'updated_at' => now(),
        ]);

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('extra_member_price');
        });
    }
};
