<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Pipeline;
use App\Models\Stage;
use Illuminate\Database\Seeder;

class SoftwarePipelineSeeder extends Seeder
{
    public function run(): void
    {
        // Use the most recently created org (most likely the active user's org)
        $org = Organization::latest('id')->first();

        if (! $org) {
            $this->command->error('No organization found. Please register/login first, then re-run this seeder.');
            return;
        }

        $this->command->info("Seeding pipelines for: {$org->name}");

        // ── Delete existing pipelines for this org (clean slate) ─────────────
        Pipeline::where('organization_id', $org->id)->forceDelete();

        // ── 1. Sales Pipeline (default) ───────────────────────────────────────
        $sales = Pipeline::create([
            'organization_id' => $org->id,
            'name'            => 'Sales Pipeline',
            'description'     => 'Main pipeline for tracking software sales deals',
            'is_default'      => true,
            'sort_order'      => 1,
        ]);

        $salesStages = [
            ['New Lead',          '#6366f1', 10,  false, false],
            ['Contacted',         '#3b82f6', 20,  false, false],
            ['Requirement Gathering', '#8b5cf6', 35, false, false],
            ['Demo / POC',        '#f59e0b', 50,  false, false],
            ['Proposal Sent',     '#f97316', 65,  false, false],
            ['Negotiation',       '#ec4899', 80,  false, false],
            ['Closed Won',        '#10b981', 100, true,  false],
            ['Closed Lost',       '#ef4444', 0,   false, true ],
        ];

        foreach ($salesStages as $i => [$name, $color, $prob, $won, $lost]) {
            Stage::create([
                'pipeline_id'     => $sales->id,
                'organization_id' => $org->id,
                'name'            => $name,
                'color'           => $color,
                'sort_order'      => $i,
                'probability'     => $prob,
                'is_won'          => $won,
                'is_lost'         => $lost,
            ]);
        }

        $this->command->info('  ✓ Sales Pipeline — ' . count($salesStages) . ' stages');

        // ── 2. Software Project Pipeline ──────────────────────────────────────
        $project = Pipeline::create([
            'organization_id' => $org->id,
            'name'            => 'Project Delivery Pipeline',
            'description'     => 'Track client projects from onboarding to go-live',
            'is_default'      => false,
            'sort_order'      => 2,
        ]);

        $projectStages = [
            ['Onboarding',        '#6366f1', 10,  false, false],
            ['Requirement Sign-off', '#3b82f6', 20, false, false],
            ['Design & Planning', '#8b5cf6', 30,  false, false],
            ['Development',       '#f59e0b', 50,  false, false],
            ['QA / Testing',      '#f97316', 70,  false, false],
            ['UAT',               '#14b8a6', 85,  false, false],
            ['Go-Live',           '#10b981', 100, true,  false],
            ['On Hold',           '#84cc16', 0,   false, false],
            ['Cancelled',         '#ef4444', 0,   false, true ],
        ];

        foreach ($projectStages as $i => [$name, $color, $prob, $won, $lost]) {
            Stage::create([
                'pipeline_id'     => $project->id,
                'organization_id' => $org->id,
                'name'            => $name,
                'color'           => $color,
                'sort_order'      => $i,
                'probability'     => $prob,
                'is_won'          => $won,
                'is_lost'         => $lost,
            ]);
        }

        $this->command->info('  ✓ Project Delivery Pipeline — ' . count($projectStages) . ' stages');

        // ── 3. SaaS Renewal Pipeline ──────────────────────────────────────────
        $renewal = Pipeline::create([
            'organization_id' => $org->id,
            'name'            => 'Renewal & Upsell Pipeline',
            'description'     => 'Manage subscription renewals and upsell opportunities',
            'is_default'      => false,
            'sort_order'      => 3,
        ]);

        $renewalStages = [
            ['Renewal Due (90d)', '#6366f1', 60,  false, false],
            ['Health Check Done', '#3b82f6', 70,  false, false],
            ['Upsell Identified', '#8b5cf6', 75,  false, false],
            ['Proposal Sent',     '#f59e0b', 85,  false, false],
            ['Renewed / Upsold',  '#10b981', 100, true,  false],
            ['Churned',           '#ef4444', 0,   false, true ],
        ];

        foreach ($renewalStages as $i => [$name, $color, $prob, $won, $lost]) {
            Stage::create([
                'pipeline_id'     => $renewal->id,
                'organization_id' => $org->id,
                'name'            => $name,
                'color'           => $color,
                'sort_order'      => $i,
                'probability'     => $prob,
                'is_won'          => $won,
                'is_lost'         => $lost,
            ]);
        }

        $this->command->info('  ✓ Renewal & Upsell Pipeline — ' . count($renewalStages) . ' stages');

        $this->command->info('');
        $this->command->info('Done! 3 pipelines created with ' .
            (count($salesStages) + count($projectStages) + count($renewalStages)) . ' stages total.');
    }
}
