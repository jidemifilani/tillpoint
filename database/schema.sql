-- ====================================================================
-- TillPoint - schema
-- ====================================================================
-- Deliberately idempotent and non-destructive: every statement is
-- CREATE TABLE IF NOT EXISTS, and there is no USE, no DROP DATABASE and
-- no DROP TABLE anywhere in this file. Running it twice, or against the
-- wrong connection, adds nothing and destroys nothing.
--
-- The target database is chosen by the caller (install.php, or
-- `mysql -D tillpoint < schema.sql`), never by this file.
--
-- To wipe and rebuild, use database/reset.sql, which is destructive and
-- named so you cannot run it by accident.
--
-- Conventions
--   qty     DECIMAL(12,3)  - 3dp so weighed goods (0.250 kg) are exact
--   money   DECIMAL(14,2)
--   cost    DECIMAL(14,4)  - 4dp: weighted-average cost must not drift
-- ====================================================================

-- ====================================================================
-- 1. ORGANISATION, USERS, SECURITY
-- ====================================================================

CREATE TABLE IF NOT EXISTS stores (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code          VARCHAR(12)  NOT NULL,
  name          VARCHAR(120) NOT NULL,
  address       VARCHAR(255) NULL,
  phone         VARCHAR(40)  NULL,
  tin           VARCHAR(40)  NULL COMMENT 'tax id printed on receipts',
  is_warehouse  TINYINT(1)   NOT NULL DEFAULT 0,
  shrinkage_target_pct DECIMAL(6,2) NOT NULL DEFAULT 2.00 COMMENT 'what the shrinkage report measures against; differs by store',
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_stores_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roles (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(40)  NOT NULL,
  name        VARCHAR(80)  NOT NULL,
  description VARCHAR(255) NULL,
  is_system   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'system roles cannot be deleted',
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
  code   VARCHAR(60)  NOT NULL,
  module VARCHAR(40)  NOT NULL,
  label  VARCHAR(120) NOT NULL,
  PRIMARY KEY (code),
  KEY idx_perm_module (module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id         INT UNSIGNED NOT NULL,
  permission_code VARCHAR(60)  NOT NULL,
  PRIMARY KEY (role_id, permission_code),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_code) REFERENCES permissions (code) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  staff_no            VARCHAR(20)  NOT NULL,
  full_name           VARCHAR(120) NOT NULL,
  email               VARCHAR(160) NOT NULL,
  phone               VARCHAR(40)  NULL,
  password_hash       VARCHAR(255) NOT NULL,
  -- Supervisor PIN, hashed. Authorises one override at a till; never a login.
  pin_hash            VARCHAR(255) NULL,
  pin_failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  pin_locked_until    DATETIME     NULL,
  role_id             INT UNSIGNED NOT NULL,
  -- NULL store_id = may work in any store (head office, group auditor).
  store_id            INT UNSIGNED NULL,
  is_active           TINYINT(1)   NOT NULL DEFAULT 1,
  must_change_password TINYINT(1)  NOT NULL DEFAULT 0,
  failed_attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until        DATETIME     NULL,
  last_login_at       DATETIME     NULL,
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_staff (staff_no),
  KEY idx_users_role (role_id),
  KEY idx_users_store (store_id),
  CONSTRAINT fk_users_role  FOREIGN KEY (role_id)  REFERENCES roles (id),
  CONSTRAINT fk_users_store FOREIGN KEY (store_id) REFERENCES stores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Staff who cover more than one branch. A user's own store_id is implicit; this
-- adds the others. Empty for most staff.
CREATE TABLE IF NOT EXISTS user_stores (
  user_id  INT UNSIGNED NOT NULL,
  store_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, store_id),
  CONSTRAINT fk_us_user  FOREIGN KEY (user_id)  REFERENCES users (id)  ON DELETE CASCADE,
  CONSTRAINT fk_us_store FOREIGN KEY (store_id) REFERENCES stores (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  identity   VARCHAR(160) NOT NULL COMMENT 'email as typed',
  ip         VARCHAR(45)  NOT NULL,
  user_agent VARCHAR(255) NULL,
  success    TINYINT(1)   NOT NULL DEFAULT 0,
  reason     VARCHAR(60)  NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_la_ip_time (ip, created_at),
  KEY idx_la_identity_time (identity, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  skey        VARCHAR(80)  NOT NULL,
  svalue      TEXT         NULL,
  value_type  ENUM('string','int','decimal','bool','json') NOT NULL DEFAULT 'string',
  label       VARCHAR(160) NOT NULL,
  category    VARCHAR(40)  NOT NULL DEFAULT 'general',
  updated_by  INT UNSIGNED NULL,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (skey),
  KEY idx_settings_cat (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-document-type running numbers, per store and period. Rows are locked
-- FOR UPDATE while allocating so two tills cannot mint the same receipt number.
CREATE TABLE IF NOT EXISTS number_sequences (
  doc_type  VARCHAR(20)  NOT NULL,
  store_id  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = not store-scoped',
  period    VARCHAR(8)   NOT NULL DEFAULT '' COMMENT 'YYYYMM, or blank if not reset per period',
  last_no   INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (doc_type, store_id, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================================
-- 2. AUDIT LOG  (append-only, hash-chained)
-- ====================================================================
-- Every mutating action lands here. row_hash is SHA-256 over this row's own
-- fields plus the previous row's hash, so the table is a chain: altering or
-- deleting any historic row breaks every hash after it, and audit/integrity
-- re-walks the chain to prove it has not happened. Nothing in the codebase
-- ever UPDATEs or DELETEs a row in this table.
CREATE TABLE IF NOT EXISTS audit_log (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id   INT UNSIGNED NULL,
  user_id    INT UNSIGNED NULL,
  user_label VARCHAR(120) NULL COMMENT 'denormalised: the trail must survive a user being renamed or removed',
  action     VARCHAR(60)  NOT NULL COMMENT 'e.g. sale.complete, product.price_change',
  entity     VARCHAR(40)  NOT NULL,
  entity_id  BIGINT UNSIGNED NULL,
  summary    VARCHAR(255) NOT NULL,
  before_json JSON        NULL,
  after_json  JSON        NULL,
  severity   ENUM('info','notice','warning','critical') NOT NULL DEFAULT 'info',
  ip         VARCHAR(45)  NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME(3)  NOT NULL,
  prev_hash  CHAR(64)     NOT NULL,
  row_hash   CHAR(64)     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_audit_entity (entity, entity_id),
  KEY idx_audit_user (user_id, created_at),
  KEY idx_audit_action (action, created_at),
  KEY idx_audit_time (created_at),
  KEY idx_audit_store (store_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_integrity_checks (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  run_by        INT UNSIGNED NULL,
  rows_checked  INT UNSIGNED NOT NULL DEFAULT 0,
  first_id      BIGINT UNSIGNED NULL,
  last_id       BIGINT UNSIGNED NULL,
  result        ENUM('ok','broken','empty') NOT NULL,
  broken_at_id  BIGINT UNSIGNED NULL,
  note          VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY idx_aic_run (run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================================
-- 3. CATALOGUE
-- ====================================================================

CREATE TABLE IF NOT EXISTS units (
  code       VARCHAR(10)  NOT NULL,
  name       VARCHAR(40)  NOT NULL,
  is_decimal TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = sold by weight/volume, fractional qty allowed',
  PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code              VARCHAR(20)  NOT NULL,
  name              VARCHAR(100) NOT NULL,
  parent_id         INT UNSIGNED NULL,
  vat_applicable    TINYINT(1)   NOT NULL DEFAULT 1,
  target_margin_pct DECIMAL(6,2) NOT NULL DEFAULT 20.00,
  is_active         TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cat_code (code),
  KEY idx_cat_parent (parent_id),
  CONSTRAINT fk_cat_parent FOREIGN KEY (parent_id) REFERENCES categories (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS suppliers (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code               VARCHAR(20)  NOT NULL,
  name               VARCHAR(140) NOT NULL,
  contact_name       VARCHAR(120) NULL,
  phone              VARCHAR(40)  NULL,
  email              VARCHAR(160) NULL,
  address            VARCHAR(255) NULL,
  payment_terms_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  lead_time_days     SMALLINT UNSIGNED NOT NULL DEFAULT 7,
  is_active          TINYINT(1)   NOT NULL DEFAULT 1,
  created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sup_code (code),
  KEY idx_sup_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sku             VARCHAR(30)  NOT NULL,
  barcode         VARCHAR(40)  NULL COMMENT 'primary scan code; extras live in product_barcodes',
  name            VARCHAR(160) NOT NULL,
  description     VARCHAR(255) NULL,
  category_id     INT UNSIGNED NOT NULL,
  unit_code       VARCHAR(10)  NOT NULL DEFAULT 'EA',
  supplier_id     INT UNSIGNED NULL COMMENT 'preferred supplier',
  pack_size       DECIMAL(10,3) NOT NULL DEFAULT 1.000 COMMENT 'units per supplier case',
  cost_price      DECIMAL(14,4) NOT NULL DEFAULT 0 COMMENT 'current standard/list cost',
  sell_price      DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'VAT-inclusive shelf price',
  vat_rate        DECIMAL(6,4) NOT NULL DEFAULT 0.0750,
  is_weighed      TINYINT(1)   NOT NULL DEFAULT 0,
  is_perishable   TINYINT(1)   NOT NULL DEFAULT 0,
  track_batches   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'batch + expiry captured on receipt, consumed FEFO',
  shelf_life_days SMALLINT UNSIGNED NULL,
  reorder_level   DECIMAL(12,3) NOT NULL DEFAULT 0,
  reorder_qty     DECIMAL(12,3) NOT NULL DEFAULT 0,
  age_restricted  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'alcohol/tobacco: till prompts for age check',
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_prod_sku (sku),
  UNIQUE KEY uq_prod_barcode (barcode),
  KEY idx_prod_name (name),
  KEY idx_prod_cat (category_id),
  KEY idx_prod_sup (supplier_id),
  KEY idx_prod_active (is_active),
  CONSTRAINT fk_prod_cat  FOREIGN KEY (category_id) REFERENCES categories (id),
  CONSTRAINT fk_prod_sup  FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE SET NULL,
  CONSTRAINT fk_prod_unit FOREIGN KEY (unit_code)   REFERENCES units (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A multipack and its single scan to the same product at different prices, and
-- imported goods often carry two codes. The till resolves any of them.
CREATE TABLE IF NOT EXISTS product_barcodes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id  INT UNSIGNED NOT NULL,
  barcode     VARCHAR(40)  NOT NULL,
  qty_per_scan DECIMAL(10,3) NOT NULL DEFAULT 1.000 COMMENT 'a case barcode adds this many units',
  label       VARCHAR(60)  NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pb_barcode (barcode),
  KEY idx_pb_product (product_id),
  CONSTRAINT fk_pb_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_suppliers (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id     INT UNSIGNED NOT NULL,
  supplier_id    INT UNSIGNED NOT NULL,
  supplier_sku   VARCHAR(40)  NULL,
  cost_price     DECIMAL(14,4) NOT NULL DEFAULT 0,
  lead_time_days SMALLINT UNSIGNED NOT NULL DEFAULT 7,
  is_preferred   TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ps (product_id, supplier_id),
  CONSTRAINT fk_ps_product  FOREIGN KEY (product_id)  REFERENCES products (id)  ON DELETE CASCADE,
  CONSTRAINT fk_ps_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every cost or shelf-price change, with who and why. Margin erosion and
-- quiet price edits are both audit findings, and neither is visible from the
-- current value alone.
CREATE TABLE IF NOT EXISTS price_history (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id INT UNSIGNED NOT NULL,
  old_cost   DECIMAL(14,4) NULL,
  new_cost   DECIMAL(14,4) NULL,
  old_price  DECIMAL(14,2) NULL,
  new_price  DECIMAL(14,2) NULL,
  reason     VARCHAR(160) NULL,
  changed_by INT UNSIGNED NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ph_product (product_id, created_at),
  CONSTRAINT fk_ph_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Why a thing happened, as a closed list rather than free text, so exceptions
-- can be counted per reason. applies_to keeps the till's void list from showing
-- wastage reasons.
CREATE TABLE IF NOT EXISTS reason_codes (
  code       VARCHAR(20)  NOT NULL,
  label      VARCHAR(120) NOT NULL,
  applies_to ENUM('wastage','adjustment','void','refund','price_override','discount','no_sale','cash') NOT NULL,
  needs_approval TINYINT(1) NOT NULL DEFAULT 0,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (code),
  KEY idx_rc_applies (applies_to, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================================
-- 4. STOCK
-- ====================================================================

-- Current position per product per store. A cache of the ledger: it exists so
-- the till does not SUM() the whole movement history to price a line. The smoke
-- test asserts it equals SUM(stock_movements.qty_delta) for every row.
CREATE TABLE IF NOT EXISTS stock_levels (
  product_id      INT UNSIGNED NOT NULL,
  store_id        INT UNSIGNED NOT NULL,
  qty_on_hand     DECIMAL(12,3) NOT NULL DEFAULT 0,
  qty_reserved    DECIMAL(12,3) NOT NULL DEFAULT 0 COMMENT 'held by parked sales and in-flight transfers',
  avg_cost        DECIMAL(14,4) NOT NULL DEFAULT 0 COMMENT 'weighted average, moved only by receipts',
  last_counted_at DATETIME     NULL,
  last_sold_at    DATETIME     NULL,
  last_received_at DATETIME    NULL,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (product_id, store_id),
  KEY idx_sl_store (store_id),
  KEY idx_sl_store_qty (store_id, qty_on_hand),
  CONSTRAINT fk_sl_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
  CONSTRAINT fk_sl_store   FOREIGN KEY (store_id)   REFERENCES stores (id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_batches (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id    INT UNSIGNED NOT NULL,
  store_id      INT UNSIGNED NOT NULL,
  batch_no      VARCHAR(40)  NOT NULL,
  expiry_date   DATE         NULL,
  qty_received  DECIMAL(12,3) NOT NULL DEFAULT 0,
  qty_on_hand   DECIMAL(12,3) NOT NULL DEFAULT 0,
  unit_cost     DECIMAL(14,4) NOT NULL DEFAULT 0,
  grn_id        BIGINT UNSIGNED NULL,
  received_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sb_fefo (product_id, store_id, qty_on_hand, expiry_date),
  KEY idx_sb_expiry (expiry_date),
  KEY idx_sb_store (store_id),
  CONSTRAINT fk_sb_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
  CONSTRAINT fk_sb_store   FOREIGN KEY (store_id)   REFERENCES stores (id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The stock ledger. Append-only: nothing in the codebase updates or deletes a
-- row here. A correction is another row the other way. balance_after is stored
-- so a position can be reconstructed as at any point without replaying from zero.
CREATE TABLE IF NOT EXISTS stock_movements (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id      INT UNSIGNED NOT NULL,
  product_id    INT UNSIGNED NOT NULL,
  batch_id      BIGINT UNSIGNED NULL,
  movement_type ENUM('receipt','sale','refund_in','transfer_out','transfer_in',
                     'adjustment','wastage','count_variance','opening') NOT NULL,
  qty_delta     DECIMAL(12,3) NOT NULL COMMENT 'signed: negative takes stock out',
  unit_cost     DECIMAL(14,4) NOT NULL DEFAULT 0,
  value_delta   DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'qty_delta * unit_cost, for valuation movement',
  balance_after DECIMAL(12,3) NOT NULL,
  ref_type      VARCHAR(20)  NULL COMMENT 'sale|grn|transfer|count|wastage|adjustment|refund',
  ref_id        BIGINT UNSIGNED NULL,
  ref_no        VARCHAR(30)  NULL,
  reason_code   VARCHAR(20)  NULL,
  note          VARCHAR(255) NULL,
  user_id       INT UNSIGNED NULL,
  created_at    DATETIME(3)  NOT NULL,
  PRIMARY KEY (id),
  KEY idx_sm_product_store (product_id, store_id, created_at),
  KEY idx_sm_ref (ref_type, ref_id),
  KEY idx_sm_type_time (movement_type, created_at),
  KEY idx_sm_store_time (store_id, created_at),
  KEY idx_sm_batch (batch_id),
  CONSTRAINT fk_sm_product FOREIGN KEY (product_id) REFERENCES products (id),
  CONSTRAINT fk_sm_store   FOREIGN KEY (store_id)   REFERENCES stores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Purchase orders -------------------------------------------------
CREATE TABLE IF NOT EXISTS purchase_orders (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  po_no          VARCHAR(30)  NOT NULL,
  store_id       INT UNSIGNED NOT NULL,
  supplier_id    INT UNSIGNED NOT NULL,
  status         ENUM('draft','submitted','approved','partly_received','received','cancelled')
                 NOT NULL DEFAULT 'draft',
  order_date     DATE         NOT NULL,
  expected_date  DATE         NULL,
  subtotal       DECIMAL(14,2) NOT NULL DEFAULT 0,
  vat_total      DECIMAL(14,2) NOT NULL DEFAULT 0,
  total          DECIMAL(14,2) NOT NULL DEFAULT 0,
  note           VARCHAR(255) NULL,
  created_by     INT UNSIGNED NOT NULL,
  submitted_at   DATETIME     NULL,
  -- Approver is checked against created_by at approval time: a buyer may not
  -- approve their own order (see sod_assert_different).
  approved_by    INT UNSIGNED NULL,
  approved_at    DATETIME     NULL,
  cancelled_by   INT UNSIGNED NULL,
  cancelled_at   DATETIME     NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_po_no (po_no),
  KEY idx_po_status (status),
  KEY idx_po_supplier (supplier_id),
  KEY idx_po_store (store_id, order_date),
  CONSTRAINT fk_po_store    FOREIGN KEY (store_id)    REFERENCES stores (id),
  CONSTRAINT fk_po_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_order_lines (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  po_id         BIGINT UNSIGNED NOT NULL,
  product_id    INT UNSIGNED NOT NULL,
  qty_ordered   DECIMAL(12,3) NOT NULL,
  qty_received  DECIMAL(12,3) NOT NULL DEFAULT 0,
  unit_cost     DECIMAL(14,4) NOT NULL,
  vat_rate      DECIMAL(6,4) NOT NULL DEFAULT 0,
  line_total    DECIMAL(14,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_pol_po (po_id),
  KEY idx_pol_product (product_id),
  CONSTRAINT fk_pol_po      FOREIGN KEY (po_id)      REFERENCES purchase_orders (id) ON DELETE CASCADE,
  CONSTRAINT fk_pol_product FOREIGN KEY (product_id) REFERENCES products (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Goods received --------------------------------------------------
CREATE TABLE IF NOT EXISTS grns (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  grn_no              VARCHAR(30)  NOT NULL,
  po_id               BIGINT UNSIGNED NULL COMMENT 'NULL for a direct/over-the-counter receipt',
  store_id            INT UNSIGNED NOT NULL,
  supplier_id         INT UNSIGNED NOT NULL,
  supplier_invoice_no VARCHAR(40)  NULL,
  received_date       DATE         NOT NULL,
  status              ENUM('draft','posted','cancelled') NOT NULL DEFAULT 'draft',
  subtotal            DECIMAL(14,2) NOT NULL DEFAULT 0,
  vat_total           DECIMAL(14,2) NOT NULL DEFAULT 0,
  total               DECIMAL(14,2) NOT NULL DEFAULT 0,
  note                VARCHAR(255) NULL,
  received_by         INT UNSIGNED NOT NULL,
  posted_by           INT UNSIGNED NULL,
  posted_at           DATETIME     NULL,
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_grn_no (grn_no),
  KEY idx_grn_po (po_id),
  KEY idx_grn_store (store_id, received_date),
  KEY idx_grn_supplier (supplier_id),
  KEY idx_grn_status (status),
  CONSTRAINT fk_grn_store    FOREIGN KEY (store_id)    REFERENCES stores (id),
  CONSTRAINT fk_grn_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id),
  CONSTRAINT fk_grn_po       FOREIGN KEY (po_id)       REFERENCES purchase_orders (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS grn_lines (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  grn_id      BIGINT UNSIGNED NOT NULL,
  po_line_id  BIGINT UNSIGNED NULL,
  product_id  INT UNSIGNED NOT NULL,
  qty         DECIMAL(12,3) NOT NULL,
  unit_cost   DECIMAL(14,4) NOT NULL,
  vat_rate    DECIMAL(6,4) NOT NULL DEFAULT 0,
  line_total  DECIMAL(14,2) NOT NULL DEFAULT 0,
  batch_no    VARCHAR(40)  NULL,
  expiry_date DATE         NULL,
  PRIMARY KEY (id),
  KEY idx_gl_grn (grn_id),
  KEY idx_gl_product (product_id),
  CONSTRAINT fk_gl_grn     FOREIGN KEY (grn_id)     REFERENCES grns (id) ON DELETE CASCADE,
  CONSTRAINT fk_gl_product FOREIGN KEY (product_id) REFERENCES products (id),
  CONSTRAINT fk_gl_poline  FOREIGN KEY (po_line_id) REFERENCES purchase_order_lines (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Inter-store transfers -------------------------------------------
-- Dispatch and receipt are separate postings so stock in a van belongs to
-- neither store's sellable position; the difference is the in-transit figure
-- and a persistent gap is a shrinkage finding.
CREATE TABLE IF NOT EXISTS stock_transfers (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  transfer_no   VARCHAR(30)  NOT NULL,
  from_store_id INT UNSIGNED NOT NULL,
  to_store_id   INT UNSIGNED NOT NULL,
  status        ENUM('draft','dispatched','received','cancelled') NOT NULL DEFAULT 'draft',
  note          VARCHAR(255) NULL,
  created_by    INT UNSIGNED NOT NULL,
  dispatched_by INT UNSIGNED NULL,
  dispatched_at DATETIME     NULL,
  received_by   INT UNSIGNED NULL,
  received_at   DATETIME     NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tr_no (transfer_no),
  KEY idx_tr_from (from_store_id, status),
  KEY idx_tr_to (to_store_id, status),
  CONSTRAINT fk_tr_from FOREIGN KEY (from_store_id) REFERENCES stores (id),
  CONSTRAINT fk_tr_to   FOREIGN KEY (to_store_id)   REFERENCES stores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_transfer_lines (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  transfer_id  BIGINT UNSIGNED NOT NULL,
  product_id   INT UNSIGNED NOT NULL,
  qty_sent     DECIMAL(12,3) NOT NULL,
  qty_received DECIMAL(12,3) NOT NULL DEFAULT 0,
  unit_cost    DECIMAL(14,4) NOT NULL DEFAULT 0,
  note         VARCHAR(160) NULL,
  PRIMARY KEY (id),
  KEY idx_trl_transfer (transfer_id),
  KEY idx_trl_product (product_id),
  CONSTRAINT fk_trl_transfer FOREIGN KEY (transfer_id) REFERENCES stock_transfers (id) ON DELETE CASCADE,
  CONSTRAINT fk_trl_product  FOREIGN KEY (product_id)  REFERENCES products (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Stock counts ----------------------------------------------------
CREATE TABLE IF NOT EXISTS stock_counts (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  count_no    VARCHAR(30)  NOT NULL,
  store_id    INT UNSIGNED NOT NULL,
  count_type  ENUM('full','cycle','spot') NOT NULL DEFAULT 'cycle',
  status      ENUM('open','counted','approved','posted','cancelled') NOT NULL DEFAULT 'open',
  scope_note  VARCHAR(255) NULL,
  category_id INT UNSIGNED NULL COMMENT 'cycle counts are usually scoped to one category',
  variance_qty   DECIMAL(14,3) NOT NULL DEFAULT 0,
  variance_value DECIMAL(14,2) NOT NULL DEFAULT 0,
  created_by  INT UNSIGNED NOT NULL,
  counted_by  INT UNSIGNED NULL,
  counted_at  DATETIME     NULL,
  -- Checked against counted_by: whoever counted may not sign off their own
  -- variance. That rule is the whole point of a cycle count.
  approved_by INT UNSIGNED NULL,
  approved_at DATETIME     NULL,
  posted_at   DATETIME     NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sc_no (count_no),
  KEY idx_sc_store (store_id, status),
  CONSTRAINT fk_sc_store FOREIGN KEY (store_id) REFERENCES stores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_count_lines (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  count_id       BIGINT UNSIGNED NOT NULL,
  product_id     INT UNSIGNED NOT NULL,
  system_qty     DECIMAL(12,3) NOT NULL DEFAULT 0 COMMENT 'frozen when the sheet was generated',
  counted_qty    DECIMAL(12,3) NULL,
  variance_qty   DECIMAL(12,3) NOT NULL DEFAULT 0,
  unit_cost      DECIMAL(14,4) NOT NULL DEFAULT 0,
  variance_value DECIMAL(14,2) NOT NULL DEFAULT 0,
  note           VARCHAR(160) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_scl (count_id, product_id),
  KEY idx_scl_product (product_id),
  CONSTRAINT fk_scl_count   FOREIGN KEY (count_id)   REFERENCES stock_counts (id) ON DELETE CASCADE,
  CONSTRAINT fk_scl_product FOREIGN KEY (product_id) REFERENCES products (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Wastage & adjustments -------------------------------------------
CREATE TABLE IF NOT EXISTS wastage (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  waste_no    VARCHAR(30)  NOT NULL,
  store_id    INT UNSIGNED NOT NULL,
  product_id  INT UNSIGNED NOT NULL,
  batch_id    BIGINT UNSIGNED NULL,
  qty         DECIMAL(12,3) NOT NULL,
  unit_cost   DECIMAL(14,4) NOT NULL DEFAULT 0,
  total_value DECIMAL(14,2) NOT NULL DEFAULT 0,
  reason_code VARCHAR(20)  NOT NULL,
  note        VARCHAR(255) NULL,
  status      ENUM('pending','approved','rejected','posted') NOT NULL DEFAULT 'pending',
  logged_by   INT UNSIGNED NOT NULL,
  approved_by INT UNSIGNED NULL,
  approved_at DATETIME     NULL,
  posted_at   DATETIME     NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_waste_no (waste_no),
  KEY idx_w_store (store_id, created_at),
  KEY idx_w_product (product_id),
  KEY idx_w_status (status),
  KEY idx_w_reason (reason_code),
  CONSTRAINT fk_w_store   FOREIGN KEY (store_id)   REFERENCES stores (id),
  CONSTRAINT fk_w_product FOREIGN KEY (product_id) REFERENCES products (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_adjustments (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  adj_no      VARCHAR(30)  NOT NULL,
  store_id    INT UNSIGNED NOT NULL,
  reason_code VARCHAR(20)  NOT NULL,
  note        VARCHAR(255) NULL,
  status      ENUM('draft','pending','approved','posted','rejected') NOT NULL DEFAULT 'draft',
  total_value DECIMAL(14,2) NOT NULL DEFAULT 0,
  created_by  INT UNSIGNED NOT NULL,
  approved_by INT UNSIGNED NULL,
  approved_at DATETIME     NULL,
  posted_at   DATETIME     NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_adj_no (adj_no),
  KEY idx_adj_store (store_id, status),
  CONSTRAINT fk_adj_store FOREIGN KEY (store_id) REFERENCES stores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_adjustment_lines (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  adj_id     BIGINT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  qty_delta  DECIMAL(12,3) NOT NULL COMMENT 'signed',
  unit_cost  DECIMAL(14,4) NOT NULL DEFAULT 0,
  note       VARCHAR(160) NULL,
  PRIMARY KEY (id),
  KEY idx_adjl_adj (adj_id),
  KEY idx_adjl_product (product_id),
  CONSTRAINT fk_adjl_adj     FOREIGN KEY (adj_id)     REFERENCES stock_adjustments (id) ON DELETE CASCADE,
  CONSTRAINT fk_adjl_product FOREIGN KEY (product_id) REFERENCES products (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================================
-- 5. SALES
-- ====================================================================

CREATE TABLE IF NOT EXISTS terminals (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code      VARCHAR(20)  NOT NULL,
  name      VARCHAR(80)  NOT NULL,
  store_id  INT UNSIGNED NOT NULL,
  is_active TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_term_code (code),
  KEY idx_term_store (store_id),
  CONSTRAINT fk_term_store FOREIGN KEY (store_id) REFERENCES stores (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customers (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code          VARCHAR(20)  NOT NULL,
  name          VARCHAR(140) NOT NULL,
  phone         VARCHAR(40)  NULL,
  email         VARCHAR(160) NULL,
  loyalty_card  VARCHAR(30)  NULL,
  points_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
  credit_limit  DECIMAL(14,2) NOT NULL DEFAULT 0,
  balance       DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'owed by the customer on account',
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  joined_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cust_code (code),
  UNIQUE KEY uq_cust_card (loyalty_card),
  KEY idx_cust_phone (phone),
  KEY idx_cust_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A cashier's shift at one terminal. Everything a till does hangs off a session,
-- so cash is always attributable: opening float in, sales and movements during,
-- declared cash out, and the variance between declared and expected.
CREATE TABLE IF NOT EXISTS till_sessions (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_no     VARCHAR(30)  NOT NULL,
  store_id       INT UNSIGNED NOT NULL,
  terminal_id    INT UNSIGNED NOT NULL,
  user_id        INT UNSIGNED NOT NULL COMMENT 'the cashier',
  opened_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  opening_float  DECIMAL(14,2) NOT NULL DEFAULT 0,
  closed_at      DATETIME     NULL,
  expected_cash  DECIMAL(14,2) NULL COMMENT 'float + cash sales - refunds + pay-ins - pay-outs - drops',
  declared_cash  DECIMAL(14,2) NULL COMMENT 'what the cashier counted',
  variance       DECIMAL(14,2) NULL COMMENT 'declared - expected; negative is short',
  status         ENUM('open','closed','reconciled') NOT NULL DEFAULT 'open',
  closed_by      INT UNSIGNED NULL,
  -- A cashier closes their own till but may not clear their own variance.
  reconciled_by  INT UNSIGNED NULL,
  reconciled_at  DATETIME     NULL,
  note           VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ts_no (session_no),
  KEY idx_ts_store_status (store_id, status),
  KEY idx_ts_user (user_id, opened_at),
  KEY idx_ts_terminal (terminal_id, status),
  CONSTRAINT fk_ts_store    FOREIGN KEY (store_id)    REFERENCES stores (id),
  CONSTRAINT fk_ts_terminal FOREIGN KEY (terminal_id) REFERENCES terminals (id),
  CONSTRAINT fk_ts_user     FOREIGN KEY (user_id)     REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  receipt_no      VARCHAR(30)  NOT NULL,
  store_id        INT UNSIGNED NOT NULL,
  terminal_id     INT UNSIGNED NULL,
  session_id      BIGINT UNSIGNED NULL,
  customer_id     INT UNSIGNED NULL,
  cashier_id      INT UNSIGNED NOT NULL,
  business_date   DATE         NOT NULL COMMENT 'trading day; a sale after midnight still belongs to the shift',
  sold_at         DATETIME     NULL COMMENT 'set when the sale completes',
  status          ENUM('parked','completed','voided','refunded','partly_refunded') NOT NULL DEFAULT 'parked',
  subtotal        DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'net of VAT, after discounts',
  discount_total  DECIMAL(14,2) NOT NULL DEFAULT 0,
  vat_total       DECIMAL(14,2) NOT NULL DEFAULT 0,
  total           DECIMAL(14,2) NOT NULL DEFAULT 0,
  cost_total      DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'cost of goods at point of sale, for margin reporting',
  paid_total      DECIMAL(14,2) NOT NULL DEFAULT 0,
  change_given    DECIMAL(14,2) NOT NULL DEFAULT 0,
  refunded_total  DECIMAL(14,2) NOT NULL DEFAULT 0,
  points_earned   DECIMAL(12,2) NOT NULL DEFAULT 0,
  points_redeemed DECIMAL(12,2) NOT NULL DEFAULT 0,
  line_count      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  void_reason     VARCHAR(20)  NULL,
  void_note       VARCHAR(255) NULL,
  voided_by       INT UNSIGNED NULL,
  voided_at       DATETIME     NULL,
  note            VARCHAR(255) NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sale_no (receipt_no),
  KEY idx_sale_store_date (store_id, business_date),
  KEY idx_sale_cashier (cashier_id, business_date),
  KEY idx_sale_session (session_id),
  KEY idx_sale_status (status),
  KEY idx_sale_customer (customer_id),
  KEY idx_sale_soldat (sold_at),
  CONSTRAINT fk_sale_store    FOREIGN KEY (store_id)    REFERENCES stores (id),
  CONSTRAINT fk_sale_cashier  FOREIGN KEY (cashier_id)  REFERENCES users (id),
  CONSTRAINT fk_sale_session  FOREIGN KEY (session_id)  REFERENCES till_sessions (id) ON DELETE SET NULL,
  CONSTRAINT fk_sale_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL,
  CONSTRAINT fk_sale_terminal FOREIGN KEY (terminal_id) REFERENCES terminals (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sale_lines (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sale_id        BIGINT UNSIGNED NOT NULL,
  product_id     INT UNSIGNED NOT NULL,
  batch_id       BIGINT UNSIGNED NULL COMMENT 'FEFO batch the units came from, when tracked',
  description    VARCHAR(160) NOT NULL COMMENT 'name as sold, frozen against later renames',
  qty            DECIMAL(12,3) NOT NULL,
  unit_price     DECIMAL(14,2) NOT NULL COMMENT 'VAT-inclusive price actually charged',
  list_price     DECIMAL(14,2) NOT NULL COMMENT 'shelf price before promotion or override',
  unit_cost      DECIMAL(14,4) NOT NULL DEFAULT 0,
  line_discount  DECIMAL(14,2) NOT NULL DEFAULT 0,
  discount_reason VARCHAR(20)  NULL,
  promotion_id   INT UNSIGNED NULL,
  vat_rate       DECIMAL(6,4) NOT NULL DEFAULT 0,
  vat_amount     DECIMAL(14,2) NOT NULL DEFAULT 0,
  line_total     DECIMAL(14,2) NOT NULL COMMENT 'VAT-inclusive, after discount',
  qty_refunded   DECIMAL(12,3) NOT NULL DEFAULT 0,
  price_overridden TINYINT(1) NOT NULL DEFAULT 0,
  overridden_by  INT UNSIGNED NULL COMMENT 'supervisor whose PIN authorised it',
  PRIMARY KEY (id),
  KEY idx_sl_sale (sale_id),
  KEY idx_sl_product (product_id),
  KEY idx_sl_promo (promotion_id),
  KEY idx_sl_override (price_overridden),
  CONSTRAINT fk_sline_sale    FOREIGN KEY (sale_id)    REFERENCES sales (id) ON DELETE CASCADE,
  CONSTRAINT fk_sline_product FOREIGN KEY (product_id) REFERENCES products (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sale_payments (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sale_id    BIGINT UNSIGNED NOT NULL,
  method     ENUM('cash','card','transfer','mobile','voucher','points','credit') NOT NULL,
  amount     DECIMAL(14,2) NOT NULL,
  tendered   DECIMAL(14,2) NULL COMMENT 'cash handed over, before change',
  reference  VARCHAR(60)  NULL COMMENT 'card auth code, transfer ref, voucher code',
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sp_sale (sale_id),
  KEY idx_sp_method (method, created_at),
  CONSTRAINT fk_sp_sale FOREIGN KEY (sale_id) REFERENCES sales (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS refunds (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  refund_no    VARCHAR(30)  NOT NULL,
  sale_id      BIGINT UNSIGNED NOT NULL COMMENT 'a refund always points at an original sale',
  store_id     INT UNSIGNED NOT NULL,
  session_id   BIGINT UNSIGNED NULL,
  cashier_id   INT UNSIGNED NOT NULL,
  reason_code  VARCHAR(20)  NOT NULL,
  note         VARCHAR(255) NULL,
  total        DECIMAL(14,2) NOT NULL DEFAULT 0,
  method       ENUM('cash','card','transfer','mobile','voucher','credit') NOT NULL DEFAULT 'cash',
  restock      TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0 for spoiled goods: money back, stock stays out',
  -- Posted at the till by someone who held refund.approve, under the limit, so
  -- policy required no second person. Distinguishes that from a refund that
  -- went to the queue and was then approved by its own raiser, which is a breach.
  self_approved TINYINT(1)  NOT NULL DEFAULT 0,
  status       ENUM('pending','approved','rejected','posted') NOT NULL DEFAULT 'pending',
  requested_by INT UNSIGNED NOT NULL,
  approved_by  INT UNSIGNED NULL,
  approved_at  DATETIME     NULL,
  rejected_note VARCHAR(255) NULL,
  posted_at    DATETIME     NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_refund_no (refund_no),
  KEY idx_rf_sale (sale_id),
  KEY idx_rf_store (store_id, created_at),
  KEY idx_rf_status (status),
  KEY idx_rf_cashier (cashier_id, created_at),
  CONSTRAINT fk_rf_sale  FOREIGN KEY (sale_id)  REFERENCES sales (id),
  CONSTRAINT fk_rf_store FOREIGN KEY (store_id) REFERENCES stores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS refund_lines (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  refund_id    BIGINT UNSIGNED NOT NULL,
  sale_line_id BIGINT UNSIGNED NOT NULL,
  product_id   INT UNSIGNED NOT NULL,
  qty          DECIMAL(12,3) NOT NULL,
  unit_price   DECIMAL(14,2) NOT NULL,
  unit_cost    DECIMAL(14,4) NOT NULL DEFAULT 0,
  vat_amount   DECIMAL(14,2) NOT NULL DEFAULT 0,
  line_total   DECIMAL(14,2) NOT NULL,
  restock      TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_rfl_refund (refund_id),
  KEY idx_rfl_saleline (sale_line_id),
  CONSTRAINT fk_rfl_refund   FOREIGN KEY (refund_id)    REFERENCES refunds (id) ON DELETE CASCADE,
  CONSTRAINT fk_rfl_saleline FOREIGN KEY (sale_line_id) REFERENCES sale_lines (id),
  CONSTRAINT fk_rfl_product  FOREIGN KEY (product_id)   REFERENCES products (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Money in and out of the drawer that is not a sale. no_sale rows have a zero
-- amount and exist only so opening the drawer is on the record - a drawer that
-- opens often between sales is one of the audit rules.
CREATE TABLE IF NOT EXISTS cash_movements (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id    BIGINT UNSIGNED NOT NULL,
  store_id      INT UNSIGNED NOT NULL,
  type          ENUM('float_in','pay_in','pay_out','drop','no_sale') NOT NULL,
  amount        DECIMAL(14,2) NOT NULL DEFAULT 0,
  reason_code   VARCHAR(20)  NULL,
  note          VARCHAR(255) NULL,
  user_id       INT UNSIGNED NOT NULL,
  authorised_by INT UNSIGNED NULL COMMENT 'supervisor PIN, for pay-outs and drops',
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cm_session (session_id),
  KEY idx_cm_store_time (store_id, created_at),
  KEY idx_cm_type (type, created_at),
  CONSTRAINT fk_cm_session FOREIGN KEY (session_id) REFERENCES till_sessions (id) ON DELETE CASCADE,
  CONSTRAINT fk_cm_store   FOREIGN KEY (store_id)   REFERENCES stores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Promotions ------------------------------------------------------
CREATE TABLE IF NOT EXISTS promotions (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(20)  NOT NULL,
  name        VARCHAR(140) NOT NULL,
  promo_type  ENUM('pct_off','fixed_price','bogo','bundle_pct') NOT NULL,
  scope       ENUM('product','category','all') NOT NULL DEFAULT 'product',
  product_id  INT UNSIGNED NULL,
  category_id INT UNSIGNED NULL,
  value       DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'percent for pct_off/bundle_pct, price for fixed_price',
  min_qty     DECIMAL(12,3) NOT NULL DEFAULT 1 COMMENT 'buy this many ...',
  free_qty    DECIMAL(12,3) NOT NULL DEFAULT 0 COMMENT '... get this many free (bogo)',
  starts_at   DATETIME     NOT NULL,
  ends_at     DATETIME     NOT NULL,
  hour_from   TINYINT UNSIGNED NULL COMMENT 'happy-hour window, 0-23',
  hour_to     TINYINT UNSIGNED NULL,
  days_mask   TINYINT UNSIGNED NOT NULL DEFAULT 127 COMMENT 'bitmask, bit0=Mon .. bit6=Sun',
  priority    SMALLINT UNSIGNED NOT NULL DEFAULT 100 COMMENT 'lowest wins when several apply',
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_by  INT UNSIGNED NULL,
  approved_by INT UNSIGNED NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_promo_code (code),
  KEY idx_promo_window (is_active, starts_at, ends_at),
  KEY idx_promo_product (product_id),
  KEY idx_promo_cat (category_id),
  CONSTRAINT fk_promo_product FOREIGN KEY (product_id)  REFERENCES products (id)   ON DELETE CASCADE,
  CONSTRAINT fk_promo_cat     FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loyalty_ledger (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id  INT UNSIGNED NOT NULL,
  sale_id      BIGINT UNSIGNED NULL,
  kind         ENUM('earn','redeem','adjust','expire') NOT NULL,
  points_delta DECIMAL(12,2) NOT NULL,
  balance_after DECIMAL(12,2) NOT NULL,
  note         VARCHAR(160) NULL,
  user_id      INT UNSIGNED NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ll_customer (customer_id, created_at),
  KEY idx_ll_sale (sale_id),
  CONSTRAINT fk_ll_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE,
  CONSTRAINT fk_ll_sale     FOREIGN KEY (sale_id)     REFERENCES sales (id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vouchers (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(30)  NOT NULL,
  face_value  DECIMAL(14,2) NOT NULL,
  balance     DECIMAL(14,2) NOT NULL,
  status      ENUM('active','redeemed','expired','cancelled') NOT NULL DEFAULT 'active',
  issued_by   INT UNSIGNED NULL,
  issued_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at  DATE         NULL,
  last_used_at DATETIME    NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_voucher_code (code),
  KEY idx_voucher_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Day end ---------------------------------------------------------
-- The Z-report, persisted. Once a business date is closed its figures are
-- frozen; a late correction has to be a dated adjustment, not a quiet edit.
CREATE TABLE IF NOT EXISTS day_end_closures (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id       INT UNSIGNED NOT NULL,
  business_date  DATE         NOT NULL,
  status         ENUM('open','closed') NOT NULL DEFAULT 'open',
  gross_sales    DECIMAL(14,2) NOT NULL DEFAULT 0,
  discount_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  refund_total   DECIMAL(14,2) NOT NULL DEFAULT 0,
  net_sales      DECIMAL(14,2) NOT NULL DEFAULT 0,
  vat_total      DECIMAL(14,2) NOT NULL DEFAULT 0,
  cost_total     DECIMAL(14,2) NOT NULL DEFAULT 0,
  gross_profit   DECIMAL(14,2) NOT NULL DEFAULT 0,
  cash_expected  DECIMAL(14,2) NOT NULL DEFAULT 0,
  cash_declared  DECIMAL(14,2) NOT NULL DEFAULT 0,
  cash_variance  DECIMAL(14,2) NOT NULL DEFAULT 0,
  transaction_count INT UNSIGNED NOT NULL DEFAULT 0,
  void_count     INT UNSIGNED NOT NULL DEFAULT 0,
  closed_by      INT UNSIGNED NULL,
  closed_at      DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dec (store_id, business_date),
  CONSTRAINT fk_dec_store FOREIGN KEY (store_id) REFERENCES stores (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================================
-- 6. AUDITING
-- ====================================================================

-- The rule catalogue. Thresholds live in data so an auditor can tighten a rule
-- without a deploy, and so a report can state the threshold that was in force.
CREATE TABLE IF NOT EXISTS audit_rules (
  code        VARCHAR(40)  NOT NULL,
  name        VARCHAR(140) NOT NULL,
  description VARCHAR(500) NOT NULL,
  module      VARCHAR(30)  NOT NULL,
  severity    ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  threshold   DECIMAL(14,2) NULL,
  threshold_label VARCHAR(80) NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (code),
  KEY idx_ar_module (module, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_exceptions (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  rule_code    VARCHAR(40)  NOT NULL,
  store_id     INT UNSIGNED NULL,
  subject_user_id INT UNSIGNED NULL COMMENT 'whose behaviour the exception is about',
  entity       VARCHAR(40)  NULL,
  entity_id    BIGINT UNSIGNED NULL,
  period_date  DATE         NULL COMMENT 'the trading day scanned',
  severity     ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  metric_value DECIMAL(14,2) NULL,
  threshold    DECIMAL(14,2) NULL,
  summary      VARCHAR(255) NOT NULL,
  detail_json  JSON         NULL,
  status       ENUM('open','reviewing','cleared','confirmed','escalated') NOT NULL DEFAULT 'open',
  reviewed_by  INT UNSIGNED NULL,
  reviewed_at  DATETIME     NULL,
  disposition  VARCHAR(40)  NULL,
  disposition_note VARCHAR(500) NULL,
  detected_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  -- One exception per rule per subject per day: re-running the scan updates
  -- rather than duplicating, so the queue does not grow on every cron tick.
  UNIQUE KEY uq_ae_dedupe (rule_code, period_date, store_id, subject_user_id, entity_id),
  KEY idx_ae_status (status, severity),
  KEY idx_ae_store (store_id, period_date),
  KEY idx_ae_subject (subject_user_id, period_date),
  KEY idx_ae_rule (rule_code, period_date),
  CONSTRAINT fk_ae_rule FOREIGN KEY (rule_code) REFERENCES audit_rules (code) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every disposition, kept even when the status later changes, so "who cleared
-- this and on what grounds" survives a second reviewer disagreeing.
CREATE TABLE IF NOT EXISTS audit_reviews (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  exception_id BIGINT UNSIGNED NOT NULL,
  reviewer_id  INT UNSIGNED NOT NULL,
  action       VARCHAR(40)  NOT NULL,
  note         VARCHAR(500) NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_arev_exc (exception_id, created_at),
  CONSTRAINT fk_arev_exc FOREIGN KEY (exception_id) REFERENCES audit_exceptions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_scan_runs (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  period_date    DATE         NOT NULL,
  store_id       INT UNSIGNED NULL,
  rules_run      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  exceptions_new INT UNSIGNED NOT NULL DEFAULT 0,
  exceptions_updated INT UNSIGNED NOT NULL DEFAULT 0,
  started_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at    DATETIME     NULL,
  run_by         INT UNSIGNED NULL,
  trigger_source VARCHAR(20)  NOT NULL DEFAULT 'manual',
  PRIMARY KEY (id),
  KEY idx_asr_period (period_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- In-app alerts. Addressed to a role or a user; a store manager and the group
-- auditor want different things.
CREATE TABLE IF NOT EXISTS notifications (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NULL,
  role_code  VARCHAR(40)  NULL,
  store_id   INT UNSIGNED NULL,
  severity   ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
  title      VARCHAR(160) NOT NULL,
  body       VARCHAR(500) NULL,
  link       VARCHAR(255) NULL,
  read_at    DATETIME     NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notif_user (user_id, read_at),
  KEY idx_notif_role (role_code, read_at),
  KEY idx_notif_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
