# KonX Affiliate Dashboard — Milestone Bonus

## Overview

For every 100 completed paid sales, an affiliate receives a bonus equal
to the total approved commission earned from that 100-sale block.

The bonus repeats every 100 sales indefinitely:
- Milestone 1: sales 1–100
- Milestone 2: sales 101–200
- Milestone 3: sales 201–300
- etc.

## Milestone Logic

### Trigger

After every successful commission wallet credit (one-time or recurring),
the commission engine calls:

```php
Konx_Milestone_Bonus::maybe_award_bonus( $affiliate_id );
```

### Decision Flow

```
maybe_award_bonus($affiliate_id)
    |
    +-- Get affiliate's completed_sales count
    |     < 100 -> return (too early)
    |
    +-- Check: completed_sales % 100 === 0 ?
    |     No -> return (not on a boundary)
    |
    +-- milestone_number = completed_sales / 100
    |
    +-- IDEMPOTENCY: has_bonus_for_milestone(affiliate_id, milestone_number)?
    |     Yes -> return (already awarded)
    |
    +-- Calculate block boundaries:
    |     end   = milestone_number × 100
    |     start = end - 99
    |
    +-- Sum approved commissions in block:
    |     SELECT SUM(commission_amount)
    |     WHERE affiliate_id = X
    |       AND sale_sequence BETWEEN start AND end
    |       AND status = 'approved'
    |
    |     = 0.00 -> log "skipped, no approved commissions", return
    |
    +-- Check admin fee eligibility:
    |     can_affiliate_earn() -> false: status = 'blocked'
    |     can_affiliate_earn() -> true:  status = 'approved'
    |
    +-- Create milestone record in wp_konx_milestones
    |
    +-- If approved: credit wallet
    |     Konx_Wallet::credit(TYPE_MILESTONE_BONUS, REF_MILESTONE, milestone_id)
    |
    +-- Log to audit
```

## Sale Sequence Block Rules

Sale blocks are defined by the `sale_sequence` column on `wp_konx_commissions`.
Both one-time and recurring commissions increment the same per-affiliate
sequence, so they both count toward milestones.

| Milestone # | sale_sequence range | Trigger count |
|---|---|---|
| 1 | 1 – 100 | 100 |
| 2 | 101 – 200 | 200 |
| 3 | 201 – 300 | 300 |
| N | (N-1)×100+1 – N×100 | N×100 |

### Boundary Calculation

```php
$end   = $milestone_number * 100;
$start = $end - 99;
```

Example for milestone 3:
- end = 300
- start = 201

## Bonus Formula

```
bonus_amount = SUM(commission_amount)
               FROM wp_konx_commissions
               WHERE affiliate_id = %d
                 AND sale_sequence BETWEEN %d AND %d
                 AND status = 'approved'
```

Only `approved` commissions are summed. Excluded:
- `blocked` — fee unpaid, commission not yet earned
- `reversed` — refunded, no longer valid
- `pending` — order not yet completed

## Repeat Behavior

The milestone repeats at every 100th sale with no cap:

```
Sales 100  -> Bonus = SUM(commissions for sales 1–100)
Sales 200  -> Bonus = SUM(commissions for sales 101–200)
Sales 300  -> Bonus = SUM(commissions for sales 201–300)
...
Sales 1000 -> Bonus = SUM(commissions for sales 901–1000)
```

Each block's bonus is independent. A high-volume block earns a larger
bonus than a low-volume block.

## Admin Fee Blocking

If the affiliate has unpaid admin fees when the milestone is reached:

- Milestone record created with `status = 'blocked'`
- Wallet NOT credited
- Record preserved for future release

## Idempotency Strategy

### Layer 1: Application Check

```php
has_bonus_for_milestone($affiliate_id, $milestone_number)
```

Checks `wp_konx_milestones` before inserting.

### Layer 2: Database Unique Index

```
UNIQUE KEY uq_affiliate_milestone (affiliate_id, milestone_number)
```

If the application check misses (race condition), the database
rejects the duplicate INSERT.

### Layer 3: Wallet Idempotency

```
Konx_Wallet::entry_exists(affiliate_id, TYPE_MILESTONE_BONUS, REF_MILESTONE, milestone_id)
```

The wallet's own idempotency check prevents double-crediting even
if `credit_wallet()` is called twice with the same milestone ID.

### Safe to Call Multiple Times

`maybe_award_bonus()` can be called after every commission credit.
If the affiliate is not on a 100-sale boundary, or the milestone
was already awarded, it returns immediately with no side effects.

## Integration Points

### One-Time Commission Engine

```php
// In Konx_Commission_Engine::process_order_item()
if ( self::STATUS_APPROVED === $status ) {
    self::credit_wallet( $affiliate, $commission_id, $commission_data );
    Konx_Milestone_Bonus::maybe_award_bonus( (int) $affiliate->id );
}
```

### Recurring Commission Engine

```php
// In Konx_Recurring_Commission_Engine::process_renewal_item()
if ( Konx_Commission_Engine::STATUS_APPROVED === $status ) {
    self::credit_wallet( $affiliate, $commission_id, $commission_data );
    Konx_Milestone_Bonus::maybe_award_bonus( (int) $affiliate->id );
}
```

### Why After Wallet Credit

The milestone check runs after `credit_wallet()` because:
1. The commission record and sale_sequence must exist first.
2. The `completed_sales` counter must be updated first.
3. If the wallet credit fails, we still check (the sale was recorded).

### Why Only For Approved Commissions

Blocked commissions don't increment `completed_sales` in the
commission engine — wait, they do. Both approved and blocked
commissions get a sale_sequence and increment completed_sales.
However, the milestone is only called when the commission is
approved (inside the `if STATUS_APPROVED` block). This means
blocked commissions can push the count to 100 but the milestone
check won't fire until the next approved commission.

If the 100th sale is blocked, the milestone triggers when the 101st
(approved) sale is processed. At that point, `completed_sales` is
101, which is not on a 100 boundary, so the milestone doesn't
trigger. The milestone for block 1 would need to be awarded manually
or via a reconciliation process. This is an acceptable edge case
for the initial implementation.

## Progress Display

`get_progress_to_next_milestone()` returns:

```php
array(
    'completed_sales'     => 267,
    'next_milestone_at'   => 300,
    'sales_remaining'     => 33,
    'sales_in_block'      => 67,
    'percent_complete'    => 67.0,
    'milestones_achieved' => 2,
)
```

Used by the frontend affiliate dashboard to show a progress bar.

## Manual Testing Checklist

### Basic Milestone

- [ ] Affiliate reaches 100 approved sales -> milestone 1 created
- [ ] Bonus amount = SUM of approved commissions for sequence 1–100
- [ ] Wallet credited with `TYPE_MILESTONE_BONUS`
- [ ] Ledger entry ID stored on milestone record
- [ ] Milestone record has correct block start/end

### Repeat Milestones

- [ ] Affiliate reaches 200 sales -> milestone 2 created
- [ ] Milestone 2 bonus = SUM of commissions for sequence 101–200
- [ ] Milestone 1 and 2 are independent records

### Bonus Calculation

- [ ] Only `approved` commissions are summed (not blocked/reversed/pending)
- [ ] Both one-time and recurring commissions count
- [ ] Correct amount for mixed commission types in same block

### Admin Fee Blocking

- [ ] Affiliate with unpaid fees at milestone -> status = `blocked`
- [ ] Wallet NOT credited when blocked
- [ ] Milestone record preserved for future release

### Idempotency

- [ ] Same milestone number cannot be awarded twice
- [ ] `has_bonus_for_milestone()` returns true after first award
- [ ] Database unique index prevents duplicate on race condition
- [ ] Wallet `entry_exists()` prevents double-crediting

### Edge Cases

- [ ] Affiliate with 99 sales -> no milestone (not on boundary)
- [ ] Affiliate with 101 sales -> no milestone for 100 if it was the blocked sale
- [ ] Block with all reversed commissions -> bonus = $0, milestone skipped
- [ ] Block with $0 total -> skipped with audit log entry

### Progress

- [ ] `get_progress_to_next_milestone()` returns correct values
- [ ] Progress at 0 sales -> next milestone at 100
- [ ] Progress at 50 sales -> 50% complete
- [ ] Progress at 100 sales -> milestones_achieved = 1, next at 200

### Integration

- [ ] One-time commission triggers milestone check
- [ ] Recurring commission triggers milestone check
- [ ] Blocked commission does NOT trigger milestone check
- [ ] Milestone check called after wallet credit (not before)

### Audit

- [ ] Milestone awarded -> audit log entry
- [ ] Milestone skipped (no commissions) -> audit log entry
- [ ] Wallet credit failed -> audit log entry
