# KonX Affiliate Dashboard — Known Limitations (MVP)

## Functional Limitations

### 1. Blocked Commission Release

When an admin marks outstanding fees as paid, existing blocked
commissions are **not automatically released**. They remain with
`status = 'blocked'` until manually re-processed.

**Workaround:** Admin can identify blocked commissions via the
Reports page and manually adjust the wallet via the future admin
commission management page.

**Future fix:** Add a "release blocked commissions" action to the
admin fee payment flow.

### 2. Single eCard Commission

The `ecard_single` product ($55) is in the product map schema but
has no default commission rules in the seeded data. Single eCard
purchases do not earn commissions unless a rule is manually added
in Settings.

**Workaround:** Admin can add a commission rule for `ecard_single`
via the Settings page.

### 3. Partial Refund Accuracy

Proportional partial refunds calculate reversal amounts as a ratio
of refund amount to order subtotal. This may produce slight rounding
differences on multi-item orders.

Item-level partial refunds are more accurate but depend on WooCommerce
providing refund items, which not all payment gateways support.

### 4. Business Affiliate Activation

Business Affiliates are created with `pending` status and must be
manually activated by an admin after pack purchase is verified.
There is no automatic activation when a pack product is purchased.

**Future fix:** Hook into `woocommerce_order_status_completed` to
detect pack purchases and auto-activate pending business affiliates.

### 5. YITH Hook Verification

The recurring commission engine uses the `ywsbs_renew_order_payed`
hook (YITH's spelling). This hook name has not been verified against
the specific YITH version installed on konx.world. If YITH has
changed the hook name in a newer version, recurring commissions
won't fire.

**Verification needed:** Check the installed YITH version and
confirm the hook name before production deployment.

### 6. No Automated Payouts

Withdrawals are paid manually via Wise. The admin must:
1. Review the request in the admin panel
2. Send payment via Wise externally
3. Enter the Wise reference in the plugin
4. Mark as completed

There is no integration with the Wise API.

### 7. No Multi-Currency Support

All amounts are in USD. The plugin does not support currency
conversion or multi-currency display.

### 8. No REST API

There are no REST API endpoints. All interactions are via the
WordPress admin and frontend shortcodes.

### 9. No CSV Export

Admin reports do not support CSV export. Data can only be viewed
in the browser.

### 10. No Email Templates

Email notifications use `wp_mail()` with plain text. There are
no customizable HTML email templates.

## Performance Limitations

### 1. Dashboard Queries

The affiliate dashboard (`[konx_affiliate_dashboard]`) runs multiple
database queries on each page load (balance, milestones, commissions,
withdrawals, fees). For affiliates with large transaction histories,
this could be slow.

**Mitigation:** `cached_balance` on the affiliates table reduces
wallet balance queries. Commission and withdrawal tables have
date/affiliate indexes.

### 2. Milestone Catch-Up Loop

The `maybe_award_bonus()` method loops from milestone 1 to max
on every commission credit. For affiliates with thousands of sales,
this could produce many `has_bonus_for_milestone()` queries.

**Mitigation:** Each query is a simple indexed lookup. The loop
skips quickly for already-awarded milestones.

### 3. Reports Page

The Reports page runs 8 aggregate queries on every load. No caching.
On databases with millions of commission records, these GROUP BY
queries may be slow.

**Future fix:** Add transient caching or async report generation.

## Security Limitations

### 1. Audit Log Not Tamper-Proof

The `wp_konx_audit_log` table uses standard MySQL INSERT. A database
administrator could modify or delete audit records. The audit log
is not cryptographically sealed.

### 2. No Rate Limiting on Registration

The affiliate registration form has nonce protection but no rate
limiting. An attacker could submit many registration requests.

**Mitigation:** WordPress nonces prevent CSRF. Consider adding
a CAPTCHA or honeypot field for production.

### 3. No Two-Factor Authentication

Affiliate accounts use standard WordPress authentication with no
additional verification.

## Integration Limitations

### 1. No app.konx.world Integration

The plugin does not integrate with app.konx.world. This is
explicitly out of scope for this phase.

### 2. No Coupon Affiliates Migration

There is no automated migration path from Coupon Affiliates for
WooCommerce. If Coupon Affiliates has existing data, it must be
migrated manually before activating this plugin.

### 3. Cookie Consent

The referral tracking cookie is set without cookie consent verification.
If konx.world requires GDPR cookie consent, the referral cookie
should be integrated with the site's consent mechanism.
