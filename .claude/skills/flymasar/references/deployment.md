# FlyMasar — Deployment Reference (Hostinger)

How FlyMasar reaches production. Read this before running any deploy, migration, or server-side command. The owner's role is **supervision**: propose commands, explain impact, wait for approval. Never run a destructive command against production without an explicit go-ahead and a fresh backup.

## Target environment

- Host: Hostinger **Business** shared hosting (hPanel).
- Web root (confirmed): `~/domains/beige-hare-390642.hostingersite.com/public_html`
- GitHub repo: `aaziz123q8/Fly` (must be **private**).
- SSH: enabled (IP `147.93.73.43`, port `65002`, user `u205378580`, key auth) — but **direct outbound SSH from the Claude Code environment times out** (sandbox blocks non-standard outbound ports). Do not rely on direct SSH automation; deploy via Auto-Deploy instead.
- **Secrets never live in this skill or in git.** `APP_MASTER_KEY` and Duffel/RateHawk/Stripe keys live in hPanel environment variables only; `config/apis.php` real values are never committed.

## Deploy mechanism — Git Auto-Deploy (the chosen path)

Source of truth is the private GitHub repo. Production is never edited by hand and never reached by direct SSH automation.

**Setup (one time):** hPanel → Git → select repo `aaziz123q8/Fly` + a **stable deploy branch** (use `main` or `production`, not the throwaway `claude/*` branch) → path `/domains/beige-hare-390642.hostingersite.com/public_html` → enable **Auto Deploy**.

**Routine flow:** Claude Code merges work into the deploy branch and pushes → Hostinger pulls automatically within seconds. No terminal, no SSH.

> Only the owner-approved deploy branch is auto-deployed, so unreviewed work on feature branches never reaches production.

Routine change flow:
1. Make code changes (following conventions/code-templates references).
2. `git add` + `git commit` with a clear message + `git push` to the deploy branch.
3. Auto-deploy pulls (or run the manual `git pull`).
4. Verify the site, then move on.

## Database migrations — no SSH, handle with care

Auto-Deploy pulls **code only**; it never runs migrations. Since direct SSH automation isn't available, migrations run by one of these:

1. **First-time bulk load:** import the numbered `NNN_*.sql` files + seeds via **phpMyAdmin** in hPanel (one session).
2. **Ongoing:** a **guarded migration runner** shipped with the app — a `migrate.php` reachable only with a secret token, that applies pending migrations in order, records each in a `migrations` tracking table, logs results, and refuses to re-run applied ones. Trigger it by visiting the secret URL once after a deploy, then it stays inert. Never leave it runnable without the token.

Safety rules regardless of method:
- **Back up first**, every time: `mysqldump -u <db_user> -p <db_name> > flymasar_$(date +%F_%H%M).sql` (run via phpMyAdmin export if no shell).
- Apply only new migrations, in order; never re-run old ones.
- Destructive DDL (`DROP`, `ALTER ... DROP`, data deletes) needs explicit owner approval before execution — state exactly what changes.

## What requires explicit approval (stop and ask)

- Any `DROP`, `TRUNCATE`, or column/table deletion.
- Anything touching the live `payments`, `invoices`, `booking_sessions`, or webhook tables.
- Rotating or changing keys / env vars.
- `rm -rf`, force-pushes to the deploy branch, or overwriting `public_html` wholesale.

## Pre-deploy checklist

- New migrations identified and backed up before running.
- `config/` and `database/` still protected by `.htaccess` (`Deny from all`).
- No secrets committed to git (check `.gitignore` covers `config/apis.php` local overrides, `.env`, keys).
- The four invariants (C-01→C-04) intact in any changed payment/webhook/checkout code.
- Cron queue worker still scheduled (emails/jobs depend on it).

## Mobile supervision model

Claude Code runs in the app and asks for approval before executing. The owner reviews and approves each step (or a trusted batch) from the phone. Keep destructive operations as individual, clearly-explained approvals rather than batched auto-accept.
