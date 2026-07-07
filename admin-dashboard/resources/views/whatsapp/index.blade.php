@extends('layouts.admin')

@section('title', 'WhatsApp Templates — CRM Admin')
@section('page-title', 'WhatsApp Templates')

@section('header-actions')
    <button onclick="document.getElementById('create-modal').classList.remove('hidden')"
            class="inline-flex items-center gap-2 px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-semibold rounded-xl transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        Add Template
    </button>
@endsection

@section('content')

{{-- Variable helper --}}
<div class="mb-4 px-4 py-3 bg-green-50 border border-green-200 rounded-xl text-sm text-green-800 flex items-start gap-2">
    <svg class="w-4 h-4 mt-0.5 shrink-0 text-green-600" fill="currentColor" viewBox="0 0 20 20">
        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
    </svg>
    <div>
        <strong>Available variables:</strong>
        <code class="ml-1 bg-green-100 px-1.5 py-0.5 rounded text-xs">@{{ name }}</code> — lead's full name &nbsp;|&nbsp;
        <code class="bg-green-100 px-1.5 py-0.5 rounded text-xs">@{{ company }}</code> — lead's company.
        These are substituted automatically when the sales rep sends a message.
    </div>
</div>

{{-- Templates list --}}
<div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
        <p class="text-sm text-gray-500">{{ $templates->count() }} template{{ $templates->count() !== 1 ? 's' : '' }}</p>
        <p class="text-xs text-gray-400">Drag to reorder (sort_order column)</p>
    </div>

    @forelse ($templates as $tpl)
        <div class="border-b border-gray-50 last:border-0 px-6 py-5 {{ $tpl->is_active ? '' : 'opacity-50' }} hover:bg-gray-50/50 transition-colors group">
            <div class="flex items-start gap-4">
                {{-- Status indicator --}}
                <div class="mt-1 shrink-0">
                    <div class="w-2.5 h-2.5 rounded-full {{ $tpl->is_active ? 'bg-green-500' : 'bg-gray-300' }}"></div>
                </div>

                {{-- Content --}}
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-3 mb-1">
                        <span class="font-semibold text-gray-900 text-sm">{{ $tpl->name }}</span>
                        <span class="text-[10px] font-medium px-2 py-0.5 rounded-full {{ $tpl->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                            {{ $tpl->is_active ? 'Active' : 'Inactive' }}
                        </span>
                        <span class="text-[10px] text-gray-400">#{{ $tpl->sort_order }}</span>
                    </div>
                    <p class="text-sm text-gray-600 leading-relaxed">{{ $tpl->message }}</p>
                    <p class="text-xs text-gray-400 mt-1">Updated {{ $tpl->updated_at->diffForHumans() }}</p>
                </div>

                {{-- Actions --}}
                <div class="flex items-center gap-2 opacity-0 group-hover:opacity-100 transition-opacity shrink-0">
                    {{-- Toggle active --}}
                    <form method="POST" action="{{ route('whatsapp-templates.toggle', $tpl) }}">
                        @csrf
                        <button type="submit"
                                class="px-3 py-1.5 text-xs font-medium rounded-lg border transition-colors
                                       {{ $tpl->is_active
                                          ? 'border-amber-200 text-amber-700 hover:bg-amber-50'
                                          : 'border-green-200 text-green-700 hover:bg-green-50' }}">
                            {{ $tpl->is_active ? 'Disable' : 'Enable' }}
                        </button>
                    </form>

                    {{-- Edit --}}
                    <button
                        onclick="openEditModal({{ $tpl->id }}, {{ json_encode($tpl->name) }}, {{ json_encode($tpl->message) }}, {{ $tpl->is_active ? 'true' : 'false' }}, {{ $tpl->sort_order }})"
                        class="px-3 py-1.5 text-xs font-medium rounded-lg border border-indigo-200 text-indigo-700 hover:bg-indigo-50 transition-colors">
                        Edit
                    </button>

                    {{-- Delete --}}
                    <form method="POST" action="{{ route('whatsapp-templates.destroy', $tpl) }}"
                          onsubmit="return confirm('Delete this template?')">
                        @csrf @method('DELETE')
                        <button type="submit"
                                class="px-3 py-1.5 text-xs font-medium rounded-lg border border-red-200 text-red-600 hover:bg-red-50 transition-colors">
                            Delete
                        </button>
                    </form>
                </div>
            </div>
        </div>
    @empty
        <div class="px-6 py-12 text-center">
            <div class="text-4xl mb-3">💬</div>
            <p class="text-sm text-gray-500 font-medium">No WhatsApp templates yet</p>
            <p class="text-xs text-gray-400 mt-1">Click "Add Template" to create your first one.</p>
        </div>
    @endforelse
</div>

{{-- Create Modal --}}
<div id="create-modal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl w-full max-w-lg shadow-2xl">
        <div class="px-6 py-5 border-b border-gray-100 flex items-center justify-between">
            <h3 class="font-semibold text-gray-900">New WhatsApp Template</h3>
            <button onclick="document.getElementById('create-modal').classList.add('hidden')"
                    class="text-gray-400 hover:text-gray-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <form method="POST" action="{{ route('whatsapp-templates.store') }}" class="p-6 space-y-4">
            @csrf
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Template Name *</label>
                <input name="name" required maxlength="100" placeholder="e.g. Initial Follow-up"
                       class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-500"/>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Message *</label>
                <textarea name="message" required maxlength="1000" rows="4"
                          placeholder="Hi @{{ name }}, ..."
                          class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-500 resize-none"></textarea>
                <p class="text-[11px] text-gray-400 mt-1">Use <code class="bg-gray-100 px-1 rounded">@{{ name }}</code> and <code class="bg-gray-100 px-1 rounded">@{{ company }}</code> as placeholders.</p>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Sort Order</label>
                    <input name="sort_order" type="number" min="0" placeholder="0"
                           class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-500"/>
                </div>
                <div class="flex items-end pb-2.5">
                    <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
                        <input type="checkbox" name="is_active" value="1" checked
                               class="rounded border-gray-300 text-green-600 focus:ring-green-500"/>
                        Active
                    </label>
                </div>
            </div>
            <div class="flex gap-3 pt-1">
                <button type="submit"
                        class="flex-1 px-4 py-2.5 bg-green-600 hover:bg-green-700 text-white text-sm font-semibold rounded-xl transition-colors">
                    Create Template
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

{{-- Edit Modal --}}
<div id="edit-modal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl w-full max-w-lg shadow-2xl">
        <div class="px-6 py-5 border-b border-gray-100 flex items-center justify-between">
            <h3 class="font-semibold text-gray-900">Edit Template</h3>
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
                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Template Name *</label>
                <input id="edit-name" name="name" required maxlength="100"
                       class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"/>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Message *</label>
                <textarea id="edit-message" name="message" required maxlength="1000" rows="4"
                          class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-none"></textarea>
                <p class="text-[11px] text-gray-400 mt-1">Use <code class="bg-gray-100 px-1 rounded">@{{ name }}</code> and <code class="bg-gray-100 px-1 rounded">@{{ company }}</code> as placeholders.</p>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Sort Order</label>
                    <input id="edit-sort" name="sort_order" type="number" min="0"
                           class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"/>
                </div>
                <div class="flex items-end pb-2.5">
                    <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
                        <input id="edit-active" type="checkbox" name="is_active" value="1"
                               class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"/>
                        Active
                    </label>
                </div>
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
function openEditModal(id, name, message, isActive, sortOrder) {
    const base = '{{ url("whatsapp-templates") }}';
    document.getElementById('edit-form').action = base + '/' + id;
    document.getElementById('edit-name').value    = name;
    document.getElementById('edit-message').value = message;
    document.getElementById('edit-sort').value    = sortOrder;
    document.getElementById('edit-active').checked = isActive;
    document.getElementById('edit-modal').classList.remove('hidden');
}
</script>
@endpush
