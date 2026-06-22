# KonX Affiliate Dashboard — Email Template Roadmap

## Status: Planned (Future Phase)

## Planned Templates

| Template | Trigger | Recipient | Priority |
|---|---|---|---|
| Affiliate Registration | New affiliate signs up | Affiliate + Admin | High |
| Affiliate Approval | Admin activates pending affiliate | Affiliate | High |
| Commission Earned | Commission approved and credited | Affiliate | Medium |
| Milestone Bonus | 100-sale milestone reached | Affiliate | Medium |
| Withdrawal Submitted | Affiliate requests withdrawal | Admin | High |
| Withdrawal Approved | Admin approves withdrawal | Affiliate | Medium |
| Withdrawal Completed | Admin completes Wise payment | Affiliate | High |
| Withdrawal Rejected | Admin rejects withdrawal | Affiliate | High |
| Admin Fee Reminder | Fee approaching due date | Affiliate | Medium |
| Admin Fee Overdue | Fee past due date | Affiliate + Admin | High |

## Current State

Email notifications use `wp_mail()` with plain text bodies. No HTML
templates, no customization, no branding.

## Recommended Approach

### Option A: WooCommerce Email System (Recommended)

Extend `WC_Email` to create custom email classes that:
- Use WooCommerce's HTML email template wrapper
- Inherit the store's email branding (logo, colors, footer)
- Are customizable via WooCommerce > Settings > Emails
- Support preview and test sending

### Option B: Custom HTML Templates

Create standalone HTML email templates in `templates/emails/`.
Simpler but no WooCommerce integration.

## Implementation Plan

1. Create base template extending WC_Email
2. Register each email type with WooCommerce
3. Add template files to `templates/emails/`
4. Allow theme override via `theme/konx-affiliate-dashboard/emails/`
5. Add email toggle settings in plugin Settings page

## Timeline

Implement after v1.1.0. Estimated: 2-3 days of development.
