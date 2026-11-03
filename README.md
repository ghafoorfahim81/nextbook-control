# NextBook Control Center

The system that controls every NextBook customer: who they are, which package
they have, until when, what they owe, and what they use. A NextBook (the ERP)
never decides any of this; it receives a signed licence from here and enforces
it. The design is in the NextBook repo, `docs/control-center-plan-fa.pdf`.

Laravel 12, Inertia and Vue, PostgreSQL. A separate project and database from
the ERP on purpose: nothing of a customer's business data is stored here, only
counters.

## Set up (development)

```bash
composer install && npm ci
cp .env.example .env && php artisan key:generate
# set DB_* to a PostgreSQL database, then:
php artisan migrate
php artisan control:keys                       # the key pair that signs licences
php artisan control:staff you@example.com owner --name="You"
npm run build && php artisan serve
```

There is no sign-up and no seeded account: staff are made with
`control:staff` (or, once there is an owner, on the Staff screen). Everyone sets
up two-factor at their first sign-in.

Tests run against a real PostgreSQL database named in `.env.testing`
(`nextbook_control_test`), never SQLite:

```bash
php artisan test        # PHP
npm test                # the Jalali date helpers
```

## On a server

See `docs/deployment-fa.md` (install, nginx, queue worker, cron, connecting a
NextBook, updating, backups, and a security checklist) and start from
`.env.production.example`. After any change to the setup:

```bash
php artisan control:doctor
```

It checks the environment, database, licence keys, mail, WhatsApp, queue and
scheduler, and names what is wrong. `docs/messaging-fa.md` covers the reminders
and the WhatsApp templates.

## What is here

| | |
|---|---|
| Customers, contacts, packages, subscriptions | the screens; a package is switched off, never deleted; a price is added from a date, never rewritten |
| `app/Services/SubscriptionService.php` | trial, assign, renew, block, per-customer limits, cancel, invoice, take payment |
| `app/Services/LicenseService.php` | builds and signs (Ed25519) the licence an ERP receives; `verify()` is all an ERP needs |
| `routes/api.php` | what an ERP calls: `/api/license`, `/api/invoices`, `/api/usage` (by token) |
| `app/Services/Messaging` | reminders and notices by email and WhatsApp, deduplicated, in the customer's language |
| `app/Services/ReportService.php` | revenue, receivables, renewals, usage; each exports to Excel |
| `app/Services/Audit.php` | the append-only trail of every staff action |
| `app/Support/Roles.php` | owner, sales, finance, support, viewer: what each may do |

## Not built yet

Provisioning a company in an ERP from here, "send a reminder now", online
payment, and a customer-facing portal. See the plan for the order.
