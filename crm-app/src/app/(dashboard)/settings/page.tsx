'use client';

import { useState, useRef, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useAuthStore } from '@/store/authStore';
import { authApi } from '@/lib/api/auth';
import { emailTemplatesApi, type EmailTemplatePayload } from '@/lib/api/emailTemplates';
import { organizationApi, type ReceiptSettings } from '@/lib/api/organization';
import type { EmailTemplate } from '@/types';

const inputCls = 'w-full border border-gray-300 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500';

// ── Settings Page ─────────────────────────────────────────────────────────────
export default function SettingsPage() {
  const { user, setUser, isSsoUser, isOwner, isAdmin } = useAuthStore();
  const canManage = isOwner() || isAdmin();
  const qc = useQueryClient();

  // Fetch fresh user+org data on mount so organization.timezone is never stale
  const { data: freshMe } = useQuery({
    queryKey: ['me'],
    queryFn: authApi.me,
    staleTime: 0,          // always re-fetch when settings page mounts
    refetchOnMount: true,
  });
  useEffect(() => {
    if (freshMe) setUser(freshMe);
  }, [freshMe]); // eslint-disable-line react-hooks/exhaustive-deps

  // Profile
  const [profileForm, setProfileForm]   = useState({ name: user?.name ?? '', phone: user?.phone ?? '' });
  const [pwForm, setPwForm]             = useState({ current_password: '', password: '', password_confirmation: '' });
  const [profileMsg, setProfileMsg]     = useState('');
  const [pwMsg, setPwMsg]               = useState('');
  const [profileError, setProfileError] = useState('');
  const [pwError, setPwError]           = useState('');
  const [profileLoading, setProfileLoading] = useState(false);
  const [pwLoading, setPwLoading]           = useState(false);

  // Receipt settings
  const logoInputRef = useRef<HTMLInputElement>(null);
  const { data: savedReceipt } = useQuery({
    queryKey: ['receipt-settings'],
    queryFn: organizationApi.getReceiptSettings,
    enabled: canManage,
  });
  const [receipt, setReceipt] = useState<ReceiptSettings>({});
  const [receiptInitialized, setReceiptInitialized] = useState(false);
  const [receiptMsg, setReceiptMsg]     = useState('');
  const [receiptError, setReceiptError] = useState('');

  // Sync fetched receipt settings into local state once
  if (savedReceipt && !receiptInitialized) {
    setReceipt(savedReceipt);
    setReceiptInitialized(true);
  }

  const receiptMutation = useMutation({
    mutationFn: organizationApi.updateReceiptSettings,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['receipt-settings'] });
      setReceiptMsg('Receipt settings saved.');
      setTimeout(() => setReceiptMsg(''), 3000);
    },
    onError: () => setReceiptError('Failed to save. Please try again.'),
  });

  const handleLogoUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    if (file.size > 1024 * 1024) { alert('Logo must be under 1 MB.'); return; }
    const reader = new FileReader();
    reader.onload = (ev) => setReceipt((r) => ({ ...r, logo: ev.target?.result as string }));
    reader.readAsDataURL(file);
  };

  const setR = (k: keyof ReceiptSettings, v: string) => setReceipt((r) => ({ ...r, [k]: v }));

  // Email templates
  const { data: templates } = useQuery({
    queryKey: ['email-templates'],
    queryFn: emailTemplatesApi.list,
    enabled: canManage,
  });

  const [tplForm, setTplForm] = useState<EmailTemplatePayload>({ name: '', subject: '', body: '', stage_trigger: '' });
  const [editTpl, setEditTpl] = useState<EmailTemplate | null>(null);
  const [showTplForm, setShowTplForm] = useState(false);

  const createTplMutation = useMutation({
    mutationFn: emailTemplatesApi.create,
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['email-templates'] }); setShowTplForm(false); setTplForm({ name: '', subject: '', body: '', stage_trigger: '' }); },
  });

  const updateTplMutation = useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: Partial<EmailTemplatePayload> }) => emailTemplatesApi.update(id, payload),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['email-templates'] }); setEditTpl(null); },
  });

  const deleteTplMutation = useMutation({
    mutationFn: emailTemplatesApi.delete,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['email-templates'] }),
  });

  // ── Handlers ─────────────────────────────────────────────────────────────────
  const handleProfileSave = async (e: React.FormEvent) => {
    e.preventDefault();
    setProfileMsg(''); setProfileError('');
    setProfileLoading(true);
    try {
      const updated = await authApi.updateMe(profileForm);
      setUser(updated);
      setProfileMsg('Profile updated successfully.');
    } catch {
      setProfileError('Failed to update profile.');
    } finally {
      setProfileLoading(false);
    }
  };

  const handlePasswordChange = async (e: React.FormEvent) => {
    e.preventDefault();
    setPwMsg(''); setPwError('');
    setPwLoading(true);
    try {
      await authApi.changePassword(pwForm);
      setPwMsg('Password changed successfully.');
      setPwForm({ current_password: '', password: '', password_confirmation: '' });
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
      setPwError(msg ?? 'Failed to change password.');
    } finally {
      setPwLoading(false);
    }
  };

  // ── Render ────────────────────────────────────────────────────────────────────
  return (
    <div className="max-w-3xl space-y-6">
      <h1 className="text-xl font-bold text-gray-900">Settings</h1>

      {/* ── Profile ─────────────────────────────────────────────────────────── */}
      <div className="bg-white rounded-2xl border border-gray-200 p-6">
        <h2 className="font-semibold text-gray-900 mb-5">Profile</h2>
        <form onSubmit={handleProfileSave} className="space-y-4">
          {profileMsg  && <div className="px-4 py-3 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm">{profileMsg}</div>}
          {profileError && <div className="px-4 py-3 bg-red-50 border border-red-200 text-red-700 rounded-xl text-sm">{profileError}</div>}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1.5">Full Name</label>
            <input value={profileForm.name} onChange={(e) => setProfileForm({ ...profileForm, name: e.target.value })} className={inputCls} />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1.5">Phone</label>
            <input value={profileForm.phone} onChange={(e) => setProfileForm({ ...profileForm, phone: e.target.value })} className={inputCls} />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1.5">Email</label>
            <input value={user?.email ?? ''} disabled className="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-gray-50 text-gray-400 cursor-not-allowed" />
          </div>
          <button type="submit" disabled={profileLoading} className="bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white font-semibold px-5 py-2.5 rounded-xl text-sm">
            {profileLoading ? 'Saving…' : 'Save Profile'}
          </button>
        </form>
      </div>

      {/* ── Change Password ──────────────────────────────────────────────────── */}
      {!isSsoUser() && (
        <div className="bg-white rounded-2xl border border-gray-200 p-6">
          <h2 className="font-semibold text-gray-900 mb-5">Change Password</h2>
          <form onSubmit={handlePasswordChange} className="space-y-4">
            {pwMsg  && <div className="px-4 py-3 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm">{pwMsg}</div>}
            {pwError && <div className="px-4 py-3 bg-red-50 border border-red-200 text-red-700 rounded-xl text-sm">{pwError}</div>}
            {(['current_password', 'password', 'password_confirmation'] as const).map((k) => (
              <div key={k}>
                <label className="block text-sm font-medium text-gray-700 mb-1.5">
                  {k === 'current_password' ? 'Current Password' : k === 'password' ? 'New Password' : 'Confirm New Password'}
                </label>
                <input type="password" value={pwForm[k]} onChange={(e) => setPwForm({ ...pwForm, [k]: e.target.value })} className={inputCls} />
              </div>
            ))}
            <button type="submit" disabled={pwLoading} className="bg-gray-900 hover:bg-gray-800 disabled:opacity-50 text-white font-semibold px-5 py-2.5 rounded-xl text-sm">
              {pwLoading ? 'Updating…' : 'Change Password'}
            </button>
          </form>
        </div>
      )}

      {/* ── Email Templates (managers only) ─────────────────────────────────── */}
      {canManage && (
        <div className="bg-white rounded-2xl border border-gray-200 p-6">
          <div className="flex items-center justify-between mb-5">
            <div>
              <h2 className="font-semibold text-gray-900">Email Templates</h2>
              <p className="text-xs text-gray-400 mt-0.5">Reusable templates for the Email log form on lead pages</p>
            </div>
            <button
              onClick={() => { setShowTplForm(true); setEditTpl(null); setTplForm({ name: '', subject: '', body: '', stage_trigger: '' }); }}
              className="flex items-center gap-1.5 px-3 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-xl transition-colors"
            >
              + Add Template
            </button>
          </div>

          {/* Template form */}
          {(showTplForm || editTpl) && (
            <div className="mb-5 border border-indigo-100 bg-indigo-50/50 rounded-2xl p-4 space-y-3">
              <h3 className="text-xs font-semibold text-gray-700">{editTpl ? 'Edit Template' : 'New Template'}</h3>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-medium text-gray-600 mb-1">Template Name *</label>
                  <input
                    value={editTpl ? editTpl.name : tplForm.name}
                    onChange={(e) => editTpl ? setEditTpl({ ...editTpl, name: e.target.value }) : setTplForm({ ...tplForm, name: e.target.value })}
                    placeholder="e.g. Initial Outreach"
                    className="w-full border border-gray-200 rounded-xl px-3 py-2 text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  />
                </div>
                <div>
                  <label className="block text-xs font-medium text-gray-600 mb-1">Stage Trigger (optional)</label>
                  <input
                    value={editTpl ? (editTpl.stage_trigger ?? '') : (tplForm.stage_trigger ?? '')}
                    onChange={(e) => editTpl ? setEditTpl({ ...editTpl, stage_trigger: e.target.value }) : setTplForm({ ...tplForm, stage_trigger: e.target.value })}
                    placeholder="e.g. qualified"
                    className="w-full border border-gray-200 rounded-xl px-3 py-2 text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  />
                </div>
              </div>
              <div>
                <label className="block text-xs font-medium text-gray-600 mb-1">Subject *</label>
                <input
                  value={editTpl ? editTpl.subject : tplForm.subject}
                  onChange={(e) => editTpl ? setEditTpl({ ...editTpl, subject: e.target.value }) : setTplForm({ ...tplForm, subject: e.target.value })}
                  placeholder="e.g. Following up on your enquiry"
                  className="w-full border border-gray-200 rounded-xl px-3 py-2 text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
              </div>
              <div>
                <label className="block text-xs font-medium text-gray-600 mb-1">Body *</label>
                <textarea
                  rows={4}
                  value={editTpl ? editTpl.body : tplForm.body}
                  onChange={(e) => editTpl ? setEditTpl({ ...editTpl, body: e.target.value }) : setTplForm({ ...tplForm, body: e.target.value })}
                  placeholder="Hi {{lead_name}}, ..."
                  className="w-full border border-gray-200 rounded-xl px-3 py-2 text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-none"
                />
                <p className="text-[10px] text-gray-400 mt-1">Variables: <code className="bg-gray-100 px-1 rounded">{'{{lead_name}}'}</code> <code className="bg-gray-100 px-1 rounded">{'{{rep_name}}'}</code> <code className="bg-gray-100 px-1 rounded">{'{{company}}'}</code></p>
              </div>
              <div className="flex gap-2">
                <button
                  onClick={() => {
                    if (editTpl) {
                      updateTplMutation.mutate({ id: editTpl.id, payload: { name: editTpl.name, subject: editTpl.subject, body: editTpl.body, stage_trigger: editTpl.stage_trigger } });
                    } else {
                      createTplMutation.mutate(tplForm);
                    }
                  }}
                  disabled={createTplMutation.isPending || updateTplMutation.isPending}
                  className="px-4 py-1.5 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 text-white text-xs font-semibold rounded-xl transition-colors"
                >
                  {createTplMutation.isPending || updateTplMutation.isPending ? 'Saving…' : editTpl ? 'Update' : 'Save Template'}
                </button>
                <button
                  onClick={() => { setShowTplForm(false); setEditTpl(null); }}
                  className="px-4 py-1.5 border border-gray-200 text-gray-600 text-xs font-medium rounded-xl hover:bg-gray-50 transition-colors"
                >
                  Cancel
                </button>
              </div>
            </div>
          )}

          {/* Template list */}
          {!templates || templates.length === 0 ? (
            <div className="text-center py-8">
              <div className="w-10 h-10 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-2">
                <span className="text-xl">✉️</span>
              </div>
              <p className="text-sm text-gray-500">No templates yet.</p>
              <p className="text-xs text-gray-400 mt-1">Add templates to speed up email logging on lead pages.</p>
            </div>
          ) : (
            <div className="divide-y divide-gray-50">
              {templates.map((tpl) => (
                <div key={tpl.id} className="py-3.5 flex items-start gap-3">
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 flex-wrap">
                      <p className="text-sm font-semibold text-gray-900">{tpl.name}</p>
                      {tpl.stage_trigger && (
                        <span className="text-[10px] bg-indigo-50 text-indigo-600 px-1.5 py-0.5 rounded-full font-medium">
                          {tpl.stage_trigger}
                        </span>
                      )}
                    </div>
                    <p className="text-xs text-gray-500 mt-0.5 truncate">{tpl.subject}</p>
                    <p className="text-[11px] text-gray-400 mt-0.5 line-clamp-1">{tpl.body}</p>
                  </div>
                  <div className="flex items-center gap-1 shrink-0">
                    <button
                      onClick={() => { setEditTpl(tpl); setShowTplForm(false); }}
                      className="px-2.5 py-1 text-xs text-gray-500 hover:text-indigo-600 border border-gray-200 hover:border-indigo-300 rounded-lg transition-colors"
                    >
                      Edit
                    </button>
                    <button
                      onClick={() => { if (confirm('Delete this template?')) deleteTplMutation.mutate(tpl.id); }}
                      disabled={deleteTplMutation.isPending}
                      className="px-2.5 py-1 text-xs text-red-500 hover:text-red-700 border border-red-100 hover:border-red-300 rounded-lg transition-colors"
                    >
                      Delete
                    </button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      )}

      {/* ── Receipt Settings (managers only) ────────────────────────────────── */}
      {canManage && (
        <div className="bg-white rounded-2xl border border-gray-200 p-6">
          <div className="mb-5">
            <h2 className="font-semibold text-gray-900">Receipt Settings</h2>
            <p className="text-xs text-gray-400 mt-0.5">Company details printed on every payment money receipt</p>
          </div>

          {receiptMsg   && <div className="mb-4 px-4 py-3 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm">{receiptMsg}</div>}
          {receiptError && <div className="mb-4 px-4 py-3 bg-red-50 border border-red-200 text-red-700 rounded-xl text-sm">{receiptError}</div>}

          <div className="space-y-5">
            {/* Logo upload */}
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">Company Logo</label>
              <div className="flex items-center gap-4">
                {receipt.logo ? (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img src={receipt.logo} alt="Logo" className="w-16 h-16 object-contain border border-gray-200 rounded-xl bg-gray-50 p-1" />
                ) : (
                  <div className="w-16 h-16 border-2 border-dashed border-gray-200 rounded-xl flex items-center justify-center text-gray-300">
                    <svg className="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                  </div>
                )}
                <div>
                  <button
                    type="button"
                    onClick={() => logoInputRef.current?.click()}
                    className="px-3 py-2 text-sm font-medium border border-gray-200 rounded-xl hover:bg-gray-50 transition-colors text-gray-700"
                  >
                    {receipt.logo ? 'Change Logo' : 'Upload Logo'}
                  </button>
                  {receipt.logo && (
                    <button
                      type="button"
                      onClick={() => setR('logo', '')}
                      className="ml-2 px-3 py-2 text-sm text-red-500 hover:text-red-700 transition-colors"
                    >
                      Remove
                    </button>
                  )}
                  <p className="text-xs text-gray-400 mt-1">PNG or JPG, max 1 MB</p>
                </div>
                <input ref={logoInputRef} type="file" accept="image/png,image/jpeg,image/gif,image/webp" className="hidden" onChange={handleLogoUpload} />
              </div>
            </div>

            {/* Company name + tagline */}
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1.5">Company Name</label>
                <input value={receipt.company_name ?? ''} onChange={(e) => setR('company_name', e.target.value)} placeholder="Acme Pvt Ltd" className={inputCls} />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1.5">Tagline <span className="font-normal text-gray-400">(optional)</span></label>
                <input value={receipt.tagline ?? ''} onChange={(e) => setR('tagline', e.target.value)} placeholder="e.g. Trusted since 2010" className={inputCls} />
              </div>
            </div>

            {/* Address */}
            <div className="space-y-3">
              <label className="block text-sm font-medium text-gray-700">Address</label>
              <input value={receipt.address_line1 ?? ''} onChange={(e) => setR('address_line1', e.target.value)} placeholder="Address Line 1" className={inputCls} />
              <input value={receipt.address_line2 ?? ''} onChange={(e) => setR('address_line2', e.target.value)} placeholder="Address Line 2 (optional)" className={inputCls} />
              <div className="grid grid-cols-3 gap-3">
                <input value={receipt.city ?? ''} onChange={(e) => setR('city', e.target.value)} placeholder="City" className={inputCls} />
                <input value={receipt.state ?? ''} onChange={(e) => setR('state', e.target.value)} placeholder="State" className={inputCls} />
                <input value={receipt.pincode ?? ''} onChange={(e) => setR('pincode', e.target.value)} placeholder="Pincode" className={inputCls} />
              </div>
            </div>

            {/* Contact */}
            <div className="grid grid-cols-3 gap-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1.5">Phone</label>
                <input value={receipt.phone ?? ''} onChange={(e) => setR('phone', e.target.value)} placeholder="+91 98765 43210" className={inputCls} />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1.5">Email</label>
                <input value={receipt.email ?? ''} onChange={(e) => setR('email', e.target.value)} placeholder="info@company.com" className={inputCls} />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1.5">Website <span className="font-normal text-gray-400">(optional)</span></label>
                <input value={receipt.website ?? ''} onChange={(e) => setR('website', e.target.value)} placeholder="www.company.com" className={inputCls} />
              </div>
            </div>

            {/* Tax */}
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1.5">GSTIN <span className="font-normal text-gray-400">(optional)</span></label>
                <input value={receipt.gstin ?? ''} onChange={(e) => setR('gstin', e.target.value)} placeholder="22AAAAA0000A1Z5" className={inputCls} />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1.5">PAN <span className="font-normal text-gray-400">(optional)</span></label>
                <input value={receipt.pan ?? ''} onChange={(e) => setR('pan', e.target.value)} placeholder="AAAAA0000A" className={inputCls} />
              </div>
            </div>

            {/* Footer note */}
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1.5">Receipt Footer Note <span className="font-normal text-gray-400">(optional)</span></label>
              <textarea
                rows={2}
                value={receipt.footer_note ?? ''}
                onChange={(e) => setR('footer_note', e.target.value)}
                placeholder="e.g. Thank you for your business! Payments are non-refundable."
                className={`${inputCls} resize-none`}
              />
            </div>

            <button
              type="button"
              onClick={() => { setReceiptMsg(''); setReceiptError(''); receiptMutation.mutate(receipt); }}
              disabled={receiptMutation.isPending}
              className="bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white font-semibold px-5 py-2.5 rounded-xl text-sm"
            >
              {receiptMutation.isPending ? 'Saving…' : 'Save Receipt Settings'}
            </button>
          </div>
        </div>
      )}

      {/* ── Organization info ────────────────────────────────────────────────── */}
      <div className="bg-white rounded-2xl border border-gray-200 p-6">
        <h2 className="font-semibold text-gray-900 mb-4">Organization</h2>
        <dl className="space-y-3 text-sm">
          {[
            ['Name', user?.organization?.name],
            ['Email', user?.organization?.email],
            ['Timezone', user?.organization?.timezone],
            ['Country', user?.organization?.country],
          ].map(([label, value]) => (
            <div key={label} className="flex justify-between">
              <dt className="text-gray-500">{label}</dt>
              <dd className="text-gray-900 font-medium">{value ?? '—'}</dd>
            </div>
          ))}
        </dl>
      </div>
    </div>
  );
}
