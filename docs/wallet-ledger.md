# KonX Affiliate Dashboard — Wallet Ledger

## Concept

Every affiliate has a wallet. The wallet is not a single balance column — it
is an **append-only ledger** where every credit and debit is a row in
`wp_konx_wallet_ledger`. The balance is derived from `SUM(amount)`.

Ledger entries are **never updated or deleted** after creation. Corrections
are made by appending a new reversal or adjustment entry. This provides a
complete, auditable financial history.

A `cached_balance` column on `wp_konx_affiliates` stores the current balance
for fast reads. It is updated atomically inside the same database transaction
as each ledger insert. The SUM query remains the authoritative source of truth.

## Ledger Entry Types

| Constant | `entry_type` Value | Direction | Trigger |
|---|---|---|---|
| `TYPE_COMMISSION` | `commission` | Credit (+) | One-time commission approved |
| `TYPE_RECURRING_COMMISSION` | `recurring_commission` | Credit (+) | YITH subscription renewal commission |
| `TYPE_MILESTONE_BONUS` | `milestone_bonus` | Credit (+) | 100-sale milestone bonus |
| `TYPE_WITHDRAWAL` | `withdrawal` | Debit (-) | Withdrawal marked completed by admin |
| `TYPE_REVERSAL` | `reversal` | Debit (-) | Commission reversed (order refunded) |
| `TYPE_ADJUSTMENT` | `adjustment` | Credit or Debit | Manual admin adjustment |

## Reference Types

Each ledger entry links to a source record via `reference_type` and
`reference_id`:

| `reference_type` | `reference_id` Points To |
|---|---|
| `commission` | `wp_konx_commissions.id` |
| `withdrawal` | `wp_konx_withdrawals.id` |
| `milestone` | `wp_konx_milestones.id` |
| `admin` | NULL (description explains the adjustment) |

## Balance Formulas

### Available Balance (Authoritative)

```sql
SELECT COALESCE(SUM(amount), 0)
FROM wp_konx_wallet_ledger
WHERE affiliate_id = %d
```

This is the source of truth. Credits are positive, debits are negative.
The sum is the current available balance.

### Cached Balance (Performance Optimization)

```
cached_balance on wp_konx_affiliates
```

Updated atomically with every ledger insert. Used for display and quick
checks. The `reconcile()` method can detect and correct drift.

### Lifetime Earnings

```sql
SELECT COALESCE(SUM(amount), 0)
FROM wp_konx_wallet_ledger
WHERE affiliate_id = %d AND amount > 0
```

Sum of all credits ever received (commissions + bonuses + positive adjustments).

### Total Withdrawals

```sql
SELECT COALESCE(ABS(SUM(amount)), 0)
FROM wp_konx_wallet_ledger
WHERE affiliate_id = %d AND entry_type = 'withdrawal'
```

Sum of all withdrawal debits (returned as a positive number).

### Total Reversals

```sql
SELECT COALESCE(ABS(SUM(amount)), 0)
FROM wp_konx_wallet_ledger
WHERE affiliate_id = %d AND entry_type = 'reversal'
```

Sum of all reversal debits (returned as a positive number).

### Balance Summary

The `get_affiliate_balance_summary()` method returns all of the above
plus a `in_sync` flag indicating whether cached_balance matches the
ledger SUM.

## Transaction Flow

Every ledger write follows this sequence:

```
1. START TRANSACTION

2. SELECT ... FROM wp_konx_affiliates WHERE id = %d FOR UPDATE
   (Acquires row-level lock to serialize concurrent writes)

3. SELECT COALESCE(SUM(amount), 0) FROM wp_konx_wallet_ledger
   WHERE affiliate_id = %d
   (Read current balance under the lock)

4. For debits: check current_balance + debit_amount >= 0
   If negative and not forced: ROLLBACK + return WP_Error

5. Calculate running_balance = current_balance + amount

6. INSERT INTO wp_konx_wallet_ledger (...)

7. UPDATE wp_konx_affiliates
   SET cached_balance = running_balance
   WHERE id = %d

8. COMMIT
```

The `FOR UPDATE` lock on step 2 ensures that if two orders complete
simultaneously for the same affiliate, their wallet credits are
serialized and the running balance is always correct.

## Debit Validation

Before any debit (withdrawal, reversal, adjustment), the system:

1. Acquires a `FOR UPDATE` lock on the affiliate row.
2. Reads the authoritative balance from `SUM(amount)`.
3. Checks that `balance + debit_amount >= 0`.
4. If the result would be negative:
   - Normal debits (withdrawals): **rejected** with WP_Error `insufficient_balance`.
   - Forced debits (reversals, admin adjustments): **allowed** — these must
     always record to maintain financial integrity.
5. Failed debit attempts are logged to the audit log.

### Why Reversals Allow Negative Balance

When an order is refunded, the commission must be reversed even if the
affiliate has already withdrawn the funds. The negative balance represents
money owed. The admin can then:
- Wait for future commissions to offset the negative balance, or
- Make a manual adjustment, or
- Deduct from the next withdrawal.

## Idempotency Rules

The `entry_exists()` method prevents duplicate ledger entries by checking
the combination of:

- `affiliate_id`
- `entry_type`
- `reference_type`
- `reference_id`

Before inserting any entry, the `insert_entry()` method calls
`entry_exists()`. If a matching entry is found, it returns
`WP_Error('duplicate_entry')` instead of inserting.

### Examples

| Scenario | Prevented By |
|---|---|
| Same commission credited twice | `entry_exists(aff_id, 'commission', 'commission', commission_id)` |
| Same milestone bonus credited twice | `entry_exists(aff_id, 'milestone_bonus', 'milestone', milestone_id)` |
| Same withdrawal debited twice | `entry_exists(aff_id, 'withdrawal', 'withdrawal', withdrawal_id)` |

Note: Reversals for the same commission use `reference_type = 'commission'`
and `entry_type = 'reversal'`, so they don't conflict with the original
credit which has `entry_type = 'commission'`.

## Refund / Reversal Handling

When a WooCommerce order is refunded:

```
1. Commission engine identifies the commission record(s) for the order
2. For each commission:
   a. Mark commission status as 'reversed'
   b. Call Konx_Wallet::reverse(affiliate_id, amount, commission_id, reason)
   c. This inserts a negative ledger entry with:
      - entry_type = 'reversal'
      - reference_type = 'commission'
      - reference_id = commission_id
      - amount = -commission_amount
3. cached_balance and running_balance are updated atomically
```

The original credit entry is **never modified**. The full history is
preserved: the credit entry shows the original commission, and the
reversal entry shows when and why it was clawed back.

## Decimal Arithmetic

All monetary values are handled as **string-based decimal arithmetic**
to avoid IEEE 754 floating-point precision errors.

| Operation | bcmath Available | Fallback |
|---|---|---|
| Add | `bcadd($a, $b, 2)` | `number_format((float)$a + (float)$b, 2, '.', '')` |
| Subtract | `bcsub($a, $b, 2)` | `number_format((float)$a - (float)$b, 2, '.', '')` |
| Compare | `bccomp($a, $b, 2)` | Float comparison with 0.005 epsilon |

All amounts are normalized to 2-decimal strings via `normalize_amount()`
before storage and comparison.

## Reconciliation

The `reconcile()` method compares `cached_balance` against `SUM(amount)`:

```
1. Read authoritative balance from SUM(wallet_ledger.amount)
2. Read cached_balance from wp_konx_affiliates
3. If they match: return {was_in_sync: true}
4. If they differ:
   a. Update cached_balance to match SUM
   b. Log 'balance_reconciled' to audit log with drift amount
   c. Return {was_in_sync: false, drift: difference}
```

Reconciliation can be triggered:
- Manually by admin (future admin page)
- Programmatically before critical operations (withdrawals)
- Via WP-CLI command (future)

## Manual Testing Checklist

### Credits

- [ ] `credit()` with valid amount inserts a positive ledger entry
- [ ] `credit()` updates `running_balance` correctly
- [ ] `credit()` updates `cached_balance` on affiliates table
- [ ] `credit()` with zero or negative amount returns WP_Error
- [ ] `credit()` with non-existent affiliate returns WP_Error
- [ ] `credit()` with duplicate reference (same commission ID) returns WP_Error

### Debits

- [ ] `debit()` with valid amount inserts a negative ledger entry
- [ ] `debit()` reduces `running_balance` and `cached_balance`
- [ ] `debit()` with amount > balance returns WP_Error `insufficient_balance`
- [ ] `debit()` with amount > balance logs to audit log
- [ ] `debit()` with `force=true` allows negative balance
- [ ] `debit()` with zero amount returns WP_Error

### Reversals

- [ ] `reverse()` inserts a negative entry with type `reversal`
- [ ] `reverse()` allows balance to go negative (forced)
- [ ] `reverse()` with duplicate reference returns WP_Error
- [ ] Original credit entry is unchanged after reversal

### Balance Queries

- [ ] `get_available_balance()` matches manual SUM calculation
- [ ] `get_lifetime_earnings()` only includes positive entries
- [ ] `get_total_withdrawals()` only includes withdrawal debits
- [ ] `get_total_reversals()` only includes reversal debits
- [ ] `get_affiliate_balance_summary()` returns all fields correctly
- [ ] `in_sync` is true when cached_balance matches SUM

### Idempotency

- [ ] Two credits for same (affiliate, entry_type, ref_type, ref_id) — second fails
- [ ] Two debits for same withdrawal — second fails
- [ ] Reversal + original credit don't conflict (different entry_type)

### Concurrency

- [ ] Two concurrent credits for the same affiliate produce correct balance
- [ ] FOR UPDATE lock prevents running_balance corruption
- [ ] cached_balance matches SUM after concurrent operations

### Reconciliation

- [ ] `reconcile()` with matching balances returns `was_in_sync: true`
- [ ] `reconcile()` with drift corrects cached_balance
- [ ] `reconcile()` logs correction to audit log with drift amount

### Ledger History

- [ ] `get_ledger_history()` returns entries in descending order
- [ ] Pagination works (page 1, page 2, etc.)
- [ ] Entry type filter works
- [ ] Total count and page count are correct

### Append-Only Rule

- [ ] No UPDATE statements on wp_konx_wallet_ledger
- [ ] No DELETE statements on wp_konx_wallet_ledger
- [ ] Corrections create new entries, not modify existing ones
