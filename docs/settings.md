# KonX Affiliate Dashboard — Settings

## Overview

The Settings page allows administrators to configure commission rates,
recurring rates, admin fees, withdrawal rules, and referral tracking
behavior without modifying code.

**Menu:** KonX Affiliates > Settings
**Capability:** `manage_konx_settings`

## Settings Sections

### 1. Commission Rates

One-time commission rates per affiliate type and product type, displayed
as a percentage matrix. Stored in `wp_konx_commission_rules` table.

| Type / Product | Starter Pack | Pro Pack | eCard Pack |
|---|---|---|---|
| Business | 40% | 40% | 40% |
| Referral | 20% | 20% | 20% |
| Team Agent | 40% | 40% | 40% |
| Marketing Agent | 40% | 20% | 20% |
| Sales Agent | 20% | 20% | 20% |

**Storage:** Custom table `wp_konx_commission_rules`. Each rate is a row
with `affiliate_type`, `product_type`, `commission_type = 'one_time'`,
and `rate` as `DECIMAL(5,4)`.

**How commission engine reads it:** `Konx_Commission_Engine::get_commission_rate()`
queries the table at runtime. Changes take effect on the next order.

**Impact of changes:** Only affects future commissions. Past commissions
retain their snapshotted `affiliate_type_at_sale` and `commission_rate`.

### 2. Recurring Commission Rate

A single flat percentage applied to all subscription renewals for
all affiliate types.

| Setting | Default | Option Key |
|---|---|---|
| Recurring Rate | 10% | `konx_recurring_commission_rate` |

**Storage:** `wp_options` as decimal string (e.g., `'0.1000'`).

**How recurring engine reads it:** `Konx_Settings_Page::get_recurring_rate()`.
The engine previously used a hardcoded constant; it now reads from options.

### 3. Admin Fees

Monthly admin fee amounts per affiliate type.

| Setting | Default | Option Key |
|---|---|---|
| Business fee | (configurable) | `konx_admin_fee_settings['business']` |
| Referral fee | (configurable) | `konx_admin_fee_settings['referral']` |
| Team Agent fee | (configurable) | `konx_admin_fee_settings['team_agent']` |
| Marketing Agent fee | (configurable) | `konx_admin_fee_settings['marketing_agent']` |
| Sales Agent fee | (configurable) | `konx_admin_fee_settings['sales_agent']` |
| Default fee | $10.00 | `konx_admin_fee_settings['default']` |

**Storage:** `wp_options` as serialized array under `konx_admin_fee_settings`.

**How admin fees reads it:** `Konx_Admin_Fees::get_fee_amount()` reads
per-type values with fallback to default.

### 4. Withdrawal Settings

| Setting | Default | Option Key |
|---|---|---|
| Minimum Withdrawal | $50.00 | `konx_affiliate_settings['min_withdrawal']` |

**Storage:** `wp_options` under `konx_affiliate_settings` (serialized array).

**How withdrawals reads it:** `Konx_Withdrawals::get_minimum_amount()`.

### 5. Referral Settings

| Setting | Default | Option Key |
|---|---|---|
| Cookie Duration | 30 days | `konx_referral_settings['cookie_days']` |
| URL Parameter | `ref` | `konx_referral_settings['ref_param']` |
| Dedup Window | 24 hours | `konx_referral_settings['dedup_hours']` |

**Storage:** `wp_options` under `konx_referral_settings` (serialized array).

**How tracker reads it:**
- `Konx_Settings_Page::get_cookie_days()` → cookie expiry
- `Konx_Settings_Page::get_ref_param()` → URL parameter name
- `Konx_Settings_Page::get_dedup_window()` → dedup seconds

## Settings Reader Methods

| Method | Returns | Used By |
|---|---|---|
| `Konx_Settings_Page::get_recurring_rate()` | `'0.1000'` | Recurring commission engine |
| `Konx_Settings_Page::get_cookie_days()` | `30` | Referral tracker (cookie expiry) |
| `Konx_Settings_Page::get_ref_param()` | `'ref'` | Referral tracker (URL parameter) |
| `Konx_Settings_Page::get_dedup_window()` | `86400` | Referral tracker (dedup seconds) |

These methods are static and can be called from any class without
instantiating the settings page.

## Backward Compatibility

| Concern | Handling |
|---|---|
| Existing seeded commission rules | Preserved. Settings page reads existing rows and updates them in place. |
| No settings saved yet | All readers have sensible defaults matching original hardcoded values. |
| Options not created until first save | `get_option()` with default values handles missing options. |
| Commission rate format | Settings page converts percentage input (40) to decimal (0.4000). |

## Testing Checklist

### Commission Rates

- [ ] All 15 rates displayed correctly (5 types × 3 products)
- [ ] Default values match seeded rules
- [ ] Change Business Starter from 40% to 35% → saved to table
- [ ] Next one-time commission uses new rate
- [ ] Past commissions unaffected (snapshotted rate preserved)
- [ ] Rate of 0% → commission amount is $0.00

### Recurring Rate

- [ ] Default shows 10%
- [ ] Change to 15% → saved as '0.1500' in options
- [ ] Next recurring commission uses 15% rate
- [ ] Change to 0% → no recurring commission credited

### Admin Fees

- [ ] Per-type fees displayed and editable
- [ ] Default fee shown and editable
- [ ] Empty per-type field → falls back to default
- [ ] `get_fee_amount()` returns correct value after change

### Withdrawal Settings

- [ ] Minimum withdrawal displayed and editable
- [ ] Change to $100 → affiliates cannot withdraw less than $100
- [ ] `get_minimum_amount()` returns updated value

### Referral Settings

- [ ] Cookie duration displayed (default 30)
- [ ] Change to 60 days → tracker sets 60-day cookie
- [ ] URL parameter displayed (default 'ref')
- [ ] Change to 'aff' → tracker reads ?aff= parameter
- [ ] Dedup window displayed (default 24 hours)
- [ ] Change to 12 hours → dedup uses 12-hour window

### Save Behavior

- [ ] Submit button saves all sections at once
- [ ] Success message displayed after save
- [ ] No data loss on save (all sections preserved)

### Security

- [ ] Unauthorized user cannot access settings
- [ ] Nonce verified on save
- [ ] All inputs sanitized
- [ ] All outputs escaped
- [ ] Commission rate conversion: 40 → 0.4000 (not 40.0000)
