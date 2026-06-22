# KonX Affiliate Dashboard — Refund & Reversal Handling

## Overview

When a WooCommerce order is refunded, cancelled, or fails after
commissions have been created, the plugin reverses the associated
wallet credits. Commission records are marked as `reversed` but
never deleted, preserving the full audit trail.

## WooCommerce Hooks

| Hook | When It Fires | Plugin Action |
|---|---|---|
| `woocommerce_order_refunded` | Full or partial refund created | `process_refund()` |
| `woocommerce_order_status_cancelled` | Order status -> cancelled | `process_cancelled_order()` |
| `woocommerce_order_status_failed` | Order status -> failed | `process_cancelled_order()` |

## Full Refund Flow

```
woocommerce_order_refunded fires
    |
    v
process_refund($order_id, $refund_id)
    |
    +-- Get commissions for order
    |     None -> return (organic order, no commissions)
    |
    +-- Determine: full or partial refund?
    |     remaining order total <= 0 or refund >= subtotal -> FULL
    |
    +-- reverse_order_commissions():
          |
          For each commission:
              |
              reverse_commission($commission, $reason):
                  |
                  +-- Status is 'reversed'? -> skip (idempotent)
                  +-- Status is 'pending'? -> mark reversed, no wallet entry
                  +-- Status is 'blocked'? -> mark reversed, no wallet entry
                  |     (never credited, so nothing to reverse in wallet)
                  +-- Status is 'approved' with ledger_entry_id?
                        -> mark reversed
                        -> Konx_Wallet::reverse(affiliate_id, amount, commission_id)
                        -> wallet balance decreases
```

## Partial Refund Flow

```
process_refund() determines partial refund
    |
    v
process_partial_refund($commissions, $order, $refund)
    |
    +-- Try ITEM-LEVEL matching first:
    |     For each refunded item in the refund order:
    |       Match product_id or variation_id to a commission
    |       If matched -> fully reverse that commission
    |
    +-- If no items matched -> PROPORTIONAL fallback:
          |
          ratio = refund_amount / order_subtotal
          |
          For each approved, non-reversed commission:
              partial_amount = commission_amount × ratio
              Konx_Wallet::reverse(partial_amount)
              (Commission status stays 'approved' — only a partial clawback)
```

### Item-Level vs Proportional

| Approach | When Used | What Happens |
|---|---|---|
| Item-level | Refund has specific items | Matching commissions fully reversed |
| Proportional | Refund has no items or no match | Each commission reduced by refund ratio |

### Proportional Example

Order: Starter Pack ($100) + Pro Pack ($200) = $300 subtotal
Refund: $150 (50% of subtotal)

- Starter Pack commission ($40): reversed $20 (50%)
- Pro Pack commission ($80): reversed $40 (50%)

## Cancellation Flow

```
woocommerce_order_status_cancelled / _failed fires
    |
    v
process_cancelled_order($order_id)
    |
    +-- Get commissions for order
    |     None -> return
    |
    +-- reverse_order_commissions() — same as full refund
```

Cancellation uses the same full reversal logic. If commissions exist
for the order, they are all reversed.

## Commission Status After Reversal

| Original Status | Action | Wallet Entry |
|---|---|---|
| `approved` (with `ledger_entry_id`) | Mark `reversed` + create wallet reversal | Yes — negative entry |
| `blocked` (never credited) | Mark `reversed` | No — nothing to reverse |
| `pending` (order never completed) | Mark `reversed` | No — nothing to reverse |
| `reversed` (already reversed) | Skip | No — idempotent |

## Wallet Reversal Behavior

### Entry Created

```
Konx_Wallet::reverse($affiliate_id, $amount, $commission_id, $reason)
```

This creates a ledger entry with:
- `entry_type = 'reversal'`
- `reference_type = 'commission'`
- `reference_id = commission_id`
- `amount = -$X.XX` (negative)

### Negative Balance

If the affiliate has already withdrawn funds and the reversal would
make their balance negative, **the reversal still proceeds**.
`Konx_Wallet::reverse()` uses `force = true` internally, bypassing
the negative balance check.

This is correct because:
- The affiliate was credited money they are no longer entitled to
- The negative balance represents a debt to the program
- Future commissions will offset the negative balance
- The admin can make a manual adjustment if needed

## Idempotency

### Commission Level

`reverse_commission()` checks the commission's `status` before acting:
- If already `reversed` → skip immediately (no-op)
- The `mark_commission_reversed()` UPDATE is safe to call multiple times

### Wallet Level

`has_reversal($commission_id)` checks the wallet ledger for an existing
reversal entry matching the commission ID:

```sql
SELECT COUNT(*) FROM wp_konx_wallet_ledger
WHERE entry_type = 'reversal'
  AND reference_type = 'commission'
  AND reference_id = %d
```

Additionally, `Konx_Wallet::entry_exists()` runs inside the wallet's
own `insert_entry()` method, providing a second layer of protection.

### Hook Re-Trigger Safety

WooCommerce hooks can fire multiple times (admin changes status back
and forth). The combination of status checks and wallet idempotency
ensures no double-reversals occur.

## What Is NOT Deleted

- Commission records → status changed to `reversed`, never deleted
- Original wallet credit → stays in ledger, reversal appended separately
- Conversion records → remain unchanged
- Click records → remain unchanged

The complete history is preserved for audit purposes.

## Testing Checklist

### Full Refund

- [ ] Full refund on order with 1 commission -> commission reversed, wallet debited
- [ ] Full refund on order with 2 commissions -> both reversed
- [ ] Commission amount matches reversal amount
- [ ] Commission status changed to `reversed`
- [ ] Wallet ledger has reversal entry with negative amount
- [ ] Wallet `running_balance` and `cached_balance` decreased
- [ ] Audit log entry created

### Partial Refund — Item Level

- [ ] Refund 1 of 2 items -> only matching commission reversed
- [ ] Non-refunded item's commission unchanged
- [ ] Matching by product_id works
- [ ] Matching by variation_id works

### Partial Refund — Proportional

- [ ] 50% refund -> each commission reversed by 50%
- [ ] Proportional amount calculated correctly (bcmul or float)
- [ ] Wallet has partial reversal entries
- [ ] Commission status remains `approved` (partial, not fully reversed)

### Cancelled / Failed Order

- [ ] Cancelled order with commissions -> all reversed
- [ ] Failed order with commissions -> all reversed
- [ ] Order without commissions -> no action

### Blocked Commissions

- [ ] Blocked commission refunded -> marked reversed, no wallet entry
- [ ] No wallet reversal for never-credited commission
- [ ] Audit log records "blocked commission cancelled"

### Pending Commissions

- [ ] Pending commission on cancelled order -> marked reversed, no wallet entry

### Negative Balance

- [ ] Affiliate with $0 balance, $100 commission reversed -> balance -$100
- [ ] Reversal proceeds (force = true in wallet)
- [ ] Wallet balance accurately reflects the negative

### Idempotency

- [ ] Same refund processed twice -> no duplicate reversals
- [ ] Commission already reversed -> skipped
- [ ] `has_reversal()` returns true after first reversal
- [ ] Wallet `entry_exists()` prevents double entry
- [ ] Order status toggled back and forth -> commissions only reversed once

### HPOS Compatibility

- [ ] `wc_get_order()` used for order and refund
- [ ] `$order->get_subtotal()`, `$order->get_total()` used (not direct queries)
- [ ] `$refund->get_total()`, `$refund->get_items()` used
- [ ] No `wp_postmeta` queries

### Audit Trail

- [ ] Refund detected -> audit entry with refund amount
- [ ] Each commission reversal -> audit entry
- [ ] Blocked commission cancelled -> audit entry
- [ ] Wallet reversal failed -> audit entry
- [ ] Proportional reversal -> audit entry with ratio
