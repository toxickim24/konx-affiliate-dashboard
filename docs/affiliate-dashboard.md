# KonX Affiliate Dashboard — Frontend Dashboard

## Shortcode Usage

```
[konx_affiliate_dashboard]
```

Place this shortcode on any WordPress page. It renders the full
affiliate dashboard for logged-in affiliates. Compatible with
Elementor — use a Shortcode widget or text editor block.

Also accessible via WooCommerce My Account at `/my-account/affiliate-dashboard/`.

## Dashboard Layout

### 1. Hero Bar

Compact gradient bar at the top:
- Welcome message with affiliate's first name
- Status badge (Active/Pending/etc.)
- Affiliate type label
- Referral code (monospace, pill-styled)
- Copy Referral Link button
- Log Out button

### 2. Statistics Cards (Hero Cards)

Four primary metric cards displayed in a single row:

| Card | Value | Notes |
|---|---|---|
| **Available Balance** | `$X,XXX.XX` | Primary card (blue left border), includes "Request Withdrawal" button |
| **Total Earnings** | `$X,XXX.XX` | Lifetime sum of all credits |
| **Total Sales** | `XXX` | Completed sales count |
| **Withdrawn** | `$X,XXX.XX` | Sum of completed withdrawals |

**Responsive behavior:**
- Desktop (>900px): 4 columns in one row
- Tablet (768-900px): 2x2 grid
- Mobile (<480px): Single column stack

The "Request Withdrawal" button scrolls to and activates the Withdrawals tab.

### 3. Milestone Progress + Achievements (Two-Column)

Side-by-side sections on desktop, stacked on tablet/mobile.

**Milestone Progress:**
- Large sales count: `X / 100` sales toward next milestone
- Progress bar with percentage
- Estimated next bonus amount
- Explainer text

**Achievements** (renamed from "Affiliate Success Journey"):
- Large milestone count number
- "Milestones Completed" label
- Journey progress bar
- Step checklist (done/pending with checkmarks)
- Next step hint

All milestone logic and calculations are unchanged — UI rename only.

### 4. Recent Referral Activity (Placeholder)

Dashed-border placeholder card:
- Title: "Recent Referral Activity"
- Body: "Coming Soon" (italic)

Prepares UI for future referral activity tracking. No functionality implemented.

### 5. Referral Tools

- Referral Code input with Copy button
- Referral Link input with Copy button
- Share row with labeled section:
  - Facebook, X/Twitter, LinkedIn, Email buttons

### 6. Milestone Bonus History

Table of past milestone bonuses (only shown if bonuses exist):
- Milestone number, block range, amount, status, date

### 7. Financial Activity

Tabbed interface:
- **Commissions tab**: Table with date, type, product, price, rate, commission, status
- **Withdrawals tab**: Withdrawal form or pending notice + history table

### 8. Admin Fee Status

Only shown when unpaid/overdue fees exist:
- Three stat cards: Unpaid, Overdue, Outstanding
- Warning message

### 9. Commission Rate Card

Table showing the affiliate's commission rates by product type.

### 10. Profile Settings

Wise Payment Email form at the bottom.

## Card Hierarchy

```
Hero Bar (gradient, identity)
  |
Hero Cards (4x metrics — the numbers that matter)
  |
  +-- Milestone Progress  |  Achievements (side-by-side)
  |
Recent Referral Activity (placeholder)
  |
Referral Tools (sharing)
  |
Milestone Bonus History (table, if applicable)
  |
Financial Activity (commissions + withdrawals tabs)
  |
Admin Fee Status (conditional)
  |
Commission Rates (reference)
  |
Profile Settings (form)
```

## Responsive Behavior

| Breakpoint | Hero Cards | Two-Column | Stats Grid |
|---|---|---|---|
| >900px (Desktop) | 4 columns | 2 columns | auto-fit |
| 768-900px (Tablet) | 2x2 | 1 column | 2 columns |
| <480px (Mobile) | 1 column | 1 column | 1 column |

## Future Placeholders

| Section | Status | Purpose |
|---|---|---|
| Recent Referral Activity | Coming Soon | Will show recent clicks, conversions, referral timeline |

## Permissions

| User State | What They See |
|---|---|
| Not logged in | Login prompt with link |
| Logged in, not an affiliate | Message to contact admin |
| Logged in affiliate | Full dashboard |

The affiliate can only see **their own data**. All queries filter
by affiliate_id. No cross-affiliate data exposure.

## Withdrawal Flow

```
1. Affiliate fills out withdrawal form
2. Form POSTs to admin-post.php (action: konx_affiliate_withdrawal)
3. Konx_Dashboard::handle_withdrawal_form() runs:
   a. Verify user is logged in
   b. Verify user is an affiliate
   c. Verify nonce (per-affiliate: konx_withdrawal_request_{id})
   d. Sanitize all inputs
   e. Call Konx_Withdrawals::create_request()
   f. Set feedback transient (success or error)
   g. Redirect back to the dashboard page
4. Dashboard re-renders with feedback message
```

### Validation (by Konx_Withdrawals::create_request)

- Amount >= minimum ($50 default)
- Amount <= available balance
- No pending/approved withdrawal exists
- Valid Wise email
- Valid affiliate

## Security

| Check | Implementation |
|---|---|
| Login required | `is_user_logged_in()` before rendering |
| Affiliate required | `get_affiliate_by_user()` check |
| Data isolation | All queries scoped to affiliate_id |
| Withdrawal nonce | Per-affiliate: `konx_withdrawal_request_{affiliate_id}` |
| Input sanitization | `sanitize_text_field()`, `sanitize_email()`, `sanitize_textarea_field()` |
| Output escaping | `esc_html()`, `esc_attr()`, `esc_url()`, `esc_js()` |
| No direct SQL in view | View only reads `$data` array prepared by the class |
| CSS scoped | All styles under `.konx-dashboard` to avoid theme conflicts |

## CSS

Stylesheet: `assets/css/konx-dashboard.css`

- Loaded only on pages containing the shortcode (`has_shortcode()`)
- Also loaded on My Account affiliate-dashboard endpoint
- Scoped under `.konx-dashboard` wrapper
- Uses CSS custom properties from `konx-frontend.css`
- Responsive at 900px, 768px, 480px breakpoints
- Works inside Elementor containers

## Testing Checklist

### Access Control

- [ ] Not logged in -> login prompt shown
- [ ] Logged in non-affiliate -> "no affiliate account" message
- [ ] Logged in affiliate -> full dashboard rendered

### Hero Bar

- [ ] Welcome message shows first name
- [ ] Status badge correct color
- [ ] Affiliate type label correct
- [ ] Referral code displayed in monospace
- [ ] Copy Referral Link copies URL to clipboard
- [ ] Log Out button works

### Statistics Cards

- [ ] Available Balance matches `get_available_balance()`
- [ ] Total Earnings matches `get_lifetime_earnings()`
- [ ] Total Sales matches `completed_sales`
- [ ] Withdrawn matches `get_total_withdrawals()`
- [ ] Request Withdrawal scrolls to withdrawals tab
- [ ] 4 cards on desktop, 2x2 on tablet, 1-col on mobile

### Milestone Progress

- [ ] Sales in block / 100 displayed correctly
- [ ] Progress bar width matches percent_complete
- [ ] Estimated next bonus calculated from current block

### Achievements

- [ ] Milestones achieved count correct
- [ ] Journey steps show done/pending state
- [ ] Next step hint shown when applicable
- [ ] Section labeled "Achievements" (not "Affiliate Success Journey")

### Recent Referral Activity

- [ ] Placeholder card shown with dashed border
- [ ] "Coming Soon" text displayed
- [ ] No functionality or data queries

### Referral Tools

- [ ] Referral code input readonly with copy
- [ ] Referral link input readonly with copy
- [ ] Share buttons link to correct URLs

### Financial Activity

- [ ] Commission table shows recent 10
- [ ] Withdrawal form visible when no pending request
- [ ] Withdrawal history table rendered

### Responsive

- [ ] Dashboard readable on mobile (< 480px)
- [ ] Hero cards collapse correctly
- [ ] Two-column layout stacks on tablet
- [ ] Tables scroll horizontally if needed
