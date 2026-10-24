---
name: sync-schema
description: Keep database/sync-schema.php up to date so "sync:export" never copies personal data from production. Use after creating or changing migrations (new tables or columns), when "sync:check" fails, or when the sync:check Stop hook reports problems.
---

<!-- Installed by drands/deployer-sync. It's overwritten on every package install/update: don't edit it. -->

# Keeping database/sync-schema.php up to date

`php artisan sync:export` (run in production by `dep sync:prod-to-local`) copies the database to
developers' machines. `database/sync-schema.php` declares, for every table, what happens to its data.
If a table, or a column of an anonymized table, is missing, the export fails in production.

## Workflow

1. Run the migrations (`php artisan migrate`): the check reads the local database, not the migration files.
2. Run `php artisan sync:check --fix`. It declares what's missing with suggested strategies (guessed from
   column names) and removes tables/columns that no longer exist.
3. **Review every added entry** (`git diff database/sync-schema.php`) using the rules below. The
   suggestions are only name-based guesses: fix whatever they got wrong.
4. Run `php artisan sync:check` until it passes. `tests/Feature/SyncSchemaTest.php` runs it too.

## Declarations

```php
'products' => 'keep',      // dumped as is: only for tables with no personal data at all
'sessions' => 'exclude',   // not dumped: empty locally
'users' => [               // anonymized: EVERY column must be declared
    'id' => 'keep',
    'name' => 'name',
    'email' => 'unique_email',
    'password' => 'password',
    'remember_token' => null,
],
```

Column strategies (NULL values always stay NULL):

| Strategy | Result |
|---|---|
| `'keep'` | Original value |
| `null` | NULL (only for nullable columns) |
| `'static:<value>'` | That literal value |
| `'unique_email'` | `user<primary key>@example.test`: use it for columns with a UNIQUE index |
| `'password'` | Bcrypt hash of `password`, so developers can log in locally |
| `'<formatter>'` | Any Faker formatter: `name`, `firstName`, `lastName`, `safeEmail`, `phoneNumber`, `streetAddress`, `city`, `postcode`, `iban`, `ipv4`, `sentence`, `paragraph`, `date`... |
| `'<formatter>:<arg>'` | Faker formatter with one argument, e.g. `'regexify:[0-9]{8}[A-Z]'` (DNI/NIF), `'numerify:6########'` |
| `'App\\Sync\\MyAnonymizer'` | Class implementing `Drands\DeployerSync\Anonymization\Anonymizer`, for complex cases such as JSON columns with personal data inside |

## What counts as personal data

Anonymize anything that identifies or describes a real person, or that's secret:

- Names, emails, phones, postal addresses, ID/tax numbers (DNI, NIF, NIE, VAT), birth dates.
- Bank and payment data: IBAN, card numbers, payment provider customer/account ids.
- IPs, user agents, geolocation of people.
- Tokens, API keys, secrets, 2FA data → `null` (or a random hash if NOT NULL).
- **Free text written by or about customers**: notes, comments, messages, order observations, support
  tickets → `null` or `'paragraph'`. Name-based guesses miss these: look for them explicitly.
- **JSON/text payloads** that may embed any of the above (addresses snapshots, API logs, webhooks, audit
  logs) → `null`, or a custom `Anonymizer` class if the app needs the structure.

Keep (`'keep'`) foreign keys, ids, statuses, amounts, prices, dates of business events, slugs and
anything about products/catalog/config. Business addresses (stores, warehouses) can be kept.

Prefer `'exclude'` for tables that only hold transient or log data the app doesn't need locally
(jobs, cache, sessions, logs, activity/audit logs).

Rules:

- Never mark a table or column `'keep'` just to make the check pass. If unsure, anonymize.
- Use `'unique_email'` (not `'safeEmail'`) for columns with a UNIQUE index.
- Keep strategies compatible with the column: `null` needs a nullable column; dates need date formatters.
- Comments in `database/sync-schema.php` are lost every time `--fix` runs.
