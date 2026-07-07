@extends('layouts.admin')

@section('title', 'AI Config — CRM Admin')
@section('page-title', 'AI / LLM Config')

@section('header-actions')
    <button onclick="document.getElementById('create-modal').classList.remove('hidden')"
            class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-xl transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        Add New Config
    </button>
@endsection

@section('content')

{{-- Info box --}}
<div class="mb-4 px-4 py-3 bg-indigo-50 border border-indigo-200 rounded-xl text-sm text-indigo-800 flex items-start gap-2">
    <svg class="w-4 h-4 mt-0.5 shrink-0 text-indigo-600" fill="currentColor" viewBox="0 0 20 20">
        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
    </svg>
    <div>
        Only <strong>one config can be active</strong> at a time. The active config is used by <em>all</em> CRM organizations when generating AI proposals. The API key is used directly — ensure it has sufficient credits.
    </div>
</div>

{{-- Configs list --}}
<div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-100">
        <p class="text-sm text-gray-500">{{ $configs->count() }} configuration{{ $configs->count() !== 1 ? 's' : '' }}</p>
    </div>

    @forelse ($configs as $cfg)
        <div class="border-b border-gray-50 last:border-0 px-6 py-5 hover:bg-gray-50/50 transition-colors group">
            <div class="flex items-start gap-4">

                {{-- Status dot --}}
                <div class="mt-1.5 shrink-0">
                    <div class="w-2.5 h-2.5 rounded-full {{ $cfg->is_active ? 'bg-emerald-500' : 'bg-gray-300' }}"></div>
                </div>

                {{-- Content --}}
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-3 mb-1 flex-wrap">
                        <span class="font-semibold text-gray-900 text-sm">{{ $cfg->label }}</span>
                        @if ($cfg->is_active)
                            <span class="text-[10px] font-semibold px-2.5 py-0.5 rounded-full bg-emerald-100 text-emerald-700 uppercase tracking-wide">● Active</span>
                        @else
                            <span class="text-[10px] font-medium px-2.5 py-0.5 rounded-full bg-gray-100 text-gray-500 uppercase tracking-wide">Inactive</span>
                        @endif
                        <span class="text-[11px] px-2 py-0.5 rounded-full bg-blue-50 text-blue-700 font-medium">{{ strtoupper($cfg->provider) }}</span>
                    </div>
                    <div class="flex items-center gap-4 text-xs text-gray-500 flex-wrap mt-1">
                        <span>Model: <strong class="text-gray-700">{{ $cfg->model }}</strong></span>
                        <span>Max Tokens: <strong class="text-gray-700">{{ number_format($cfg->max_tokens) }}</strong></span>
                        <span>Temperature: <strong class="text-gray-700">{{ number_format($cfg->temperature, 2) }}</strong></span>
                        <span>Key: <code class="bg-gray-100 px-1 py-0.5 rounded text-gray-500">sk-...{{ substr($cfg->api_key, -6) }}</code></span>
                    </div>
                    @if ($cfg->notes)
                        <p class="text-xs text-gray-400 mt-1 italic">{{ $cfg->notes }}</p>
                    @endif
                    <p class="text-xs text-gray-400 mt-1">Updated {{ $cfg->updated_at->diffForHumans() }}</p>
                </div>

                {{-- Actions --}}
                <div class="flex items-center gap-2 opacity-0 group-hover:opacity-100 transition-opacity shrink-0">
                    @unless ($cfg->is_active)
                        <form method="POST" action="{{ route('ai-config.activate', $cfg) }}">
                            @csrf
                            <button type="submit"
                                    class="px-3 py-1.5 text-xs font-medium rounded-lg border border-emerald-200 text-emerald-700 hover:bg-emerald-50 transition-colors">
                                Set Active
                            </button>
                        </form>
                    @endunless

                    <button
                        onclick="openEditModal(
                            {{ $cfg->id }},
                            {{ json_encode($cfg->label) }},
                            {{ json_encode($cfg->provider) }},
                            {{ json_encode($cfg->api_key) }},
                            {{ json_encode($cfg->model) }},
                            {{ $cfg->max_tokens }},
                            {{ $cfg->temperature }},
                            {{ json_encode($cfg->notes ?? '') }}
                        )"
                        class="px-3 py-1.5 text-xs font-medium rounded-lg border border-indigo-200 text-indigo-700 hover:bg-indigo-50 transition-colors">
                        Edit
                    </button>

                    @unless ($cfg->is_active)
                        <form method="POST" action="{{ route('ai-config.destroy', $cfg) }}"
                              onsubmit="return confirm('Delete this AI config?')">
                            @csrf @method('DELETE')
                            <button type="submit"
                                    class="px-3 py-1.5 text-xs font-medium rounded-lg border border-red-200 text-red-600 hover:bg-red-50 transition-colors">
                                Delete
                            </button>
                        </form>
                    @endunless
                </div>
            </div>
        </div>
    @empty
        <div class="px-6 py-14 text-center">
            <div class="text-5xl mb-3">🤖</div>
            <p class="text-sm text-gray-500 font-medium">No AI configurations yet</p>
            <p class="text-xs text-gray-400 mt-1">Click "Add New Config" to add an API key. Groq is free!</p>
        </div>
    @endforelse
</div>

{{-- ── Create Modal ─────────────────────────────────────────────────────────── --}}
<div id="create-modal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl w-full max-w-lg shadow-2xl max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-5 border-b border-gray-100 flex items-center justify-between sticky top-0 bg-white z-10">
            <h3 class="font-semibold text-gray-900">New AI Config</h3>
            <button onclick="document.getElementById('create-modal').classList.add('hidden')"
                    class="text-gray-400 hover:text-gray-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <form method="POST" action="{{ route('ai-config.store') }}" class="p-6 space-y-4" id="create-form">
            @csrf
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Label *</label>
                <input name="label" required maxlength="100" placeholder="e.g. Groq Llama 3.3"
                       class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"/>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Provider</label>
                <select name="provider" id="create-provider" onchange="updateModels('create')"
                        class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    <option value="groq">Groq (Free ✓)</option>
                    <option value="xai">xAI / Grok (xai-... key)</option>
                    <option value="openai">OpenAI (Paid)</option>
                </select>
                <p class="text-[11px] text-gray-400 mt-1">Groq is free → <strong>console.groq.com</strong> &nbsp;|&nbsp; xAI → <strong>console.x.ai</strong></p>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Model *</label>
                <select name="model" id="create-model"
                        class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    <option value="llama-3.3-70b-versatile">llama-3.3-70b-versatile (best, free)</option>
                    <option value="llama-3.1-8b-instant">llama-3.1-8b-instant (fastest, free)</option>
                    <option value="mixtral-8x7b-32768">mixtral-8x7b-32768 (good, free)</option>
                    <option value="llama3-70b-8192">llama3-70b-8192 (free)</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">API Key *</label>
                <input name="api_key" type="password" required maxlength="500" placeholder="gsk_... (Groq) or sk-... (OpenAI)"
                       class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 font-mono"/>
                <p class="text-[11px] text-gray-400 mt-1">Stored in the database. Keep this secret.</p>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Max Tokens</label>
                    <input name="max_tokens" type="number" value="4096" min="256" max="16000"
                           class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"/>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Temperature (0–1)</label>
                    <input name="temperature" type="number" value="0.70" min="0" max="1" step="0.05"
                           class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"/>
                    <p class="text-[11px] text-gray-400 mt-1">Higher = more creative</p>
                </div>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Notes (optional)</label>
                <textarea name="notes" rows="2" maxlength="1000" placeholder="Internal notes about this config..."
                          class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-none"></textarea>
            </div>
            <div class="flex gap-3 pt-1">
                <button type="submit"
                        class="flex-1 px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-xl transition-colors">
                    Save Config
                </button>
                <button type="button"
                        onclick="document.getElementById('create-modal').classList.add('hidden')"
                        class="px-4 py-2.5 border border-gray-200 text-gray-600 text-sm font-medium rounded-xl hover:bg-gray-50 transition-colors">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

{{-- ── Edit Modal ───────────────────────────────────────────────────────────── --}}
<div id="edit-modal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl w-full max-w-lg shadow-2xl max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-5 border-b border-gray-100 flex items-center justify-between sticky top-0 bg-white z-10">
            <h3 class="font-semibold text-gray-900">Edit AI Config</h3>
            <button onclick="document.getElementById('edit-modal').classList.add('hidden')"
                    class="text-gray-400 hover:text-gray-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <form id="edit-form" method="POST" action="" class="p-6 space-y-4">
            @csrf @method('PUT')
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Label *</label>
                <input id="edit-label" name="label" required maxlength="100"
                       class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"/>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Provider</label>
                <select id="edit-provider" name="provider" onchange="updateModels('edit')"
                        class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    <option value="groq">Groq (Free ✓)</option>
                    <option value="xai">xAI / Grok (xai-... key)</option>
                    <option value="openai">OpenAI (Paid)</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Model *</label>
                <select id="edit-model" name="model"
                        class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    <option value="llama-3.3-70b-versatile">llama-3.3-70b-versatile (best, free)</option>
                    <option value="llama-3.1-8b-instant">llama-3.1-8b-instant (fastest, free)</option>
                    <option value="mixtral-8x7b-32768">mixtral-8x7b-32768 (good, free)</option>
                    <option value="llama3-70b-8192">llama3-70b-8192 (free)</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">API Key *</label>
                <input id="edit-api-key" name="api_key" type="password" required maxlength="500"
                       class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 font-mono"/>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Max Tokens</label>
                    <input id="edit-max-tokens" name="max_tokens" type="number" min="256" max="16000"
                           class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"/>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Temperature (0–1)</label>
                    <input id="edit-temperature" name="temperature" type="number" min="0" max="1" step="0.05"
                           class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"/>
                </div>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Notes (optional)</label>
                <textarea id="edit-notes" name="notes" rows="2" maxlength="1000"
                          class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-none"></textarea>
            </div>
            <div class="flex gap-3 pt-1">
                <button type="submit"
                        class="flex-1 px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-xl transition-colors">
                    Save Changes
                </button>
                <button type="button"
                        onclick="document.getElementById('edit-modal').classList.add('hidden')"
                        class="px-4 py-2.5 border border-gray-200 text-gray-600 text-sm font-medium rounded-xl hover:bg-gray-50 transition-colors">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

@endsection

@push('scripts')
<script>
const MODELS = {
    groq: [
        { value: 'llama-3.3-70b-versatile', label: 'llama-3.3-70b-versatile (best, free)' },
        { value: 'llama-3.1-8b-instant',    label: 'llama-3.1-8b-instant (fastest, free)' },
        { value: 'mixtral-8x7b-32768',       label: 'mixtral-8x7b-32768 (good, free)' },
        { value: 'llama3-70b-8192',          label: 'llama3-70b-8192 (free)' },
    ],
    xai: [
        { value: 'grok-3-mini',  label: 'grok-3-mini (recommended)' },
        { value: 'grok-3',       label: 'grok-3 (most powerful)' },
        { value: 'grok-2',       label: 'grok-2' },
        { value: 'grok-beta',    label: 'grok-beta' },
    ],
    openai: [
        { value: 'gpt-4o',       label: 'gpt-4o (recommended)' },
        { value: 'gpt-4o-mini',  label: 'gpt-4o-mini (cheaper)' },
        { value: 'gpt-4-turbo',  label: 'gpt-4-turbo' },
        { value: 'gpt-3.5-turbo',label: 'gpt-3.5-turbo (fastest)' },
    ],
};

function updateModels(prefix, selectedModel) {
    const provider = document.getElementById(prefix + '-provider').value;
    const modelSel = document.getElementById(prefix + '-model');
    const models   = MODELS[provider] || MODELS.groq;
    modelSel.innerHTML = models.map(m =>
        `<option value="${m.value}"${m.value === selectedModel ? ' selected' : ''}>${m.label}</option>`
    ).join('');
}

function openEditModal(id, label, provider, apiKey, model, maxTokens, temperature, notes) {
    const base = '{{ url("ai-config") }}';
    document.getElementById('edit-form').action          = base + '/' + id;
    document.getElementById('edit-label').value          = label;
    document.getElementById('edit-provider').value       = provider;
    document.getElementById('edit-api-key').value        = apiKey;
    document.getElementById('edit-max-tokens').value     = maxTokens;
    document.getElementById('edit-temperature').value    = temperature;
    document.getElementById('edit-notes').value          = notes;
    // Populate models for this provider then select the current model
    updateModels('edit', model);
    document.getElementById('edit-modal').classList.remove('hidden');
}

// Init create form models on page load
updateModels('create');
</script>
@endpush
