'use client';

import { useState } from 'react';
import type { Lead } from '@/types';
import type { LeadPayload } from '@/lib/api/leads';

interface Props {
  lead: Lead;
  onUpdate: (payload: Partial<LeadPayload>) => void;
  saving: boolean;
  canEdit?: boolean;
}

const inputCls = 'w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white transition-shadow';

export function LeadInfoCard({ lead, onUpdate, saving, canEdit = true }: Props) {
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState<Partial<LeadPayload>>({});

  const startEdit = () => {
    setDraft({
      first_name: lead.first_name,
      last_name:  lead.last_name  ?? '',
      email:      lead.email      ?? '',
      phone:      lead.phone      ?? '',
      company:    lead.company    ?? '',
      job_title:  lead.job_title  ?? '',
      website:    lead.website    ?? '',
      industry:   lead.industry   ?? '',
      city:       lead.city       ?? '',
      country:    lead.country    ?? '',
      notes:      lead.notes      ?? '',
    });
    setEditing(true);
  };

  const cancelEdit = () => { setDraft({}); setEditing(false); };

  const handleSave = () => {
    onUpdate(draft);
    setEditing(false);
  };

  const set = (k: keyof LeadPayload, v: string) => setDraft((d) => ({ ...d, [k]: v }));

  const ROW_FIELDS: { label: string; key: keyof Lead; editKey: keyof LeadPayload; type?: string }[] = [
    { label: 'Email',     key: 'email',     editKey: 'email',     type: 'email' },
    { label: 'Phone',     key: 'phone',     editKey: 'phone',     type: 'tel'   },
    { label: 'Company',   key: 'company',   editKey: 'company'                  },
    { label: 'Job Title', key: 'job_title', editKey: 'job_title'                },
    { label: 'Website',   key: 'website',   editKey: 'website',   type: 'url'   },
    { label: 'Industry',  key: 'industry',  editKey: 'industry'                 },
    { label: 'City',      key: 'city',      editKey: 'city'                     },
    { label: 'Country',   key: 'country',   editKey: 'country'                  },
  ];

  const renderValue = (key: keyof Lead) => {
    const val = lead[key] as string | undefined;
    if (!val) return <span className="text-gray-300">—</span>;
    if (key === 'email')   return <a href={`mailto:${val}`} className="text-indigo-600 hover:underline">{val}</a>;
    if (key === 'phone')   return <a href={`tel:${val}`}    className="text-indigo-600 hover:underline">{val}</a>;
    if (key === 'website') return <a href={val} target="_blank" rel="noopener noreferrer" className="text-indigo-600 hover:underline truncate block max-w-[160px]">{val.replace(/^https?:\/\//, '')}</a>;
    return <span className="text-gray-800">{val}</span>;
  };

  return (
    <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
      {/* Header */}
      <div className="px-5 py-4 border-b border-gray-50 flex items-center justify-between">
        <h3 className="text-sm font-semibold text-gray-900">Contact Info</h3>
        {!editing ? (
          canEdit && (
          <button
            onClick={startEdit}
            className="w-7 h-7 rounded-lg flex items-center justify-center text-gray-400 hover:text-indigo-600 hover:bg-indigo-50 transition-colors"
            title="Edit"
          >
            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
            </svg>
          </button>
          )
        ) : (
          <div className="flex items-center gap-2">
            <button
              onClick={handleSave}
              disabled={saving}
              className="px-3 py-1 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 text-white text-xs font-semibold rounded-lg transition-colors"
            >
              {saving ? 'Saving…' : 'Save'}
            </button>
            <button onClick={cancelEdit} className="px-3 py-1 text-xs text-gray-500 hover:text-gray-700 border border-gray-200 rounded-lg transition-colors">
              Cancel
            </button>
          </div>
        )}
      </div>

      {/* Edit: name row */}
      {editing && (
        <div className="px-5 pt-4 pb-2 grid grid-cols-2 gap-2">
          <div>
            <label className="block text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-1">First Name *</label>
            <input value={draft.first_name ?? ''} onChange={(e) => set('first_name', e.target.value)} className={inputCls} />
          </div>
          <div>
            <label className="block text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-1">Last Name</label>
            <input value={draft.last_name ?? ''} onChange={(e) => set('last_name', e.target.value)} className={inputCls} />
          </div>
        </div>
      )}

      {/* Fields */}
      <div className="px-5 py-3 space-y-3">
        {ROW_FIELDS.map(({ label, key, editKey, type }) => (
          <div key={key} className="flex items-start gap-3">
            <span className="text-[11px] font-semibold text-gray-400 uppercase tracking-wide w-20 shrink-0 pt-0.5">{label}</span>
            {editing ? (
              <input
                type={type ?? 'text'}
                value={(draft[editKey] as string) ?? ''}
                onChange={(e) => set(editKey, e.target.value)}
                className={inputCls}
              />
            ) : (
              <div className="flex-1 text-sm">{renderValue(key)}</div>
            )}
          </div>
        ))}

        {/* Notes */}
        <div className="flex items-start gap-3 pt-1 border-t border-gray-50">
          <span className="text-[11px] font-semibold text-gray-400 uppercase tracking-wide w-20 shrink-0 pt-0.5">Notes</span>
          {editing ? (
            <textarea
              rows={3}
              value={draft.notes ?? ''}
              onChange={(e) => set('notes', e.target.value)}
              className={`${inputCls} resize-none`}
            />
          ) : (
            <p className="flex-1 text-sm text-gray-700 leading-relaxed whitespace-pre-line">
              {lead.notes || <span className="text-gray-300">—</span>}
            </p>
          )}
        </div>
      </div>
    </div>
  );
}
