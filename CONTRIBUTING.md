# Contributing

Pull requests and issues are welcome.

1. Fork, then create a branch.
2. Set up: `cp .env.example .env && composer install && php artisan key:generate && npm ci`.
3. Make your change with a test. The suite is Pest on SQLite, no services needed.
4. Before opening the PR, run all three and make sure they pass:
   ```bash
   vendor/bin/pest
   vendor/bin/pint --test      # `vendor/bin/pint` fixes style
   npm run build               # type-checks the SPA (vue-tsc) and builds it
   ```
5. Keep the conventions in `CLAUDE.md`: one module per feature under `app/Modules`, thin controllers with form requests and
   resources, money in integer paise, every workspace-owned model uses `BelongsToTenant` and every authenticated API route
   uses the `tenant` middleware (an architecture test enforces both).
6. Security problems: do not open a public issue, see `SECURITY.md`.

Tax and legal behaviour (GST rules, data protection) must be conservative and documented: if you are unsure about a rule,
say so in the PR and in the README's "Needs CA confirmation" list rather than guessing.

By contributing you agree that your contribution is licensed under the MIT licence of this project.
