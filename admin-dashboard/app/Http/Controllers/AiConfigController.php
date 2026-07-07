<?php

namespace App\Http\Controllers;

use App\Models\AiConfig;
use Illuminate\Http\Request;

class AiConfigController extends Controller
{
    public function index()
    {
        $configs = AiConfig::orderByDesc('is_active')->orderBy('id')->get();
        return view('ai-config.index', compact('configs'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'label'       => ['required', 'string', 'max:100'],
            'provider'    => ['required', 'in:openai,groq,xai'],
            'api_key'     => ['required', 'string', 'max:500'],
            'model'       => ['required', 'string', 'max:100'],
            'max_tokens'  => ['required', 'integer', 'min:256', 'max:16000'],
            'temperature' => ['required', 'numeric', 'min:0', 'max:1'],
            'notes'       => ['nullable', 'string', 'max:1000'],
        ]);

        AiConfig::create($data);

        return back()->with('success', 'AI config created successfully.');
    }

    public function update(Request $request, AiConfig $aiConfig)
    {
        $data = $request->validate([
            'label'       => ['required', 'string', 'max:100'],
            'provider'    => ['required', 'in:openai,groq,xai'],
            'api_key'     => ['required', 'string', 'max:500'],
            'model'       => ['required', 'string', 'max:100'],
            'max_tokens'  => ['required', 'integer', 'min:256', 'max:16000'],
            'temperature' => ['required', 'numeric', 'min:0', 'max:1'],
            'notes'       => ['nullable', 'string', 'max:1000'],
        ]);

        $aiConfig->update($data);

        return back()->with('success', 'AI config updated successfully.');
    }

    public function destroy(AiConfig $aiConfig)
    {
        if ($aiConfig->is_active) {
            return back()->with('error', 'Cannot delete the active AI config. Set another config as active first.');
        }

        $aiConfig->delete();
        return back()->with('success', 'AI config deleted.');
    }

    public function toggleActive(AiConfig $aiConfig)
    {
        // Deactivate all others, activate this one
        AiConfig::where('id', '!=', $aiConfig->id)->update(['is_active' => false]);
        $aiConfig->update(['is_active' => true]);

        return back()->with('success', "\"{$aiConfig->label}\" is now the active AI config.");
    }
}
