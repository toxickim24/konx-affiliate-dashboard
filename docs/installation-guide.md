# KonX Affiliate Dashboard — Installation Guide

## Requirements

- WordPress 5.8+
- WooCommerce 6.0+
- PHP 7.4+
- MySQL 5.6+ or MariaDB 10.0+
- HTTPS recommended for cookie security
- YITH WooCommerce Subscription (optional, for recurring commissions)

## Installation

1. Download from GitHub: `https://github.com/toxickim24/konx-affiliate-dashboard`
2. Upload to `wp-content/plugins/konx-affiliate-dashboard/`
3. Or clone: `git clone https://github.com/toxickim24/konx-affiliate-dashboard.git`

## Activation

1. Go to **Plugins > Installed Plugins**
2. Find **KonX Affiliate Dashboard** and click **Activate**
3. On activation, the plugin creates:
   - 11 database tables
   - 5 affiliate roles
   - 8 custom capabilities
   - 20 default commission rules
   - IP hash salt for privacy
   - Daily cron for overdue fee detection

## Product Mapping

1. Go to **KonX Affiliates > Product Mapping**
2. Search for a WooCommerce product by name
3. Select a commission category
4. Click **Save Mapping**
5. Repeat for all commission-eligible products

For variable products, map each **variation** separately.

## Commission Setup

1. Go to **KonX Affiliates > Settings**
2. Review the commission rates matrix (default: 40%/20% depending on type)
3. Set the recurring commission rate (default: 10%)
4. Click **Save All Settings**

## Admin Fee Setup

1. In **Settings**, configure monthly fees per affiliate type
2. In **Admin Fees**, create fee records for affiliates
3. Daily cron auto-marks past-due fees as overdue

## Registration Page

1. Create a new WordPress page
2. Add the shortcode: `[konx_affiliate_register]`
3. Publish the page
4. This becomes your affiliate sign-up page

## Dashboard Page

1. Create a new WordPress page
2. Add the shortcode: `[konx_affiliate_dashboard]`
3. Publish the page
4. Set the page template to **Elementor Full Width** for best appearance

## Testing Checklist

- [ ] Plugin activates without errors
- [ ] Product mapping page loads
- [ ] Registration form displays
- [ ] Dashboard displays for logged-in affiliate
- [ ] Referral link click sets cookie
- [ ] Order with referral creates commission
- [ ] Admin overview shows stats

## Upgrade Process

1. Pull latest code: `git pull origin main`
2. The plugin auto-runs `dbDelta()` on version change to update tables
3. Existing data is preserved during upgrades
4. Commission rules are NOT re-seeded if they already exist

## Troubleshooting

- **Tables missing**: Deactivate and reactivate the plugin
- **Roles missing**: Same — reactivation re-creates them
- **Commissions not appearing**: Check product mapping, affiliate status, admin fees
- **Referral not tracking**: Check cookie settings, ref parameter, affiliate active status

## Uninstall

- **Deactivation**: Removes roles and capabilities. Data preserved.
- **Deletion**: Removes roles and capabilities. Data preserved unless
  `define('KONX_REMOVE_ALL_DATA', true)` is set in wp-config.php.
