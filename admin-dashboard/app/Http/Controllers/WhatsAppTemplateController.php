<?php

namespace App\Http\Controllers;

use App\Models\WhatsAppTemplate;
use Illuminate\Http\Request;

class WhatsAppTemplateController extends Controller
{
    public function index()
    {
        $templates = WhatsAppTemplate::orderBy('sort_order')->orderBy('id')->get();
        return view('whatsapp.index', compact('templates'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'       => ['required', 'string', 'max:100'],
            'message'    => ['required', 'string', 'max:1000'],
            'is_active'  => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ]);

        $data['is_active']  = $request->boolean('is_active', true);
        $data['sort_order'] = $data['sort_order'] ?? (WhatsAppTemplate::max('sort_order') + 1);

        WhatsAppTemplate::create($data);

        return back()->with('success', 'Template created successfully.');
    }

    public function update(Request $request, WhatsAppTemplate $whatsappTemplate)
    {
        $data = $request->validate([
            'name'       => ['required', 'string', 'max:100'],
            'message'    => ['required', 'string', 'max:1000'],
            'is_active'  => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ]);

        $data['is_active'] = $request->boolean('is_active', true);

        $whatsappTemplate->update($data);

        return back()->with('success', 'Template updated successfully.');
    }

    public function destroy(WhatsAppTemplate $whatsappTemplate)
    {
        $whatsappTemplate->delete();
        return back()->with('success', 'Template deleted.');
    }

    public function toggleActive(WhatsAppTemplate $whatsappTemplate)
    {
        $whatsappTemplate->update(['is_active' => ! $whatsappTemplate->is_active]);
        $status = $whatsappTemplate->is_active ? 'enabled' : 'disabled';
        return back()->with('success', "Template {$status}.");
    }
}
