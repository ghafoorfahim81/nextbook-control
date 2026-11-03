# NextBook Control Center

The system that controls every NextBook customer: who they are, which package
they have, until when, what they owe, and what they use. A NextBook (the ERP)
never decides any of this; it receives a signed licence from here and enforces
it. The design is in `E:\nextbook\docs\control-center-plan-fa.pdf`.

Laravel 12, PostgreSQL. A separate project and database from the ERP on purpose:
nothing of a customer's business data is stored here, only counters.

## Set up

```bash
composer install
cp .env.example .env && php artisan key:generate
# set DB_* to a PostgreSQL database, then:
php artisan migrate
php artisan control:keys     # the key pair that signs licences
```

Tests run against a real PostgreSQL database named in `.env.testing`
(`nextbook_control_test`), never SQLite.

```bash
php artisan test
```

## What is here

| | |
|---|---|
| `app/Models` | customers, contacts, instances, packages (with versioned prices), subscriptions and their history, invoices, payments, usage snapshots, the audit log |
| `app/Services/SubscriptionService.php` | trial, assign, renew, block, override a limit, cancel, invoice, take payment |
| `app/Services/LicenseService.php` | builds and signs (Ed25519) the licence an ERP receives; `verify()` is all an ERP needs |
| `app/Services/Audit.php` | the append-only trail of every staff action |
| `app/Support/Roles.php` | owner, sales, finance, support, viewer: what each may do |

## Not built yet

Staff login with two-factor, the screens, the API an ERP calls (`/api/license`,
`/api/usage`), provisioning a company in an ERP, messages to customers, reports.
