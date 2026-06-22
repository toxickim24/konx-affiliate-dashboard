# KonX Affiliate Dashboard — Recurring Commissions

## Overview

The recurring commission engine awards a flat **10% commission** to affiliates
when subscription products are renewed. It integrates with YITH WooCommerce
Subscription to detect successful renewal payments and trace attribution
back to the affiliate who originally referred the subscriber.

## YITH Integration Flow

```
YITH creates renewal order
    |
    v
Renewal payment succeeds
    |
    v
YITH fires: ywsbs_renew_order_payed($renewal_order_id, $subscription_id)
    |
    v
Konx_Recurring_Commission_Engine::process_renewal_order()
    |
    +-- Get renewal order via wc_get_order() (HPOS-compatible)
    +-- Get subscription ID from parameter or order meta
    |
    +-- ATTRIBUTION LOOKUP (get_original_affiliate):
    |     1. Check renewal order meta for _konx_referrer_id
    |     2. Find parent order via YITH subscription -> check its meta
    |     3. Fall back to wp_konx_referral_conversions by subscription_id
    |     Not found -> return (no referral on original purchase)
    |
    +-- Validate affiliate status == 'active'
    |     Inactive -> log, return
    |
    +-- Check admin fee status ONCE for order
    |
    +-- Create renewal conversion record (idempotent)
    +-- Copy attribution meta to renewal order
    |
    +-- For each line item:
          |
          process_renewal_item()
              |
              +-- Must be WC_Order_Item_Product
              +-- IDEMPOTENCY: has_recurring_commission(order_id, item_id)?
              +-- Look up product mapping
              +-- Calculate: subtotal × 10%
              +-- Create commission record (TYPE_RECURRING, sale_sequence)
              +-- Credit wallet if approved
```

## Attribution Persistence

The key difference from one-time commissions: the affiliate is determined
from the **original subscription purchase**, not from a cookie.

```
Original purchase (with referral cookie):
  Order #100 -> _konx_referrer_id = 5

Renewal 1 (no cookie needed):
  Renewal Order #200 -> trace to subscription -> trace to Order #100
  -> affiliate ID = 5

Renewal 2 (no cookie needed):
  Renewal Order #300 -> same chain -> affiliate ID = 5
```

### Lookup Chain

1. **Renewal order meta** — check `_konx_referrer_id` (may have been
   copied from a previous renewal)
2. **Parent order** — YITH subscription has a parent order ID; check
   that order's `_konx_referrer_id` meta
3. **Conversion table** — query `wp_konx_referral_conversions` for
   the earliest conversion matching the subscription ID

This three-step chain ensures attribution survives even if order meta
is missing on the renewal.

## Renewal Lifecycle

| Subscription State | Commission Action |
|---|---|
| Active (renewal paid) | Calculate and credit recurring commission |
| Active (renewal failed, then retried successfully) | Same — `ywsbs_renew_order_payed` fires on success |
| Paused | No renewal orders generated, no commission |
| Cancelled | No future renewal orders, no commission |
| Expired | No future renewal orders, no commission |

### Renewal Retries

YITH may retry a failed renewal payment. The `ywsbs_renew_order_payed`
hook only fires when the retry succeeds. The idempotency check
(`has_recurring_commission`) ensures that if the hook fires multiple
times for the same renewal order, only one commission is created.

## Commission Flow

### Rate

All affiliate types earn the same flat rate:

| Affiliate Type | Recurring Rate |
|---|---|
| Business | 10% |
| Referral | 10% |
| Team Agent | 10% |
| Marketing Agent | 10% |
| Sales Agent | 10% |

The rate is defined as the constant `RECURRING_RATE = '0.1000'`.

### Eligible Products

Any product mapped in `wp_konx_product_map` is eligible for recurring
commission. Typical subscription products:

| Product | Monthly | Annual |
|---|---|---|
| Basic Pro Conference Room | $25/mo → $2.50 | — |
| Business Conference Room | $28/mo → $2.80 | $289/yr → $28.90 |
| Corporate Conference Room | $51/mo → $5.10 | $509/yr → $50.90 |
| Enterprise Conference Room | $81/mo → $8.10 | $809/yr → $80.90 |

### Commission Base

Same rule as one-time engine: `$item->get_subtotal()` — the line item
total **before discounts, coupons, and taxes**.

### Calculation

```
commission_amount = product_subtotal × 0.10
```

Uses `bcmul()` when available, otherwise `number_format()` fallback.

## Blocked Commission Flow

If the affiliate has unpaid admin fees:

```
1. Admin fee check: has_unpaid_admin_fee() -> true
2. Commission created with:
   - status = 'blocked'
   - blocked_reason = 'unpaid_admin_fee'
3. Wallet NOT credited
4. Commission record preserved for later release (Phase 9)
```

When admin marks fees as paid, blocked commissions (both one-time and
recurring) are re-processed to approved status with wallet credit.

## Idempotency Strategy

### Layer 1: Item-Level Check

```
has_recurring_commission($order_id, $item_id)
```

Checks `wp_konx_commissions` for existing records matching the
renewal order ID, item ID, and `commission_type = 'recurring'`.

### Layer 2: Database Unique Index

```
UNIQUE KEY uq_order_item (order_id, order_item_id)
```

If the application-level check misses (race condition), the database
rejects the duplicate INSERT.

### Layer 3: Wallet Idempotency

```
Konx_Wallet::entry_exists(affiliate_id, TYPE_RECURRING_COMMISSION, REF_COMMISSION, commission_id)
```

Prevents double-crediting the same commission to the wallet.

### Conversion Idempotency

```
UNIQUE KEY uq_order_id (order_id) on wp_konx_referral_conversions
```

The `create_renewal_conversion()` method checks for an existing
conversion before inserting. If one exists, it returns the existing ID.

## YITH Hook Details

### Primary Hook

```
ywsbs_renew_order_payed
```

**Note:** The hook name uses "payed" (YITH's spelling), not "paid".
Verify against the installed YITH version on konx.world.

**Parameters:**
- `$renewal_order_id` (int) — the WooCommerce order ID for the renewal
- `$subscription_id` (int) — the YITH subscription post ID

### YITH Data Model

YITH subscriptions are stored as a custom post type. Key meta:

| Meta Key | On | Value |
|---|---|---|
| `order_id` or `_order_id` | Subscription post | Parent order ID |
| `ywsbs_subscription` or `_ywsbs_subscription` | Order | Subscription ID |

The engine checks both meta key variants for compatibility across
YITH versions.

### Conditional Hook Registration

Hooks are only registered if YITH is active:

```php
public static function init() {
    if ( ! konx_affiliate_is_yith_active() ) {
        return;
    }
    add_action( 'ywsbs_renew_order_payed', ... );
}
```

If YITH is deactivated, the engine silently stops. No errors.
Existing recurring commission records are preserved.

## Manual Testing Checklist

### Basic Recurring Commission

- [ ] Subscription renewal paid -> 10% recurring commission created
- [ ] Commission type stored as `recurring`
- [ ] Commission amount = subtotal × 10%
- [ ] Wallet credited with `TYPE_RECURRING_COMMISSION`
- [ ] Ledger entry ID stored on commission record

### Attribution Persistence

- [ ] Renewal credited to original referring affiliate (not current cookie)
- [ ] Second renewal credited to same affiliate
- [ ] Third renewal credited to same affiliate
- [ ] Attribution works after referral cookie has expired
- [ ] Attribution works if affiliate type changed between renewals

### Attribution Lookup Chain

- [ ] Renewal order has meta -> uses that affiliate
- [ ] Renewal order has no meta, parent order has meta -> uses parent's affiliate
- [ ] Neither has meta, conversion table has subscription_id -> uses that

### Admin Fee Blocking

- [ ] Affiliate with paid fees -> recurring commission approved
- [ ] Affiliate with unpaid fees -> recurring commission blocked
- [ ] Blocked reason = `unpaid_admin_fee`
- [ ] Wallet NOT credited when blocked

### Idempotency

- [ ] Same renewal order processed twice -> only one commission
- [ ] YITH retry (failed then succeeded) -> only one commission
- [ ] Wallet: same commission ID not double-credited

### Sale Sequence

- [ ] Recurring commissions increment sale_sequence
- [ ] Mixed one-time + recurring -> sequential sequence numbers
- [ ] `completed_sales` updated to match

### YITH Lifecycle

- [ ] Active subscription renewal -> commission created
- [ ] Paused subscription -> no renewal, no commission
- [ ] Cancelled subscription -> no future commissions
- [ ] Expired subscription -> no future commissions
- [ ] YITH deactivated -> no hooks registered, no errors

### Products

- [ ] Basic Pro Conference Room ($25/mo) -> $2.50 commission
- [ ] Business Conference Room ($28/mo) -> $2.80 commission
- [ ] Business Conference Room ($289/yr) -> $28.90 commission
- [ ] Corporate Conference Room ($51/mo) -> $5.10 commission
- [ ] Corporate Conference Room ($509/yr) -> $50.90 commission
- [ ] Enterprise Conference Room ($81/mo) -> $8.10 commission
- [ ] Enterprise Conference Room ($809/yr) -> $80.90 commission

### Conversion Records

- [ ] Renewal creates conversion with `is_subscription_renewal = 1`
- [ ] Conversion has `subscription_id` set
- [ ] Duplicate renewal doesn't create duplicate conversion
- [ ] Attribution meta copied to renewal order

### HPOS Compatibility

- [ ] All order access via `wc_get_order()` / `$order->get_meta()`
- [ ] No direct `wp_postmeta` queries for orders
- [ ] Works with HPOS enabled
