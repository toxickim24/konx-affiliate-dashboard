# Settings Dashboard — Setup Progress

## Overview

The admin Overview page (`KonX Affiliates > Overview`) displays a setup progress checklist at the top, giving administrators an immediate answer to "What do I need to configure?"

## Setup Checklist

### Checklist Items

| # | Item | Required | Complete When |
|---|---|---|---|
| 1 | System Status | Yes | All 11 database tables exist AND WooCommerce is active |
| 2 | Product Mapping | Yes | At least 1 active mapping in `wp_konx_product_map` |
| 3 | Commission Rules | Yes | At least 1 active rule in `wp_konx_commission_rules` |
| 4 | Required Pages | Yes | Published pages with `[konx_affiliate_dashboard]` and `[konx_affiliate_register]` shortcodes |
| 5 | Data Migration | No (optional) | `konx_migration_status` option = `completed` |

### Status Colors

| Status | Color | Icon | Meaning |
|---|---|---|---|
| `complete` | Green | Checkmark | Item is configured correctly |
| `attention` | Amber | Warning | Needs configuration |
| `optional` | Grey | Marker | Optional, not started |

## Progress Calculation

```
Progress = {required completed} / {required total} Complete
```

- Only required items (1-4) count toward completion
- Migration is always excluded from the progress fraction
- Migration never blocks the "KonX is Ready" state

Example: If System Status, Product Mapping, and Required Pages are complete but Commission Rules need attention: `3 / 4 Complete`

## Progress Bar

A visual progress bar shows the percentage:
- `(completed / total) * 100`%
- Green gradient fill matching the plugin design system
- Animated width transition

## Smart Primary Button

### When Setup Is Incomplete

Displays: **Complete Setup** (button-hero)

The button links to the first incomplete required item, in priority order:
1. System Status
2. Product Mapping
3. Commission Rules
4. Required Pages

### When Setup Is Complete

Displays: **Go to Dashboard** (button-hero)

Below the progress bar, a green success banner appears:
- Title: "KonX is Ready"
- Body: "Your affiliate platform is fully configured."

## Configuration Cards

Six quick-access cards displayed in a 3-column grid below the checklist:

| Card | Detail | Link |
|---|---|---|
| System Status | Healthy / Error detail | System Status page |
| Product Mapping | X Products Mapped | Product Mapping page |
| Commission Rules | X Active Rules | Settings page |
| Required Pages | Status detail | System Status page |
| Data Migration | Optional / Completed | Overview page |
| Help Center | Getting Started | Help Center page |

Each card shows a status icon (green check, amber warning, or grey marker) and is fully clickable.

## Optional Migration Behavior

The Data Migration item:
- Defaults to **grey** (Optional) when no migration has been attempted
- Shows **green** (Completed) when `konx_migration_status = 'completed'`
- Shows **amber** (In Progress) when migration is previewed or in progress
- Never blocks the "KonX is Ready" state
- Never counts toward the completion fraction
- Button label shows "Review" instead of "Configure"

## Implementation

### File: `admin/class-konx-admin-dashboard.php`

| Method | Purpose |
|---|---|
| `get_setup_status()` | Queries DB to calculate checklist item statuses, completion counts |
| `render_setup_checklist()` | Renders the setup card HTML with progress bar, checklist, and config cards |
| `find_page_with_shortcode()` | Finds published page containing a shortcode |

### CSS: `assets/css/konx-admin.css`

New classes:
- `.konx-setup-card` — Main checklist container
- `.konx-setup-header` — Title + smart button row
- `.konx-setup-progress` — Progress bar track
- `.konx-setup-progress-fill` — Progress bar fill
- `.konx-setup-ready` — Green success banner
- `.konx-setup-checklist` — Checklist item list
- `.konx-setup-item` — Individual checklist row
- `.konx-config-card` — Quick access card

### Data Sources

All status checks use existing plugin data:
- Database table existence: `SHOW TABLES LIKE`
- WooCommerce: `konx_affiliate_is_woocommerce_active()`
- Product mappings: `SELECT COUNT(*) FROM wp_konx_product_map WHERE is_active = 1`
- Commission rules: `SELECT COUNT(*) FROM wp_konx_commission_rules WHERE is_active = 1`
- Required pages: `SELECT ID FROM wp_posts WHERE post_content LIKE '%[shortcode]%'`
- Migration status: `get_option('konx_migration_status')`

No new database tables or options are created.

## Existing Tabs

The setup checklist enhances the Overview page only. All existing admin pages continue to work unchanged:
- Settings (commission rates, fees, referral tracking)
- System Status (detailed health checks)
- Product Mapping
- Help Center
- All other admin pages
