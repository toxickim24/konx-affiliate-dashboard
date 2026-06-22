# KonX Affiliate Dashboard — Commission Engine

## Overview

The one-time commission engine processes completed WooCommerce orders and
awards commissions to referring affiliates. It hooks into the WooCommerce
order lifecycle, calculates commissions from the product subtotal, creates
transaction records, and credits the affiliate wallet.

## Commission Flow

```
woocommerce_order_status_completed fires
    |
    v
process_order($order_id)
    |
    +-- Get order via wc_get_order() (HPOS-compatible)
    +-- Read _konx_referrer_id from order meta
    |     No referrer -> return (organic order)
    |
    +-- IDEMPOTENCY: has_commissions_for_order($order_id)?
    |     Yes -> log "duplicate skipped", return
    |
    +-- Validate affiliate exists
    |     Not found -> log, return
    |
    +-- Validate affiliate status == 'active'
    |     Inactive -> log, return
    |
    +-- Check admin fee status ONCE for entire order
    |     has_unpaid_admin_fee() -> $is_fee_blocked = true/false
    |
    +-- Get conversion record for order
    |
    +-- For each line item in order:
          |
          process_order_item()
              |
              +-- Must be WC_Order_Item_Product (skip shipping, fees, etc.)
              |
              +-- IDEMPOTENCY: has_commission_for_order_item(order_id, item_id)?
              |     Yes -> skip
              |
              +-- Look up product mapping:
              |     Konx_Product_Mapper::get_product_category(product_id, variation_id)
              |     Not mapped -> skip (no commission for unmapped products)
              |
              +-- Only process one-time types: starter_pack, pro_pack, ecard_pack
              |     Other types -> skip (handled by recurring engine later)
              |
              +-- calculate_commission()
              |     product_price = $item->get_subtotal()  <-- BEFORE discounts
              |     rate = get_commission_rate(affiliate_type, product_type)
              |     commission_amount = product_price * rate
              |
              +-- Determine status:
              |     $is_fee_blocked -> 'blocked' + reason 'unpaid_admin_fee'
              |     Otherwise -> 'approved'
              |
              +-- create_commission_record() [inside DB transaction]
              |     START TRANSACTION
              |     Lock affiliate row (FOR UPDATE)
              |     Get next sale_sequence
              |     INSERT into wp_konx_commissions
              |     UPDATE completed_sales on affiliate
              |     COMMIT
              |
              +-- If approved: credit_wallet()
                    Konx_Wallet::credit(affiliate_id, amount, ...)
                    Store ledger_entry_id on commission record
```

## Commission Rate Matrix

Rates are stored in `wp_konx_commission_rules` and seeded on activation.
The engine reads them at runtime so admin can modify without code changes.

| Affiliate Type | Starter Pack ($100) | Pro Pack ($200) | eCard Pack ($500) |
|---|---|---|---|
| Business (`business`) | 40% = $40 | 40% = $80 | 40% = $200 |
| Referral (`referral`) | 20% = $20 | 20% = $40 | 20% = $100 |
| Team Agent (`team_agent`) | 40% = $40 | 40% = $80 | 40% = $200 |
| Marketing Agent (`marketing_agent`) | 40% = $40 | 20% = $40 | 20% = $100 |
| Sales Agent (`sales_agent`) | 20% = $20 | 20% = $40 | 20% = $100 |

Rates are stored as decimal values: `0.4000` = 40%, `0.2000` = 20%.

## WooCommerce Hook

| Hook | Priority | Purpose |
|---|---|---|
| `woocommerce_order_status_completed` | 10 | Trigger commission processing |

This hook fires when an order transitions to "completed" status. It can
fire multiple times if an admin manually toggles the status. The engine
handles this via idempotency checks.

## Product Subtotal Rule

Commissions are calculated from `$item->get_subtotal()`, which returns the
**line total before discounts, coupons, and taxes**.

| WooCommerce Method | Returns | Used By Engine |
|---|---|---|
| `$item->get_subtotal()` | Before discounts, before tax | **Yes — commission base** |
| `$item->get_total()` | After discounts, before tax | No |
| `$item->get_subtotal_tax()` | Tax on subtotal | No |

**Example:** Customer buys Pro Pack ($200) with a 20% coupon.
- `get_subtotal()` = $200 (commission base)
- `get_total()` = $160 (not used)
- Business Affiliate commission: $200 x 40% = $80

## Admin Fee Rule

Before processing any line items, the engine checks ONCE:

```sql
SELECT COUNT(*) FROM wp_konx_admin_fees
WHERE affiliate_id = %d AND status IN ('unpaid', 'overdue')
```

If count > 0:
- All commissions for this order are created with `status = 'blocked'`
  and `blocked_reason = 'unpaid_admin_fee'`.
- The wallet is NOT credited.
- The commission record is still created for audit purposes.

When the admin marks fees as paid (Phase 9), blocked commissions are
re-processed: status changes to `approved` and wallet is credited.

## Idempotency Strategy

The engine uses a three-layer idempotency approach:

### Layer 1: Order-Level Check

Before processing any line items:
```
has_commissions_for_order($order_id) -> skip entire order
```

This catches re-triggers of `woocommerce_order_status_completed`
where the order was fully processed on a previous run.

### Layer 2: Item-Level Check

Before each line item:
```
has_commission_for_order_item($order_id, $item_id) -> skip item
```

This catches partial re-processing (if the first run failed mid-order).

### Layer 3: Database Unique Index

```
UNIQUE KEY uq_order_item (order_id, order_item_id)
```

If layers 1 and 2 both miss (e.g., race condition), the database
rejects the duplicate INSERT. This is the last-resort safety net.

### Wallet Idempotency

The wallet credit uses the commission ID as `reference_id`:
```
Konx_Wallet::credit(..., REF_COMMISSION, commission_id, ...)
```

`Konx_Wallet::entry_exists()` prevents double-crediting the same
commission. Even if `credit_wallet()` is called twice with the same
commission ID, only one ledger entry is created.

## Sale Sequence Strategy

Each commission record receives a `sale_sequence` number that is:
- Per-affiliate (each affiliate has their own sequence starting at 1)
- Monotonically increasing
- Assigned inside a database transaction with `FOR UPDATE` lock

```
START TRANSACTION
  SELECT ... FROM wp_konx_affiliates WHERE id = %d FOR UPDATE
  SELECT COALESCE(MAX(sale_sequence), 0) + 1 FROM wp_konx_commissions WHERE affiliate_id = %d
  INSERT INTO wp_konx_commissions (... sale_sequence = N ...)
  UPDATE wp_konx_affiliates SET completed_sales = N
COMMIT
```

The `FOR UPDATE` lock on the affiliate row serializes concurrent
commission inserts for the same affiliate, preventing duplicate
sequence numbers.

The `uq_affiliate_sequence (affiliate_id, sale_sequence)` unique
index is the database-level safety net.

## Wallet Credit Strategy

When a commission is approved (not blocked):

1. `Konx_Wallet::credit()` is called with:
   - `entry_type = TYPE_COMMISSION`
   - `reference_type = REF_COMMISSION`
   - `reference_id = commission_id`

2. Wallet internally:
   - Checks `entry_exists()` for idempotency
   - Acquires `FOR UPDATE` lock on affiliate row
   - Computes new running_balance
   - Inserts ledger entry
   - Updates cached_balance
   - Returns ledger entry ID

3. Engine stores `ledger_entry_id` on the commission record via UPDATE.

If the wallet credit fails, the commission record still exists (with
`ledger_entry_id = NULL`), and the failure is logged to the audit log.
This allows manual recovery.

## Decimal Arithmetic

All monetary calculations use string-based arithmetic:

```php
// With bcmath (preferred):
$commission = bcmul($product_price, $rate, 2);

// Without bcmath (fallback):
$commission = number_format((float)$product_price * (float)$rate, 2, '.', '');
```

All amounts are stored as `DECIMAL(12,2)` in the database.
Rates are stored as `DECIMAL(5,4)` (e.g., `0.4000` for 40%).

## HPOS Compatibility

All WooCommerce order access uses CRUD methods:

| Operation | Method |
|---|---|
| Get order | `wc_get_order($order_id)` |
| Read meta | `$order->get_meta('_konx_referrer_id')` |
| Get order ID | `$order->get_id()` |
| Get items | `$order->get_items()` |
| Item product ID | `$item->get_product_id()` |
| Item variation | `$item->get_variation_id()` |
| Item subtotal | `$item->get_subtotal()` |

No direct `wp_postmeta` queries.

## Manual Testing Checklist

### Basic Commission

- [ ] Place a referred order with Starter Pack -> 40% commission for Business Affiliate
- [ ] Place a referred order with Pro Pack -> 40% commission for Business Affiliate
- [ ] Place a referred order with eCard Pack -> 40% commission for Business Affiliate
- [ ] Place a referred order with Starter Pack -> 20% commission for Referral Affiliate
- [ ] Place a referred order with Pro Pack -> 20% commission for Marketing Agent
- [ ] Place a referred order with Starter Pack -> 40% commission for Marketing Agent

### Commission Base

- [ ] Order without coupon: commission based on full price
- [ ] Order with coupon: commission based on `get_subtotal()` (pre-discount)
- [ ] Order with quantity > 1: commission on full subtotal (price x quantity)

### Organic Orders

- [ ] Order without referral attribution -> no commission created
- [ ] Order with invalid affiliate ID in meta -> no commission created

### Affiliate Validation

- [ ] Active affiliate -> commission created
- [ ] Inactive affiliate -> commission skipped, audit logged
- [ ] Non-existent affiliate -> commission skipped, audit logged

### Admin Fee Blocking

- [ ] Affiliate with no admin fees -> commission approved, wallet credited
- [ ] Affiliate with unpaid admin fee -> commission blocked, wallet NOT credited
- [ ] Blocked commission has `blocked_reason = 'unpaid_admin_fee'`
- [ ] Admin fee check runs once per order (not per line item)

### Idempotency

- [ ] Complete order -> commissions created
- [ ] Re-trigger completed status -> no duplicate commissions, audit logged
- [ ] Wallet: same commission ID not double-credited

### Sale Sequence

- [ ] First commission for affiliate gets sequence 1
- [ ] Second commission gets sequence 2
- [ ] `completed_sales` on affiliate matches latest sequence
- [ ] Concurrent orders produce sequential (not duplicate) numbers

### Product Mapping

- [ ] Mapped product -> commission calculated
- [ ] Unmapped product -> no commission
- [ ] Mixed order (mapped + unmapped) -> commission only for mapped items
- [ ] Variable product mapped by variation ID -> correct commission
- [ ] Subscription product type -> skipped by one-time engine

### Wallet Integration

- [ ] Approved commission -> wallet credited
- [ ] Blocked commission -> wallet NOT credited
- [ ] Commission record has `ledger_entry_id` after wallet credit
- [ ] Wallet balance increased by commission amount
- [ ] `cached_balance` on affiliate updated

### Multi-Item Orders

- [ ] Order with 2 mapped products -> 2 commission records
- [ ] Order with 1 mapped + 1 unmapped -> 1 commission record
- [ ] Each commission has correct product_price and commission_amount

### Audit Trail

- [ ] Commission created -> audit log entry
- [ ] Duplicate skipped -> audit log entry
- [ ] Affiliate inactive -> audit log entry
- [ ] Wallet credit failed -> audit log entry
