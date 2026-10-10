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
5. Set the Webhooks callback to `https://api.example.com/api/webhooks/meta`, with the configured verification token, and subscribe the authorized Page to the `leadgen` field.
6. In CRM, open **Lead Integrations → Meta Ads**, connect the account, and confirm the required Page appears as Active.
7. Register campaign IDs and routing rules before enabling automatic import. Use history sync only with a form ID and a date range the API still permits.

An ad-account grant does not by itself grant access to Page forms or leads. A connection is only shown as active after OAuth returns an authorized Page token.

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
3. Exact campaign, ad, form, alias, mailbox, or authorized-sender rule by priority.
4. Concurrency-safe department round robin (API supports `department_id`).
5. Default owner from Automation Settings.
6. Review Queue.

Inactive users are skipped and existing lead owners are never overwritten by an integration event.

## WhatsApp status

WhatsApp remains disabled until official WhatsApp Business Platform credentials, number eligibility, Coexistence support, and charges are verified. Do not migrate or disconnect the existing WhatsApp Business App number. Once verified, configure the official webhook at `/api/webhooks/whatsapp`; only events with reliable ad referral metadata are treated as Click-to-WhatsApp enquiries.

## Daily use

- **Overview:** monitor received, assigned, won, and review counts. Revenue is read from existing deal payments and kept separated by currency.
- **Meta Ads / Email Accounts:** connect or disconnect provider authorization. Disconnecting stops imports and preserves history.
- **Email Campaigns:** register external campaign identity and the responsible employee.
- **Lead Routing:** create exact source mappings. Lower priority numbers run first.
- **Import History:** inspect every accepted, linked, ignored, review, or failed provider event.
- **Automation Settings:** enable only configured sources, choose the default owner, and set raw-content retention.
- **Existing Leads:** employees continue working leads using the normal Leads page and existing permissions.

## Deployment and rollback

1. Back up the database.
2. Deploy backend and frontend code, add secrets to the server environment, then clear/rebuild Laravel config cache.
3. Run the new `2026_10_10_000002_create_lead_integrations_module.php` migration once from the canonical deployment application.
4. Restart queue workers and confirm `php artisan schedule:list` contains `lead-integrations:maintain`.
5. Build and deploy the Next.js output, then verify OAuth callbacks and webhook signatures with real authorized test assets before enabling imports.

Rollback: disable all integrations first, stop integration workers, roll back only this migration batch if it contains no unrelated migrations, and revert the module code. The migration drops only the eight new integration tables; existing leads, clients, deals, payments, targets, and activity data are untouched.
