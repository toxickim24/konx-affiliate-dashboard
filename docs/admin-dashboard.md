# Admin Operations Dashboard

The KonX Admin Overview page serves as an Operations Dashboard, giving administrators an instant understanding of the platform's current state.

## Dashboard Layout

The dashboard is organized into the following sections, rendered top-to-bottom:

### 1. Setup Progress (top)

A collapsible checklist showing the platform configuration status:

- **System Status** — verifies WooCommerce and all 11 database tables
- **Product Mapping** — checks for at least one active product mapping
- **Commission Rules** — checks for at least one active commission rule
- **Required Pages** — verifies Dashboard and Registration shortcode pages
- **Data Migration** — optional item showing migration status

Includes a progress bar and a CTA button to complete setup or go to the dashboard.

### 2. Operations Summary (KPI Cards)

Six key performance indicator cards in a responsive grid:

| KPI | Source | Links To |
|-----|--------|----------|
| Total Affiliates | `konx_affiliates` COUNT | Affiliates page |
| Approved Affiliates | `konx_affiliates` WHERE status = active | Affiliates page (filtered) |
| Pending Applications | `konx_affiliates` WHERE status = pending | Affiliates page (filtered) |
| Pending Withdrawals | `konx_withdrawals` WHERE status IN (pending, approved) | Withdrawals page (filtered) |
| Monthly Commissions | `konx_commissions` SUM for current month | Reports page |
| Available Wallet Balance | `konx_affiliates` SUM(cached_balance) | Reports page |

Each card is clickable and navigates to the relevant admin page.

### 3. Action Required

Shows only items that require administrator attention:

- **Pending affiliate applications** — links to Affiliates page filtered by pending
- **Pending withdrawal requests** — links to Withdrawals page filtered by pending
- **Overdue admin fees** — links to Admin Fees page filtered by overdue
- **Migration warnings** — shown when migration is in progress but not completed

When no items require attention, displays: "No action required. Everything looks good."

### 4. Platform Health

A health summary card showing the platform's overall health percentage with individual checks:

- WooCommerce — active/version check
- Database Tables — 11/11 present check
- Required Pages — dashboard and registration pages
- Product Mapping — active mappings count
- Commission Rules — active rules count
- Migration — completion status

The health percentage is color-coded:
- 100% = green (excellent)
- 75-99% = blue (good)
- Below 75% = yellow (needs attention)

Links to the full System Status page for detailed diagnostics.

### 5. Quick Actions

Six action cards in a 2-column grid:

- **Manage Affiliates** — view, approve, and manage affiliate accounts
- **Review Withdrawals** — process pending withdrawal requests
- **Product Mapping** — map WooCommerce products to commission types
- **Commission Rules** — configure rates, tiers, and payout rules
- **Migration Wizard** — import data from PowerOf10 or other sources
- **Help Center** — documentation and getting started guides

Each card links to the corresponding admin page.

### 6. Charts (preserved)

- Monthly Commissions (line chart) — 6-month trend
- Withdrawal Volume (bar chart) — 6-month trend

### 7. Recent Activity (preserved)

- Recent Commissions table — last 10 entries
- Recent Withdrawals table — last 10 entries

## Responsive Behaviour

| Breakpoint | KPI Grid | Quick Actions | Charts/Activity |
|------------|----------|---------------|-----------------|
| Desktop (1200px+) | 4+ columns (auto-fill) | 2 columns | 2 columns |
| Tablet (782-1024px) | 2 columns | 2 columns | 2 columns |
| Mobile (782px) | 2 columns | 1 column | 1 column |
| Small Mobile (480px) | 1 column | 1 column | 1 column |

## Data Sources

All data is read-only. The dashboard does not perform any database writes. KPIs reuse existing database tables and queries from the affiliate system.

## Files

- `admin/class-konx-admin-dashboard.php` — main dashboard PHP class
- `assets/css/konx-admin.css` — shared admin design system with dashboard styles
