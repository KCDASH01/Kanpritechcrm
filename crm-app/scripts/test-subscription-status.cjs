const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const ts = require('typescript');

const source = fs.readFileSync(path.join(__dirname, '..', 'src', 'lib', 'subscriptionStatus.ts'), 'utf8');
const output = ts.transpileModule(source, {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2020 },
}).outputText;
const loaded = { exports: {} };
new Function('module', 'exports', 'require', output)(loaded, loaded.exports, require);
const { formatSubscriptionAmount, formatSubscriptionDate, getSubscriptionStatusPresentation } = loaded.exports;

const base = {
  id: 1,
  plan: 'business',
  subscription_source: 'internal',
  status: 'active',
  is_active: true,
  start_date: null,
  end_date: null,
  is_expired: false,
  amount: 19990,
  currency: 'INR',
};

function notice(overrides, surface = 'dashboard') {
  return getSubscriptionStatusPresentation({ ...base, ...overrides }, { surface, now: new Date('2026-11-05T00:00:00Z') });
}

assert.equal(formatSubscriptionAmount(base), '₹19,990');
assert.equal(formatSubscriptionDate('2026-11-11T18:30:00.000Z', 'INR'), '12 November 2026');

const authorizedTrial = notice({
  status: 'trialing', lifecycle_state: 'trial_authorized', authorization_state: 'authorized',
  renewal_mode: 'automatic', authorized_at: '2026-11-05T00:00:00Z', provider_status: 'authenticated',
  next_charge_at: '2026-11-11T18:30:00.000Z',
});
assert.equal(authorizedTrial.title, 'Your Business free trial is active.');
assert.match(authorizedTrial.description, /₹19,990/);
assert.match(authorizedTrial.description, /12 November 2026/);
assert.equal(authorizedTrial.action, undefined);

const pendingTrial = notice({
  status: 'trialing', is_active: false, lifecycle_state: 'trial_pending_authorization',
  authorization_state: 'pending', renewal_mode: 'pending', can_resume_authorization: true,
});
assert.equal(pendingTrial.action.href, '/start-trial');
assert.match(pendingTrial.description, /not enabled yet/);

const active = notice({
  lifecycle_state: 'active_auto_renew', authorization_state: 'authorized', renewal_mode: 'automatic',
  next_charge_at: '2026-12-11T18:30:00.000Z',
});
assert.match(active.title, /renews automatically/);
assert.equal(active.action, undefined);

const cancellation = notice({
  lifecycle_state: 'cancellation_pending', cancel_requested_at: '2026-11-05T00:00:00Z',
  renewal_mode: 'none', access_until: '2026-12-11T18:30:00.000Z',
});
assert.match(cancellation.description, /Automatic renewal is disabled/);
assert.equal(cancellation.action, undefined);

const cancelledTrial = notice({
  status: 'trialing', lifecycle_state: 'cancellation_pending', cancel_requested_at: '2026-11-05T00:00:00Z',
  renewal_mode: 'none', access_until: '2026-11-11T18:30:00.000Z',
});
assert.match(cancelledTrial.title, /trial has been cancelled/);

const grace = notice({ lifecycle_state: 'grace_period', grace_ends_at: '2026-11-08T18:30:00.000Z' });
assert.match(grace.title, /could not process/);
assert.equal(grace.action, undefined);

const expired = notice({ lifecycle_state: 'expired', status: 'expired', is_active: false, is_expired: true });
assert.equal(expired.action.href, '/plans');

const freeDashboard = notice({ plan: 'free', lifecycle_state: 'free', amount: undefined, currency: undefined });
const freeBilling = notice({ plan: 'free', lifecycle_state: 'free', amount: undefined, currency: undefined }, 'billing');
assert.equal(freeDashboard, null);
assert.match(freeBilling.title, /Free plan/);

const unknown = notice({ lifecycle_state: 'unknown', status: 'pending_authorization' });
assert.equal(unknown, null);
assert.match(notice({ lifecycle_state: 'unknown', status: 'pending_authorization' }, 'billing').title, /synchronizing/);

console.log('Subscription status UI tests passed: 10 scenarios.');
