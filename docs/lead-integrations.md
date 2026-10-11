# Lead Integrations setup and operating guide

The Lead Integrations module stores imported people in the existing `leads` table. Provider events, routing decisions, attribution, and review records live in separate additive tables. It never auto-creates deals, changes lead stages, or updates revenue and targets.

## Required server configuration

Set only the providers the business intends to use. Never expose these values through `NEXT_PUBLIC_*` variables.

```text
FRONTEND_URL=https://growneq.com
META_APP_ID=
META_APP_SECRET=
META_GRAPH_VERSION=
META_WEBHOOK_VERIFY_TOKEN=
META_REDIRECT_URI=https://api.example.com/api/lead-integrations/oauth/meta/callback
WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID=
WHATSAPP_REDIRECT_URI=https://api.example.com/api/lead-integrations/oauth/whatsapp/callback
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=https://api.example.com/api/lead-integrations/oauth/google/callback
GMAIL_PUBSUB_TOPIC=projects/your-project/topics/your-topic
GMAIL_PUBSUB_VERIFICATION_TOKEN=
MICROSOFT_CLIENT_ID=
MICROSOFT_CLIENT_SECRET=
MICROSOFT_TENANT=common
MICROSOFT_REDIRECT_URI=https://api.example.com/api/lead-integrations/oauth/microsoft/callback
MICROSOFT_NOTIFICATION_URL=https://api.example.com/api/webhooks/microsoft/mail
```

Run a persistent Laravel queue worker and the Laravel scheduler. The scheduler queues mailbox reconciliation every ten minutes and removes only expired raw integration payloads according to each organization’s retention setting. It never deletes imported leads or attribution.

## Meta Business setup

1. Create/configure a Meta app owned by the verified business.
2. Configure the exact OAuth redirect URI above.
3. Request only the module scopes: `pages_show_list`, `pages_manage_metadata`, `leads_retrieval`, and `ads_read` (the last is used only for authorized reporting metadata).
4. Complete any Meta App Review, business verification, data-use checks, and Page authorization that Meta requires for the account.
5. Set the Webhooks callback to `https://api.example.com/api/webhooks/meta`, with the configured verification token.
6. In CRM, open **Lead Integrations → Meta Ads**, connect the account, select the authorized Pages/forms/ad accounts and choose **Complete Setup**. Growneq subscribes selected Pages to `leadgen`.
7. Add routing rules before enabling automatic import. Historical import uses forms discovered through the authorized account and a date range the API still permits.

An ad-account grant does not by itself grant access to Page forms or leads. A connection is only active after at least one discovered asset is selected. Missing assets must be granted in Meta Business Settings; entering an ID cannot bypass provider access.

## Email setup

### Gmail / Google Workspace

Enable the Gmail API, configure the OAuth consent screen and redirect URI, and grant the read-only Gmail scope. Create the configured Pub/Sub topic, grant the Gmail push service account publisher access, and configure the push subscription endpoint as `/api/webhooks/google/gmail?token=...`. Click **Connect account** under Email Accounts. The worker renews the Gmail watch before expiry and also reconciles periodically.

### Microsoft 365

Register an Entra ID application with the exact redirect URI, notification URL, and delegated `User.Read`, `Mail.Read`, and `offline_access` permissions. Click **Connect account**. The worker renews Microsoft Graph subscriptions and also reconciles periodically. No mailbox password is stored.

### Forwarded qualified leads

Create an approved inbound email connection in **Email Accounts** and list the allowed senders and receiving aliases. Configure the approved inbound email service to POST to `/api/webhooks/email/inbound` using the connection-specific value as `X-Integration-Secret`. Structured fields use `Label: Value` lines. Incomplete or unauthorized messages enter Review rather than bypassing CRM validation.

## Email campaign attribution

Register the external campaign under **Email Campaigns**, select its responsible employee, and import legitimate recipient records with outbound Message-ID or provider thread ID. A subject line is never sufficient. Replies without a reliable recipient/message/thread match enter Review. Bounce, out-of-office, unsubscribe, and not-interested replies are recorded but do not create a qualified lead.

## Routing order

1. Existing lead ownership.
2. Registered campaign owner.
3. Exact campaign, provider, country, service, ad, form, alias, mailbox, or authorized-sender rule by priority.
4. Concurrency-safe round robin across selected organization users or a department.
5. Default owner from Automation Settings.
6. Review Queue.

Inactive users are skipped and existing lead owners are never overwritten by an integration event.

## WhatsApp status

The customer flow uses official Meta Embedded Signup. It becomes available only after the platform operator configures an approved Embedded Signup configuration and callback. The customer authorizes a WhatsApp Business Account and selects an eligible phone number. Do not migrate or disconnect an existing WhatsApp Business App number unless Meta's supported flow explicitly requires and confirms it. Configure the official webhook at `/api/webhooks/whatsapp`; only events with reliable ad referral metadata are treated as Click-to-WhatsApp campaign enquiries. Provider verification, messaging windows, approved templates and fees still apply.

## Self-service connection lifecycle

All supported providers use the same customer flow: **Choose → Authorize → Select assets → Confirm behavior → Complete Setup**. Owners/admins can manage selected assets, test health, pause/resume synchronization, reconnect through OAuth, or disconnect without deleting imported CRM history. Tokens and per-asset Page tokens are encrypted using Laravel encrypted casts and are never returned by the API.

Connection audits record authorization, asset selection, health tests, pause/resume and disconnect actions. Provider webhooks resolve tenants through selected provider assets rather than accepting a tenant ID from the browser or payload.

## Automation safety

Guided automation templates reuse the existing import, deduplication, routing, review and notification pipeline. Active templates execute once per integration event through `integration_automation_runs`. Follow-up creation uses the existing `activities` table; repeated webhook delivery cannot create the same follow-up twice. **Run safe test** is a dry run and never sends a message or changes CRM records.

## Daily use

- **Overview:** monitor received, assigned, won, and review counts. Revenue is read from existing deal payments and kept separated by currency.
- **Meta Ads / WhatsApp Ads / Email Accounts:** authorize on the provider website, select accessible assets, and complete setup. Disconnecting stops imports and preserves history.
- **Email Campaigns:** create a safe campaign draft, import trusted recipient identities, or use **Advanced → Register External Campaign**. Sending remains unavailable unless an authorized provider adapter supports it.
- **Lead Routing:** use plain-language provider/campaign/country/service rules and preview the result. Lower priority numbers run first.
- **Import History:** filter activity, retry eligible failures, and export a credential-free CSV summary.
- **Automation Settings:** enable guided templates; advanced source toggles and raw field mappings remain behind progressive disclosure.
- **Existing Leads:** employees continue working leads using the normal Leads page and existing permissions.

## Deployment and rollback

1. Back up the database.
2. Deploy backend and frontend code, add secrets to the server environment, then clear/rebuild Laravel config cache.
3. Run the existing `2026_10_10_000002_create_lead_integrations_module.php`, followed by additive `2026_10_11_000001_enhance_lead_integrations_self_service.php`, once from the canonical deployment application. Do not run both application copies against the same database.
4. Restart queue workers and confirm `php artisan schedule:list` contains `lead-integrations:maintain`.
5. Build and deploy the Next.js output, then verify OAuth callbacks and webhook signatures with real authorized test assets before enabling imports.

Rollback: pause all integrations first, stop integration workers, and revert the code. If the additive enhancement migration must be rolled back, use a targeted migration rollback only after backing up the database and confirming the batch contains no unrelated migrations. It removes connection assets, audits, automation definitions/runs and the added health columns; it does not delete existing leads, clients, deals, payments, targets, or activities. Rolling back the original module migration additionally removes the original eight integration tables.

## Capability status

- **Implemented and locally tested:** shared wizard, Meta asset discovery/selection, Page `leadgen` subscription, Gmail and Microsoft OAuth return flow, connection health/lifecycle, organization-scoped asset mapping, history filters/retry/export, routing conditions/preview, idempotent guided automation execution, responsive eight-tab UI.
- **Implemented but awaiting platform configuration:** live Meta, Gmail, Microsoft and WhatsApp authorization; webhook delivery; Gmail Pub/Sub and Microsoft subscription renewal.
- **Awaiting provider approval where applicable:** Meta App Review/advanced permissions, business verification, Page Lead Access, WhatsApp Embedded Signup/Tech Provider eligibility, Google OAuth verification, Microsoft tenant consent.
- **Not advertised as operational:** Mailchimp/Brevo/SendGrid/Mailgun/SES campaign discovery or sending, rich email authoring, bulk CSV/XLSX campaign sending, and custom IMAP/SMTP password storage. The current campaign builder creates attribution-safe drafts/registries only.
