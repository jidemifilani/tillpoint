# TillPoint

Supermarket inventory, sales and audit suite. PHP 8.2 / MariaDB, no framework,
no build step, no external runtime dependencies.

Three pillars, with auditing treated as a working module rather than a log
viewer:

- **Inventory** — products and barcodes, batch tracking with first-expiry-first-out
  consumption, weighted-average costing, an append-only stock ledger, purchase
  orders, goods receipts, inter-store transfers, stock counts, wastage and
  adjustments
- **Sales** — till sessions with float and variance, a POS terminal, split
  payments, promotions, loyalty, refunds, cash-drawer movements, X and Z reports
- **Auditing** — a hash-chained tamper-evident trail, 28 exception-detection
  rules, enforced segregation of duties, shrinkage and cashier scorecards,
  auditor sign-off with a kept decision history

---

## Install

Requires PHP 8.2+ with PDO MySQL, and MariaDB 10.4+ or MySQL 8.

```
git clone <repo> tillpoint
cd tillpoint
cp config/config.example.php config/config.php
```

Edit `config/config.php` and set `DB_USER` and `DB_PASS`. Then either open
`http://localhost/tillpoint/install.php` in a browser, or do it from the
command line:

```
mysql -u root -e "CREATE DATABASE tillpoint CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -u root -D tillpoint < database/schema.sql
php database/migrate.php --apply
```

The web installer will also offer to generate demonstration data: three stores,
twelve staff, seventy products, opening stock and a fortnight of real trading.
Everything is posted through the production routines rather than inserted, so
the generated history obeys the same invariants as real data — which is what
makes the smoke test meaningful. It takes a minute or two.

**Delete `install.php` once you are satisfied the system works.** It seals
itself after a successful run, but while the file exists it still reveals the
database host and table count to anyone who can reach the URL.

### Demonstration credentials

All demo accounts share the password `Tillpoint#2026`.

| Account | Role | Notes |
|---|---|---|
| `adaeze@tillpoint.test` | Store Manager | Approvals, pricing, day end. PIN 7391 |
| `tunde@tillpoint.test` | Till Supervisor | Authorises overrides. PIN 4826 |
| `chioma@tillpoint.test` | Cashier | Cannot discount, void or approve |
| `segun@tillpoint.test` | Stock Controller | Goods in, counts, transfers |
| `yemi@tillpoint.test` | Buyer | Raises orders, cannot approve them |
| `hauwa@tillpoint.test` | Internal Auditor | Reads everything, posts nothing |

The Internal Auditor role deliberately holds no permission that creates or
approves a transaction. `tests/smoke.php` asserts that, so granting one will
fail the test — which is the intended behaviour.

---

## The invariants

These are the equalities the whole system rests on. If any is false, no figure
in the application means anything.

1. **Every stock position equals the sum of its own ledger movements.**
   `stock_levels.qty_on_hand == SUM(stock_movements.qty_delta)` for every
   product and store. Nothing writes `qty_on_hand` directly; everything goes
   through `stock_post()`.
2. **Every batch-tracked position totals its batches.** Asserted inside
   `stock_post()` rather than left to callers — see the note below on why.
3. **Every sale total equals its lines, and VAT is derived, not added.** Shelf
   prices are VAT-inclusive, so `vat = line_total * rate / (1 + rate)`. Adding
   VAT to an exclusive price would make the receipt total disagree with the
   shelf prices the customer added up.
4. **No refund exceeds what the original sale actually sold** and has not
   already had back.
5. **The audit trail's hash chain verifies end to end.**
6. **Nobody approves their own document.** Enforced by `sod_assert_different()`
   inside each posting routine, not by hiding buttons.

Run `php tests/smoke.php` to check all of them. 116 assertions; exit code 1 on
any failure, so it is usable from CI.

### Costing

Weighted average, moved only by receipts. Issues carry the cost at the moment
they happened, so a later price rise cannot retroactively change the margin on
sales already made. Batch-tracked products issue at the drawn batch's own cost
under FEFO, which is why a batch-tracked sale will not match the blended average
— that is correct, not a defect.

---

## Layout

```
config/          config.php (gitignored) and config.example.php
includes/        the engine - see below
database/        schema.sql, migrate.php, reset.sql, repair tools
pos/             the till, sessions, cash drawer, receipts, refunds
sales/           register, promotions, customers, vouchers, refund queue
inventory/       products, stock, batches, ledger, purchasing, counts, wastage
audit/           exception queue, scorecards, shrinkage, trail, integrity
reports/         Z-report, margin, valuation, VAT, expiry, slow movers, hourly
admin/           users, roles, stores, settings, reason codes, security, day end
cron/            daily-scan.php
tests/           smoke.php
```

The engine, in dependency order:

| File | Responsibility |
|---|---|
| `db.php` | PDO, query helpers, `db_tx()`, row locking |
| `functions.php` | URLs, escaping, money and quantity formatting, settings |
| `csrf.php` | One token per session, checked on every POST |
| `rbac.php` | The 52-permission catalogue, role templates, duty segregation |
| `auth.php` | Sign-in, lockouts, store context, supervisor PIN overrides |
| `audit.php` | The hash-chained trail and exception raising |
| `numbering.php` | Document numbers, allocated under a row lock |
| `inventory.php` | **The stock engine.** The single write path |
| `stock_docs.php` | Posting routines for POs, GRNs, transfers, counts, wastage |
| `promotions.php` | The promotion engine |
| `pos.php` | Till sessions, baskets, sale completion, refunds, cash, loyalty |
| `audit_rules.php` | The 28-rule catalogue and the nightly scan |
| `reports.php` | Every reporting query, so one figure is computed in one place |
| `layout.php` | Page chrome and the small view helpers |

---

## Design decisions worth knowing

**The audit trail is hash-chained.** Each entry stores SHA-256 over its own
fields plus the previous entry's hash. Altering or removing a historic entry
breaks every hash after it, and `audit/integrity` re-walks the chain and names
the exact entry where it stops adding up. It is tamper-*evident*, not
tamper-*proof*: someone with database write access could rewrite the whole chain
consistently. What defeats that is the head hash recorded outside the database
by `cron/daily-scan.php` into `database/integrity/head-hashes.log` — a rebuilt
chain cannot match a hash written down before the rebuild. **Copy that file off
the machine periodically; it is the only check a database administrator cannot
defeat from inside.**

**Controls are enforced in the posting routines, not the UI.** Buttons are
hidden from people who cannot use them as a courtesy; the refusal is on the
server either way. `sod_assert_different()` throws rather than returning false,
because a control that can be ignored by not checking a return value is not a
control.

**Nothing is deleted.** Not users, not stores, not terminals, not reason codes,
not completed sales. Every one of them is referenced by records that must stay
attributable. Deactivating is the real control — it stops new activity, which is
the part that matters, while the history stays readable. A completed sale cannot
be voided at all: that route is a refund, which leaves both documents in place.

**`schema.sql` cannot destroy anything.** It is `CREATE TABLE IF NOT EXISTS`
throughout with no `USE`, no `DROP` and no `TRUNCATE`, so running it twice or
against the wrong connection adds nothing and destroys nothing. The destructive
path is `database/reset.sql`, which is named so you cannot run it by accident
and which deliberately contains no `USE` statement — you must name the database
on the command line.

**Why the batch assertion lives in `stock_post()`.** Stock counts and
adjustments change a position with no delivery behind them, so they arrive
without a batch. For six produce lines that silently drifted the batch
sub-ledger by 13 units. Fixing the two callers would have left the next movement
type to make the same mistake, so the reconciliation and the assertion both sit
at the single write path. `database/repair-batch-ledger.php` corrected the
historic drift.

---

## Running it

### The nightly job

```
php cron/daily-scan.php            # yesterday, every active store
php cron/daily-scan.php --quiet    # only print problems
```

Runs the exception scan, verifies the hash chain and records the head hash,
expires vouchers and dormant loyalty points, prunes old sign-in records, and
reports unclosed trading days, unposted deliveries and unreconciled tills.
Exit code 1 when anything needs attention, so a scheduler can alert on it.

Windows Task Scheduler:
```
C:\xampp\php\php.exe C:\xampp\htdocs\tillpoint\cron\daily-scan.php
```
cron:
```
15 2 * * *  /usr/bin/php /var/www/tillpoint/cron/daily-scan.php --quiet
```

### Command-line tools

| Command | What it does |
|---|---|
| `php tests/smoke.php` | Asserts every invariant. Exit 1 on failure |
| `php database/migrate.php` | Reports outstanding additive schema changes; `--apply` writes them |
| `php database/repair-batch-ledger.php` | Brings batch balances back into step; `--apply` to correct |

`migrate.php` only ever adds columns and indexes. It never drops, renames or
retypes anything, because each of those needs a human deciding what happens to
the rows that already exist.

---

## Before going live

- [ ] Delete `install.php`
- [ ] Serve over HTTPS. `APP_HTTPS` is detected, including the
      `X-Forwarded-Proto` header, and drives the Secure cookie flag and HSTS
- [ ] Confirm `APP_DEBUG` is off. It is derived from the host, so it switches
      off automatically anywhere that is not localhost or `.test` — but check
      `TILLPOINT_DEBUG` is not set in the environment
- [ ] Schedule `cron/daily-scan.php`
- [ ] Set up `mysqldump` backups, encrypted, and **test a restore**
- [ ] Copy `database/integrity/head-hashes.log` off the machine on a schedule
- [ ] Review the thresholds under Administration → Settings against what the
      shop actually does. A discount ceiling set too high authorises everything
      and the exception rule never fires
- [ ] Set each store's shrinkage target under Administration → Stores
- [ ] Check `mod_rewrite` is enabled — every internal link assumes the clean-URL
      rewrite in `.htaccess`
- [ ] Make sure there is more than one administrator account

The Administration overview runs most of these as live health checks and will
tell you which are outstanding.

---

## Not built

Honest list of what is missing, so nobody goes looking:

- **Supplier invoices and three-way match.** `grn_post()` flags cost variance
  against the purchase order, but there is no invoice entity, so nothing checks
  what the supplier actually billed. The commonest purchasing leak.
- **Self-service password reset.** The schema columns exist
  (`password_reset_token`, `password_reset_expires`) and nothing uses them yet;
  an administrator must reset a password.
- **Two-factor authentication.** The supervisor PIN is for till overrides and is
  deliberately not a login factor.
- **Hardware integration.** Receipts print through the browser dialog, not
  ESC-POS; there is no cash-drawer pulse, no scale integration and no parsing of
  weight-embedded barcodes.
- **Shelf-edge and barcode label printing.**
- **Basket analysis, demand forecasting, accounting export.** Replenishment uses
  a flat 30-day rate with no seasonality.
- **Archival.** `stock_movements` and `audit_log` grow without bound. Partition
  or archive by year when they get large — but never delete, or the chain breaks.
- **Offline till resilience.** A dropped database connection mid-shift halts
  trading. Nothing corrupts, because `sale_complete()` is transactional, but the
  cashier gets an error rather than a graceful degradation.
