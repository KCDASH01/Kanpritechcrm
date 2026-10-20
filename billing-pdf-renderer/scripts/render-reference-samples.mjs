import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { spawn } from 'node:child_process';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const renderer = join(root, 'dist', 'render.mjs');
const outputDirectory = resolve(root, '..', 'tmp', 'pdfs', 'generated');
await readFile(renderer);
await mkdir(outputDirectory, { recursive: true });

const issuer = {
  legal_name: 'Kanpri Technologies Private Limited', trade_name: 'Growneq',
  registered_address: 'Bhubaneswar, Odisha 751001', country: 'India',
  gstin: '21ABCDE1234F1Z5', pan: 'ABCDE1234F', contact_phone: '+91 98765 43210',
  contact_email: 'billing@example.test', website: 'www.growneq.com',
};
const customer = {
  company_name: 'Acme Business Solutions Private Limited', contact_name: 'Ananya Sharma',
  billing_address: '45 Business Park, Bengaluru, Karnataka 560001', country: 'India',
  gstin: '29ABCDE5678G1Z2', contact_phone: '+91 91234 56789', contact_email: 'accounts@acme.example',
};
const items = [
  { description: 'Growneq CRM implementation and onboarding', hsn_sac: '998314', quantity: 1, unit: 'service', unit_price: 100000, taxable_amount: 100000, gst_rate: 18, cgst_amount: 9000, sgst_amount: 9000, igst_amount: 0, line_total: 118000 },
  { description: 'Team training and production handover', hsn_sac: '998314', quantity: 2, unit: 'session', unit_price: 10000, taxable_amount: 20000, gst_rate: 18, cgst_amount: 1800, sgst_amount: 1800, igst_amount: 0, line_total: 23600 },
];
const documentBase = {
  kind: 'document', template: 'classic', issue_date: '2026-10-20', due_date: '2026-11-04', currency: 'INR',
  issuer, customer, items, subtotal: 120000, discount_total: 0, taxable_total: 120000,
  cgst_total: 10800, sgst_total: 10800, igst_total: 0, rounding_adjustment: 0,
  grand_total: 141600, notes: 'Thank you for choosing Growneq CRM.',
  terms: 'Payment is due within fifteen days. Please quote the document number when making payment.',
  footer: 'This is an electronically generated document.', payment_link: 'https://example.test/pay/INV-029',
};

const samples = [
  ['growneq-proforma-reference.pdf', { ...documentBase, document_type: 'proforma', document_number: '2026-27/PI-029' }],
  ['growneq-tax-invoice-reference.pdf', { ...documentBase, document_type: 'invoice', document_number: '2026-27/INV-029' }],
  ['growneq-payment-receipt-reference.pdf', {
    kind: 'receipt', receipt_number: '2026-27/RCP-063', receipt_date: '2026-10-20', currency: 'INR', issuer, customer,
    payment: { payment_date: '2026-10-20', payment_method: 'Bank Transfer', reference: 'UTR123456789', product_details: 'Growneq CRM implementation and onboarding', amount: 80000 },
    footer: 'This receipt acknowledges the existing payment shown above.',
  }],
  ['growneq-consolidated-receipt-reference.pdf', {
    kind: 'consolidated_receipt', receipt_number: '2026-27/CR-029', receipt_date: '2026-10-20', period_start: '2026-10-10', period_end: '2026-10-20', currency: 'INR', issuer, customer,
    invoice_number: '2026-27/INV-029', payment_status: 'partially_paid', total_billed: 141600, represented_total: 100000,
    payments: [
      { receipt_number: '2026-27/RCP-061', payment_date: '2026-10-10', product_details: 'Growneq CRM implementation', payment_method: 'UPI', amount: 20000 },
      { receipt_number: '2026-27/RCP-063', payment_date: '2026-10-20', product_details: 'Growneq CRM implementation and onboarding', payment_method: 'Bank Transfer', amount: 80000 },
    ],
    footer: 'This consolidated receipt summarizes existing allocations and does not create additional revenue.',
  }],
];

const render = (payload) => new Promise((resolvePromise, reject) => {
  const child = spawn(process.execPath, [renderer], { stdio: ['pipe', 'pipe', 'pipe'] });
  const stdout = []; const stderr = [];
  child.stdout.on('data', (chunk) => stdout.push(chunk));
  child.stderr.on('data', (chunk) => stderr.push(chunk));
  child.on('error', reject);
  child.on('close', (code) => code === 0 ? resolvePromise(Buffer.concat(stdout)) : reject(new Error(Buffer.concat(stderr).toString('utf8'))));
  child.stdin.end(JSON.stringify(payload));
});

for (const [filename, payload] of samples) await writeFile(join(outputDirectory, filename), await render(payload));
process.stdout.write(`${samples.length} reference QA PDFs written to ${outputDirectory}\n`);
