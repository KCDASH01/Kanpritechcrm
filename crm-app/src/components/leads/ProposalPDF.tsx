'use client';

import { useRef, useState, type CSSProperties } from 'react';
import type { Proposal, Lead, ProposedSolution } from '@/types';

// ── Theme config ──────────────────────────────────────────────────────────────

const THEME_STYLES = {
  modern: {
    primary:    '#4f46e5',
    accent:     '#7c3aed',
    headerBg:   'linear-gradient(135deg, #4f46e5, #7c3aed)',
    bg:         '#f8fafc',
    sectionBg:  '#eef2ff',
    sectionText:'#3730a3',
    border:     '#c7d2fe',
    tableHead:  '#4f46e5',
    pillBg:     '#e0e7ff',
    pillText:   '#4338ca',
  },
  corporate: {
    primary:    '#1e3a5f',
    accent:     '#b8860b',
    headerBg:   'linear-gradient(135deg, #1e3a5f, #2c5282)',
    bg:         '#f9f7f3',
    sectionBg:  '#fef9e7',
    sectionText:'#7d6608',
    border:     '#d4a017',
    tableHead:  '#1e3a5f',
    pillBg:     '#fef3c7',
    pillText:   '#92400e',
  },
  minimal: {
    primary:    '#111827',
    accent:     '#6b7280',
    headerBg:   'linear-gradient(135deg, #111827, #374151)',
    bg:         '#ffffff',
    sectionBg:  '#f9fafb',
    sectionText:'#374151',
    border:     '#e5e7eb',
    tableHead:  '#111827',
    pillBg:     '#f3f4f6',
    pillText:   '#374151',
  },
  vibrant: {
    primary:    '#7c3aed',
    accent:     '#059669',
    headerBg:   'linear-gradient(135deg, #7c3aed, #059669)',
    bg:         '#faf5ff',
    sectionBg:  '#f0fdf4',
    sectionText:'#065f46',
    border:     '#a7f3d0',
    tableHead:  '#7c3aed',
    pillBg:     '#ede9fe',
    pillText:   '#5b21b6',
  },
};

type ThemeStyles = typeof THEME_STYLES.modern;

const KANPRITECH_LOGO = '/KanpriTechText.png';

// ── Helpers ───────────────────────────────────────────────────────────────────

function hexToRgb(hex: string): [number, number, number] {
  const h = hex.replace('#', '');
  return [parseInt(h.slice(0, 2), 16), parseInt(h.slice(2, 4), 16), parseInt(h.slice(4, 6), 16)];
}

function loadImage(src: string): Promise<HTMLImageElement> {
  return new Promise((resolve, reject) => {
    const img = new Image();
    img.crossOrigin = 'anonymous';
    img.onload = () => resolve(img);
    img.onerror = () => reject(new Error(`Failed to load ${src}`));
    img.src = src;
  });
}

function rowInkRatio(ctx: CanvasRenderingContext2D, y: number, width: number): number {
  const clampedY = Math.max(0, Math.min(Math.floor(y), ctx.canvas.height - 1));
  const data = ctx.getImageData(0, clampedY, width, 1).data;
  let ink = 0;
  const step = 16;
  for (let i = 0; i < data.length; i += step) {
    if (data[i] < 248 || data[i + 1] < 248 || data[i + 2] < 248) ink++;
  }
  return ink / (data.length / step);
}

/** Prefer splitting on a blank row so headings like THANK YOU are not cut in half. */
function findSplitY(ctx: CanvasRenderingContext2D, startY: number, idealEnd: number): number {
  const canvasH = ctx.canvas.height;
  if (idealEnd >= canvasH) return canvasH;
  const width = ctx.canvas.width;
  const lookback = Math.min(Math.floor((idealEnd - startY) * 0.35), 280);
  const minY = startY + 80;
  for (let y = idealEnd; y > Math.max(minY, idealEnd - lookback); y -= 2) {
    if (rowInkRatio(ctx, y, width) < 0.015 && rowInkRatio(ctx, y - 8, width) < 0.015) {
      return y;
    }
  }
  return idealEnd;
}

function fmtDate(d: string) {
  return new Date(d).toLocaleDateString('en-IN', {
    day: 'numeric', month: 'long', year: 'numeric',
  });
}

function fmtMoney(n: number) {
  return '₹' + n.toLocaleString('en-IN');
}

function normalizeProposedSolution(ps: ProposedSolution | string): ProposedSolution {
  if (typeof ps === 'string') {
    return {
      overview: ps, approach: '', technology_stack: [],
      key_features: [], differentiators: '',
      success_metrics: [], implementation_approach: '',
    };
  }
  return ps;
}

// ── Shared UI primitives ──────────────────────────────────────────────────────

/** Letter-spaced section heading with full-width colored underline — like reference PDF */
function SectionHeading({ label, styles }: { label: string; styles: ThemeStyles }) {
  return (
    <div style={{ marginBottom: '16px' }}>
      <h2 style={{
        fontSize: '12px',
        fontWeight: 700,
        letterSpacing: '3px',
        textTransform: 'uppercase',
        color: styles.primary,
        margin: '0 0 8px 0',
      }}>
        {label}
      </h2>
      <div style={{ height: '2px', background: styles.headerBg, borderRadius: '1px' }} />
    </div>
  );
}

/** Orange label cell + white content cell — key-value row */
function LabelValueRow({ label, value, styles }: { label: string; value: string; styles: ThemeStyles }) {
  return (
    <div style={{ display: 'flex', gap: '2px', marginBottom: '4px' }}>
      <div style={{
        background: styles.primary, color: '#fff',
        padding: '8px 16px', fontSize: '11px', fontWeight: 700,
        borderRadius: '6px 0 0 6px', minWidth: '160px',
        display: 'flex', alignItems: 'center',
      }}>
        {label}
      </div>
      <div style={{
        background: '#fff', border: `1px solid ${styles.border}`,
        padding: '8px 16px', fontSize: '12px', color: '#374151',
        borderRadius: '0 6px 6px 0', flex: 1,
        display: 'flex', alignItems: 'center',
      }}>
        {value}
      </div>
    </div>
  );
}

// ── Props ─────────────────────────────────────────────────────────────────────

interface Props {
  proposal: Proposal;
  lead: Lead;
  onClose: () => void;
}

// ── Component ─────────────────────────────────────────────────────────────────

export function ProposalPDF({ proposal, lead, onClose }: Props) {
  const ref    = useRef<HTMLDivElement>(null);
  const [busy, setBusy] = useState(false);
  const styles  = THEME_STYLES[proposal.theme] ?? THEME_STYLES.modern;
  const content = proposal.content;
  const today   = new Date().toLocaleDateString('en-IN', { day: 'numeric', month: 'long', year: 'numeric' });
  const isDraft = proposal.status === 'draft';
  const ps      = normalizeProposedSolution(content.proposed_solution);

  const handleDownload = async () => {
    if (!ref.current || busy) return;
    setBusy(true);
    try {
      const [{ default: html2canvas }, { default: jsPDF }] = await Promise.all([
        import('html2canvas'),
        import('jspdf'),
      ]);

      const canvas = await html2canvas(ref.current, {
        scale: 2,
        useCORS: true,
        backgroundColor: '#ffffff',
        onclone: (doc) => {
          doc.querySelectorAll('[data-pdf-chrome], [data-pdf-watermark]').forEach((el) => {
            (el as HTMLElement).style.display = 'none';
          });
        },
      });

      const pdf = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
      const pageW = pdf.internal.pageSize.getWidth();
      const pageH = pdf.internal.pageSize.getHeight();
      const headerH = 18;
      const footerH = 18;
      const usableH = pageH - headerH - footerH;
      const pxPerMm = canvas.width / pageW;
      const slicePx = usableH * pxPerMm;
      const [pr, pg, pb] = hexToRgb(styles.primary);
      const proposalNo = `#${String(proposal.id).padStart(5, '0')}`;

      const srcCtx = canvas.getContext('2d', { willReadFrequently: true });
      const slices: { start: number; height: number }[] = [];
      if (srcCtx) {
        let y = 0;
        while (y < canvas.height - 1) {
          const idealEnd = Math.min(y + slicePx, canvas.height);
          const end = findSplitY(srcCtx, y, idealEnd);
          const next = end <= y ? idealEnd : end;
          slices.push({ start: y, height: Math.max(1, next - y) });
          y = next;
        }
      } else {
        slices.push({ start: 0, height: canvas.height });
      }

      const pageCount = Math.max(1, slices.length);

      let logoDataUrl: string | null = null;
      let logoW = 0;
      let logoH = 7;
      try {
        const logoImg = await loadImage(KANPRITECH_LOGO);
        const logoCanvas = document.createElement('canvas');
        logoCanvas.width = logoImg.naturalWidth;
        logoCanvas.height = logoImg.naturalHeight;
        logoCanvas.getContext('2d')!.drawImage(logoImg, 0, 0);
        logoDataUrl = logoCanvas.toDataURL('image/png');
        logoW = logoH * (logoImg.naturalWidth / logoImg.naturalHeight);
      } catch {
        logoDataUrl = null;
      }

      slices.forEach((slice, i) => {
        if (i > 0) pdf.addPage();

        const thisSliceMm = Math.min(slice.height / pxPerMm, usableH);

        const pageCanvas = document.createElement('canvas');
        pageCanvas.width = canvas.width;
        pageCanvas.height = slice.height;
        const ctx = pageCanvas.getContext('2d')!;
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, pageCanvas.width, pageCanvas.height);
        ctx.drawImage(
          canvas,
          0, slice.start, canvas.width, slice.height,
          0, 0, canvas.width, slice.height,
        );

        pdf.addImage(
          pageCanvas.toDataURL('image/jpeg', 0.92),
          'JPEG',
          0,
          headerH,
          pageW,
          thisSliceMm,
        );

        if (isDraft) {
          try {
            pdf.saveGraphicsState();
            const GState = (pdf as unknown as { GState: new (o: { opacity: number }) => object }).GState;
            pdf.setGState(new GState({ opacity: 0.07 }));
            pdf.setFont('helvetica', 'bold');
            pdf.setFontSize(64);
            pdf.setTextColor(79, 70, 229);
            pdf.text('DRAFT', pageW / 2, pageH / 2, { align: 'center', angle: 35 });
            pdf.restoreGraphicsState();
          } catch {
            /* watermark is optional */
          }
        }

        pdf.setFillColor(255, 255, 255);
        pdf.rect(0, 0, pageW, headerH, 'F');
        if (logoDataUrl && logoW > 0) {
          pdf.addImage(logoDataUrl, 'PNG', 12, (headerH - logoH) / 2, logoW, logoH);
        }
        pdf.setFont('helvetica', 'bold');
        pdf.setFontSize(8);
        pdf.setTextColor(pr, pg, pb);
        pdf.text('Confidential', pageW - 12, headerH / 2 + 1.2, { align: 'right' });
        pdf.setDrawColor(229, 231, 235);
        pdf.setLineWidth(0.25);
        pdf.line(12, headerH - 0.4, pageW - 12, headerH - 0.4);

        pdf.setFillColor(255, 255, 255);
        pdf.rect(0, pageH - footerH, pageW, footerH, 'F');
        pdf.setDrawColor(229, 231, 235);
        pdf.line(12, pageH - footerH + 0.4, pageW - 12, pageH - footerH + 0.4);
        pdf.setFont('helvetica', 'normal');
        pdf.setFontSize(8);
        pdf.setTextColor(156, 163, 175);
        pdf.text('Confidential', 12, pageH - 7);
        pdf.text(`Page ${i + 1} of ${pageCount}`, pageW / 2, pageH - 7, { align: 'center' });
        pdf.setFont('helvetica', 'bold');
        pdf.setTextColor(pr, pg, pb);
        pdf.text(`Proposal ${proposalNo}`, pageW - 12, pageH - 7, { align: 'right' });
      });

      const slug = lead.company
        ? lead.company.toLowerCase().replace(/\s+/g, '-')
        : lead.full_name.toLowerCase().replace(/\s+/g, '-');
      pdf.save(`proposal-${slug}-${proposal.id}.pdf`);
    } finally {
      setBusy(false);
    }
  };

  const handlePrint = () => {
    if (!ref.current) return;
    const html = ref.current.innerHTML;
    const win  = window.open('', '_blank');
    if (!win) return;
    win.document.write(`<!DOCTYPE html><html><head>
      <meta charset="UTF-8">
      <title>Proposal — ${lead.full_name}</title>
      <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #fff; }
        @media print {
          @page { size: A4; margin: 16mm 12mm 18mm 12mm; }
          body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
      </style>
    </head><body>${html}</body></html>`);
    win.document.close();
    setTimeout(() => { win.print(); }, 500);
  };

  return (
    <div className="space-y-4">
      {/* Action buttons */}
      <div className="flex items-center gap-2 justify-end">
        <button
          onClick={handlePrint}
          className="flex items-center gap-1.5 px-4 py-2 border border-gray-200 text-gray-700 text-sm font-medium rounded-xl hover:bg-gray-50 transition-colors"
        >
          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
              d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
          </svg>
          Print
        </button>
        <button
          onClick={handleDownload}
          disabled={busy}
          className="flex items-center gap-1.5 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-xl transition-colors disabled:opacity-50"
        >
          {busy ? (
            <svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
              <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
          ) : (
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
          )}
          {busy ? 'Generating…' : 'Download PDF'}
        </button>
      </div>

      {/* ── PDF Preview ─────────────────────────────────────────────────────── */}
      <div className="overflow-auto max-h-[65vh] rounded-xl border border-gray-200">
        <div
          ref={ref}
          style={{
            background: styles.bg,
            fontFamily: "'Segoe UI', Arial, sans-serif",
            width: '794px', minWidth: '794px',
            position: 'relative',
          }}
        >
          {/* DRAFT watermark (preview only; PDF draws a centered one per page) */}
          {isDraft && (
            <div
              data-pdf-watermark
              style={{ position: 'absolute', top: 0, left: 0, right: 0, bottom: 0, pointerEvents: 'none', zIndex: 10, overflow: 'hidden' }}
            >
              {[0, 1, 2, 3, 4].map((i) => (
                <div key={i} style={{
                  position: 'absolute', top: `${i * 22}%`, left: '-10%', right: '-10%',
                  textAlign: 'center', fontSize: '72px', fontWeight: 900,
                  color: 'rgba(0,0,0,0.04)', transform: 'rotate(-35deg)',
                  letterSpacing: '20px', userSelect: 'none',
                }}>
                  DRAFT
                </div>
              ))}
            </div>
          )}

          {/* ── Running document header ──────────────────────────────────── */}
          <div
            data-pdf-chrome
            style={{
              background: '#fff',
              borderBottom: `1px solid ${styles.border}`,
              padding: '10px 48px',
              display: 'flex',
              justifyContent: 'space-between',
              alignItems: 'center',
            }}
          >
            <div style={{ display: 'flex', alignItems: 'center', gap: '12px', minWidth: 0 }}>
              <img
                src={KANPRITECH_LOGO}
                alt="KanpriTech"
                style={{ height: '22px', width: 'auto', display: 'block', objectFit: 'contain' }}
              />
              <span style={{ fontSize: '10px', color: '#9ca3af', whiteSpace: 'nowrap' }}>
                {lead.company ?? lead.full_name} &nbsp;|&nbsp; Business Proposal
              </span>
            </div>
            <span style={{ fontSize: '10px', fontWeight: 700, color: styles.primary }}>
              Confidential
            </span>
          </div>

          {/* ── Cover / gradient header ──────────────────────────────────── */}
          <div style={{ background: styles.headerBg, padding: '48px 48px 40px', color: '#fff' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
              <div>
                <div style={{ fontSize: '10px', fontWeight: 700, letterSpacing: '3px', textTransform: 'uppercase', opacity: 0.75, marginBottom: '8px' }}>
                  Business Proposal
                </div>
                <div style={{ fontSize: '14px', opacity: 0.85 }}>Prepared exclusively for</div>
                <div style={{ fontSize: '22px', fontWeight: 800, marginTop: '2px' }}>
                  {lead.company ?? lead.full_name}
                </div>
                <div style={{ fontSize: '12px', opacity: 0.65, marginTop: '4px' }}>{today}</div>
              </div>
              <div style={{ textAlign: 'right' }}>
                <div style={{ fontSize: '13px', fontWeight: 600, opacity: 0.9 }}>
                  #{String(proposal.id).padStart(5, '0')}
                </div>
                {proposal.valid_until && (
                  <div style={{ fontSize: '11px', opacity: 0.7, marginTop: '4px' }}>
                    Valid until: {fmtDate(proposal.valid_until)}
                  </div>
                )}
              </div>
            </div>

            {/* Title */}
            <div style={{ marginTop: '32px', fontSize: '24px', fontWeight: 700, lineHeight: 1.3, textAlign: 'center' }}>
              {content.title}
            </div>

            {/* Divider */}
            <div style={{ margin: '20px 0', height: '1px', background: 'rgba(255,255,255,0.25)' }} />

            {/* Name / draft — plain bold text, no pill boxes */}
            <div style={{
              textAlign: 'center',
              fontSize: '18px',
              fontWeight: 700,
              color: '#fff',
              letterSpacing: '0.02em',
              lineHeight: 1.4,
            }}>
              {[
                isDraft ? 'DRAFT' : null,
                lead.full_name,
                lead.industry,
                lead.stage?.name,
              ].filter(Boolean).join('  ·  ')}
            </div>
          </div>

          {/* ── Body ─────────────────────────────────────────────────────── */}
          <div style={{ padding: '40px 48px' }}>

            {/* About Us */}
            {content.about_us && (
              <div style={{ marginBottom: '32px' }}>
                <SectionHeading label="About Us" styles={styles} />
                <div style={{
                  background: '#fff', border: `1px solid ${styles.border}`,
                  borderRadius: '10px', padding: '16px 20px',
                  display: 'grid', gridTemplateColumns: '1fr auto', gap: '16px', alignItems: 'center',
                }}>
                  <p style={{ fontSize: '13px', lineHeight: 1.8, color: '#374151', margin: 0 }}>
                    {content.about_us}
                  </p>
                  <div style={{ display: 'flex', flexDirection: 'column', gap: '8px', minWidth: '130px' }}>
                    {[
                      { label: 'Projects', value: '200+' },
                      { label: 'Clients',  value: '80+' },
                      { label: 'Satisfied','value': '98%' },
                    ].map(({ label, value }) => (
                      <div key={label} style={{
                        background: styles.sectionBg, borderRadius: '8px',
                        padding: '8px 12px', textAlign: 'center',
                      }}>
                        <div style={{ fontSize: '16px', fontWeight: 800, color: styles.primary }}>{value}</div>
                        <div style={{ fontSize: '10px', color: '#9ca3af', fontWeight: 500 }}>{label}</div>
                      </div>
                    ))}
                  </div>
                </div>
              </div>
            )}

            {/* Why Choose Us */}
            {content.why_choose_us && content.why_choose_us.length > 0 && (
              <div style={{ marginBottom: '32px' }}>
                <SectionHeading label="Why Choose Us" styles={styles} />
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px' }}>
                  {content.why_choose_us.map((item, i) => (
                    <div key={i} style={{
                      background: '#fff', border: `1px solid ${styles.border}`,
                      borderRadius: '10px', padding: '12px 16px',
                      display: 'flex', alignItems: 'flex-start', gap: '10px',
                    }}>
                      <div style={{
                        width: '22px', height: '22px', borderRadius: '50%',
                        background: styles.primary, color: '#fff',
                        display: 'flex', alignItems: 'center', justifyContent: 'center',
                        fontSize: '11px', fontWeight: 700, flexShrink: 0, marginTop: '1px',
                      }}>✓</div>
                      <span style={{ fontSize: '12px', color: '#374151', lineHeight: 1.5 }}>{item}</span>
                    </div>
                  ))}
                </div>
              </div>
            )}

            {/* Executive Summary */}
            <div style={{ marginBottom: '32px' }}>
              <SectionHeading label="Executive Summary" styles={styles} />
              <p style={{ fontSize: '13px', lineHeight: 1.85, color: '#374151', whiteSpace: 'pre-wrap', margin: 0 }}>
                {content.executive_summary}
              </p>
            </div>

            {/* Acknowledgement / Understanding */}
            <div style={{ marginBottom: '32px' }}>
              <SectionHeading label="Acknowledgement" styles={styles} />
              <div style={{
                background: styles.sectionBg, borderRadius: '10px',
                padding: '16px 20px', borderLeft: `4px solid ${styles.primary}`,
              }}>
                <p style={{ fontSize: '13px', lineHeight: 1.85, color: '#374151', whiteSpace: 'pre-wrap', margin: 0 }}>
                  {content.understanding}
                </p>
              </div>
            </div>

            {/* Problems Identified — 2-column card grid */}
            {content.client_pain_points && content.client_pain_points.length > 0 && (
              <div style={{ marginBottom: '32px' }}>
                <SectionHeading label="Problems Identified" styles={styles} />
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px' }}>
                  {content.client_pain_points.map((p, i) => (
                    <div key={i} style={{
                      background: styles.sectionBg,
                      borderRadius: '8px', padding: '14px 16px',
                      borderLeft: `3px solid ${styles.primary}`,
                    }}>
                      <div style={{
                        fontSize: '12px', fontWeight: 700,
                        color: styles.primary, marginBottom: '8px',
                      }}>
                        {p.title}
                      </div>
                      {p.points.map((pt, j) => (
                        <div key={j} style={{ display: 'flex', gap: '6px', marginBottom: '4px' }}>
                          <span style={{ color: styles.accent, fontSize: '10px', marginTop: '2px', flexShrink: 0 }}>•</span>
                          <span style={{ fontSize: '11px', color: '#374151', lineHeight: 1.5 }}>{pt}</span>
                        </div>
                      ))}
                    </div>
                  ))}
                </div>
              </div>
            )}

            {/* Key Benefits */}
            {content.key_benefits && content.key_benefits.length > 0 && (
              <div style={{ marginBottom: '32px' }}>
                <SectionHeading label="Key Benefits" styles={styles} />
                <div style={{ display: 'grid', gridTemplateColumns: `repeat(${Math.min(content.key_benefits.length, 2)}, 1fr)`, gap: '10px' }}>
                  {content.key_benefits.map((b, i) => (
                    <div key={i} style={{
                      background: '#fff', border: `1px solid ${styles.border}`,
                      borderRadius: '10px', padding: '14px 16px',
                      borderTop: `3px solid ${styles.primary}`,
                    }}>
                      <div style={{ fontSize: '22px', fontWeight: 800, color: styles.primary, marginBottom: '4px' }}>
                        0{i + 1}
                      </div>
                      <div style={{ fontSize: '12px', fontWeight: 700, color: '#111827', marginBottom: '4px' }}>{b.title}</div>
                      <div style={{ fontSize: '11px', color: '#6b7280', lineHeight: 1.5 }}>{b.description}</div>
                    </div>
                  ))}
                </div>
              </div>
            )}

            {/* Proposed Solution */}
            <div style={{ marginBottom: '32px' }}>
              <SectionHeading label="Our Proposed Solution" styles={styles} />

              {ps.overview && (
                <p style={{ fontSize: '13px', lineHeight: 1.85, color: '#374151', whiteSpace: 'pre-wrap', marginBottom: '16px' }}>
                  {ps.overview}
                </p>
              )}

              {/* Approach + Delivery side-by-side */}
              {(ps.approach || ps.implementation_approach) && (
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px', marginBottom: '16px' }}>
                  {ps.approach && (
                    <div style={{ background: styles.sectionBg, borderRadius: '10px', padding: '12px 16px' }}>
                      <div style={{ fontSize: '10px', fontWeight: 700, color: styles.sectionText, textTransform: 'uppercase', letterSpacing: '1px', marginBottom: '6px' }}>Our Approach</div>
                      <p style={{ fontSize: '12px', color: '#374151', lineHeight: 1.6, margin: 0 }}>{ps.approach}</p>
                    </div>
                  )}
                  {ps.implementation_approach && (
                    <div style={{ background: styles.sectionBg, borderRadius: '10px', padding: '12px 16px' }}>
                      <div style={{ fontSize: '10px', fontWeight: 700, color: styles.sectionText, textTransform: 'uppercase', letterSpacing: '1px', marginBottom: '6px' }}>Delivery Model</div>
                      <p style={{ fontSize: '12px', color: '#374151', lineHeight: 1.6, margin: 0 }}>{ps.implementation_approach}</p>
                    </div>
                  )}
                </div>
              )}

              {/* Tech stack pills */}
              {ps.technology_stack && ps.technology_stack.length > 0 && (
                <div style={{ marginBottom: '16px' }}>
                  <div style={{ fontSize: '10px', fontWeight: 700, color: '#6b7280', textTransform: 'uppercase', letterSpacing: '1px', marginBottom: '8px' }}>Technology & Tools</div>
                  <div style={{ display: 'flex', flexWrap: 'wrap', gap: '6px' }}>
                    {ps.technology_stack.map((tech, i) => (
                      <span key={i} style={{
                        background: styles.pillBg, color: styles.pillText,
                        borderRadius: '20px', padding: '4px 12px', fontSize: '11px', fontWeight: 600,
                      }}>
                        {tech}
                      </span>
                    ))}
                  </div>
                </div>
              )}

              {/* Key features 2-col */}
              {ps.key_features && ps.key_features.length > 0 && (
                <div style={{ marginBottom: '16px' }}>
                  <div style={{ fontSize: '10px', fontWeight: 700, color: '#6b7280', textTransform: 'uppercase', letterSpacing: '1px', marginBottom: '8px' }}>Key Features & Capabilities</div>
                  <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '8px' }}>
                    {ps.key_features.map((f, i) => (
                      <div key={i} style={{
                        background: '#fff', border: `1px solid ${styles.border}`,
                        borderRadius: '8px', padding: '10px 12px',
                        display: 'flex', gap: '10px', alignItems: 'flex-start',
                      }}>
                        <div style={{ width: '6px', height: '6px', borderRadius: '50%', background: styles.primary, marginTop: '5px', flexShrink: 0 }} />
                        <div>
                          <div style={{ fontSize: '12px', fontWeight: 700, color: '#111827', marginBottom: '2px' }}>{f.feature}</div>
                          <div style={{ fontSize: '11px', color: '#6b7280', lineHeight: 1.4 }}>{f.benefit}</div>
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              )}

              {/* Success Metrics */}
              {ps.success_metrics && ps.success_metrics.length > 0 && (
                <div style={{ marginBottom: '16px' }}>
                  <div style={{ fontSize: '10px', fontWeight: 700, color: '#6b7280', textTransform: 'uppercase', letterSpacing: '1px', marginBottom: '8px' }}>Success Metrics</div>
                  <div style={{ display: 'flex', gap: '8px', flexWrap: 'wrap' }}>
                    {ps.success_metrics.map((m, i) => (
                      <div key={i} style={{
                        background: styles.sectionBg, border: `1px solid ${styles.border}`,
                        borderRadius: '10px', padding: '10px 14px', flex: '1', minWidth: '160px',
                      }}>
                        <div style={{ fontSize: '13px', fontWeight: 800, color: styles.primary, marginBottom: '2px' }}>{m.target}</div>
                        <div style={{ fontSize: '11px', color: '#6b7280' }}>{m.metric}</div>
                      </div>
                    ))}
                  </div>
                </div>
              )}

              {/* Differentiators */}
              {ps.differentiators && (
                <div style={{ background: styles.sectionBg, borderRadius: '10px', padding: '12px 16px', borderLeft: `4px solid ${styles.accent}` }}>
                  <div style={{ fontSize: '10px', fontWeight: 700, color: styles.sectionText, textTransform: 'uppercase', letterSpacing: '1px', marginBottom: '4px' }}>What Sets Us Apart</div>
                  <p style={{ fontSize: '12px', color: '#374151', lineHeight: 1.6, margin: 0 }}>{ps.differentiators}</p>
                </div>
              )}
            </div>

            {/* Scope of Work */}
            <div style={{ marginBottom: '32px' }}>
              <SectionHeading label="Scope of Work" styles={styles} />
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px' }}>
                {content.scope_of_work.map((item, i) => (
                  <div key={i} style={{
                    background: '#fff', border: `1px solid ${styles.border}`,
                    borderLeft: `4px solid ${styles.primary}`, borderRadius: '8px', padding: '12px 14px',
                  }}>
                    <div style={{ fontSize: '12px', fontWeight: 700, color: styles.primary, marginBottom: '4px' }}>
                      {i + 1}. {item.item}
                    </div>
                    <div style={{ fontSize: '11px', color: '#6b7280', lineHeight: 1.5, marginBottom: item.key_deliverables?.length ? '8px' : 0 }}>
                      {item.description}
                    </div>
                    {item.key_deliverables && item.key_deliverables.length > 0 && (
                      <div style={{ display: 'flex', flexDirection: 'column', gap: '3px' }}>
                        {item.key_deliverables.map((d, j) => (
                          <div key={j} style={{ display: 'flex', alignItems: 'flex-start', gap: '6px' }}>
                            <span style={{ color: styles.accent, fontSize: '10px', marginTop: '2px', flexShrink: 0 }}>▸</span>
                            <span style={{ fontSize: '10px', color: '#4b5563', lineHeight: 1.4 }}>{d}</span>
                          </div>
                        ))}
                      </div>
                    )}
                  </div>
                ))}
              </div>
            </div>

            {/* Our Team */}
            {content.team && content.team.length > 0 && (
              <div style={{ marginBottom: '32px' }}>
                <SectionHeading label="Our Team" styles={styles} />
                <div style={{ display: 'flex', gap: '10px', flexWrap: 'wrap' }}>
                  {content.team.map((m, i) => (
                    <div key={i} style={{
                      background: '#fff', border: `1px solid ${styles.border}`,
                      borderRadius: '10px', padding: '14px 16px',
                      flex: '1', minWidth: '140px', textAlign: 'center',
                    }}>
                      <div style={{
                        width: '40px', height: '40px', borderRadius: '50%',
                        background: styles.sectionBg, margin: '0 auto 8px',
                        display: 'flex', alignItems: 'center', justifyContent: 'center',
                        fontSize: '16px', fontWeight: 800, color: styles.primary,
                      }}>
                        {m.role.charAt(0).toUpperCase()}
                      </div>
                      <div style={{ fontSize: '12px', fontWeight: 700, color: '#111827', marginBottom: '4px' }}>{m.role}</div>
                      <div style={{ fontSize: '10px', color: '#9ca3af', lineHeight: 1.4 }}>{m.responsibility}</div>
                    </div>
                  ))}
                </div>
              </div>
            )}

            {/* Project Timeline — visual numbered steps (PDF style) */}
            <div style={{ marginBottom: '32px' }}>
              <SectionHeading label="Step-By-Step Work Process" styles={styles} />
              <div style={{ display: 'flex', flexDirection: 'column', gap: '4px' }}>
                {content.timeline.map((row, i) => (
                  <div key={i} style={{ display: 'flex', gap: '0' }}>
                    {/* Numbered box */}
                    <div style={{
                      background: styles.primary, color: '#fff',
                      width: '64px', minWidth: '64px',
                      display: 'flex', alignItems: 'center', justifyContent: 'center',
                      fontSize: '20px', fontWeight: 800,
                      borderRadius: '8px 0 0 8px',
                      padding: '16px 0',
                    }}>
                      {String(i + 1).padStart(2, '0')}
                    </div>
                    {/* Content */}
                    <div style={{
                      background: i % 2 === 0 ? '#fff' : styles.sectionBg,
                      border: `1px solid ${styles.border}`,
                      flex: 1, padding: '12px 16px',
                      borderRadius: '0 8px 8px 0',
                      borderLeft: 'none',
                    }}>
                      <div style={{ fontSize: '13px', fontWeight: 700, color: '#111827', marginBottom: '2px' }}>
                        {row.phase}
                        <span style={{ fontSize: '11px', color: styles.primary, fontWeight: 600, marginLeft: '10px' }}>
                          · {row.duration}
                        </span>
                      </div>
                      <div style={{ fontSize: '11px', color: '#6b7280', lineHeight: 1.5 }}>{row.deliverables}</div>
                    </div>
                  </div>
                ))}
              </div>
            </div>

            {/* Platform & Technology — key-value rows */}
            {content.platform_tech && content.platform_tech.length > 0 && (
              <div style={{ marginBottom: '32px' }}>
                <SectionHeading label="Platform & Technology" styles={styles} />
                {content.platform_tech.map((pt, i) => (
                  <LabelValueRow key={i} label={pt.label} value={pt.value} styles={styles} />
                ))}
              </div>
            )}

            {/* Investment */}
            <div style={{ marginBottom: '32px' }}>
              <SectionHeading label="Cost Estimation" styles={styles} />

              {/* Resource breakdown if present */}
              {content.investment.resource_breakdown && content.investment.resource_breakdown.length > 0 && (
                <div style={{ marginBottom: '12px' }}>
                  <div style={{ fontSize: '10px', fontWeight: 700, color: '#6b7280', textTransform: 'uppercase', letterSpacing: '1px', marginBottom: '6px' }}>
                    Resource Breakdown
                  </div>
                  <table style={{ width: '100%', borderCollapse: 'collapse', marginBottom: '12px' }}>
                    <thead>
                      <tr style={{ background: styles.sectionBg }}>
                        {['Resource', 'Rate', 'Hours', 'Amount'].map((h) => (
                          <th key={h} style={{ padding: '8px 12px', fontSize: '10px', fontWeight: 700, textAlign: h === 'Resource' ? 'left' : 'right', color: styles.sectionText, textTransform: 'uppercase', letterSpacing: '0.5px' }}>{h}</th>
                        ))}
                      </tr>
                    </thead>
                    <tbody>
                      {content.investment.resource_breakdown.map((r, i) => (
                        <tr key={i} style={{ background: i % 2 === 0 ? '#fff' : styles.sectionBg }}>
                          <td style={{ padding: '8px 12px', fontSize: '11px', color: '#374151' }}>{r.resource}</td>
                          <td style={{ padding: '8px 12px', fontSize: '11px', color: '#374151', textAlign: 'right' }}>{fmtMoney(r.rate)}/hr</td>
                          <td style={{ padding: '8px 12px', fontSize: '11px', color: '#374151', textAlign: 'right' }}>{r.hours}h</td>
                          <td style={{ padding: '8px 12px', fontSize: '11px', color: '#374151', textAlign: 'right' }}>{fmtMoney(r.amount)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}

              {/* Summary table — SERVICE | COST style from reference PDF */}
              <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                <thead>
                  <tr style={{ background: styles.tableHead }}>
                    <th style={{ padding: '10px 14px', fontSize: '11px', fontWeight: 700, textAlign: 'left', color: '#fff', textTransform: 'uppercase', letterSpacing: '1px' }}>Service</th>
                    <th style={{ padding: '10px 14px', fontSize: '11px', fontWeight: 700, textAlign: 'right', color: '#fff', textTransform: 'uppercase', letterSpacing: '1px' }}>Cost</th>
                  </tr>
                </thead>
                <tbody>
                  {content.investment.breakdown.map((row, i) => (
                    <tr key={i} style={{ background: i % 2 === 0 ? '#fff' : styles.sectionBg }}>
                      <td style={{ padding: '10px 14px', fontSize: '12px', color: '#374151' }}>{row.item}</td>
                      <td style={{ padding: '10px 14px', fontSize: '12px', color: styles.primary, textAlign: 'right', fontWeight: 600 }}>{fmtMoney(row.amount)}</td>
                    </tr>
                  ))}
                  <tr style={{ background: '#111827' }}>
                    <td style={{ padding: '12px 14px', fontSize: '13px', fontWeight: 700, color: '#fff' }}>Total Project Cost</td>
                    <td style={{ padding: '12px 14px', fontSize: '14px', fontWeight: 800, color: '#fbbf24', textAlign: 'right' }}>
                      {fmtMoney(content.investment.total)} {content.investment.currency}
                    </td>
                  </tr>
                </tbody>
              </table>

              {/* Payment Structure */}
              {content.investment.payment_terms && (
                <div style={{ marginTop: '12px' }}>
                  <div style={{ fontSize: '10px', fontWeight: 700, color: '#6b7280', textTransform: 'uppercase', letterSpacing: '1px', marginBottom: '6px' }}>
                    Payment Structure
                  </div>
                  <div style={{ padding: '10px 14px', background: styles.sectionBg, borderRadius: '8px', fontSize: '12px', color: styles.sectionText }}>
                    <strong>Terms:</strong> {content.investment.payment_terms}
                  </div>
                </div>
              )}
            </div>

            {/* Risk & Mitigation */}
            {content.risk_mitigation && content.risk_mitigation.length > 0 && (
              <div style={{ marginBottom: '32px' }}>
                <SectionHeading label="Risk & Mitigation" styles={styles} />
                <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                  <thead>
                    <tr style={{ background: styles.tableHead, color: '#fff' }}>
                      <th style={{ padding: '10px 14px', fontSize: '11px', fontWeight: 600, textAlign: 'left', textTransform: 'uppercase', letterSpacing: '0.5px', width: '45%' }}>Potential Risk</th>
                      <th style={{ padding: '10px 14px', fontSize: '11px', fontWeight: 600, textAlign: 'left', textTransform: 'uppercase', letterSpacing: '0.5px' }}>Mitigation Strategy</th>
                    </tr>
                  </thead>
                  <tbody>
                    {content.risk_mitigation.map((r, i) => (
                      <tr key={i} style={{ background: i % 2 === 0 ? '#fff' : styles.sectionBg }}>
                        <td style={{ padding: '10px 14px', fontSize: '12px', color: '#374151', fontWeight: 500 }}>{r.risk}</td>
                        <td style={{ padding: '10px 14px', fontSize: '12px', color: '#374151' }}>{r.mitigation}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            {/* Assumptions */}
            {content.assumptions && content.assumptions.length > 0 && (
              <div style={{ marginBottom: '32px' }}>
                <SectionHeading label="Assumptions" styles={styles} />
                <div style={{ background: '#fff', border: `1px solid ${styles.border}`, borderRadius: '10px', padding: '14px 18px' }}>
                  {content.assumptions.map((a, i) => (
                    <div key={i} style={{ display: 'flex', gap: '10px', alignItems: 'flex-start', marginBottom: i < content.assumptions!.length - 1 ? '8px' : 0 }}>
                      <span style={{
                        background: styles.sectionBg, color: styles.primary,
                        borderRadius: '50%', width: '20px', height: '20px',
                        display: 'flex', alignItems: 'center', justifyContent: 'center',
                        fontSize: '10px', fontWeight: 700, flexShrink: 0,
                      }}>{i + 1}</span>
                      <span style={{ fontSize: '12px', color: '#374151', lineHeight: 1.5 }}>{a}</span>
                    </div>
                  ))}
                </div>
              </div>
            )}

            {/* Maintenance & Support */}
            {content.maintenance_support && (
              <div style={{ marginBottom: '32px' }}>
                <SectionHeading label="Maintenance & Support" styles={styles} />
                <LabelValueRow
                  label="Free Support Period"
                  value={content.maintenance_support.period + ' Included'}
                  styles={styles}
                />
                {content.maintenance_support.includes && content.maintenance_support.includes.length > 0 && (
                  <div style={{
                    marginTop: '8px', background: '#fff', border: `1px solid ${styles.border}`,
                    borderRadius: '8px', padding: '12px 16px',
                  }}>
                    {content.maintenance_support.includes.map((item, i) => (
                      <div key={i} style={{ display: 'flex', gap: '8px', alignItems: 'flex-start', marginBottom: i < content.maintenance_support!.includes.length - 1 ? '6px' : 0 }}>
                        <span style={{ color: styles.primary, fontSize: '11px', marginTop: '2px', flexShrink: 0 }}>•</span>
                        <span style={{ fontSize: '12px', color: '#374151', lineHeight: 1.5 }}>{item}</span>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            )}

            {/* Terms & Conditions */}
            <div style={{ marginBottom: '32px' }}>
              <SectionHeading label="Terms & Conditions" styles={styles} />
              <p style={{ fontSize: '12px', lineHeight: 1.8, color: '#6b7280', whiteSpace: 'pre-wrap', margin: 0 }}>
                {content.terms_and_conditions}
              </p>
            </div>

            {/* Next Steps */}
            <div style={{ marginBottom: '32px' }}>
              <SectionHeading label="Next Steps" styles={styles} />
              <div style={{ padding: '16px', background: styles.sectionBg, borderRadius: '10px', borderLeft: `4px solid ${styles.accent}` }}>
                <p style={{ fontSize: '13px', color: '#374151', lineHeight: 1.7, whiteSpace: 'pre-wrap', margin: 0 }}>
                  {content.next_steps}
                </p>
              </div>
            </div>

            {/* Validity note */}
            {proposal.valid_until && (
              <div style={{ marginBottom: '32px', padding: '14px 20px', background: '#fef3c7', borderRadius: '10px', border: '1px solid #fcd34d', textAlign: 'center' }}>
                <div style={{ fontSize: '12px', color: '#92400e', fontWeight: 600 }}>
                  This proposal is valid until {fmtDate(proposal.valid_until)}. Please respond before this date to secure the quoted pricing.
                </div>
              </div>
            )}

            {/* Signature lines */}
            <div style={{ marginBottom: '40px', borderTop: `2px solid ${styles.border}`, paddingTop: '24px', display: 'flex', justifyContent: 'space-between' }}>
              <div>
                <div style={{ fontSize: '11px', color: '#9ca3af', marginBottom: '40px' }}>Authorized Signatory</div>
                <div style={{ width: '160px', borderTop: `1px solid ${styles.primary}`, paddingTop: '6px', fontSize: '11px', color: styles.primary, fontWeight: 600 }}>
                  Company Representative
                </div>
              </div>
              <div style={{ textAlign: 'right' }}>
                <div style={{ fontSize: '11px', color: '#9ca3af', marginBottom: '40px' }}>Client Acceptance</div>
                <div style={{ width: '160px', borderTop: '1px solid #9ca3af', paddingTop: '6px', fontSize: '11px', color: '#6b7280' }}>
                  {lead.full_name}
                </div>
              </div>
            </div>

            {/* Closing / Thank You — extra top gap so PDF page-split can land above it */}
            <div style={{
              textAlign: 'center',
              padding: '64px 20px 40px',
              marginTop: '24px',
              borderTop: `2px solid ${styles.border}`,
            }}>
              <div style={{
                fontSize: '26px', fontWeight: 800, letterSpacing: '8px',
                textTransform: 'uppercase', color: styles.primary, marginBottom: '16px',
              }}>
                T H A N K &nbsp; Y O U
              </div>
              {content.closing_note && (
                <p style={{
                  fontSize: '13px', color: '#6b7280', lineHeight: 1.7,
                  maxWidth: '400px', margin: '0 auto 20px',
                }}>
                  {content.closing_note}
                </p>
              )}
              <div style={{
                display: 'inline-block', padding: '2px 16px',
                borderTop: `1px solid ${styles.border}`, borderBottom: `1px solid ${styles.border}`,
                fontSize: '11px', color: '#9ca3af', letterSpacing: '1px',
              }}>
                {lead.company ?? lead.full_name} &nbsp;|&nbsp; Confidential
              </div>
            </div>

          </div>

          {/* ── Running footer (preview only; PDF draws this on every page) ─ */}
          <div
            data-pdf-chrome
            style={{
              background: '#fff',
              borderTop: `1px solid ${styles.border}`,
              padding: '10px 48px',
              display: 'flex',
              justifyContent: 'space-between',
              alignItems: 'center',
              fontSize: '10px',
              color: '#9ca3af',
            }}
          >
            <span>Confidential</span>
            <span>KanpriTech</span>
            <span style={{ color: styles.primary, fontWeight: 600 }}>
              Proposal #{String(proposal.id).padStart(5, '0')}
            </span>
          </div>

        </div>
      </div>
    </div>
  );
}
