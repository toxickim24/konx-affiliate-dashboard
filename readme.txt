=== KonX Affiliate Dashboard ===
Contributors: toxickim24
Tags: woocommerce, affiliate, dashboard, commissions
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.15.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A custom affiliate dashboard for WooCommerce.

== Description ==

KonX Affiliate Dashboard provides a complete affiliate management system built on top of WooCommerce. Track referrals, manage commissions, and give affiliates their own dashboard.

== Installation ==

1. Upload the `konx-affiliate-dashboard` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Ensure WooCommerce is installed and activated.

== Frequently Asked Questions ==

= Does this plugin require WooCommerce? =

Yes. WooCommerce must be installed and active for this plugin to function.

== Changelog ==

= 1.15.0 =
**Migration reconciliation**
* Improved matching of PowerOf10 records to existing WordPress users.
* Safe Coupon Affiliates → WordPress bridge with accepted-CA-only safeguards.
* Duplicate and ambiguous bridge protection.
* Unicode email normalization.
* Corrected existing KonX user joins in reconciliation engine.

**Canonical migration planning**
* Separated reconciliation from validation into distinct wizard steps.
* Introduced Final Migration Plan as the single canonical source for all downstream steps.
* Comparison, Import Preview, and Dry Run now all consume the same canonical plan.
* Explicit planned-action labels per record (Create, Link WP, Link CA Bridge, Skip).
* Accurate WP-user and affiliate creation projections in the Dry Run.

**Hardening**
* Protected Source Comparator email lookup against non-string WordPress values on PHP 8.1+.

**Safety**
* Migration Execution is NOT enabled in this release.
* No automatic import or migration execution has been introduced.

= 1.0.0 =
* Initial plugin structure and bootstrap.
