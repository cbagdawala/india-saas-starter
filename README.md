# India SaaS Starter Kit

[![CI](https://github.com/cbagdawala/india-saas-starter/actions/workflows/ci.yml/badge.svg)](https://github.com/cbagdawala/india-saas-starter/actions/workflows/ci.yml)
[![Licence: MIT](https://img.shields.io/badge/licence-MIT-blue.svg)](LICENSE)
[![Buy Me a Coffee](https://img.shields.io/badge/Buy%20Me%20a%20Coffee-donate-FFDD00?logo=buymeacoffee&logoColor=black)](https://buymeacoffee.com/cbagdawala)

A working Laravel 13 SaaS skeleton for the Indian market: Vue 3.5 + TypeScript SPA with PrimeVue 4 and Tailwind 4, Sanctum cookie auth,
Razorpay (test-mode) subscription billing, support inbox, blog/CMS, A/B landing page, onboarding, health probe, optional error reporting.
Modular backend (`app/Modules/*`), Pest 4 tests, Docker Compose stack and CI.

Status: v0.3 (extracted, standalone): workspace multi-tenancy, email verification, GST tax invoices and UPI payment links. E-invoicing (IRN/IRP) and GSTR filing are **not** included (see Future work).
Licence: [MIT](LICENSE). Free to use, modify and sell products built on it.

> **Not professional advice.** The GST invoicing, UPI, data-protection and other tax or legal features are engineering aids with documented assumptions, not tax, legal or accounting advice. Have a Chartered Accountant and a lawyer confirm them for your business before you issue real invoices or process personal data (see "Needs CA confirmation" and "Data protection"). The software is provided as is, without warranty (see `LICENSE`).

## What is included

| Area | Where |
|---|---|
| Auth (register/login/logout/me, cookie session) | `app/Modules/Auth`, `POST /api/auth/{register,login,logout}`, `GET /api/auth/me` |
| Billing (gateway interface, Razorpay + fake gateway, plans, subscriptions, webhook) | `app/Modules/Billing`, `config/billing.php`, `/api/billing/*` |
| Health probe | `GET /health` returns `{"status":"ok"}` (503 + `degraded` when the DB is down). Details with header `X-Health-Token` = `HEALTH_TOKEN` (>= 32 chars): db, cache, queue, scheduler heartbeat, build SHA |
| Landing page + A/B variants | `app/Modules/Landing`, `GET /`, `config/landing.php`, `landing_variants`, `LandingVariantSeeder` |
| Blog / CMS + SEO | `app/Modules/Content`, `/blog`, `/blog/{slug}`, `/sitemap.xml`, `/robots.txt`, `ArticleSeeder` |
| Support inbox + contact form | `app/Modules/Support`, `/api/support/tickets`, optional Brevo inbound-parse webhook `POST /api/support/inbound`, SPA `/support` |
| Onboarding | `app/Modules/Onboarding`, `config/onboarding.php`, SPA `/welcome` |
| Invoicing (GST tax invoices, credit notes, PDF, UPI) | `app/Modules/Invoicing`, `config/invoicing.php`, `/api/invoices`, `/api/billing-profile`, SPA `/invoices`, `/billing-profile` |
| Multi-tenancy (workspaces, members, invitations) | `app/Modules/Tenancy`, `config/tenancy.php`, `/api/tenants`, `/api/tenant/*`, SPA `/members` |
| Notes (example CRUD module with policy, workspace-scoped) | `app/Modules/Notes`, `/api/notes` |
| Error reporting (optional) | `sentry/sentry-laravel` (GlitchTip or Sentry); empty `SENTRY_LARAVEL_DSN` = off; `php artisan app:error-report-test` |
| SPA shell | `resources/js` (Login, Register, Dashboard placeholder, Billing, Support, Onboarding) |
| Docker | `docker-compose.yml`, `docker/` (php-fpm, nginx, MySQL 8, Redis, queue worker, scheduler, Mailpit) |
| CI (GitHub Actions) | `.github/workflows/ci.yml`: Pint, Pest on sqlite, `npm ci && npm run build` |

### Billing

- `PaymentGateway` (`createSubscription`, `createOrder`, `verifyPayment`, `cancel`) is bound by `BILLING_DRIVER`: `fake` (default; no network, refused in production) or `razorpay`.
- `RazorpayGateway` uses `RAZORPAY_KEY_ID` / `RAZORPAY_KEY_SECRET`. Empty keys give a clear 503 before any request. Use `rzp_test_*` keys; create the plans in the Razorpay test dashboard and set `RAZORPAY_PLAN_STARTER` / `RAZORPAY_PLAN_PRO`.
- Plans live in `config/billing.php` (paise): `starter` Rs 499/mo, `pro` Rs 1,499/mo.
- `POST /api/billing/webhook` verifies `X-Razorpay-Signature` (HMAC-SHA256 with `RAZORPAY_WEBHOOK_SECRET`, `hash_equals`), refuses everything when the secret is empty, and is idempotent on the event id. Events whose `created_at` is older than `BILLING_WEBHOOK_MAX_AGE_HOURS` (48) are recorded but ignored, and an event older than the last one applied to a subscription never overwrites it (out-of-order and replay protection). Invoices for payments: see Invoicing. **Listeners you attach to the forwarded events (`payment.failed`, `refund.*`, or `BillingEventOccurred` in general) must be idempotent on the payment/refund id**: the ledger drops a redelivered event id, but a gateway can also send the same payment event under a new event id.
- Flow: `POST /api/billing/checkout {plan}` -> browser checkout -> `POST /api/billing/confirm` -> webhooks keep status in sync.
- Set `BILLING_ENABLED=false` for a product without billing: no billing routes, no gateway keys needed.
- Billing and support fire `BillingEventOccurred` / `SupportTicketCreated`; registration fires Laravel's `Registered`. Attach your own listeners (emails, CRM, analytics).

### Multi-tenancy (workspaces)

Single database, team/workspace style: one `tenants` row per workspace, users join several through `tenant_user` (role `owner` / `admin` / `member`).

- **Current workspace** is stored server-side on `users.current_tenant_id` and changed with `POST /api/tenants/{id}/switch`. Registration creates a personal workspace (user = owner); a user who leaves their last workspace gets a new personal one, so everyone always has one.
- **Isolation, three layers.** (1) The `tenant` middleware (after auth, before route-model binding) loads the current workspace *through the membership table on every request*, so removed members lose access at once; not a member / none selected = 403. (2) `BelongsToTenant` on a model adds a global scope (queries, relations and route binding only see the current tenant; another tenant's id is a 404), fills `tenant_id` on create and refuses to move a row between tenants. With no tenant in context the scope returns nothing (fails closed). (3) Policies re-check the tenant.
- **Cannot be forgotten.** `tests/Feature/Tenancy/ArchitectureTest.php` fails if a model with a `tenant_id` or `user_id` column lacks the trait (exemptions are listed in the test, with reasons), and if an authenticated API route has no `tenant` middleware and is not declared user-level.
- **What is tenant-owned:** `Note` (shared inside the workspace; author or owner/admin can edit), `Subscription` (billing is per workspace; only the owner can check out, confirm or cancel; `user_id` stays as the purchaser), `SupportTicket` (workspace scoped: members see their own, owner/admin see all; unlinked inbound email has no tenant), `TenantInvitation`. Inbound support mail from a verified sender whose own email is verified goes to the sender's personal (first owned) workspace. Not tenant-owned: users, onboarding state, the webhook ledger. System code with no tenant (payment webhooks, inbound email, invite lookup) uses the explicit `Model::withoutTenancy()`.
- **Your own models:** add `tenant_id` (`foreignId('tenant_id')->constrained()->cascadeOnDelete()`) to the table, `use BelongsToTenant;` on the model, put the route behind the `tenant` middleware, and authorise in a policy. In queue jobs / seeders use `app(TenantContext::class)->runAs($tenant, fn () => ...)`.
- **Invitations:** `POST /api/tenant/invitations {email, role}` emails a link (`/invitations/accept?token=...`); the 48-char token is stored only as an HMAC-SHA256 hash, expires after `TENANCY_INVITATION_TTL_DAYS` (7), works once, and only for the signed-in account with the invited email. `POST /api/invitations/accept {token}`.
- **Roles:** owner (everything, billing, role changes), admin (invite/remove members, revoke invites), member. The last owner cannot be demoted, removed or leave.
- **Routes:** `GET|POST /api/tenants`, `POST /api/tenants/{id}/switch`, `POST /api/invitations/accept`, `GET /api/tenant`, `GET /api/tenant/members`, `PATCH|DELETE /api/tenant/members/{userId}`, `POST /api/tenant/leave`, `GET|POST /api/tenant/invitations`, `DELETE /api/tenant/invitations/{id}`.
- **Upgrading an existing install:** the migrations give every existing user a personal workspace and move their notes, subscriptions and tickets into it.
- `tenants.gstin`, `tenants.state_code` and the billing-profile columns are the invoice buyer details, read by the Invoicing module (see below).

### Email verification

`User` implements `MustVerifyEmail`. Registration sends Laravel's signed verification mail (expires after 60 minutes); the link `GET /email/verify/{id}/{hash}` checks the signature and the email hash (no session needed, so it works from another browser) and redirects to the SPA page `/verify-email?status=success|invalid`. `POST /api/auth/email/verification-notification` re-sends it (3 per minute). The SPA shows a banner with a Resend button until the address is verified. **Accepting a workspace invitation and `POST /api/billing/checkout` require a verified email** (`verified` middleware, 403 otherwise). Everything else works unverified. Local mail goes to Mailpit.

### Invoicing (GST tax invoices)

For what the SaaS charges its workspaces. Seller = you (`INVOICING_SELLER_*`), buyer = the workspace billing profile. Issuing refuses to run (503, logged) until the seller name, address, GSTIN (checksum-validated) and a matching state code are set.

- **Billing profile** (`GET|PUT /api/billing-profile`, owner/admin only, SPA `/billing-profile`): legal name, address, country, state, optional GSTIN. Stored on `tenants` (`gstin`, `state_code` plus new columns). GSTIN is validated by `ValidGstin` and its state must match the chosen state. Invoices store a snapshot of seller and buyer, so editing the profile never changes an issued invoice. **Checkout requires a complete profile** (legal name, address, city, and a state, or a valid GSTIN to derive it from; GSTIN itself is optional): without it `POST /api/billing/checkout` returns 422 `errors.billing_profile` and the SPA sends the owner to the profile form. The profile is needed because the invoice is immutable and the buyer's state decides CGST + SGST versus IGST.
- **GST code in PHP** (`app/Modules/Invoicing/Support`): `GstStateCodes` (official codes, retired 25 and 28 accepted, name lookup) and `Gstin` (format and base-36 checksum). `tests/fixtures/india-data/` holds copies of the shared vectors (`gstin.json`, `gst-state-codes.json`, see its README) and `GstinTest` runs every vector, so the kit has no runtime or test dependency on `shared/`.
- **Tax (integer paise only).** Place of supply = buyer state, falling back to the **seller's state** when the buyer has none. Same state: CGST + SGST; other state or 97 Other Territory: IGST; buyer outside India: export, zero-rated under LUT (default, with a note) or IGST (`INVOICING_EXPORT_MODE=igst`). Line items carry SAC, quantity, unit price, discount, taxable value and rate (default 18%). Rounding: tax is computed **per line**, rounded half up to the paisa; invoice totals are the sums of the lines; for an intra-state line CGST = floor(tax/2) and SGST = tax - CGST, so they always add up exactly to the tax (SGST takes the odd paisa). Totals are not rounded to whole rupees. `INVOICING_PRICES_INCLUDE_TAX=true` (default) treats the plan price as GST-inclusive: the amount charged is the invoice total.
- **Numbering.** `PREFIX/YY-YY/NNNNN`, e.g. `INV/26-27/00001`. That is 15 characters: the SAC-style long form `INV/2026-27/00001` is 17 and breaks the 16-character limit of CGST Rule 46, so the compact year is used and the generator refuses anything longer. Series are per seller GSTIN, per series (invoice `INV`, credit note `CN`) and per Indian financial year (1 Apr to 31 Mar, evaluated in Asia/Kolkata). The counter row is locked `FOR UPDATE` and incremented in the same transaction as the invoice insert (gapless, rolled back with it). Verified with 12 parallel processes on MySQL 8.4. `INVOICING_...` width: `invoicing.number_width` (default 5) is validated at boot so the longest possible number is at most 16 characters; usage is logged and shown in `/health` details at 90% of a series' capacity.
- **Immutable.** An issued invoice cannot be updated or deleted (model guards; the only allowed change is recording the payment once). Corrections are credit notes (`CN/...`, own series) that reference the original; the sum of credit notes cannot exceed the original. Foreign keys are `RESTRICT`. Note the guard is at application level: a direct SQL statement can still change a row, so restrict DB access in production. **Dates:** an invoice is dated now (Asia/Kolkata). Back-dating, future dates, dates in a closed financial year and dates earlier than the series' latest invoice are refused, so a number's date never runs backwards.
- **Auto-issue and recovery.** When billing confirms a payment (browser confirm or `subscription.activated` / `subscription.charged` webhook) the `IssueInvoiceForPayment` listener issues the invoice after the billing transaction commits, so a failure never undoes or blocks a payment. Idempotent per gateway payment id (unique `(type, payment_id)`). A failed issue is **not** repaired by Razorpay redelivering the webhook (the same event id is dropped by the idempotency ledger), so `php artisan invoicing:backfill` finds confirmed payments without an invoice (every confirm and webhook is in the ledger) and issues them; it is scheduled every 15 minutes (run the scheduler: the compose `starter-scheduler` service) and safe to run twice. Invoices are never issued for the `fake` gateway outside local/testing.
- **Operator commands** (manual invoices, credit notes; there is no operator UI): `php artisan invoicing:issue {workspaceId} "Description" {paise}`, `invoicing:mark-paid "INV/26-27/00001" --reference=...`, `invoicing:credit-note "INV/26-27/00001" "reason"` (whole invoice) or with `--amount={paise}` for a partial credit note. **Limitation: refunds (Razorpay `refund.*` events) do not raise credit notes automatically.** After refunding a customer, issue the credit note yourself.
- **API** (members of the workspace; another workspace's id is a 404): `GET /api/invoices`, `GET /api/invoices/{id}`, `GET /api/invoices/{id}/pdf`. SPA: `/invoices` (DataTable), invoice detail, PDF download.
- **PDF** with `dompdf/dompdf` (MIT): "Tax Invoice" / "Credit Note", supplier and recipient GSTINs, place of supply, line table, HSN/SAC summary, tax breakdown, total in words with Indian grouping, "Whether tax is payable under reverse charge: No". Remote resources are disabled.
- **SAC:** `INVOICING_SAC` defaults to 998314. **Needs CA confirmation** (see below).

### UPI payment links

Unpaid **manual** tax invoices (`payment_method = manual`, issued with `invoicing:issue`) get a UPI deep link `upi://pay?pa=&pn=&am=&cu=INR&tn=&tr=` (seller VPA from `INVOICING_UPI_VPA`, validated; RFC 3986 encoding; amount with two decimals) and an SVG QR (`bacon/bacon-qr-code`, MIT), shown on the invoice page and printed on the PDF. Gateway-paid invoices, paid invoices and credit notes never show it.

**A UPI link or QR does not confirm payment.** Nothing in this kit learns whether the payer paid. Reconcile your bank / UPI statement and run `invoicing:mark-paid`. For automatic confirmation use the Razorpay checkout (subscriptions) instead.

### Needs CA confirmation (not tax advice)

The code makes these choices; have a Chartered Accountant confirm them before issuing real invoices.
1. **SAC for SaaS.** 998314 is the default. Other candidates: 998313, 998315, 998431 (online content), 998439. Rate 18% assumed.
2. **GST-inclusive pricing** (the Razorpay charge is the invoice total) versus adding GST on top.
3. **Rounding** per line (above), and not rounding the invoice to whole rupees.
4. **Place of supply** for an unregistered buyer without a state: seller's state is used. Recipient's state for registered buyers.
5. **Export of services as zero-rated under LUT** (conditions of IGST Act s.2(6), foreign-exchange receipt, LUT filing) and the alternative of paying IGST.
6. **Code 97 (Other Territory) as IGST** and the treatment of SEZ buyers (not modelled).
7. **Credit notes** (s.34 CGST): timing limits, whether partial credit notes need tax-rate-wise detail, ITC reversal wording.
8. **Invoice content and date**: whether the invoice date should be the payment date (used) or the service date, and mandatory fields for B2C invoices.
9. **Reverse charge** is always printed as "No".
10. **Recovered invoices are dated the day they are issued**, not the payment day (an invoice is never back-dated), so a payment recovered by `invoicing:backfill` after a failed auto-issue can fall in a later month or even the next financial year. Keep the scheduler running so recovery happens within minutes, and ask your CA how to treat the rare late invoice.

### Future work (not built)

E-invoicing (IRN / IRP, QR with IRN) which becomes mandatory above a turnover threshold; GSTR-1 / GSTR-3B export or filing; e-way bills (not relevant to services); SEZ and composition-scheme sellers; multi-seller or per-workspace sellers; an operator UI for manual invoices and credit notes; automatic UPI reconciliation; emailing invoices.

### Password reset, account data and retention

- **Password reset:** `POST /api/auth/forgot-password` (same answer for known and unknown addresses; 5 per minute per IP, plus the broker's per-address throttle) and `POST /api/auth/reset-password` (token stored hashed, one use, expires after 60 minutes). A successful reset marks the email verified and revokes API tokens. SPA pages `/forgot-password` and `/reset-password`. The emailed link is built from `APP_URL` (never the request's Host header) and carries the token and email in the URL fragment, so they do not reach access logs or Referer headers. After a reset the user's other browser sessions end (`AuthenticateWebSession`; with the `database` session driver their rows are deleted too), the known-device cookies stop working (they are bound to the password hash) and the login lock-out is cleared. 
- **Rate limits** are named limiters (`AppServiceProvider`), one counter each; a test forbids numeric `throttle:N,M` on routes because those share a counter per user/IP. Guest auth endpoints are limited per IP + email and per IP.
- **Unverified accounts** are deleted after `ACCOUNTS_UNVERIFIED_PURGE_DAYS` (default 14) by `accounts:purge-unverified` (scheduled daily).
- **Login protection:** 5 attempts per minute per email and IP. Per email across all IPs, after `LOGIN_DELAY_AFTER` (10) failures an hour each further failure doubles a lock-out (up to 15 minutes) and `LOGIN_EMAIL_CEILING` (100) is a hard stop. Neither applies to a browser that has logged in to that account before (signed, httpOnly `known_device` cookie), so a botnet cannot lock the real owner out. Needs `TRUSTED_PROXIES` behind a proxy, or every visitor shares the proxy's IP.

### Data protection (DPDP Act 2023)

The operator of the product is the data fiduciary for its users' personal data. The kit gives you the mechanics; the obligations are yours.
- **Access / portability:** `GET /api/account/export` (3 per hour) returns the user's profile, memberships, notes, support tickets, subscriptions and invitations as JSON. SPA `/account`.
- **Erasure:** `DELETE /api/account` with the password. It is refused (409 with reasons) for the sole owner of a workspace that has other members or an active subscription. If another owner remains, the subscriptions this user purchased are handed to that owner (`subscriptions.user_id` is nullable with ON DELETE SET NULL, so billing is never deleted with a person); the checks are repeated under a lock inside the deletion. Otherwise it deletes the user, their tokens, notes, support tickets (including unlinked inbound mail from their address) and any workspace they alone own (emptied, scrubbed, soft-deleted). **Notes the user wrote in a shared workspace stay in that workspace with the author detached** (the workspace's own records; `notes.user_id` becomes NULL), so teammates do not lose work. If your privacy notice promises more, delete or export first. Export and erase need a verified email; inbound support mail is matched to an account by address only when the account's email is verified and the mail itself passed sender verification. **Issued invoices and credit notes are kept** with their buyer snapshot: GST law requires the supplier to retain them (CGST Act s.36, 72 months), they reference the workspace, not the user. Tell your users this in your privacy notice.
- **Retention:** `billing:prune-webhook-payloads` (daily) strips webhook bodies after `BILLING_WEBHOOK_PAYLOAD_DAYS` (90). Support tickets are kept until the account is deleted; decide your own retention for closed tickets.
- **Your responsibilities (not solved by code):** a privacy notice and consent text, a grievance contact, breach notification, processor agreements (Razorpay, mail, hosting, error tracker), purpose and retention limits for the data you add, and honouring requests within the legal time. Have a lawyer review.
- **Members can see the workspace's invoices.** Any member may list and download the workspace's invoices and PDFs (they are the workspace's own documents, and contain the buyer name, address and GSTIN the owner entered). Only owners and admins can read or edit the billing profile.

### Before you go live

1. `cp .env.production.example .env` and fill every value. The app refuses to boot as `APP_ENV=local` when `APP_URL` is a public host (set `ALLOW_LOCAL_ON_PUBLIC_HOST=true` only if deliberate). `APP_ENV=production`, `APP_DEBUG=false` (the app refuses to boot with debug on in production), `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`, HTTPS only.
2. `BILLING_DRIVER=razorpay` with live keys and webhook secret. The `fake` driver is allowed **only** when `APP_ENV` is `local` or `testing`; any other environment name refuses to boot.
3. **Set `INVOICING_SELLER_*`** (legal name, address, valid GSTIN, matching state code) or `INVOICING_ENABLED=false`. With a real billing driver and an invalid seller the app refuses to boot outside local/testing, and `/health` reports `degraded` with reason `invoicing_not_configured`. Have a CA confirm the points above.
4. Run the scheduler (it runs `invoicing:backfill`, the purge and prune jobs and the health heartbeat) and a queue worker. Watch `/health` with `X-Health-Token`.
5. `TRUSTED_PROXIES` set to your proxy/load balancer, `TRUSTED_HOSTS` and `SANCTUM_STATEFUL_DOMAINS` to your domains.
6. Mail: a real SMTP/API provider with SPF/DKIM; verification, reset and invitation emails depend on it.
7. Security headers (CSP, frame, nosniff, referrer, permissions, HSTS on HTTPS) are sent by the app (`SecurityHeaders` middleware) and by the bundled nginx for static files. The CSP allows Razorpay and, when `PLAUSIBLE_SRC` is set, its origin; it is not sent when `APP_ENV=local`.
8. Rate limits use the cache: use Redis in production so limits are shared across processes.

### Landing page and A/B variants

- `GET /` renders a server-side landing page for guests (signed-in users get the SPA). Default copy: `config/landing.php`.
- Variants are rows in `landing_variants` (weighted, sticky per visitor through the `bv_id` cookie). Manage them locally with
  `database/seeders/LandingVariantSeeder.php` (calls `LandingService::upsert()`), then `php artisan db:seed --class=LandingVariantSeeder`.
- Optional Plausible events (`Landing Impression`, `CTA Click`, `Signup`) are sent only when `PLAUSIBLE_DOMAIN` and `PLAUSIBLE_SRC` are set; add the origin to the CSP in `docker/nginx/default.conf`.

### Blog and SEO

- Articles (`articles` table) are Markdown, rendered safely (`html_input=strip`, unsafe links dropped). Only `published` with `published_at <= now` are public.
- Add posts through `ArticleService::upsert()` (see `database/seeders/ArticleSeeder.php`). `/sitemap.xml` is cached 1 h and flushed on upsert; `/robots.txt` blocks crawlers outside production.

### Support inbox

- Signed-in users: SPA `/support` and `GET/POST /api/support/tickets` (5 tickets/min).
- Optional inbound email: Brevo inbound-parse JSON to `POST /api/support/inbound`, guarded by `SUPPORT_INBOUND_TOKEN` (>= 32 chars, header only; empty = endpoint refuses everything). A ticket is linked to a user only when the trusted MX (`SUPPORT_TRUSTED_AUTHSERV_ID`) reports aligned DMARC/DKIM/SPF.

## Quickstart with Docker

Requires Docker with Compose, plus PHP 8.4, Composer and Node 22 only if you work outside containers.

```bash
cp .env.example .env
# set APP_KEY (docker compose run --rm starter-php php artisan key:generate), DB_PASSWORD, REDIS_PASSWORD
docker compose run --rm starter-php composer install
docker compose --profile build run --rm starter-build      # builds the SPA into public/build
docker compose up -d --build
docker compose exec starter-php php artisan migrate --force
docker compose exec starter-php php artisan db:seed         # demo user demo@example.com / password (local env only), sample landing variant + article
curl http://127.0.0.1:8200/health                           # {"status":"ok"}
```

App: http://127.0.0.1:8200. Mailpit: http://127.0.0.1:8225. MySQL is published on 127.0.0.1:3307 (host-side tools). Stop with `docker compose down` (add `-v` to wipe data).
The `docker/php/Dockerfile` uses `HOST_UID`/`HOST_GID` (default 501/20, macOS); on Linux set them to your own ids in `.env`.

## Quickstart without Docker

```bash
cp .env.example .env
composer install
php artisan key:generate
# point DB_* at a database, or use sqlite: DB_CONNECTION=sqlite and touch database/database.sqlite;
# also CACHE_STORE=file SESSION_DRIVER=file QUEUE_CONNECTION=sync if you have no Redis
php artisan migrate --seed
npm ci && npm run build        # or `npm run dev` for hot reload
php artisan serve
```

## Tests and checks

```bash
cp .env.example .env            # once (the suite itself uses sqlite in memory, set in phpunit.xml)
vendor/bin/pest                 # 240 tests (1 MySQL-only test is skipped on sqlite)
vendor/bin/pint --test          # code style
npm run build                   # vue-tsc type-check + vite build
# MySQL-only invoice numbering concurrency test (wipes that database): see tests/Concurrency/NumberingConcurrencyTest.php
```

## Configuration

Everything is in `.env.example` (placeholders only; never commit `.env`). Key switches: `BILLING_DRIVER`, `BILLING_ENABLED`, `RAZORPAY_*`,
`HEALTH_TOKEN`, `SUPPORT_INBOUND_TOKEN`, `SENTRY_LARAVEL_DSN`, `PLAUSIBLE_*`, `TRUSTED_HOSTS`, `TRUSTED_PROXIES`, `ALLOW_LOCAL_ON_PUBLIC_HOST`, `INVOICING_*`, `LOGIN_*`, `ACCOUNTS_UNVERIFIED_PURGE_DAYS`, `BILLING_WEBHOOK_*`.

Production notes: set `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `SANCTUM_STATEFUL_DOMAINS` to your app domain, serve over HTTPS and
enable the HSTS line in `docker/nginx/default.conf`. Outside local/testing only the `APP_URL` host and `TRUSTED_HOSTS` are accepted as Host headers.

## Build on it

1. Replace the dashboard placeholder (`resources/js/pages/DashboardPage.vue`) and write your feature as a new module in `app/Modules/<Name>` (copy `Notes`: model, policy, form requests, resource, service, routes, Pest tests). Routes under `Modules/*/routes/api.php` and `web.php` and `Console/*Command.php` are auto-loaded by `ModuleServiceProvider`.
2. Edit copy and plans: `config/landing.php`, `config/billing.php`, `config/onboarding.php`.
3. Rename: `APP_NAME`, `composer.json` name, `package.json` name, compose project and `starter-*` service names if you like.

## Support the project

This kit is free and MIT licensed. If it saved you time, you can [buy me a coffee](https://buymeacoffee.com/cbagdawala). Stars, bug reports and pull requests help just as much.

## Contributing

Pull requests are welcome. Run the three checks before opening one: `vendor/bin/pest`, `vendor/bin/pint --test` and `npm run build`. See `CONTRIBUTING.md`. Report security problems privately, see `SECURITY.md`.
