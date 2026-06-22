# KonX Affiliate Dashboard — Database Schema

## Overview

This document defines the custom database tables for the KonX Affiliate Dashboard plugin. All tables use the WordPress table prefix (`$wpdb->prefix`, typically `wp_`) followed by the `konx_` namespace.

The plugin also stores data in:
- **WordPress user meta** (`wp_usermeta`) — affiliate role flag, affiliate type, referral code (duplicated for fast lookup)
- **WooCommerce order meta** (`wp_postmeta` or HPOS `wp_wc_orders_meta`) — referrer affiliate ID per order (`_konx_referrer_id`)
- **WordPress options** (`wp_options`) — plugin settings under the key `konx_affiliate_settings`

Custom tables are used for high-volume transactional data (clicks, commissions, wallet entries) where querying wp_postmeta would be inefficient.

---

## Table Summary

| # | Table Name | Purpose |
|---|---|---|
| 1 | `wp_konx_affiliates` | Affiliate profiles and status |
| 2 | `wp_konx_referral_clicks` | Raw referral link click tracking |
| 3 | `wp_konx_referral_conversions` | Successful referral-to-order attribution |
| 4 | `wp_konx_commissions` | Individual commission transaction records |
| 5 | `wp_konx_wallet_ledger` | Append-only wallet credit/debit ledger |
| 6 | `wp_konx_withdrawals` | Withdrawal requests and payout status |
| 7 | `wp_konx_admin_fees` | Admin fee records per affiliate |
| 8 | `wp_konx_milestones` | Milestone bonus records |
| 9 | `wp_konx_commission_rules` | Commission rate rules per affiliate type and product type |
| 10 | `wp_konx_product_map` | WooCommerce product ID to internal product type mapping |

---

## 1. `wp_konx_affiliates`

Stores one row per affiliate. Links to a WordPress user via `user_id`.

| Column | Type | Nullable | Default | Purpose |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | AUTO_INCREMENT | Primary key |
| `user_id` | `BIGINT UNSIGNED` | NO | — | WordPress user ID (`wp_users.ID`) |
| `affiliate_type` | `VARCHAR(20)` | NO | `'referral'` | Affiliate type (see values below) |
| `referral_code` | `VARCHAR(12)` | NO | — | Unique referral code (8-char alphanumeric, uppercase) |
| `status` | `VARCHAR(20)` | NO | `'active'` | Affiliate account status |
| `completed_sales` | `INT UNSIGNED` | NO | `0` | Running count of completed paid sales |
| `parent_affiliate_id` | `BIGINT UNSIGNED` | YES | `NULL` | The affiliate who referred this affiliate (self-referral tracking) |
| `payment_email` | `VARCHAR(255)` | YES | `NULL` | Email used for Wise payouts |
| `notes` | `TEXT` | YES | `NULL` | Admin notes |
| `registered_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP` | When the affiliate registered |
| `updated_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP` | Last profile update |

### Affiliate Type Values

| Value | Label |
|---|---|
| `business` | Business Affiliate |
| `referral` | Referral Affiliate |
| `team_agent` | Team Agent |
| `marketing_agent` | Marketing Agent |
| `sales_agent` | Sales Agent |

### Affiliate Status Values

| Value | Meaning |
|---|---|
| `active` | Can earn commissions and request withdrawals |
| `inactive` | Disabled by admin — no commissions earned, no dashboard access |
| `pending` | Awaiting admin approval (if manual approval is enabled) |

### Indexes

| Index Name | Columns | Type | Purpose |
|---|---|---|---|
| `PRIMARY` | `id` | PRIMARY | Row identity |
| `uq_user_id` | `user_id` | UNIQUE | One affiliate record per WordPress user |
| `uq_referral_code` | `referral_code` | UNIQUE | Fast lookup by referral code, prevent duplicates |
| `idx_affiliate_type` | `affiliate_type` | INDEX | Filter affiliates by type |
| `idx_status` | `status` | INDEX | Filter by status |
| `idx_parent_affiliate` | `parent_affiliate_id` | INDEX | Look up who referred whom |

### User Meta (stored in `wp_usermeta`)

These values are duplicated in user meta for use with WordPress functions (`get_user_meta`, `WP_User_Query`):

| Meta Key | Value | Purpose |
|---|---|---|
| `konx_affiliate_id` | Affiliate table ID | Quick lookup without joining custom table |
| `konx_affiliate_type` | `business`, `referral`, etc. | Used in capability checks and display |
| `konx_referral_code` | `ABC12345` | Used in referral URL generation |

---

## 2. `wp_konx_referral_clicks`

Logs every inbound click on a referral link. Used for analytics (click-through rates, geographic data). High-volume table.

| Column | Type | Nullable | Default | Purpose |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | AUTO_INCREMENT | Primary key |
| `affiliate_id` | `BIGINT UNSIGNED` | NO | — | The affiliate whose link was clicked |
| `referral_code` | `VARCHAR(12)` | NO | — | The referral code from the URL |
| `ip_hash` | `VARCHAR(64)` | NO | — | SHA-256 hash of visitor IP (privacy-safe) |
| `user_agent` | `VARCHAR(500)` | YES | `NULL` | Browser user agent string |
| `landing_url` | `VARCHAR(2048)` | YES | `NULL` | The page the visitor landed on |
| `referrer_url` | `VARCHAR(2048)` | YES | `NULL` | HTTP referer header (where the click came from) |
| `converted` | `TINYINT(1)` | NO | `0` | Whether this click led to a purchase |
| `clicked_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP` | When the click occurred |

### Indexes

| Index Name | Columns | Type | Purpose |
|---|---|---|---|
| `PRIMARY` | `id` | PRIMARY | Row identity |
| `idx_affiliate_id` | `affiliate_id` | INDEX | Click count per affiliate |
| `idx_clicked_at` | `clicked_at` | INDEX | Date-range queries for reporting |
| `idx_affiliate_date` | `affiliate_id, clicked_at` | INDEX | Affiliate + date range reports |
| `idx_ip_hash` | `ip_hash` | INDEX | Duplicate click detection within time window |

### Notes

- IP addresses are stored as SHA-256 hashes for GDPR/privacy compliance. The raw IP is never persisted.
- Duplicate click suppression: clicks from the same `ip_hash` for the same `affiliate_id` within a 24-hour window are ignored (checked at insert time, not enforced by unique constraint, since the window is time-based).

---

## 3. `wp_konx_referral_conversions`

Records the link between a referral click and a WooCommerce order. One row per referred order. This is the attribution record.

| Column | Type | Nullable | Default | Purpose |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | AUTO_INCREMENT | Primary key |
| `affiliate_id` | `BIGINT UNSIGNED` | NO | — | The affiliate who earned the referral |
| `order_id` | `BIGINT UNSIGNED` | NO | — | WooCommerce order ID (`wp_posts.ID` or HPOS order ID) |
| `customer_user_id` | `BIGINT UNSIGNED` | YES | `NULL` | WordPress user ID of the customer (NULL for guest checkout) |
| `referral_code` | `VARCHAR(12)` | NO | — | The referral code used |
| `click_id` | `BIGINT UNSIGNED` | YES | `NULL` | FK to `wp_konx_referral_clicks.id` (NULL if cookie was set before click tracking existed) |
| `order_total` | `DECIMAL(12,2)` | NO | — | Full order total at time of conversion (informational) |
| `is_subscription_renewal` | `TINYINT(1)` | NO | `0` | Whether this is a YITH subscription renewal order |
| `subscription_id` | `BIGINT UNSIGNED` | YES | `NULL` | YITH subscription ID (for renewal attribution persistence) |
| `converted_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP` | When the conversion was recorded |

### Indexes

| Index Name | Columns | Type | Purpose |
|---|---|---|---|
| `PRIMARY` | `id` | PRIMARY | Row identity |
| `uq_order_id` | `order_id` | UNIQUE | One conversion per order — prevents duplicate commissions |
| `idx_affiliate_id` | `affiliate_id` | INDEX | Conversions per affiliate |
| `idx_customer_user_id` | `customer_user_id` | INDEX | Orders by customer |
| `idx_subscription_id` | `subscription_id` | INDEX | Renewal lookups by subscription |
| `idx_converted_at` | `converted_at` | INDEX | Date-range reporting |

### WooCommerce Order ID Notes

- `order_id` references the WooCommerce order. In classic WooCommerce this is `wp_posts.ID` with `post_type = 'shop_order'`. Under WooCommerce HPOS (High-Performance Order Storage), this is the ID in the `wp_wc_orders` table. The plugin stores the ID as returned by `$order->get_id()` which is consistent across both storage modes.
- The referrer affiliate ID is also stored as WooCommerce order meta (`_konx_referrer_id`) for quick access during order processing without joining this table.

### YITH Subscription Renewal Notes

- When a customer initially purchases a subscription via a referral, the `affiliate_id` is stored in the conversion record and as order meta.
- On YITH subscription renewal (`ywsbs_renew_order_payed`), the plugin creates a new conversion row with `is_subscription_renewal = 1` and the `subscription_id` set. The `affiliate_id` is copied from the original subscription order's meta, preserving attribution indefinitely.

---

## 4. `wp_konx_commissions`

Stores individual commission transactions. One row per commission-eligible line item per order. An order with two commission-eligible products produces two rows.

| Column | Type | Nullable | Default | Purpose |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | AUTO_INCREMENT | Primary key |
| `affiliate_id` | `BIGINT UNSIGNED` | NO | — | The affiliate earning the commission |
| `conversion_id` | `BIGINT UNSIGNED` | NO | — | FK to `wp_konx_referral_conversions.id` |
| `order_id` | `BIGINT UNSIGNED` | NO | — | WooCommerce order ID (denormalized for fast queries) |
| `order_item_id` | `BIGINT UNSIGNED` | NO | — | WooCommerce order line item ID |
| `product_id` | `BIGINT UNSIGNED` | NO | — | WooCommerce product ID |
| `product_type` | `VARCHAR(30)` | NO | — | Internal product type (e.g., `starter_pack`, `pro_pack`) |
| `affiliate_type_at_sale` | `VARCHAR(20)` | NO | — | Affiliate type at time of sale (snapshot, not current) |
| `product_price` | `DECIMAL(12,2)` | NO | — | Full product price before gateway fees/taxes |
| `commission_rate` | `DECIMAL(5,4)` | NO | — | Rate applied (e.g., `0.4000` for 40%) |
| `commission_amount` | `DECIMAL(12,2)` | NO | — | Calculated: `product_price × commission_rate` |
| `commission_type` | `VARCHAR(20)` | NO | — | `one_time` or `recurring` |
| `status` | `VARCHAR(20)` | NO | `'pending'` | Commission status (see values below) |
| `blocked_reason` | `VARCHAR(50)` | YES | `NULL` | Reason if status is `blocked` (e.g., `unpaid_admin_fee`) |
| `ledger_entry_id` | `BIGINT UNSIGNED` | YES | `NULL` | FK to `wp_konx_wallet_ledger.id` when credited |
| `created_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP` | When the commission was created |
| `updated_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP` | Last status change |

### Commission Status Values

| Value | Meaning |
|---|---|
| `pending` | Order placed but not yet completed |
| `approved` | Order completed, commission credited to wallet |
| `blocked` | Commission earned but held — admin fee unpaid |
| `reversed` | Order refunded, commission debited from wallet |

### Commission Type Values

| Value | Meaning |
|---|---|
| `one_time` | One-time commission on pack purchase |
| `recurring` | Recurring commission on subscription renewal or eCard renewal |

### Indexes

| Index Name | Columns | Type | Purpose |
|---|---|---|---|
| `PRIMARY` | `id` | PRIMARY | Row identity |
| `uq_order_item` | `order_id, order_item_id` | UNIQUE | Prevents duplicate commissions for the same line item |
| `idx_affiliate_id` | `affiliate_id` | INDEX | Commissions per affiliate |
| `idx_affiliate_status` | `affiliate_id, status` | INDEX | Affiliate + status filter (approved, blocked, etc.) |
| `idx_conversion_id` | `conversion_id` | INDEX | Commissions per conversion |
| `idx_order_id` | `order_id` | INDEX | Commissions per order |
| `idx_status` | `status` | INDEX | Filter by status globally |
| `idx_created_at` | `created_at` | INDEX | Date-range reporting |

### Why `affiliate_type_at_sale` Is Snapshotted

An admin can change an affiliate's type at any time. If the commission rate were derived from the affiliate's current type, historical commissions would retroactively change. Snapshotting the type at sale time preserves the correct rate that was applied.

### Commission Base Note

`product_price` is the full WooCommerce product price as defined on the product, **before** payment gateway fees and taxes. It is read from the order line item via `$item->get_total()` (which is the line total before tax) or directly from the product price depending on configuration. This ensures commissions are consistent regardless of which gateway the customer uses.

---

## 5. `wp_konx_wallet_ledger`

Append-only ledger. Every credit and debit is a row. The affiliate's balance is derived by summing all entries for that affiliate. No balance column is maintained — the sum is the source of truth.

| Column | Type | Nullable | Default | Purpose |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | AUTO_INCREMENT | Primary key |
| `affiliate_id` | `BIGINT UNSIGNED` | NO | — | The affiliate whose wallet is affected |
| `entry_type` | `VARCHAR(30)` | NO | — | Type of ledger entry (see values below) |
| `amount` | `DECIMAL(12,2)` | NO | — | Positive = credit, negative = debit |
| `running_balance` | `DECIMAL(12,2)` | NO | — | Balance after this entry (denormalized for display) |
| `reference_type` | `VARCHAR(30)` | NO | — | What this entry references (see values below) |
| `reference_id` | `BIGINT UNSIGNED` | YES | `NULL` | ID of the referenced record |
| `description` | `VARCHAR(500)` | NO | — | Human-readable description |
| `created_by` | `BIGINT UNSIGNED` | YES | `NULL` | WordPress user ID of actor (NULL = system) |
| `created_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP` | When the entry was created |

### Entry Type Values

| Value | Direction | Trigger |
|---|---|---|
| `commission` | Credit (+) | One-time commission approved |
| `recurring_commission` | Credit (+) | Recurring commission approved |
| `milestone_bonus` | Credit (+) | 100-sale milestone reached |
| `withdrawal` | Debit (−) | Withdrawal completed |
| `reversal` | Debit (−) | Commission reversed (order refunded) |
| `adjustment` | Credit or Debit | Manual admin adjustment |

### Reference Type Values

| Value | `reference_id` Points To |
|---|---|
| `commission` | `wp_konx_commissions.id` |
| `withdrawal` | `wp_konx_withdrawals.id` |
| `milestone` | `wp_konx_milestones.id` |
| `admin` | `NULL` (description explains the adjustment) |

### Indexes

| Index Name | Columns | Type | Purpose |
|---|---|---|---|
| `PRIMARY` | `id` | PRIMARY | Row identity |
| `idx_affiliate_id` | `affiliate_id` | INDEX | All entries for an affiliate (balance calculation) |
| `idx_affiliate_type` | `affiliate_id, entry_type` | INDEX | Filtered ledger views |
| `idx_reference` | `reference_type, reference_id` | INDEX | Find ledger entry for a specific commission/withdrawal |
| `idx_created_at` | `created_at` | INDEX | Date-range queries |

### Running Balance

The `running_balance` column is a denormalized convenience field updated at insert time. It exists for display purposes (showing the balance after each transaction in the ledger view). The authoritative balance is always `SUM(amount) WHERE affiliate_id = X`. If any discrepancy is detected, the running balance can be recalculated from the sum.

### Append-Only Rule

In normal operation, rows are never updated or deleted. Corrections are made by inserting a new `reversal` or `adjustment` entry. The only exception is a full data migration or repair operation performed by an admin.

---

## 6. `wp_konx_withdrawals`

Stores withdrawal requests submitted by affiliates and managed by admins.

| Column | Type | Nullable | Default | Purpose |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | AUTO_INCREMENT | Primary key |
| `affiliate_id` | `BIGINT UNSIGNED` | NO | — | The requesting affiliate |
| `amount` | `DECIMAL(12,2)` | NO | — | Requested withdrawal amount |
| `payment_method` | `VARCHAR(50)` | NO | `'wise'` | Payment method (currently only Wise) |
| `payment_email` | `VARCHAR(255)` | NO | — | Email for payout (snapshot at request time) |
| `status` | `VARCHAR(20)` | NO | `'pending'` | Withdrawal status (see values below) |
| `admin_user_id` | `BIGINT UNSIGNED` | YES | `NULL` | Admin who processed the withdrawal |
| `admin_note` | `TEXT` | YES | `NULL` | Admin note (especially for rejections) |
| `transaction_reference` | `VARCHAR(255)` | YES | `NULL` | Wise transaction reference or ID |
| `ledger_entry_id` | `BIGINT UNSIGNED` | YES | `NULL` | FK to `wp_konx_wallet_ledger.id` when completed |
| `requested_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP` | When the affiliate submitted the request |
| `processed_at` | `DATETIME` | YES | `NULL` | When the admin completed or rejected the request |

### Withdrawal Status Values

| Value | Meaning |
|---|---|
| `pending` | Submitted by affiliate, awaiting admin review |
| `approved` | Admin approved, payment in progress |
| `completed` | Admin paid via Wise and marked complete — wallet debited |
| `rejected` | Admin rejected with reason |

### Indexes

| Index Name | Columns | Type | Purpose |
|---|---|---|---|
| `PRIMARY` | `id` | PRIMARY | Row identity |
| `idx_affiliate_id` | `affiliate_id` | INDEX | Withdrawal history per affiliate |
| `idx_affiliate_status` | `affiliate_id, status` | INDEX | Check for existing pending withdrawal |
| `idx_status` | `status` | INDEX | Admin views filtered by status |
| `idx_requested_at` | `requested_at` | INDEX | Date-range queries |

### One Pending Withdrawal Rule

An affiliate can only have **one withdrawal with status `pending` or `approved`** at a time. This is enforced in application logic by checking `SELECT COUNT(*) FROM wp_konx_withdrawals WHERE affiliate_id = X AND status IN ('pending', 'approved')` before inserting a new request. A partial unique index is not available in MySQL/MariaDB, so application-level enforcement is used.

### Wallet Debit Timing

The wallet is **not debited when the withdrawal is requested or approved**. The debit happens only when the admin marks the withdrawal as `completed`. This ensures the balance reflects money that has actually been paid out.

---

## 7. `wp_konx_admin_fees`

Tracks admin fee obligations and payment status per affiliate per period.

| Column | Type | Nullable | Default | Purpose |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | AUTO_INCREMENT | Primary key |
| `affiliate_id` | `BIGINT UNSIGNED` | NO | — | The affiliate who owes the fee |
| `fee_amount` | `DECIMAL(12,2)` | NO | — | Amount due |
| `fee_period` | `VARCHAR(20)` | NO | — | Period label (e.g., `2026-Q1`, `2026-06`) |
| `due_date` | `DATE` | NO | — | When the fee is due |
| `status` | `VARCHAR(20)` | NO | `'unpaid'` | Payment status |
| `paid_date` | `DATE` | YES | `NULL` | When payment was recorded |
| `paid_by_admin_id` | `BIGINT UNSIGNED` | YES | `NULL` | Admin who marked it paid |
| `notes` | `TEXT` | YES | `NULL` | Admin notes |
| `created_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP` | When the fee record was created |
| `updated_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP` | Last status change |

### Admin Fee Status Values

| Value | Meaning |
|---|---|
| `unpaid` | Fee is due but not yet paid |
| `overdue` | Past due date and still unpaid |
| `paid` | Fee has been paid and recorded |
| `waived` | Admin waived the fee |

### Indexes

| Index Name | Columns | Type | Purpose |
|---|---|---|---|
| `PRIMARY` | `id` | PRIMARY | Row identity |
| `uq_affiliate_period` | `affiliate_id, fee_period` | UNIQUE | One fee record per affiliate per period |
| `idx_affiliate_id` | `affiliate_id` | INDEX | Fee history per affiliate |
| `idx_status` | `status` | INDEX | Find all unpaid/overdue fees |
| `idx_due_date` | `due_date` | INDEX | Upcoming/overdue fee queries |

### Commission Blocking Logic

Before crediting a commission, the system checks:

```sql
SELECT COUNT(*) FROM wp_konx_admin_fees
WHERE affiliate_id = %d
  AND status IN ('unpaid', 'overdue')
```

If count > 0, the commission status is set to `blocked` with `blocked_reason = 'unpaid_admin_fee'` and the wallet is **not** credited. When the admin marks all outstanding fees as `paid`, the system queries all `blocked` commissions for that affiliate and re-processes them (changes status to `approved`, credits the wallet).

---

## 8. `wp_konx_milestones`

Records each milestone bonus earned by an affiliate. One row per milestone event.

| Column | Type | Nullable | Default | Purpose |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | AUTO_INCREMENT | Primary key |
| `affiliate_id` | `BIGINT UNSIGNED` | NO | — | The affiliate who reached the milestone |
| `milestone_number` | `INT UNSIGNED` | NO | — | Which milestone (1 = first 100 sales, 2 = second 100 sales, ...) |
| `sale_count_at_trigger` | `INT UNSIGNED` | NO | — | The completed sale count that triggered it (100, 200, 300, ...) |
| `sale_block_start` | `INT UNSIGNED` | NO | — | First sale in the block (1, 101, 201, ...) |
| `sale_block_end` | `INT UNSIGNED` | NO | — | Last sale in the block (100, 200, 300, ...) |
| `total_commissions_in_block` | `DECIMAL(12,2)` | NO | — | Sum of approved commissions in this 100-sale block |
| `bonus_amount` | `DECIMAL(12,2)` | NO | — | Bonus credited (equals `total_commissions_in_block`) |
| `status` | `VARCHAR(20)` | NO | `'approved'` | `approved` or `blocked` (if admin fee unpaid) |
| `ledger_entry_id` | `BIGINT UNSIGNED` | YES | `NULL` | FK to `wp_konx_wallet_ledger.id` when credited |
| `created_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP` | When the milestone was reached |

### Indexes

| Index Name | Columns | Type | Purpose |
|---|---|---|---|
| `PRIMARY` | `id` | PRIMARY | Row identity |
| `uq_affiliate_milestone` | `affiliate_id, milestone_number` | UNIQUE | Prevents duplicate milestone bonuses |
| `idx_affiliate_id` | `affiliate_id` | INDEX | Milestone history per affiliate |
| `idx_created_at` | `created_at` | INDEX | Date-range reporting |

### Milestone Calculation

When `wp_konx_affiliates.completed_sales` reaches a multiple of 100:

1. `milestone_number` = `completed_sales / 100`
2. `sale_block_start` = `completed_sales - 99`
3. `sale_block_end` = `completed_sales`
4. Sum approved commissions from commissions table where the affiliate's sale sequence falls within this block (using the commission `created_at` ordering and `ROW_NUMBER` or equivalent sequential logic)
5. `bonus_amount` = that sum
6. Credit to wallet (or block if admin fee unpaid)

---

## 9. `wp_konx_commission_rules`

Stores commission rate rules. Each row defines the rate for a specific affiliate type and product type combination. This table replaces hardcoded rates and allows admin configuration.

| Column | Type | Nullable | Default | Purpose |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | AUTO_INCREMENT | Primary key |
| `affiliate_type` | `VARCHAR(20)` | NO | — | Affiliate type this rule applies to |
| `product_type` | `VARCHAR(30)` | NO | — | Internal product type (e.g., `starter_pack`) |
| `commission_type` | `VARCHAR(20)` | NO | `'one_time'` | `one_time` or `recurring` |
| `rate` | `DECIMAL(5,4)` | NO | — | Commission rate as decimal (e.g., `0.4000` = 40%) |
| `is_active` | `TINYINT(1)` | NO | `1` | Whether this rule is currently active |
| `created_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP` | When the rule was created |
| `updated_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP` | Last modification |

### Indexes

| Index Name | Columns | Type | Purpose |
|---|---|---|---|
| `PRIMARY` | `id` | PRIMARY | Row identity |
| `uq_rule` | `affiliate_type, product_type, commission_type` | UNIQUE | One rate per combination |
| `idx_affiliate_type` | `affiliate_type` | INDEX | Look up all rules for an affiliate type |
| `idx_product_type` | `product_type` | INDEX | Look up all rules for a product type |

### Default Data (Seeded on Activation)

**One-time commission rules:**

| `affiliate_type` | `product_type` | `rate` |
|---|---|---|
| `business` | `starter_pack` | `0.4000` |
| `business` | `pro_pack` | `0.4000` |
| `business` | `ecard_pack` | `0.4000` |
| `referral` | `starter_pack` | `0.2000` |
| `referral` | `pro_pack` | `0.2000` |
| `referral` | `ecard_pack` | `0.2000` |
| `team_agent` | `starter_pack` | `0.4000` |
| `team_agent` | `pro_pack` | `0.4000` |
| `team_agent` | `ecard_pack` | `0.4000` |
| `marketing_agent` | `starter_pack` | `0.4000` |
| `marketing_agent` | `pro_pack` | `0.2000` |
| `marketing_agent` | `ecard_pack` | `0.2000` |
| `sales_agent` | `starter_pack` | `0.2000` |
| `sales_agent` | `pro_pack` | `0.2000` |
| `sales_agent` | `ecard_pack` | `0.2000` |

**Recurring commission rules (all types, all subscription products):**

| `affiliate_type` | `product_type` | `rate` |
|---|---|---|
| `business` | `subscription` | `0.1000` |
| `referral` | `subscription` | `0.1000` |
| `team_agent` | `subscription` | `0.1000` |
| `marketing_agent` | `subscription` | `0.1000` |
| `sales_agent` | `subscription` | `0.1000` |

The `subscription` product type covers all recurring products: conference room subscriptions and eCard renewals.

---

## 10. `wp_konx_product_map`

Maps WooCommerce product IDs to internal product type identifiers used by the commission engine.

| Column | Type | Nullable | Default | Purpose |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | AUTO_INCREMENT | Primary key |
| `product_id` | `BIGINT UNSIGNED` | NO | — | WooCommerce product ID |
| `product_type` | `VARCHAR(30)` | NO | — | Internal product type key |
| `product_label` | `VARCHAR(100)` | NO | — | Human-readable label for admin display |
| `is_subscription` | `TINYINT(1)` | NO | `0` | Whether this product has recurring billing |
| `is_active` | `TINYINT(1)` | NO | `1` | Whether this mapping is active |
| `created_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP` | When the mapping was created |
| `updated_at` | `DATETIME` | NO | `CURRENT_TIMESTAMP` | Last modification |

### Product Type Values

| `product_type` | Product | Price | `is_subscription` |
|---|---|---|---|
| `starter_pack` | KonX Starter Pack | $100 | `0` |
| `pro_pack` | KonX Pro Pack | $200 | `0` |
| `ecard_pack` | KonX eCard Pack | $500 | `0` |
| `ecard_single` | KonX eCard | $55 | `0` |
| `basic_pro_conference` | Basic Pro Conference Room | $25/month | `1` |
| `enterprise_conference` | Enterprise Conference Room | $81/month or $809/year | `1` |
| `business_conference` | Business Conference Room | $28/month or $289/year | `1` |
| `corporate_conference` | Corporate Conference Room | $51/month or $509/year | `1` |

### Indexes

| Index Name | Columns | Type | Purpose |
|---|---|---|---|
| `PRIMARY` | `id` | PRIMARY | Row identity |
| `uq_product_id` | `product_id` | UNIQUE | One mapping per WooCommerce product |
| `idx_product_type` | `product_type` | INDEX | Look up products by internal type |

### Notes

- The admin configures product mappings via the plugin settings page. When a WooCommerce product ID changes (e.g., product is recreated), the admin updates the mapping here — no code change required.
- Products not in this table are ignored by the commission engine (no commission is calculated).

---

## Entity Relationship Diagram (Logical)

```
wp_users (WordPress)
    │
    └──< wp_konx_affiliates (1:1 via user_id)
              │
              ├──< wp_konx_referral_clicks (1:many)
              │
              ├──< wp_konx_referral_conversions (1:many)
              │         │
              │         └──< wp_konx_commissions (1:many via conversion_id)
              │                   │
              │                   └──> wp_konx_wallet_ledger (1:1 via ledger_entry_id)
              │
              ├──< wp_konx_wallet_ledger (1:many via affiliate_id)
              │
              ├──< wp_konx_withdrawals (1:many)
              │         │
              │         └──> wp_konx_wallet_ledger (1:1 via ledger_entry_id)
              │
              ├──< wp_konx_admin_fees (1:many)
              │
              └──< wp_konx_milestones (1:many)
                        │
                        └──> wp_konx_wallet_ledger (1:1 via ledger_entry_id)

wp_konx_commission_rules (standalone config — no FK relationships)

wp_konx_product_map (standalone config — references WooCommerce product IDs)

wp_wc_orders / wp_posts (WooCommerce)
    │
    └──> wp_konx_referral_conversions.order_id
    └──> wp_konx_commissions.order_id
```

---

## Duplicate Prevention Strategy

Duplicate data is the most dangerous data integrity issue in a financial system. The following strategies prevent it:

| Scenario | Prevention Mechanism |
|---|---|
| Duplicate affiliate for same user | `UNIQUE` index on `wp_konx_affiliates.user_id` |
| Duplicate referral code | `UNIQUE` index on `wp_konx_affiliates.referral_code` |
| Duplicate conversion for same order | `UNIQUE` index on `wp_konx_referral_conversions.order_id` |
| Duplicate commission for same line item | `UNIQUE` index on `wp_konx_commissions(order_id, order_item_id)` |
| Duplicate milestone bonus | `UNIQUE` index on `wp_konx_milestones(affiliate_id, milestone_number)` |
| Duplicate admin fee for same period | `UNIQUE` index on `wp_konx_admin_fees(affiliate_id, fee_period)` |
| Duplicate commission rule | `UNIQUE` index on `wp_konx_commission_rules(affiliate_type, product_type, commission_type)` |
| Duplicate product mapping | `UNIQUE` index on `wp_konx_product_map.product_id` |
| Duplicate referral clicks (spam) | Application-level: same `ip_hash` + `affiliate_id` within 24 hours is ignored |
| Duplicate pending withdrawal | Application-level: check for existing `pending`/`approved` withdrawal before insert |

All unique constraints are enforced at the database level where possible. Application-level checks are used only when the constraint involves time-based logic or multi-status conditions that cannot be expressed as a simple unique index.

---

## Audit Trail Strategy

### Principles

1. **Append-only ledger**: The `wp_konx_wallet_ledger` table is the financial audit trail. Entries are never modified or deleted in normal operation.
2. **Source traceability**: Every ledger entry links to its source via `reference_type` and `reference_id`.
3. **Actor recording**: Ledger entries and admin actions record the `created_by` user ID. System-generated entries use `NULL`.
4. **Snapshot at action time**: The `wp_konx_commissions.affiliate_type_at_sale` column captures the affiliate type when the commission was calculated, so rate changes do not retroactively alter history.
5. **Status history**: Commission and withdrawal status changes update `updated_at`. For a full status change log, an `wp_konx_audit_log` table can be added in a future phase if needed.

### What Is Auditable

| Event | Where Recorded |
|---|---|
| Commission earned | `wp_konx_commissions` row + `wp_konx_wallet_ledger` entry |
| Commission blocked | `wp_konx_commissions.status = 'blocked'` + `blocked_reason` |
| Commission reversed | New `wp_konx_commissions` status + reversal ledger entry |
| Milestone bonus | `wp_konx_milestones` row + `wp_konx_wallet_ledger` entry |
| Withdrawal requested | `wp_konx_withdrawals` row with `requested_at` |
| Withdrawal completed | `wp_konx_withdrawals.processed_at` + debit ledger entry |
| Admin fee marked paid | `wp_konx_admin_fees.paid_date` + `paid_by_admin_id` |
| Affiliate type changed | New commission records use new `affiliate_type_at_sale` |
| Manual balance adjustment | `wp_konx_wallet_ledger` entry with `entry_type = 'adjustment'` + `created_by` |

### Reconciliation

At any point, the system can verify:
- `SUM(wallet_ledger.amount)` per affiliate = expected balance
- Each `approved` commission has exactly one `commission` or `recurring_commission` ledger entry
- Each `completed` withdrawal has exactly one `withdrawal` ledger entry
- Each `approved` milestone has exactly one `milestone_bonus` ledger entry

An admin reconciliation tool can be built in a future phase to run these checks automatically.

---

## Data Retention Notes

### Active Data

All tables retain data indefinitely during normal plugin operation. There is no automatic purging. Affiliate data, commissions, and wallet history are considered business records and should be preserved for accounting and dispute resolution.

### Click Data

`wp_konx_referral_clicks` is the highest-volume table and may grow large over time. Options for managing size:

- **Archival**: Move clicks older than 12 months to an archive table or export to CSV.
- **Aggregation**: Summarize old click data into daily/weekly counts per affiliate and purge raw rows.
- **Retention setting**: Add a plugin setting for click retention period (e.g., 365 days). A scheduled WP-Cron job deletes clicks older than the configured period.

Click data is analytical, not financial. It can be purged without affecting commission integrity.

### Uninstall Behavior

When the plugin is deleted via WordPress (triggering `uninstall.php`):

1. All custom tables (`wp_konx_*`) are dropped.
2. All plugin options (`konx_affiliate_settings`) are deleted.
3. All user meta with `konx_` prefix is deleted.
4. All order meta with `_konx_` prefix is deleted.
5. The `konx_affiliate` role is removed.
6. Custom capabilities are removed from all roles.

This is destructive and irreversible. The uninstall routine will include a confirmation constant (`KONX_REMOVE_ALL_DATA`) that must be defined as `true` before data is deleted, as an additional safety measure.

### Deactivation Behavior

Deactivation (disabling the plugin without deleting it) does **not** remove any data. It only removes the custom role and capabilities so that affiliate-specific permissions are no longer active. Data remains intact for reactivation.

---

## MySQL/MariaDB Compatibility Notes

- All tables use `InnoDB` engine for transaction support and foreign key capabilities.
- `DATETIME` columns use server time (UTC recommended via WordPress `current_time('mysql', true)`).
- `DECIMAL(12,2)` supports values up to 9,999,999,999.99 — sufficient for commission and wallet amounts.
- `DECIMAL(5,4)` for rates supports values from 0.0000 to 9.9999 — sufficient for percentage rates.
- `VARCHAR` lengths are chosen to balance storage efficiency with practical limits.
- `BIGINT UNSIGNED` for all ID columns matches WordPress conventions and supports IDs up to 18.4 quintillion.
- Tables are created via `dbDelta()` in the activation routine, which requires specific SQL formatting (will be documented in the implementation phase).
- Foreign keys are **not** enforced at the database level. WordPress and `dbDelta()` do not reliably support foreign key constraints across plugins. Referential integrity is enforced in application logic. The schema documents logical relationships for developer reference.
