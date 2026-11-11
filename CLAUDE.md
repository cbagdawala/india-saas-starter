# India SaaS Starter Kit: working notes

Standalone Laravel 13 + Vue 3.5 (TS) + PrimeVue 4 + Tailwind 4 SaaS starter, MIT licensed. These are working notes for contributors
and coding assistants; see also `CONTRIBUTING.md`.

## Layout
- `app/Modules/<Name>/{Http,Services,Models,Providers,Console,routes}`: one module per feature. `ModuleServiceProvider` auto-loads
  `routes/api.php` (under `/api`), `routes/web.php` and `Console/*Command.php`. Thin controllers, form requests, resources, service interfaces.
- `resources/js`: SPA (modules, pages, router, Pinia). Public landing/blog are server-rendered Blade.
- `database/seeders`: demo data and the way to manage landing variants and articles locally.
- Money is stored in paise (INR minor units).

## Commands
- `vendor/bin/pest` (sqlite in memory, needs a `.env` file to exist: `cp .env.example .env`), `vendor/bin/pint` (keep `--test` clean), `npm run build`.
- Docker: `docker compose up -d --build`; app on 127.0.0.1:8200; services are named `starter-*`.

## Rules
- No secrets in git; `.env.example` holds placeholders only. Razorpay: test-mode keys only until the owner says otherwise.
- Billing driver `fake` must never run in production (boot check exists).
- Keep Sentry/GlitchTip and Plausible optional (empty value = off).
- Multi-tenancy is built (`app/Modules/Tenancy`). Every new model that holds user/workspace data needs `BelongsToTenant` + a `tenant_id` column; every new authenticated API route needs the `tenant` middleware (or an entry in `USER_LEVEL_ROUTES` in `tests/Feature/Tenancy/ArchitectureTest.php`). The architecture test enforces both: it fails for a model with a `tenant_id` or `user_id` column that lacks the trait, and for an authenticated `api/*` route without the `tenant` middleware that is not on that list. It cannot see a model that holds workspace data but has neither column, so give such a model a `tenant_id`.
- Invoicing (GST invoices, credit notes, PDF, UPI) is built in `app/Modules/Invoicing`. Issued invoices are immutable: corrections are credit notes. Money is integer paise. Not built: e-invoicing (IRN/IRP) and GSTR filing. Tax choices needing CA review are listed in README.md.
- Keep the kit standalone: no dependency on any external private service or workspace.
