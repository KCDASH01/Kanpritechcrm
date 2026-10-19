import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { spawn } from 'node:child_process';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const renderer = join(root, 'dist', 'render.mjs');
const outputDirectory = resolve(root, '..', 'tmp', 'pdfs');
const templates = ['classic', 'modern', 'minimal', 'corporate', 'executive'];

await readFile(renderer);
await mkdir(outputDirectory, { recursive: true });

const items = Array.from({ length: 24 }, (_, index) => {
  const quantity = (index % 3) + 1;
  const unitPrice = 8500 + (index * 725);
  const taxableAmount = quantity * unitPrice;
  const gstRate = index % 2 === 0 ? 18 : 12;
  const gst = taxableAmount * gstRate / 100;

  return {
    description: `Implementation milestone ${index + 1} - responsive CRM delivery, configuration, quality assurance, training and production handover with a deliberately long description for wrapping verification`,
    details: `Acceptance criteria batch ${index + 1}; includes documentation and post-delivery support.`,
    hsn_sac: index % 2 === 0 ? '998314' : '998313',
    quantity,
    unit: 'service',
    unit_price: unitPrice,
    discount_type: 'percentage',
    discount_value: index % 4 === 0 ? 5 : 0,
    discount_amount: index % 4 === 0 ? taxableAmount * 0.05 : 0,
    taxable_amount: taxableAmount,
    gst_rate: gstRate,
    cgst_amount: gst / 2,
    sgst_amount: gst / 2,
    igst_amount: 0,
    line_total: taxableAmount + gst,
  };
});

const subtotal = items.reduce((sum, item) => sum + item.taxable_amount, 0);
const discountTotal = items.reduce((sum, item) => sum + item.discount_amount, 0);
const cgstTotal = items.reduce((sum, item) => sum + item.cgst_amount, 0);
const sgstTotal = items.reduce((sum, item) => sum + item.sgst_amount, 0);
const grandTotal = subtotal - discountTotal + cgstTotal + sgstTotal;

for (const template of templates) {
  const payload = {
    kind: 'document',
    template,
    document_type: 'proforma',
    document_number: `PI/2026/${template.toUpperCase()}/0042`,
    issue_date: '2026-10-20',
    due_date: '2026-11-04',
    currency: 'INR',
    issuer: {
      legal_name: 'Kanpri Technologies Private Limited',
      trade_name: 'Growneq CRM',
      registered_address: 'Technology Business Centre, Main Road',
      city: 'Bhubaneswar',
      state: 'Odisha',
      postal_code: '751001',
      country: 'India',
      gstin: '21ABCDE1234F1Z5',
      pan: 'ABCDE1234F',
      contact_phone: '+91 98765 43210',
      contact_email: 'billing@example.test',
    },
    customer: {
      company_name: 'Acme International Services and Solutions Private Limited',
      contact_name: 'Ananya Sharma',
      billing_address: '45 Long Business Park, Phase Two, Industrial Estate',
      city: 'Bengaluru',
      state: 'Karnataka',
      postal_code: '560001',
      country: 'India',
      gstin: '29ABCDE5678G1Z2',
      contact_phone: '+91 91234 56789',
      contact_email: 'accounts@acme.example',
    },
    bank: {
      account_name: 'Kanpri Technologies Private Limited',
      bank_name: 'Example Bank',
      account_number: '001234567890',
      ifsc: 'EXAM0000123',
      swift: 'EXAMPINBXXX',
      upi_id: 'growneq@example',
    },
    items,
    subtotal,
    discount_total: discountTotal,
    taxable_total: subtotal - discountTotal,
    cgst_total: cgstTotal,
    sgst_total: sgstTotal,
    igst_total: 0,
    rounding_adjustment: 0.25,
    grand_total: grandTotal + 0.25,
    notes: 'Thank you for choosing Growneq CRM. Please quote this document number when arranging payment.',
    terms: 'This proforma invoice is valid for fifteen days. Work begins after payment confirmation. Taxes are calculated from the supplied place-of-supply data. This is not a tax invoice.',
    footer: 'Growneq CRM - billing@example.test - +91 98765 43210',
    payment_link: 'https://example.test/pay/PI-0042',
  };

  const pdf = await new Promise((resolvePromise, reject) => {
    const child = spawn(process.execPath, [renderer], { stdio: ['pipe', 'pipe', 'pipe'] });
    const stdout = [];
    const stderr = [];
    child.stdout.on('data', chunk => stdout.push(chunk));
    child.stderr.on('data', chunk => stderr.push(chunk));
    child.on('error', reject);
    child.on('close', code => code === 0
      ? resolvePromise(Buffer.concat(stdout))
      : reject(new Error(Buffer.concat(stderr).toString('utf8'))));
    child.stdin.end(JSON.stringify(payload));
  });

  await writeFile(join(outputDirectory, `billing-${template}-qa.pdf`), pdf);
}

process.stdout.write(`${templates.length} QA PDFs written to ${outputDirectory}\n`);
