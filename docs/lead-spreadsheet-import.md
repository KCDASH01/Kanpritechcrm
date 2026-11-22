# Growneq lead spreadsheet import

Business and Enterprise members can upload CSV, XLSX, or BIFF XLS from **Leads → Import Leads**. Managers can read their organization’s import history. Employees can read their own uploads. Only the uploader can validate and confirm an import.

## Data handling

- Each row needs a name, a valid email or phone, and an existing service or product. Import uses the Add Lead request rules and the shared lead creation service. Full Name splits at the first space. The original name and historical status descriptions remain in the lead notes.
- Phone matching uses complete E.164 values. The original phone text, including leading zeros, is stored on the lead. A national number needs a recognized country. Country classification compares the row to the organization’s home country. Supply a default market type when either country is missing or ambiguous.
- Email matching trims whitespace and compares case-insensitively. Tenant or file matches on email or phone are skipped. Website matches are warnings. MySQL organization row locks serialize duplicate checks and inserts across imports. Each batch commits its lead records and row outcomes together.
- Use **Status** for an ordinary CRM status such as Future Lead. **Current Status**, **Call Status**, and **WhatsApp Status** are saved as historical notes. **Assigned to** and **Total Leads** are ignored. Converted, Follow-up, and Meeting statuses require their CRM workflows. The import creates ordinary leads and creation events. It sends no communications.
- Confirmation and background jobs use the server’s validated revision. Repeated confirmation returns the current state. Queued copies cannot change a newer revision, and overlapping copies exit while the active copy holds the lock. The scheduler can recover a dispatch that did not reach the queue. The previous raw JSON import endpoint returns HTTP 410. Callers should use the upload and confirmation workflow.
- The readers never request calculated formula values. A formula or spreadsheet error in a mapped cell invalidates the row. XLSX may contain plain hyperlinks. External workbook resources, embedded media, macros, and encrypted XLSX packages are rejected. CSV reports protect untrusted formula-like text.
- Uploads use the Laravel private local disk. Preview and mapping payloads are encrypted with the application key. Successful imports delete the uploaded file. Maintenance purges expired payloads and files while retaining the counts, outcomes, and lead references for history and auditing.
- Column headings are limited to 255 characters. Long notes are retained up to a combined limit of 60 KB per lead. Rows that exceed the limit receive a validation error. Private JSON responses and downloads use `Cache-Control: private, no-store`.

## API

All routes require authentication under `/api/leads/imports`.

| Method and path | Purpose |
| --- | --- |
| GET / | Paginated history |
| POST / | Upload multipart `file`, inspect worksheets, and suggest mappings |
| GET /template | Download the XLSX sample and instructions |
| GET /{id} | Read details and progress |
| POST /{id}/validate | Save mapping and defaults and queue validation |
| GET /{id}/preview | Paginate and filter preview or result rows |
| POST /{id}/confirm | Queue lead creation for a validated revision |
| GET /{id}/errors | Download the private validation or error report |

`GET /api/leads?import_id={id}` shows the actual lead records created by the import, subject to lead visibility.

## Configuration and release preparation

- Install the locked Composer dependencies and required PHP extensions. PhpSpreadsheet supports legacy XLS and XLSX. The lite libphonenumber package supplies country-aware phone metadata. Enable `intl` for broad country-name recognition.
- Take a database backup and verify the shared admin-dashboard migration on staging. It creates import and row tables and tenant-scoped normalized email and website lookup indexes.
- Set web and PHP request limits to permit the configured file size. Defaults are `LEAD_IMPORT_MAX_KB=10240`, `LEAD_IMPORT_MAX_ROWS=10000`, and `LEAD_IMPORT_BATCH_SIZE=500`. Excel is capped at 20 sheets and 80 columns. The decompressed XLSX limit is 64 MiB.
- Run `php artisan queue:work lead-imports --queue=lead-imports --timeout=840 --tries=6`. The connection uses the existing database queue table with a 1,200-second retry window. Keep the existing worker for the default queue. Use shared private storage, a shared cache for job locks, and the same application key when workers run across multiple hosts. `LEAD_IMPORT_QUEUE_CONNECTION` overrides the default import connection.
- Keep the Laravel scheduler running for `lead-imports:maintain`. `LEAD_IMPORT_RETENTION_DAYS` defaults to seven days for uploaded files, row payloads, and mapping details.
- On staging, verify the worker, scheduler, app key, and storage permissions with a synthetic workbook and both an employee and a manager account. Use normal production error settings with debug disabled. Deployment requires approval.
- To roll back, pause new imports, let in-flight jobs finish, and then stop the import worker. Preserve the schema and data for auditing when reverting the application. The migration's `down()` drops the import history tables and lookup columns and indexes. Use it only with separate approval and a backup of history you need to retain.

## Dependency review

The CRM API production Composer lockfile audit initially reported 49 advisories. Scoped compatible updates resolved 48. `guzzlehttp/promises` changed from 2.3.0 to 2.6.2 and `laravel/prompts` changed from 0.3.17 to 0.3.27 to satisfy the patched packages. The PHP 8.3 platform check passed.

| Production dependency | Previously locked | Locally locked after review | Highest reported severity before review | Exposure |
| --- | --- | --- | --- | --- |
| `laravel/framework` | 13.7.0 | 13.43.0 | Critical | Shared authentication, routing, and validation runtime |
| `guzzlehttp/guzzle` | 7.10.0 | 7.16.5 | High | Outbound HTTP integrations |
| `guzzlehttp/psr7` | 2.9.0 | 2.13.5 | Medium | HTTP messages and streams |
| `league/commonmark` | 2.8.2 | 2.10.7 | High | Markdown rendering in the application runtime |
| `league/flysystem` | 3.33.0 | 3.36.0 | Low | Private import file storage |
| `symfony/http-foundation` | 7.4.8 | 7.4.23 | High | HTTP requests, uploaded files, and streamed responses |
| `symfony/http-kernel` | 7.4.8 | 7.4.23 | High | HTTP request handling |
| `symfony/mailer` | 7.4.8 | 7.4.22 | Medium | Outbound mail in the application |
| `symfony/mime` | 7.4.8 | 7.4.22 | High | File MIME detection and mail handling |
| `symfony/polyfill-intl-idn` | 1.37.0 | 1.43.0 | Low | Internationalized domain handling |
| `symfony/routing` | 7.4.8 | 7.4.23 | Medium | Application routing |
| `firebase/php-jwt` | 6.11.1 | 6.11.1 | Low | SSO token verification. The first patched release is 7.0.0. |

The remaining Composer advisory is [CVE-2025-45769](https://github.com/advisories/GHSA-2x45-7fc3-mxwq). A JWT 7 upgrade needs separate SSO compatibility checks. Import endpoints use existing Sanctum authentication.

The original import review identified critical Next.js 14.2.4 image optimizer advisories. The local frontend remediation and its deployment prerequisites are documented separately. [CRM frontend dependency remediation](nextjs-security-remediation.md)

## Tests

Run `LeadSpreadsheetImportTest` with in-memory SQLite. Set `GROWNEQ_RUN_IMPORT_BENCHMARK=1` to measure indexed CSV and representative XLSX fixtures. The synthetic benchmark includes 5% existing duplicates and runs the worker inline. Queue scheduling, database latency, and workbook complexity affect production throughput.

The focused Laravel suite passed 73 tests with 675 assertions. Its nine opt-in cases cover seven benchmark runs, which passed separately, and two dedicated MySQL ENUM fixtures, which were skipped in this review. The final spreadsheet and database worker regressions passed with 72 assertions. A separate fresh migration run on local MySQL 8.4.3 applied the shared admin-dashboard chain. A two-worker MySQL test imported the same normalized contact concurrently and produced one lead, one duplicate outcome, and one timeline entry. The database queue and interrupted-batch tests verified retries preserve previously committed leads and events.

The Next.js production build, TypeScript check, lead management UI checks, Assistant UI checks, and subscription status tests passed locally. Headless browser tests with synthetic API responses exercised the wizard and verified the desktop entry, mobile upload, and expired-plan route guard against the final build.

| Format | Rows | Existing duplicates skipped | Validation | Import | Peak PHP memory |
| --- | ---: | ---: | ---: | ---: | ---: |
| CSV | 100 | 5 | 0.69 s | 0.36 s | 68 MiB |
| CSV | 500 | 25 | 2.73 s | 1.56 s | 70 MiB |
| CSV | 1,000 | 50 | 5.53 s | 2.70 s | 70 MiB |
| CSV | 5,000 | 250 | 42.69 s | 28.25 s | 72 MiB |
| CSV | 10,000 | 500 | 110.62 s | 85.35 s | 74 MiB |
| XLSX | 1,000 | 50 | 12.73 s | 7.48 s | 76 MiB |
| XLSX | 10,000 | 500 | 242.21 s | 117.56 s | 124 MiB |
