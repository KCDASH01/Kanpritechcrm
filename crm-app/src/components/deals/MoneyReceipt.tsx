'use client';

import { useRef, useState } from 'react';
import type { DealPayment, Deal } from '@/types';
import type { ReceiptSettings } from '@/lib/api/organization';

// ── Amount in words (Indian system) ──────────────────────────────────────────
const ONES = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
  'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen',
  'Eighteen', 'Nineteen'];
const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

function belowHundred(n: number): string {
  if (n < 20) return ONES[n];
  return TENS[Math.floor(n / 10)] + (n % 10 ? ' ' + ONES[n % 10] : '');
}

function belowThousand(n: number): string {
  if (n < 100) return belowHundred(n);
  return ONES[Math.floor(n / 100)] + ' Hundred' + (n % 100 ? ' ' + belowHundred(n % 100) : '');
}

export function amountInWords(amount: number, currency: string = 'INR'): string {
  const isUsd = currency === 'USD';
  if (!amount || amount <= 0) return isUsd ? 'Zero Dollars Only' : 'Zero Rupees Only';
  const intPart  = Math.floor(amount);
  const decPart  = Math.round((amount - intPart) * 100);

  let words = '';
  const crore = Math.floor(intPart / 10_000_000);
  const lakh  = Math.floor((intPart % 10_000_000) / 100_000);
  const thou  = Math.floor((intPart % 100_000) / 1_000);
  const rem   = intPart % 1_000;

  if (crore) words += belowThousand(crore) + ' Crore ';
  if (lakh)  words += belowThousand(lakh)  + ' Lakh ';
  if (thou)  words += belowThousand(thou)  + ' Thousand ';
  if (rem)   words += belowThousand(rem)   + ' ';

  words += isUsd ? 'Dollars' : 'Rupees';
  if (decPart) words += ' and ' + belowHundred(decPart) + (isUsd ? ' Cents' : ' Paise');
  return words.trim() + ' Only';
}

// ── Receipt Number ────────────────────────────────────────────────────────────
function receiptNo(id: number) {
  return `RCP-${String(id).padStart(6, '0')}`;
}

// ── Format date ───────────────────────────────────────────────────────────────
function fmtLong(d: string) {
  return new Date(d).toLocaleDateString('en-IN', { day: 'numeric', month: 'long', year: 'numeric' });
}

function fmtMode(m: string) {
  const map: Record<string, string> = {
    cash: 'Cash', cheque: 'Cheque', bank_transfer: 'Bank Transfer',
    upi: 'UPI', card: 'Card', aggregator: 'Aggregator', other: 'Other',
  };
  return map[m] ?? m;
}

// ── Props ─────────────────────────────────────────────────────────────────────
interface Props {
  payment: DealPayment;
  deal: Deal;
  settings: ReceiptSettings;
  onClose: () => void;
}

// ── The receipt template (hidden A4 div + styled copy for display) ────────────
export default function MoneyReceipt({ payment, deal, settings, onClose }: Props) {
  const receiptRef = useRef<HTMLDivElement>(null);
  const [downloading, setDownloading] = useState(false);

  // ── Print ─────────────────────────────────────────────────────────────────
  const handlePrint = () => {
    const el = receiptRef.current;
    if (!el) return;

    const printWindow = window.open('', '_blank', 'width=800,height=900');
    if (!printWindow) return;

    printWindow.document.write(`
      <!DOCTYPE html>
      <html>
        <head>
          <meta charset="utf-8" />
          <title>Receipt ${receiptNo(payment.id)}</title>
          <style>
            * { box-sizing: border-box; margin: 0; padding: 0; }
            body { font-family: 'Segoe UI', Arial, sans-serif; color: #111; background: #fff; }
            .receipt { width: 794px; min-height: 600px; margin: 20px auto; padding: 40px; border: 2px solid #e5e7eb; }
            @media print {
              body { margin: 0; }
              .receipt { margin: 0; border: 2px solid #e5e7eb; width: 100%; }
              @page { size: A4; margin: 10mm; }
            }
          </style>
        </head>
        <body>
          ${el.innerHTML}
        </body>
      </html>
    `);
    printWindow.document.close();
    printWindow.focus();
    setTimeout(() => { printWindow.print(); printWindow.close(); }, 400);
  };

  // ── Download PDF ─────────────────────────────────────────────────────────
  const handleDownload = async () => {
    const el = receiptRef.current;
    if (!el) return;
    setDownloading(true);
    try {
      const html2canvas = (await import('html2canvas')).default;
      const jsPDF = (await import('jspdf')).default;

      const canvas = await html2canvas(el, {
        scale: 2,
        useCORS: true,
        backgroundColor: '#ffffff',
        logging: false,
      });

      const imgData = canvas.toDataURL('image/png');
      const pdf = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });

      const pdfW = pdf.internal.pageSize.getWidth();
      const pdfH = (canvas.height * pdfW) / canvas.width;

      pdf.addImage(imgData, 'PNG', 0, 0, pdfW, pdfH);
      pdf.save(`${receiptNo(payment.id)}.pdf`);
    } catch (e) {
      console.error('PDF generation failed:', e);
    } finally {
      setDownloading(false);
    }
  };

  // ── Client info from deal's lead ─────────────────────────────────────────
  const clientName    = deal.lead?.full_name ?? '—';
  const clientCompany = deal.lead?.company   ?? '';
  const clientPhone   = deal.lead?.phone     ?? '';
  const clientEmail   = deal.lead?.email     ?? '';

  // ── Address string ────────────────────────────────────────────────────────
  const addressParts = [
    settings.address_line1,
    settings.address_line2,
    [settings.city, settings.state].filter(Boolean).join(', '),
    settings.pincode,
  ].filter(Boolean);

  return (
    <div className="space-y-4">
      {/* Action buttons */}
      <div className="flex items-center justify-end gap-2 pb-2 border-b border-gray-100">
        <button
          onClick={handlePrint}
          className="flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium text-gray-600 border border-gray-200 rounded-xl hover:bg-gray-50 transition-colors"
        >
          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
              d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
          </svg>
          Print
        </button>
        <button
          onClick={handleDownload}
          disabled={downloading}
          className="flex items-center gap-1.5 px-3 py-1.5 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 rounded-xl transition-colors"
        >
          {downloading ? (
            <svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
              <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
            </svg>
          ) : (
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
            </svg>
          )}
          {downloading ? 'Generating…' : 'Download PDF'}
        </button>
      </div>

      {/* The actual receipt — this div is captured by html2canvas */}
      <div className="overflow-auto max-h-[70vh]">
        <div
          ref={receiptRef}
          style={{ fontFamily: 'Arial, Helvetica, sans-serif', color: '#111827', background: '#fff' }}
          className="w-[720px] mx-auto border-2 border-gray-200 rounded-sm"
        >
          {/* ── Header ──────────────────────────────────────────────────────── */}
          <div style={{ background: 'linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%)', padding: '28px 32px' }}>
            <div style={{ display: 'flex', alignItems: 'flex-start', gap: '20px' }}>
              {/* Logo */}
              {settings.logo ? (
                // eslint-disable-next-line @next/next/no-img-element
                <img
                  src={settings.logo}
                  alt="Logo"
                  style={{ width: 72, height: 72, objectFit: 'contain', borderRadius: 8, background: '#fff', padding: 4, flexShrink: 0 }}
                />
              ) : (
                <div style={{
                  width: 72, height: 72, borderRadius: 8, background: 'rgba(255,255,255,0.2)',
                  display: 'flex', alignItems: 'center', justifyContent: 'center',
                  fontSize: 28, fontWeight: 800, color: '#fff', flexShrink: 0,
                }}>
                  {(settings.company_name ?? 'C').charAt(0).toUpperCase()}
                </div>
              )}

              {/* Company info */}
              <div style={{ flex: 1 }}>
                <div style={{ fontSize: 22, fontWeight: 800, color: '#fff', lineHeight: 1.2 }}>
                  {settings.company_name || 'Your Company'}
                </div>
                {settings.tagline && (
                  <div style={{ fontSize: 12, color: 'rgba(255,255,255,0.8)', marginTop: 3 }}>
                    {settings.tagline}
                  </div>
                )}
                {addressParts.length > 0 && (
                  <div style={{ fontSize: 11, color: 'rgba(255,255,255,0.75)', marginTop: 6, lineHeight: 1.5 }}>
                    {addressParts.join(' · ')}
                  </div>
                )}
                <div style={{ fontSize: 11, color: 'rgba(255,255,255,0.75)', marginTop: 4, display: 'flex', flexWrap: 'wrap', gap: '0 16px' }}>
                  {settings.phone   && <span>📞 {settings.phone}</span>}
                  {settings.email   && <span>✉ {settings.email}</span>}
                  {settings.website && <span>🌐 {settings.website}</span>}
                </div>
                {(settings.gstin || settings.pan) && (
                  <div style={{ fontSize: 10, color: 'rgba(255,255,255,0.65)', marginTop: 4, display: 'flex', gap: 16 }}>
                    {settings.gstin && <span>GSTIN: {settings.gstin}</span>}
                    {settings.pan   && <span>PAN: {settings.pan}</span>}
                  </div>
                )}
              </div>
            </div>
          </div>

          {/* ── Receipt Title Bar ─────────────────────────────────────────── */}
          <div style={{ background: '#f8fafc', borderBottom: '1px solid #e5e7eb', padding: '14px 32px', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
            <div style={{ fontSize: 18, fontWeight: 800, letterSpacing: 1, color: '#1e293b', textTransform: 'uppercase' }}>
              Money Receipt
            </div>
            <div style={{ textAlign: 'right' }}>
              <div style={{ fontSize: 13, fontWeight: 700, color: '#4f46e5' }}>{receiptNo(payment.id)}</div>
              <div style={{ fontSize: 11, color: '#6b7280', marginTop: 2 }}>Date: {fmtLong(payment.payment_date)}</div>
            </div>
          </div>

          {/* ── Body ─────────────────────────────────────────────────────── */}
          <div style={{ padding: '24px 32px', display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 20 }}>

            {/* Left: Received From */}
            <div style={{ background: '#f8fafc', borderRadius: 8, padding: '16px 18px', border: '1px solid #e5e7eb' }}>
              <div style={{ fontSize: 10, fontWeight: 700, color: '#6b7280', textTransform: 'uppercase', letterSpacing: 1, marginBottom: 10 }}>
                Received From
              </div>
              <div style={{ fontSize: 15, fontWeight: 700, color: '#111827' }}>{clientName}</div>
              {clientCompany && (
                <div style={{ fontSize: 12, color: '#4b5563', marginTop: 3 }}>{clientCompany}</div>
              )}
              {clientPhone && (
                <div style={{ fontSize: 11, color: '#6b7280', marginTop: 6 }}>📞 {clientPhone}</div>
              )}
              {clientEmail && (
                <div style={{ fontSize: 11, color: '#6b7280', marginTop: 3 }}>✉ {clientEmail}</div>
              )}
              <div style={{ borderTop: '1px solid #e5e7eb', marginTop: 10, paddingTop: 10, fontSize: 11, color: '#6b7280' }}>
                Deal: <span style={{ color: '#1e293b', fontWeight: 600 }}>{deal.title}</span>
              </div>
            </div>

            {/* Right: Payment Details */}
            <div style={{ background: '#f0fdf4', borderRadius: 8, padding: '16px 18px', border: '1px solid #bbf7d0' }}>
              <div style={{ fontSize: 10, fontWeight: 700, color: '#16a34a', textTransform: 'uppercase', letterSpacing: 1, marginBottom: 10 }}>
                Payment Details
              </div>
              <div style={{ fontSize: 26, fontWeight: 800, color: '#15803d', marginBottom: 4 }}>
                {deal.currency === 'USD' ? '$' : '₹'}{Number(payment.amount).toLocaleString(deal.currency === 'USD' ? 'en-US' : 'en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
              </div>
              <div style={{ fontSize: 10, color: '#166534', fontStyle: 'italic', marginBottom: 10, lineHeight: 1.4 }}>
                {amountInWords(Number(payment.amount), deal.currency)}
              </div>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 5 }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 11 }}>
                  <span style={{ color: '#6b7280' }}>Mode</span>
                  <span style={{ fontWeight: 600, color: '#111827' }}>{fmtMode(payment.payment_mode)}</span>
                </div>
                {payment.txn_or_utr_number && (
                  <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 11 }}>
                    <span style={{ color: '#6b7280' }}>UTR / Txn #</span>
                    <span style={{ fontWeight: 600, color: '#111827', fontFamily: 'monospace', fontSize: 10 }}>
                      {payment.txn_or_utr_number}
                    </span>
                  </div>
                )}
                {payment.notes && (
                  <div style={{ fontSize: 11, color: '#6b7280', marginTop: 4, borderTop: '1px solid #bbf7d0', paddingTop: 6 }}>
                    Note: {payment.notes}
                  </div>
                )}
              </div>
            </div>
          </div>

          {/* ── Declaration ──────────────────────────────────────────────── */}
          <div style={{ margin: '0 32px', padding: '12px 18px', background: '#fffbeb', border: '1px solid #fde68a', borderRadius: 8, fontSize: 11, color: '#92400e' }}>
            This is an electronically generated money receipt and serves as valid proof of payment.
          </div>

          {/* ── Signature row ────────────────────────────────────────────── */}
          <div style={{ padding: '24px 32px', display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end' }}>
            <div style={{ fontSize: 10, color: '#9ca3af' }}>
              <div>Receipt generated on {new Date().toLocaleDateString('en-IN', { day: 'numeric', month: 'long', year: 'numeric' })}</div>
              {payment.created_by && (
                <div style={{ marginTop: 2 }}>Recorded by: {payment.created_by.name}</div>
              )}
            </div>
            <div style={{ textAlign: 'center' }}>
              <div style={{ borderTop: '2px solid #1e293b', paddingTop: 6, width: 180 }}>
                <div style={{ fontSize: 11, fontWeight: 700, color: '#1e293b' }}>Authorized Signatory</div>
                <div style={{ fontSize: 10, color: '#6b7280', marginTop: 2 }}>{settings.company_name || 'Company Name'}</div>
              </div>
            </div>
          </div>

          {/* ── Footer ───────────────────────────────────────────────────── */}
          {settings.footer_note && (
            <div style={{ background: '#f8fafc', borderTop: '1px solid #e5e7eb', padding: '12px 32px', fontSize: 10, color: '#6b7280', textAlign: 'center' }}>
              {settings.footer_note}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
