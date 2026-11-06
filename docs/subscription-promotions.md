# Subscription promotions: architecture and release guide

## Repository audit

Growneq creates recurring subscriptions in `crm-api` through `SubscriptionLifecycleService` and `RazorpaySubscriptionGateway`. A seven-day trial is represented by a future Razorpay `start_at`. Checkout authorization is verified server-side, and subscription/payment events are reconciled by `SubscriptionWebhookService`. Shared database migrations are executed by `admin-dashboard`; the file in `crm-api/database/migrations` is only a mirror that requires the canonical migration.

The customer checkout is `crm-app/src/app/(auth)/start-trial/page.tsx`. Super Admin is the separate Blade application under `admin-dashboard`. Existing plan IDs and subscription rows are not changed by this module.

## Razorpay compatibility decision

Razorpay documents offers for subscriptions in its Dashboard flow, including linking an offer when creating a subscription and applying offer changes at the end of an active billing cycle. The public API documentation available during implementation did not clearly guarantee all subscription-offer duration and recurring payment-method combinations.

Therefore Growneq does not create offers or discounted plans automatically. A campaign is usable at checkout only when all of these are true:

1. `SUBSCRIPTION_PROMOTIONS_ENABLED=true`.
2. `RAZORPAY_SUBSCRIPTION_OFFERS_ENABLED=true` after account/API validation.
3. The campaign is active and eligible.
4. The selected plan-price mapping contains a manually approved `offer_...` ID.
5. Razorpay returns the same offer ID during authorization and charge reconciliation.

Any missing or mismatched condition fails before access is granted. Growneq never silently falls back to the undiscounted price after accepting a coupon.

First-cycle, limited-cycle and recurring business rules are represented locally. They must be matched to an equivalent Razorpay subscription offer. If the merchant account or payment method cannot enforce that duration, the mapping must remain empty and activation is blocked. Orders API offers must not be reused on the assumption that they behave like Subscription offers.

## Data model

- `promotion_campaigns`: campaign rules, schedule, state, audience, duration, limits and integer discount values.
- `promotion_campaign_plan_prices`: exact plan/currency eligibility and approved Razorpay offer mapping.
- `promotion_campaign_customers`: selected-organization eligibility.
- `promotion_redemptions`: atomic reservation and aggregate lifecycle.
- `promotion_redemption_applications`: immutable per-payment/cycle applications and refunds.
- `promotion_audit_events`: administrator and reconciliation audit trail.

Percentage values use basis points. Money uses integer currency subunits. Unique idempotency and gateway-payment indexes prevent double consumption. Row locks protect redemption limits.

`LAUNCH20` is inserted as a disabled **draft**, mapped to the four active INR plan prices with no gateway offer IDs. It cannot be used or activated until approved mappings are entered.

## Feature flags

```dotenv
SUBSCRIPTION_PROMOTIONS_ENABLED=false
RAZORPAY_SUBSCRIPTION_OFFERS_ENABLED=false
PROMOTION_RESERVATION_MINUTES=30
```

Keep both flags false during migration and UI review. Enabling the local flag exposes validation only for active campaigns. Enable the Razorpay adapter only after Test Mode proves the expected first charge, renewal charge, refund behavior and supported payment methods.

## Safe deployment

1. Back up the database and confirm the current subscription migration is present.
2. Deploy backend, admin and frontend code with both flags false.
3. Execute the canonical migration **once from `admin-dashboard`**. Do not execute the mirrored `crm-api` migration against the same database.
4. Confirm LAUNCH20 is `draft`, has four INR mappings, and has no offer IDs.
5. Validate admin create/edit/duplicate/audit screens.
6. In Razorpay Test Mode, create/approve test subscription offers outside Growneq and enter their IDs.
7. Test monthly and annual checkout, authorization, first charge, normal renewal, failure/retry, duplicate webhook, cancellation and refund.
8. Enable `SUBSCRIPTION_PROMOTIONS_ENABLED` in staging. Enable the Razorpay adapter only after the provider tests pass.
9. Obtain explicit approval before any live offer is created or either flag is enabled in production.

The scheduler must continue running so `promotions:sync-states` can activate scheduled campaigns, expire ended campaigns and release stale reservations.

## Rollback

Disable both flags first. Existing subscriptions continue on their unchanged Razorpay plans. Do not roll back the migration after promotions have been used; preserve redemption/audit history. A code rollback is safe with the tables left in place because the pre-existing subscription paths ignore them while the flags are false. If the module has never been enabled or used, the migration `down()` removes only promotion tables.

## Known approval dependencies

- Razorpay must confirm Subscription API offer support for the merchant account, chosen recurring payment methods, trial-delayed first charge, and each requested duration.
- Approved Razorpay offer IDs must be created and verified manually; this implementation never creates live offers.
- Stacking more than one local campaign requires a provider-side combined offer. Until such an offer is explicitly mapped and tested, checkout supports one enforceable provider offer per subscription.
- Taxes remain Razorpay/provider authoritative and are displayed as additional to the quoted pre-tax subscription amount.
