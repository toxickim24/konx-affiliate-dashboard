# KonX Affiliate Dashboard — Admin Fees

## Overview

Admin fees are periodic fees that affiliates must pay to maintain their
commission earning status. If an affiliate has any unpaid or overdue
fees, all new commissions are **blocked** (recorded but not credited
to their wallet) until the fees are resolved.

## Admin Fee Rules

Fee amounts are configurable per affiliate type via the
`konx_admin_fee_settings` option. Defaults:

| Affiliate Type | Default Fee |
|---|---|
| Business Affiliate | Configurable (default $10/month) |
| Referral Affiliate | Configurable (default $10/month) |
| Team Agent | Configurable |
| Marketing Agent | Configurable |
| Sales Agent | Configurable |

The `get_fee_amount()` method reads from settings in this order:
1. Per-type setting (e.g., `settings['business']`)
2. Global default (e.g., `settings['default']`)
3. Hardcoded fallback: $10.00

## Fee Lifecycle

```
Created (unpaid)
    |
    +-- Admin marks paid --> paid
    |
    +-- Due date passes --> Daily cron marks overdue
    |     |
    |     +-- Admin marks paid --> paid
    |     +-- Admin waives --> waived
    |
    +-- Admin waives --> waived
```

### Fee Statuses

| Status | Meaning | Blocks Commissions? |
|---|---|---|
| `unpaid` | Created, not yet paid, not yet past due | Yes |
| `overdue` | Past due date and still unpaid | Yes |
| `paid` | Admin marked as paid | No |
| `waived` | Admin waived the fee | No |

## Commission Eligibility Logic

The commission engines call:

```php
$is_blocked = ! Konx_Admin_Fees::can_affiliate_earn( $affiliate_id );
```

This method checks:

```sql
SELECT COUNT(*) FROM wp_konx_admin_fees
WHERE affiliate_id = %d AND status IN ('unpaid', 'overdue')
```

- Count > 0 → affiliate **cannot** earn (commissions are blocked)
- Count = 0 → affiliate **can** earn (commissions are approved)

### How Blocking Works

When an affiliate cannot earn:

1. Commission record is still **created** in `wp_konx_commissions`
2. Status is set to `blocked` with `blocked_reason = 'unpaid_admin_fee'`
3. Wallet is **NOT credited**
4. The commission is preserved for later release

### Integration Points

Both commission engines delegate to `Konx_Admin_Fees::can_affiliate_earn()`:

| Engine | Where Called |
|---|---|
| `Konx_Commission_Engine::process_order()` | Once per order, before line item loop |
| `Konx_Recurring_Commission_Engine::process_renewal_order()` | Once per renewal order |

The admin fee check is **not duplicated** — there is a single source of
truth in `Konx_Admin_Fees`.

## Daily Overdue Cron

A daily WP-Cron event (`konx_daily_overdue_fee_check`) runs
`Konx_Admin_Fees::run_daily_overdue_check()`.

### What It Does

1. Queries all fees with `status = 'unpaid'` and `due_date < today`
2. Marks each as `overdue` via `mark_overdue()`
3. Each status change is audit-logged

### Cron Lifecycle

| Event | Action |
|---|---|
| Plugin activated | `Konx_Admin_Fees::schedule_cron()` — schedules daily event |
| Plugin deactivated | `Konx_Deactivator::clear_scheduled_events()` — unschedules |
| Daily execution | `run_daily_overdue_check()` — marks past-due fees as overdue |

## Admin Workflow

### Admin Fees Page

Located at: **KonX Affiliates > Admin Fees**

Features:
- Filter by status (unpaid, overdue, paid, waived)
- Search by affiliate name, email, or referral code
- Pagination
- Create fee form (affiliate ID, period, due date, amount)
- Action buttons per fee: Mark Paid, Mark Overdue, Waive

### Creating Fees

Admin can create fees manually via the admin page. Each fee requires:
- **Affiliate ID** — the affiliate's table ID
- **Period** — label like `2026-07` (unique per affiliate)
- **Due Date** — when the fee is due
- **Amount** — optional, auto-calculated from settings if blank

### Marking Fees Paid

When admin clicks "Mark Paid":
1. Fee status changes to `paid`
2. `paid_date` and `paid_by_admin_id` are recorded
3. Audit log entry created
4. If the affiliate now has zero unpaid/overdue fees, future commissions
   will be approved (not blocked)

**Note:** Blocked commissions from before the payment are NOT automatically
released in this phase. That requires a separate "release blocked
commissions" feature (future phase).

## Security

| Check | Implementation |
|---|---|
| Capability | `manage_konx_settings` required for all admin page actions |
| Nonces | Per-action nonces for status changes, form nonce for creation |
| Input sanitization | `absint()`, `sanitize_text_field()`, `sanitize_textarea_field()` |
| Output escaping | `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses()` |
| SQL | All queries via `$wpdb->prepare()` with parameterized values |
| Search | Uses `$wpdb->esc_like()` for LIKE queries |

## Manual Testing Checklist

### Fee Creation

- [ ] Create a fee for an active affiliate → fee record created with status `unpaid`
- [ ] Create a fee with auto-calculated amount → amount matches settings
- [ ] Create a fee with manual amount override → override amount used
- [ ] Create duplicate fee (same affiliate + period) → returns existing ID, no duplicate

### Status Changes

- [ ] Mark unpaid fee as paid → status changes, paid_date set, admin ID recorded
- [ ] Mark unpaid fee as overdue → status changes
- [ ] Mark overdue fee as paid → status changes, paid_date set
- [ ] Waive a fee → status changes to waived
- [ ] Status change logged to audit log

### Commission Eligibility

- [ ] Affiliate with no fees → `can_affiliate_earn()` returns true
- [ ] Affiliate with paid fees only → returns true
- [ ] Affiliate with one unpaid fee → returns false
- [ ] Affiliate with one overdue fee → returns false
- [ ] Affiliate with mixed paid + unpaid → returns false
- [ ] After marking all fees paid → returns true

### Commission Integration

- [ ] Order placed, affiliate has unpaid fee → commission status = `blocked`
- [ ] Order placed, affiliate fees are paid → commission status = `approved`
- [ ] Recurring renewal, affiliate has overdue fee → commission status = `blocked`
- [ ] Commission engines use `Konx_Admin_Fees::can_affiliate_earn()` (not private method)

### Daily Overdue Cron

- [ ] Fee with due_date in the past and status `unpaid` → auto-marked `overdue`
- [ ] Fee with due_date in the future → not affected
- [ ] Fee already `overdue` → not affected (no duplicate marking)
- [ ] Fee already `paid` → not affected
- [ ] Cron scheduled on activation
- [ ] Cron cleared on deactivation

### Admin Page

- [ ] Filter by status works
- [ ] Search by affiliate name works
- [ ] Search by email works
- [ ] Search by referral code works
- [ ] Pagination works
- [ ] Create fee form works
- [ ] Mark Paid button works (with nonce)
- [ ] Waive button works (with nonce)
- [ ] Mark Overdue button works (with nonce)
- [ ] Unauthorized user cannot access page
- [ ] Invalid nonce is rejected
