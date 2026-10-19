import React from 'react';
import { Document, Image, Link, Page, StyleSheet, Text, View, renderToBuffer } from '@react-pdf/renderer';
import QRCode from 'qrcode';

type Template = 'classic' | 'modern' | 'minimal' | 'corporate' | 'executive';
type Money = number | string | null | undefined;

type Party = {
  name?: string; legal_name?: string; trade_name?: string; company_name?: string;
  contact_name?: string; address?: string; registered_address?: string; billing_address?: string;
  city?: string; state?: string; postal_code?: string; country?: string; gstin?: string; pan?: string;
  phone?: string; contact_phone?: string; email?: string; contact_email?: string; website?: string;
  logo_data_uri?: string; signature_data_uri?: string;
};

type Bank = { account_name?: string; bank_name?: string; account_number?: string; ifsc?: string; swift?: string; upi_id?: string; branch_name?: string; currency?: string };
type Item = { description?: string; product_name?: string; details?: string; hsn_sac?: string; quantity?: Money; unit?: string; unit_price?: Money; discount_type?: string; discount_value?: Money; discount_amount?: Money; taxable_amount?: Money; gst_rate?: Money; cgst_amount?: Money; sgst_amount?: Money; igst_amount?: Money; line_total?: Money };
type DocumentPayload = {
  kind: 'document'; template?: Template; document_type?: 'invoice' | 'proforma'; document_number?: string;
  issue_date?: string; due_date?: string; currency?: string; issuer?: Party; customer?: Party; bank?: Bank;
  items?: Item[]; subtotal?: Money; discount_total?: Money; taxable_total?: Money; cgst_total?: Money;
  sgst_total?: Money; igst_total?: Money; rounding_adjustment?: Money; grand_total?: Money;
  notes?: string; terms?: string; footer?: string; payment_link?: string;
};
type ReceiptEntry = { receipt_number?: string; payment_date?: string; payment_method?: string; reference?: string; product_details?: string; amount?: Money };
type ReceiptPayload = { kind: 'receipt'; receipt_number?: string; receipt_date?: string; currency?: string; issuer?: Party; customer?: Party; payment?: ReceiptEntry; footer?: string };
type ConsolidatedPayload = { kind: 'consolidated_receipt'; receipt_number?: string; receipt_date?: string; period_start?: string; period_end?: string; currency?: string; issuer?: Party; customer?: Party; payments?: ReceiptEntry[]; total_billed?: Money; represented_total?: Money; footer?: string };
type Payload = DocumentPayload | ReceiptPayload | ConsolidatedPayload;

const n = (value: Money) => Number(value || 0);
const date = (value?: string) => value ? new Intl.DateTimeFormat('en-IN', { day: '2-digit', month: '2-digit', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${value.slice(0, 10)}T00:00:00Z`)) : '—';
const amount = (value: Money, currency = 'INR') => `${currency} ${new Intl.NumberFormat(currency === 'INR' ? 'en-IN' : 'en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n(value))}`;

function numberToWords(value: Money, currency = 'INR'): string {
  const units = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
  const tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
  const underThousand = (x: number): string => x < 20 ? units[x] : x < 100 ? `${tens[Math.floor(x / 10)]}${x % 10 ? ` ${units[x % 10]}` : ''}` : `${units[Math.floor(x / 100)]} Hundred${x % 100 ? ` ${underThousand(x % 100)}` : ''}`;
  let whole = Math.floor(Math.abs(n(value)));
  if (!whole) return `ZERO ${currency === 'INR' ? 'RUPEES' : currency} ONLY`;
  const groups = currency === 'INR' ? [[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand']] as const : [[1000000000, 'Billion'], [1000000, 'Million'], [1000, 'Thousand']] as const;
  const words: string[] = [];
  for (const [size, label] of groups) if (whole >= size) { words.push(`${underThousand(Math.floor(whole / size))} ${label}`); whole %= size; }
  if (whole) words.push(underThousand(whole));
  return `${words.join(' ').toUpperCase()} ${currency === 'INR' ? 'RUPEES' : currency} ONLY`;
}

const themes: Record<Template, { primary: string; secondary: string; light: string; text: string; band: boolean; uppercase: boolean }> = {
  classic: { primary: '#2563EB', secondary: '#334155', light: '#F8FAFC', text: '#0F172A', band: false, uppercase: false },
  modern: { primary: '#4F46E5', secondary: '#6B7280', light: '#F5F3FF', text: '#111827', band: false, uppercase: true },
  minimal: { primary: '#111827', secondary: '#6B7280', light: '#FFFFFF', text: '#111827', band: false, uppercase: true },
  corporate: { primary: '#123B66', secondary: '#2563EB', light: '#EFF6FF', text: '#172033', band: true, uppercase: true },
  executive: { primary: '#1A3C34', secondary: '#D97706', light: '#E8F5F1', text: '#111827', band: true, uppercase: true },
};

const common = StyleSheet.create({
  page: { fontFamily: 'Helvetica', fontSize: 9, lineHeight: 1.4, color: '#334155', paddingBottom: 70 },
  content: { paddingHorizontal: 34, paddingTop: 28 },
  row: { flexDirection: 'row' },
  between: { flexDirection: 'row', justifyContent: 'space-between' },
  logo: { width: 120, height: 48, objectFit: 'contain' },
  partyRow: { flexDirection: 'row', gap: 14, marginBottom: 20 },
  party: { flex: 1, padding: 12, borderRadius: 4 },
  partyName: { fontSize: 10, fontWeight: 'bold', marginBottom: 3 },
  partyLine: { fontSize: 8.3, marginBottom: 2 },
  table: { marginTop: 5, marginBottom: 8 },
  tableHead: { flexDirection: 'row', paddingVertical: 8, paddingHorizontal: 7 },
  tableRow: { flexDirection: 'row', paddingVertical: 8, paddingHorizontal: 7, borderBottomWidth: 1, borderBottomColor: '#E5E7EB', alignItems: 'flex-start' },
  cNo: { width: '5%' }, cDesc: { width: '31%', paddingRight: 5 }, cHsn: { width: '10%', textAlign: 'center' }, cRate: { width: '13%', textAlign: 'right' }, cQty: { width: '8%', textAlign: 'right' }, cDisc: { width: '10%', textAlign: 'right' }, cGst: { width: '8%', textAlign: 'right' }, cTotal: { width: '15%', textAlign: 'right' },
  bottom: { flexDirection: 'row', gap: 18, marginTop: 12 },
  bank: { flex: 6, padding: 13, borderWidth: 1, borderColor: '#E2E8F0', flexDirection: 'row', justifyContent: 'space-between' },
  totals: { flex: 4 }, totalLine: { flexDirection: 'row', justifyContent: 'space-between', marginBottom: 6 },
  footer: { position: 'absolute', left: 34, right: 34, bottom: 18, height: 42, borderTopWidth: 1, borderTopColor: '#CBD5E1', paddingTop: 7 },
  footerText: { fontSize: 7.2, color: '#64748B', textAlign: 'center' },
  signature: { width: 90, height: 35, objectFit: 'contain', marginTop: 7 },
});

function partyLines(p: Party = {}) {
  const address = p.billing_address || p.registered_address || p.address;
  const locality = [p.city, p.state, p.postal_code].filter(Boolean).join(', ');
  return [address, locality, p.country, p.gstin ? `GSTIN: ${p.gstin}` : '', p.pan ? `PAN: ${p.pan}` : '', p.contact_phone || p.phone, p.contact_email || p.email].filter(Boolean) as string[];
}

function PartyBox({ label, party, theme }: { label: string; party?: Party; theme: typeof themes.classic }) {
  const p = party || {};
  return <View style={[common.party, { backgroundColor: theme.light, borderWidth: theme.primary === '#111827' ? 0 : 1, borderColor: theme.primary + '33', borderLeftWidth: 3, borderLeftColor: theme.primary }]}>
    <Text style={{ color: theme.primary, fontWeight: 'bold', fontSize: 8, marginBottom: 6 }}>{label}</Text>
    <Text style={[common.partyName, { color: theme.text }]}>{p.company_name || p.trade_name || p.legal_name || p.name || '—'}</Text>
    {p.company_name && p.contact_name ? <Text style={common.partyLine}>Contact: {p.contact_name}</Text> : null}
    {partyLines(p).map((line, index) => <Text key={index} style={common.partyLine}>{line}</Text>)}
  </View>;
}

async function DocumentPdf({ data }: { data: DocumentPayload }) {
  const template: Template = themes[data.template || 'classic'] ? (data.template || 'classic') : 'classic';
  const theme = themes[template];
  const currency = data.currency || 'INR';
  const isProforma = data.document_type === 'proforma';
  const title = isProforma ? 'Proforma Invoice' : 'Tax Invoice';
  const items = data.items || [];
  const showHsn = items.some(item => Boolean(item.hsn_sac));
  const showDiscount = items.some(item => n(item.discount_amount) > 0);
  const showGst = items.some(item => n(item.gst_rate) > 0);
  const variable = (showHsn ? 10 : 0) + (showDiscount ? 10 : 0) + (showGst ? 8 : 0);
  const descWidth = `${59 - variable}%`;
  let qr: string | undefined;
  if (isProforma && currency === 'INR' && data.bank?.upi_id) {
    qr = await QRCode.toDataURL(`upi://pay?pa=${encodeURIComponent(data.bank.upi_id)}&pn=${encodeURIComponent(data.issuer?.trade_name || data.issuer?.legal_name || '')}&am=${n(data.grand_total).toFixed(2)}&cu=INR`, { margin: 1, width: 180 });
  }
  const headerBand = template === 'corporate' || template === 'executive';
  return <Document title={`${title} ${data.document_number || 'Draft'}`} author={data.issuer?.legal_name || data.issuer?.name || 'Growneq CRM'}>
    <Page size="A4" style={[common.page, { backgroundColor: headerBand ? '#FAFAFA' : '#FFFFFF' }]}>
      {template === 'modern' ? <View style={{ height: 6, backgroundColor: theme.primary }} fixed /> : null}
      <View
        wrap={false}
        style={headerBand
          ? { backgroundColor: theme.primary, paddingHorizontal: 34, paddingVertical: 21, height: 112, flexGrow: 0, flexShrink: 0 }
          : common.content}
      >
        <View style={common.between}>
          <View style={{ maxWidth: '65%' }}>
            {headerBand && data.issuer?.logo_data_uri ? <Image src={data.issuer.logo_data_uri} style={common.logo} /> : <Text style={{ fontSize: headerBand ? 20 : 18, color: headerBand ? '#FFFFFF' : theme.primary, fontWeight: 'bold', textTransform: theme.uppercase ? 'uppercase' : 'capitalize' }}>{title}</Text>}
            {!headerBand ? <View style={{ marginTop: 13 }}><Text style={{ fontSize: 8, color: theme.secondary }}>Document No.</Text><Text style={{ fontWeight: 'bold', color: theme.text }}>{data.document_number || 'DRAFT'}</Text></View> : <Text style={{ marginTop: 8, color: template === 'executive' ? '#A7C4BC' : '#BFDBFE', fontSize: 8 }}>{data.issuer?.trade_name || data.issuer?.legal_name}</Text>}
          </View>
          <View style={{ alignItems: 'flex-end' }}>
            {!headerBand && data.issuer?.logo_data_uri ? <Image src={data.issuer.logo_data_uri} style={common.logo} /> : null}
            {headerBand ? <><Text style={{ color: theme.secondary, fontSize: 8, letterSpacing: 1.5, fontWeight: 'bold' }}>{title.toUpperCase()}</Text><Text style={{ color: '#FFFFFF', fontSize: 10, fontWeight: 'bold', marginTop: 5 }}>{data.document_number || 'DRAFT'}</Text></> : null}
            <Text style={{ color: headerBand ? '#FFFFFF' : theme.text, fontSize: 8.5, marginTop: 5 }}>Date: {date(data.issue_date)}</Text>
            {data.due_date ? <Text style={{ color: headerBand ? '#FFFFFF' : theme.text, fontSize: 8.5, marginTop: 2 }}>Due: {date(data.due_date)}</Text> : null}
            <Text style={{ color: headerBand ? '#FFFFFF' : theme.text, fontSize: 8.5, marginTop: 2 }}>Currency: {currency}</Text>
          </View>
        </View>
      </View>
      {headerBand ? <View style={{ height: 3, backgroundColor: theme.secondary }} /> : null}
      <View style={headerBand ? common.content : { paddingHorizontal: 34, paddingTop: 20 }}>
        <View style={common.partyRow}><PartyBox label={template === 'classic' ? 'Billed By' : 'FROM'} party={data.issuer} theme={theme}/><PartyBox label={template === 'classic' ? 'Billed To' : 'TO'} party={data.customer} theme={theme}/></View>
        <View style={common.table}>
          <View style={[common.tableHead, { backgroundColor: theme.primary }]} fixed>
            <Text style={[common.cNo, { color: '#FFF', fontWeight: 'bold' }]}>#</Text><Text style={{ width: descWidth, color: '#FFF', fontWeight: 'bold' }}>Item & Description</Text>
            {showHsn ? <Text style={[common.cHsn, { color: '#FFF', fontWeight: 'bold' }]}>HSN/SAC</Text> : null}
            <Text style={[common.cRate, { color: '#FFF', fontWeight: 'bold' }]}>Rate</Text><Text style={[common.cQty, { color: '#FFF', fontWeight: 'bold' }]}>Qty</Text>
            {showDiscount ? <Text style={[common.cDisc, { color: '#FFF', fontWeight: 'bold' }]}>Discount</Text> : null}{showGst ? <Text style={[common.cGst, { color: '#FFF', fontWeight: 'bold' }]}>GST</Text> : null}<Text style={[common.cTotal, { color: '#FFF', fontWeight: 'bold' }]}>Total</Text>
          </View>
          {items.map((item, index) => <View key={index} style={[common.tableRow, index % 2 && template !== 'minimal' ? { backgroundColor: theme.light } : {}]} wrap={false}>
            <Text style={common.cNo}>{index + 1}.</Text><View style={{ width: descWidth, paddingRight: 5 }}><Text style={{ fontWeight: 'bold', color: theme.text }}>{item.product_name || item.description || 'Item'}</Text>{item.details ? <Text style={{ color: '#64748B', fontSize: 8, marginTop: 2 }}>{item.details}</Text> : null}</View>
            {showHsn ? <Text style={common.cHsn}>{item.hsn_sac || '—'}</Text> : null}<Text style={common.cRate}>{amount(item.unit_price, currency).replace(`${currency} `, '')}</Text><Text style={common.cQty}>{n(item.quantity).toFixed(2)}</Text>
            {showDiscount ? <Text style={common.cDisc}>{item.discount_type === 'percentage' ? `${n(item.discount_value)}%` : amount(item.discount_amount, currency).replace(`${currency} `, '')}</Text> : null}{showGst ? <Text style={common.cGst}>{n(item.gst_rate)}%</Text> : null}<Text style={common.cTotal}>{amount(item.line_total, currency).replace(`${currency} `, '')}</Text>
          </View>)}
        </View>
        <View style={{ marginVertical: 10, padding: 8, backgroundColor: theme.light, borderLeftWidth: 3, borderLeftColor: theme.primary }}><Text style={{ fontWeight: 'bold', color: theme.primary }}>Total (in words): {numberToWords(data.grand_total, currency)}</Text></View>
        <View style={common.bottom}>
          {isProforma ? <View style={common.bank}><View style={{ flex: 1 }}><Text style={{ color: theme.primary, fontWeight: 'bold', marginBottom: 7 }}>{currency === 'INR' ? 'Bank Details' : 'International Bank Transfer'}</Text>{data.bank ? <>{[['Account Name', data.bank.account_name], ['Bank Name', data.bank.bank_name], ['Account Number', data.bank.account_number], ['IFSC', data.bank.ifsc], ['SWIFT', data.bank.swift]].filter(([, v]) => v).map(([label, value]) => <View key={label} style={{ marginBottom: 4 }}><Text style={{ color: '#94A3B8', fontSize: 7 }}>{label}</Text><Text>{value}</Text></View>)}</> : <Text>Contact us for payment details.</Text>}{data.payment_link ? <Link src={data.payment_link} style={{ color: theme.primary, marginTop: 5 }}>Secure payment link</Link> : null}</View>{qr ? <View style={{ alignItems: 'center', marginLeft: 10 }}><Text style={{ fontSize: 7, color: theme.primary }}>Scan to pay via UPI</Text><Image src={qr} style={{ width: 76, height: 76, marginVertical: 3 }}/><Text style={{ fontSize: 7 }}>{data.bank?.upi_id}</Text></View> : null}</View> : <View style={{ flex: 6 }}/>}
          <View style={common.totals}>{[['Subtotal', data.subtotal], ['Discount', data.discount_total], ['CGST', data.cgst_total], ['SGST', data.sgst_total], ['IGST', data.igst_total], ['Rounding', data.rounding_adjustment]].filter(([label, value]) => label === 'Subtotal' || n(value as Money) !== 0).map(([label, value]) => <View key={String(label)} style={common.totalLine}><Text style={{ color: theme.secondary }}>{label}</Text><Text>{amount(value as Money, currency)}</Text></View>)}<View style={{ borderTopWidth: 2, borderTopColor: theme.primary, paddingTop: 7, flexDirection: 'row', justifyContent: 'space-between' }}><Text style={{ fontWeight: 'bold', fontSize: 11 }}>TOTAL</Text><Text style={{ color: theme.primary, fontWeight: 'bold', fontSize: 11 }}>{amount(data.grand_total, currency)}</Text></View></View>
        </View>
        {data.notes ? <View style={{ marginTop: 15 }}><Text style={{ color: theme.primary, fontWeight: 'bold', marginBottom: 3 }}>Notes</Text><Text>{data.notes}</Text></View> : null}
        {data.terms ? <View style={{ marginTop: 13 }} break><Text style={{ color: theme.primary, fontWeight: 'bold', marginBottom: 3 }}>Terms & Conditions</Text><Text>{data.terms}</Text></View> : null}
        {data.issuer?.signature_data_uri ? <View style={{ marginTop: 12, alignItems: 'flex-end' }}><Image src={data.issuer.signature_data_uri} style={common.signature}/><Text style={{ fontSize: 7, color: '#64748B' }}>Authorized Signatory</Text></View> : null}
      </View>
      <View style={[common.footer, headerBand ? { left: 0, right: 0, bottom: 0, height: 52, backgroundColor: theme.primary, borderTopColor: theme.secondary, paddingHorizontal: 34, paddingBottom: 9 } : {}]} fixed><Text style={[common.footerText, headerBand ? { color: '#D1D5DB' } : {}]}>{data.footer || `For enquiries: ${data.issuer?.contact_email || data.issuer?.email || ''} ${data.issuer?.contact_phone || data.issuer?.phone || ''}`}</Text><View style={[common.between, { marginTop: 4 }]}><Text style={[common.footerText, { width: 170, textAlign: 'left' }]}>{data.document_number || 'DRAFT'}</Text></View><Text style={[common.footerText, { marginTop: 3, fontWeight: 'bold' }]}>POWERED BY KANPRI TECHNOLOGIES</Text></View>
      <Text fixed style={{ position: 'absolute', right: 34, bottom: headerBand ? 25 : 38, width: 80, color: headerBand ? '#D1D5DB' : '#64748B', fontSize: 7.2, textAlign: 'right' }} render={({ pageNumber, totalPages }) => `Page ${pageNumber} of ${totalPages}`}/>
    </Page>
  </Document>;
}

function ReceiptPdf({ data }: { data: ReceiptPayload }) {
  const currency = data.currency || 'INR'; const p = data.payment || {};
  return <Document title={`Payment Receipt ${data.receipt_number || ''}`}><Page size="A4" style={[common.page, { padding: 40 }]}>
    <View style={[common.between, { paddingBottom: 15, borderBottomWidth: 1, borderBottomColor: '#D9DEE7' }]}><View><View style={[common.row, { alignItems: 'center' }]}><Text style={{ fontSize: 16, fontWeight: 'bold', color: '#111827' }}>Payment Receipt</Text><Text style={{ marginLeft: 8, backgroundColor: '#F59E0B', color: '#FFF', paddingHorizontal: 7, paddingVertical: 2, borderRadius: 4, fontSize: 8 }}>PAID</Text></View><Text style={{ marginTop: 9 }}>Receipt No.  <Text style={{ fontWeight: 'bold' }}>{data.receipt_number}</Text></Text><Text style={{ marginTop: 3 }}>Receipt Date  <Text style={{ fontWeight: 'bold' }}>{date(data.receipt_date || p.payment_date)}</Text></Text></View>{data.issuer?.logo_data_uri ? <Image src={data.issuer.logo_data_uri} style={common.logo}/> : null}</View>
    <View style={[common.partyRow, { marginTop: 20 }]}><PartyBox label="Issued by" party={data.issuer} theme={themes.classic}/><PartyBox label="Issued to" party={data.customer} theme={themes.classic}/></View>
    <Text style={{ fontSize: 12, fontWeight: 'bold', marginBottom: 8 }}>Payment Summary</Text><View style={{ borderWidth: 1, borderColor: '#E2E8F0' }}><View style={[common.tableHead, { backgroundColor: '#F8FAFC' }]}><Text style={{ width: '25%', fontWeight: 'bold' }}>Payment Method</Text><Text style={{ width: '40%', fontWeight: 'bold' }}>Product / Service Details</Text><Text style={{ width: '20%', fontWeight: 'bold' }}>Reference</Text><Text style={{ width: '15%', textAlign: 'right', fontWeight: 'bold' }}>Amount</Text></View><View style={common.tableRow}><Text style={{ width: '25%' }}>{p.payment_method || 'Account Transfer'}</Text><Text style={{ width: '40%' }}>{p.product_details || 'Payment against deal'}</Text><Text style={{ width: '20%' }}>{p.reference || '—'}</Text><Text style={{ width: '15%', textAlign: 'right' }}>{amount(p.amount, currency)}</Text></View></View>
    <View style={[common.between, { marginTop: 20 }]}><View style={{ width: '50%' }}><Text style={{ color: '#6B7280' }}>Total amount (in words)</Text><Text style={{ fontWeight: 'bold', marginTop: 3 }}>{numberToWords(p.amount, currency)}</Text></View><View style={{ width: '42%', borderTopWidth: 1, borderTopColor: '#D1D5DB', paddingTop: 8 }}><View style={common.between}><Text style={{ fontWeight: 'bold', fontSize: 11 }}>Total Amount</Text><Text style={{ fontWeight: 'bold', fontSize: 12 }}>{amount(p.amount, currency)}</Text></View></View></View>
    {data.issuer?.signature_data_uri ? <View style={{ alignItems: 'flex-end', marginTop: 30 }}><Image src={data.issuer.signature_data_uri} style={common.signature}/><Text style={{ fontSize: 7 }}>Authorized Signatory</Text></View> : null}
    <View style={common.footer} fixed><Text style={common.footerText}>{data.footer || 'This is an electronically generated document; no signature is required.'}</Text></View><Text fixed style={{ position: 'absolute', right: 34, bottom: 38, color: '#64748B', fontSize: 7.2 }} render={({ pageNumber, totalPages }) => `Page ${pageNumber} of ${totalPages}`}/>
  </Page></Document>;
}

function ConsolidatedPdf({ data }: { data: ConsolidatedPayload }) {
  const currency = data.currency || 'INR'; const rows = data.payments || []; const received = n(data.represented_total) || rows.reduce((sum, row) => sum + n(row.amount), 0); const billed = n(data.total_billed) || received; const balance = Math.max(0, billed - received);
  return <Document title={`Consolidated Receipt ${data.receipt_number || ''}`}><Page size="A4" style={[common.page, { padding: 40 }]}>
    <View style={[common.between, { paddingBottom: 15, borderBottomWidth: 1, borderBottomColor: '#D9DEE7' }]}><View><View style={[common.row, { alignItems: 'center' }]}><Text style={{ fontSize: 16, fontWeight: 'bold' }}>Payment Receipt</Text><Text style={{ marginLeft: 8, backgroundColor: '#F59E0B', color: '#FFF', padding: 4, borderRadius: 7, fontSize: 8 }}>Consolidated</Text>{balance === 0 ? <Text style={{ marginLeft: 6, backgroundColor: '#039855', color: '#FFF', padding: 4, borderRadius: 7, fontSize: 8 }}>Paid in Full</Text> : null}</View><Text style={{ marginTop: 9 }}>Receipt No.  <Text style={{ fontWeight: 'bold' }}>{data.receipt_number}</Text></Text><Text style={{ marginTop: 3 }}>Receipt Date  <Text style={{ fontWeight: 'bold' }}>{date(data.receipt_date)}</Text></Text></View>{data.issuer?.logo_data_uri ? <Image src={data.issuer.logo_data_uri} style={common.logo}/> : null}</View>
    <View style={[common.partyRow, { marginTop: 20 }]}><PartyBox label="Issued by" party={data.issuer} theme={themes.classic}/><PartyBox label="Issued to" party={data.customer} theme={themes.classic}/></View>
    <Text style={{ fontSize: 11, fontWeight: 'bold', marginBottom: 8 }}>Payment Summary</Text><View style={{ borderWidth: 1, borderColor: '#DDE3EA' }}><View style={[common.tableHead, { backgroundColor: '#F8FAFC' }]} fixed><Text style={{ width: '15%', fontWeight: 'bold' }}>Date</Text><Text style={{ width: '20%', fontWeight: 'bold' }}>Receipt No.</Text><Text style={{ width: '30%', fontWeight: 'bold' }}>Product / Service</Text><Text style={{ width: '17%', fontWeight: 'bold' }}>Method</Text><Text style={{ width: '18%', textAlign: 'right', fontWeight: 'bold' }}>Amount</Text></View>{rows.map((row, index) => <View key={index} style={common.tableRow} wrap={false}><Text style={{ width: '15%' }}>{date(row.payment_date)}</Text><Text style={{ width: '20%' }}>{row.receipt_number || '—'}</Text><Text style={{ width: '30%' }}>{row.product_details || 'Payment against deal'}</Text><Text style={{ width: '17%' }}>{row.payment_method || '—'}</Text><Text style={{ width: '18%', textAlign: 'right' }}>{amount(row.amount, currency)}</Text></View>)}</View>
    <View style={[common.between, { marginTop: 18 }]}><View style={{ width: '46%' }}><Text style={{ color: '#667085' }}>Total amount received (in words)</Text><Text style={{ fontWeight: 'bold', marginTop: 3 }}>{numberToWords(received, currency)}</Text></View><View style={{ width: '46%' }}>{[['Total Billed Amount', billed], ['Total Amount Received', received], ['Balance Due', balance]].map(([label, value]) => <View key={String(label)} style={[common.between, { paddingVertical: 5, borderBottomWidth: 1, borderBottomColor: '#D0D5DD' }]}><Text style={label === 'Balance Due' ? { fontWeight: 'bold', color: balance ? '#D92D20' : '#00875A' } : {}}>{label}</Text><Text style={{ fontWeight: 'bold', color: label === 'Balance Due' ? (balance ? '#D92D20' : '#00875A') : '#182230' }}>{amount(value as Money, currency)}</Text></View>)}</View></View>
    <Text style={{ marginTop: 20, color: '#667085', fontSize: 8 }}>This consolidated receipt summarizes existing payments only and does not create or recognize additional revenue. Period: {date(data.period_start)} to {date(data.period_end)}.</Text>
    <View style={common.footer} fixed><Text style={common.footerText}>{data.footer || 'This is an electronically generated document; no signature is required.'}</Text></View><Text fixed style={{ position: 'absolute', right: 34, bottom: 38, color: '#64748B', fontSize: 7.2 }} render={({ pageNumber, totalPages }) => `Page ${pageNumber} of ${totalPages}`}/>
  </Page></Document>;
}

async function main() {
  const chunks: Buffer[] = [];
  for await (const chunk of process.stdin) chunks.push(Buffer.from(chunk));
  const payload = JSON.parse(Buffer.concat(chunks).toString('utf8')) as Payload;
  const component = payload.kind === 'document' ? await DocumentPdf({ data: payload }) : payload.kind === 'receipt' ? <ReceiptPdf data={payload}/> : <ConsolidatedPdf data={payload}/>;
  const pdf = await renderToBuffer(component);
  process.stdout.write(pdf);
}

main().catch(error => { process.stderr.write(error instanceof Error ? error.stack || error.message : String(error)); process.exitCode = 1; });
