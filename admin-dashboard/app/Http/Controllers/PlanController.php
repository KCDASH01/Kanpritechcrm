<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function index()
    {
        $plans = Plan::orderBy('sort_order')->get();

        return view('plans.index', compact('plans'));
    }

    public function create()
    {
        return view('plans.form', ['plan' => new Plan()]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['feature_list'] = $this->parseFeatureList($request->input('feature_list_raw', ''));

        Plan::create($data);

        return redirect()->route('plans.index')->with('success', 'Plan created successfully.');
    }

    public function edit(Plan $plan)
    {
        return view('plans.form', compact('plan'));
    }

    public function update(Request $request, Plan $plan)
    {
        $data = $this->validated($request);
        $data['feature_list'] = $this->parseFeatureList($request->input('feature_list_raw', ''));

        $plan->update($data);

        return redirect()->route('plans.index')->with('success', 'Plan updated successfully.');
    }

    public function toggleActive(Plan $plan)
    {
        $plan->update(['is_active' => ! $plan->is_active]);

        return back()->with('success', 'Plan ' . ($plan->is_active ? 'activated' : 'deactivated') . '.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function validated(Request $request): array
    {
        return $request->validate([
            'name'               => ['required', 'string', 'max:100'],
            'slug'               => ['required', 'string', 'max:50', 'alpha_dash'],
            'description'        => ['nullable', 'string', 'max:500'],
            'monthly_price'      => ['required', 'numeric', 'min:0'],
            'yearly_price'       => ['required', 'numeric', 'min:0'],
            'currency'           => ['required', 'string', 'size:3'],
            'leads_limit'        => ['required', 'integer', 'min:-1'],
            'deals_limit'        => ['required', 'integer', 'min:-1'],
            'pipelines_limit'    => ['required', 'integer', 'min:-1'],
            'team_members_limit' => ['required', 'integer', 'min:-1'],
            'departments_limit'  => ['required', 'integer', 'min:-1'],
            'bulk_import'        => ['boolean'],
            'sso_access'         => ['boolean'],
            'api_access'         => ['boolean'],
            'advanced_reports'   => ['boolean'],
            'priority_support'   => ['boolean'],
            'custom_pipelines'   => ['boolean'],
            'is_active'          => ['boolean'],
            'is_featured'        => ['boolean'],
            'sort_order'         => ['required', 'integer', 'min:0'],
        ]);
    }

    /** Convert textarea (one feature per line) to JSON array */
    private function parseFeatureList(string $raw): array
    {
        return array_values(array_filter(
            array_map('trim', explode("\n", $raw))
        ));
    }
}
