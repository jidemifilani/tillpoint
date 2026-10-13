-- ====================================================================
-- TillPoint - DESTRUCTIVE RESET
-- ====================================================================
--
--   THIS FILE DELETES EVERYTHING. IT IS NOT A MIGRATION.
--
-- It exists so that rebuilding a development database is a deliberate,
-- named act rather than something schema.sql might do by accident.
-- schema.sql is CREATE TABLE IF NOT EXISTS throughout and contains no
-- DROP and no USE, so it can never destroy data. This file is the
-- opposite, and is named so you cannot run it by mistake.
--
-- DELIBERATELY ABSENT: there is no `USE` statement in this file.
--
--   You must name the database on the command line. A `USE tillpoint;`
--   here would mean that pasting part of this file into a client
--   connected to a production database would drop the wrong tables -
--   which is exactly how a live system gets wiped. The one-line cost of
--   typing the database name is worth it.
--
-- To rebuild a development database:
--
--   mysql -u root -D tillpoint < database/reset.sql
--   mysql -u root -D tillpoint < database/schema.sql
--   php database/migrate.php --apply
--   php install.php  (or the web installer) to seed reference data
--
-- Before running this against anything you care about:
--
--   mysqldump -u root tillpoint > backup-$(date +%F).sql
--
-- ====================================================================

-- Foreign keys are dropped with their tables, but the order of drops
-- would otherwise matter. Switching the checks off makes this
-- order-independent, and they are switched back on at the end.
SET FOREIGN_KEY_CHECKS = 0;

-- ---- Audit -----------------------------------------------------------
-- Dropped first in reading order, though order is irrelevant above.
-- NOTE: dropping audit_log destroys the hash chain permanently. There is
-- no way to reconstruct it, and any head hash recorded in
-- database/integrity/head-hashes.log will no longer match anything. That
-- is correct for a development rebuild and catastrophic in production.
DROP TABLE IF EXISTS audit_reviews;
DROP TABLE IF EXISTS audit_exceptions;
DROP TABLE IF EXISTS audit_scan_runs;
DROP TABLE IF EXISTS audit_integrity_checks;
DROP TABLE IF EXISTS audit_rules;
DROP TABLE IF EXISTS audit_log;
DROP TABLE IF EXISTS notifications;

-- ---- Sales -----------------------------------------------------------
DROP TABLE IF EXISTS refund_lines;
DROP TABLE IF EXISTS refunds;
DROP TABLE IF EXISTS sale_payments;
DROP TABLE IF EXISTS sale_lines;
DROP TABLE IF EXISTS sales;
DROP TABLE IF EXISTS cash_movements;
DROP TABLE IF EXISTS till_sessions;
DROP TABLE IF EXISTS day_end_closures;
DROP TABLE IF EXISTS loyalty_ledger;
DROP TABLE IF EXISTS vouchers;
DROP TABLE IF EXISTS promotions;
DROP TABLE IF EXISTS customers;
DROP TABLE IF EXISTS terminals;

-- ---- Stock -----------------------------------------------------------
DROP TABLE IF EXISTS stock_adjustment_lines;
DROP TABLE IF EXISTS stock_adjustments;
DROP TABLE IF EXISTS wastage;
DROP TABLE IF EXISTS stock_count_lines;
DROP TABLE IF EXISTS stock_counts;
DROP TABLE IF EXISTS stock_transfer_lines;
DROP TABLE IF EXISTS stock_transfers;
DROP TABLE IF EXISTS grn_lines;
DROP TABLE IF EXISTS grns;
DROP TABLE IF EXISTS purchase_order_lines;
DROP TABLE IF EXISTS purchase_orders;
DROP TABLE IF EXISTS stock_movements;
DROP TABLE IF EXISTS stock_batches;
DROP TABLE IF EXISTS stock_levels;

-- ---- Catalogue -------------------------------------------------------
DROP TABLE IF EXISTS price_history;
DROP TABLE IF EXISTS product_suppliers;
DROP TABLE IF EXISTS product_barcodes;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS suppliers;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS reason_codes;
DROP TABLE IF EXISTS units;

-- ---- Organisation and security --------------------------------------
DROP TABLE IF EXISTS number_sequences;
DROP TABLE IF EXISTS settings;
DROP TABLE IF EXISTS login_attempts;
DROP TABLE IF EXISTS user_stores;
DROP TABLE IF EXISTS role_permissions;
DROP TABLE IF EXISTS permissions;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS roles;
DROP TABLE IF EXISTS stores;

SET FOREIGN_KEY_CHECKS = 1;

-- After this, the database exists but is empty. Apply schema.sql next.
