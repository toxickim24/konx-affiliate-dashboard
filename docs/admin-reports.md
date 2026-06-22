# KonX Affiliate Dashboard — Admin Reports

## Admin Menu Structure

```
KonX Affiliates
├── Overview          — Key metrics and recent activity
├── Product Mapping   — Map WooCommerce products to categories
├── Admin Fees        — Fee records and status management
├── Withdrawals       — Withdrawal request management
└── Reports           — Aggregate reports with date filters
```

The top-level menu is registered by `Konx_Admin_Dashboard` at priority 5.
All other pages register submenus at the default priority.

## Overview Page

**Menu:** KonX Affiliates > Overview
**Capability:** `manage_konx_settings`

### Stats Cards (8 metrics)

| Metric | Source |
|---|---|
| Total Affiliates | `COUNT(*)` from `wp_konx_affiliates` |
| Active Affiliates | `COUNT(*) WHERE status = 'active'` |
| Pending Withdrawals | `COUNT(*) WHERE status IN ('pending', 'approved')` |
| One-Time Commissions | `SUM(commission_amount) WHERE status='approved' AND type='one_time'` |
| Recurring Commissions | `SUM(commission_amount) WHERE status='approved' AND type='recurring'` |
| Milestone Bonuses | `SUM(bonus_amount) WHERE status = 'approved'` |
| Withdrawals Paid | `SUM(amount) WHERE status = 'completed'` |
| Total Wallet Balance | `SUM(cached_balance)` from affiliates table |

### Recent Activity

- **Recent Commissions**: 10 most recent, showing date, affiliate, product, type, amount, status
- **Recent Withdrawals**: 10 most recent, showing date, affiliate, amount, status

## Reports Page

**Menu:** KonX Affiliates > Reports
**Capability:** `manage_konx_commissions`

### Date Range Filter

All reports (except admin fees) are filtered by date range.
Defaults to the current month (first day to today).

### Report Sections

#### Sales by Product Category

Groups approved commissions by `product_type`:
- Product type (starter_pack, pro_pack, etc.)
- Number of sales
- Total commission amount

#### Commissions by Affiliate Type

Groups approved commissions by `affiliate_type_at_sale`:
- Affiliate type
- Number of sales
- Total commission amount

#### One-Time vs Recurring

Groups approved commissions by `commission_type`:
- Type (one_time, recurring)
- Number of sales
- Total commission amount

#### Milestone Bonuses

Groups milestones by `status`:
- Status (approved, blocked)
- Count
- Total bonus amount

#### Withdrawals by Status

Groups withdrawals by `status`:
- Status (pending, approved, completed, rejected, cancelled)
- Count
- Total amount

#### Admin Fee Status

Groups all fees by `status` (no date filter — current snapshot):
- Status (unpaid, overdue, paid, waived)
- Count
- Total amount

#### Top 10 Affiliates by Sales

Ranked by approved commission count in the date range:
- Rank, name, type, total sales

#### Top 10 Affiliates by Earnings

Ranked by total approved commission amount in the date range:
- Rank, name, type, total earnings

## Permissions

| Page | Capability Required |
|---|---|
| Overview | `manage_konx_settings` |
| Reports | `manage_konx_commissions` |

Both capabilities are assigned to the `administrator` role during
plugin activation.

## SQL Performance

All report queries:
- Use `GROUP BY` with indexed columns
- Filter by date range using `BETWEEN` on indexed `created_at` columns
- Limit leaderboards to 10 rows
- Use `COALESCE(SUM(...), 0)` to handle empty results
- Use `$wpdb->prepare()` for all parameterized queries

The overview page runs 8 aggregate queries. The reports page runs
8 aggregate queries. All are simple single-table or two-JOIN queries
that leverage existing indexes.

## Testing Checklist

### Overview Page

- [ ] All 8 stat cards display correct values
- [ ] Total affiliates matches database count
- [ ] Active affiliates excludes inactive/pending
- [ ] Pending withdrawals includes pending + approved only
- [ ] Commission totals match `wp_konx_commissions` for approved status
- [ ] Milestone total matches approved bonuses
- [ ] Withdrawal paid total matches completed withdrawals
- [ ] Wallet balance matches SUM of cached_balance
- [ ] Recent commissions show last 10 in descending order
- [ ] Recent withdrawals show last 10 in descending order
- [ ] Affiliate names display correctly

### Reports Page

- [ ] Default date range is current month
- [ ] Changing date range filters results correctly
- [ ] Sales by product shows all product types with sales
- [ ] Commissions by type shows one_time and recurring separately
- [ ] Affiliate type report uses snapshotted type (not current)
- [ ] Milestone report groups by status
- [ ] Withdrawal report groups by status
- [ ] Admin fee report shows current snapshot (no date filter)
- [ ] Top 10 by sales ranks correctly
- [ ] Top 10 by earnings ranks correctly
- [ ] Empty date range shows "No data" messages

### Security

- [ ] Unauthorized user cannot access Overview
- [ ] Unauthorized user cannot access Reports
- [ ] Date inputs sanitized via `sanitize_text_field()`
- [ ] All output escaped via `esc_html()`
- [ ] No raw SQL concatenation — all parameterized

### Menu Structure

- [ ] Top-level menu shows "KonX Affiliates" with groups icon
- [ ] First submenu is "Overview"
- [ ] "Product Mapping" submenu appears
- [ ] "Admin Fees" submenu appears
- [ ] "Withdrawals" submenu appears
- [ ] "Reports" submenu appears
- [ ] Menu order is logical
