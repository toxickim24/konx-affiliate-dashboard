# KonX Affiliate Dashboard — Release Plan

## Environments

| Environment | Location | Purpose |
|---|---|---|
| Local | WAMP (localhost) | Development and initial testing |
| Staging | Staging server or subdomain | Integration testing, UAT |
| Production | konx.world | Live site |

## Pre-Deployment Checklist

- [ ] All code committed and pushed to `develop`
- [ ] No PHP errors with `WP_DEBUG = true`
- [ ] Plugin activates cleanly on fresh WordPress + WooCommerce
- [ ] All 11 database tables created successfully
- [ ] 5 roles and 8 capabilities registered
- [ ] 20 commission rules seeded
- [ ] HPOS compatibility — no WooCommerce admin warnings
- [ ] WooCommerce checkout flow works (referral → order → commission)
- [ ] Frontend dashboard renders correctly
- [ ] Admin pages accessible and functional
- [ ] Cron event scheduled for daily overdue check

## Local → Staging

### 1. Prepare Staging

- Install WordPress + WooCommerce on staging
- Install YITH WooCommerce Subscription
- Install Elementor
- Create test products matching konx.world (Starter Pack, Pro Pack, etc.)
- Import or create test users

### 2. Deploy Plugin

```bash
# On staging server
cd wp-content/plugins/
git clone https://github.com/toxickim24/konx-affiliate-dashboard.git
cd konx-affiliate-dashboard
git checkout develop
```

### 3. Activate and Configure

- Activate plugin in WordPress admin
- Navigate to KonX Affiliates > Product Mapping
- Map staging WooCommerce product IDs to categories
- Navigate to KonX Affiliates > Settings
- Verify commission rates
- Verify admin fee amounts
- Verify withdrawal minimum

### 4. Test on Staging

Run through the full testing checklist (docs/testing-checklist.md):
- Register a test affiliate
- Visit a referral link
- Place a test order
- Verify commission created
- Test subscription renewal
- Test withdrawal request/completion
- Test refund reversal
- Test admin fee blocking
- Test milestone bonus at scale (if possible)
- Test all admin pages
- Test frontend dashboard

### 5. Stakeholder Review

- Share staging URL with stakeholders
- Collect feedback
- Fix reported issues
- Re-test

## Staging → Production

### 1. Pre-Production

- [ ] All staging tests pass
- [ ] Stakeholder sign-off received
- [ ] Full production database backup taken
- [ ] Maintenance mode plan ready (optional)
- [ ] If Coupon Affiliates is active: deactivate it BEFORE activating KonX

### 2. Deploy to Production

```bash
# On production server
cd wp-content/plugins/
git clone https://github.com/toxickim24/konx-affiliate-dashboard.git
cd konx-affiliate-dashboard
git checkout main
```

Or merge develop → main first:
```bash
git checkout main
git merge develop
git tag -a v1.0.0 -m "Initial release"
git push origin main --tags
```

### 3. Activate and Configure

- Activate plugin
- Map production WooCommerce product IDs (Settings > Product Mapping)
- Configure commission rates (Settings > Settings)
- Configure admin fees
- Create an Elementor page with `[konx_affiliate_dashboard]`
- Create an Elementor page with `[konx_affiliate_register]`
- Test one end-to-end flow with a test affiliate

### 4. Post-Deployment Monitoring (24-48 hours)

- [ ] Monitor PHP error log
- [ ] Verify WooCommerce orders processing normally
- [ ] Verify no plugin conflicts (Elementor, YITH, etc.)
- [ ] Check `wp_konx_commissions` for correct entries
- [ ] Check `wp_konx_wallet_ledger` for correct balances
- [ ] Verify daily cron is running (check overdue fees)
- [ ] Verify admin dashboard stats are accurate
- [ ] Verify affiliate dashboard shows correct data

## Rollback Plan

### Level 1: Deactivate

- Deactivate plugin via Plugins menu
- WooCommerce orders continue normally
- Affiliate shortcodes show as raw text
- Data preserved for reactivation

### Level 2: Code Rollback

```bash
git checkout <previous-commit>
```

Deactivate and reactivate plugin after rollback.

### Level 3: Database Restore

- Restore from pre-deployment backup
- Roll back plugin code
- Reactivate plugin

## Uninstall Behavior

### Default Uninstall (Plugin Deletion)

When the plugin is deleted via WordPress admin **without** the
`KONX_REMOVE_ALL_DATA` constant:

| Action | Performed |
|---|---|
| Remove custom roles (5) | Yes |
| Remove capabilities from administrator (8) | Yes |
| Clear cron events | Yes |
| Drop database tables | **No** |
| Delete plugin options | **No** |
| Delete IP hash salt | **No** |
| Delete user meta | **No** |
| Delete order meta | **No** |

All financial data (commissions, wallet, withdrawals, milestones,
affiliates) is **preserved**. Reinstalling the plugin restores
full functionality.

### Destructive Uninstall

To permanently delete all data, add to `wp-config.php` **before**
deleting the plugin:

```php
define( 'KONX_REMOVE_ALL_DATA', true );
```

This must be **boolean `true`** (strict check with `===`). The
following values do NOT trigger destructive cleanup:

| Value | Triggers Cleanup |
|---|---|
| `true` | **Yes** |
| `false` | No |
| `1` | No |
| `'1'` | No |
| `'true'` | No |
| `'yes'` | No |
| `0` | No |
| `null` | No |
| Not defined | No |

After destructive uninstall, remove the constant from `wp-config.php`.

## Version Tagging

| Version | Milestone |
|---|---|
| v1.0.0 | Initial production release |
| v1.0.x | Bug fixes and hotfixes |
| v1.1.0 | Next feature release |
