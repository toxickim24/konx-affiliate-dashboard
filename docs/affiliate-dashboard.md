# KonX Affiliate Dashboard — Frontend Dashboard

## Shortcode Usage

```
[konx_affiliate_dashboard]
```

Place this shortcode on any WordPress page. It renders the full
affiliate dashboard for logged-in affiliates. Compatible with
Elementor — use a Shortcode widget or text editor block.

## Displayed Sections

### 1. Profile & Referral

- Affiliate type (Business, Referral, Team Agent, etc.)
- Account status
- Referral code
- Referral link with copy-to-clipboard button
- Member since date

### 2. Financial Summary

Four stat cards:
- **Total Earnings** — lifetime sum of all credits
- **Available Balance** — current withdrawable balance
- **Total Withdrawn** — sum of completed withdrawals
- **Total Sales** — completed sales count

### 3. Milestone Progress

- Progress bar toward next 100-sale milestone
- Sales in current block / 100
- Milestones achieved count
- Estimated next bonus (sum of approved commissions in current block)
- Bonus history table (milestone number, block range, amount, status, date)

### 4. Recent Commissions

Table showing the 10 most recent commissions:
- Date, type (one-time/recurring), product, price, rate, commission, status

### 5. Withdrawals

- **If no pending request**: withdrawal form with:
  - Amount (min/max validated)
  - Wise email (pre-filled from affiliate profile)
  - Account holder name
  - Currency
  - Notes
- **If pending/approved request**: info message with amount and status
- Withdrawal history table (date, amount, status, processed date)

### 6. Admin Fee Status

Only shown if there are unpaid or overdue fees:
- Unpaid count, overdue count, total outstanding
- Message to contact administrator

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
| Output escaping | `esc_html()`, `esc_attr()`, `esc_url()`, `esc_js()`, `wp_kses()` |
| No direct SQL in view | View only reads `$data` array prepared by the class |
| CSS scoped | All styles under `.konx-dashboard` to avoid theme conflicts |

## CSS

Stylesheet: `assets/css/konx-dashboard.css`

- Loaded only on pages containing the shortcode (`has_shortcode()`)
- Scoped under `.konx-dashboard` wrapper
- Responsive: collapses to 2-column grid on mobile
- Uses WordPress admin color palette for consistency
- Works inside Elementor containers

## Testing Checklist

### Access Control

- [ ] Not logged in -> login prompt shown
- [ ] Logged in non-affiliate -> "no affiliate account" message
- [ ] Logged in affiliate -> full dashboard rendered

### Profile Section

- [ ] Correct affiliate type displayed
- [ ] Correct referral code displayed
- [ ] Referral link is correct (home_url/?ref=CODE)
- [ ] Copy button works (copies URL to clipboard)
- [ ] Member since date formatted correctly

### Financial Summary

- [ ] Total earnings matches `get_lifetime_earnings()`
- [ ] Available balance matches `get_available_balance()`
- [ ] Total withdrawn matches `get_total_withdrawals()`
- [ ] Total sales matches `completed_sales`

### Milestone Progress

- [ ] Progress bar width matches percent_complete
- [ ] Sales in block / 100 displayed correctly
- [ ] Milestones achieved count correct
- [ ] Estimated next bonus calculated from current block
- [ ] Bonus history table shows past milestones

### Commission History

- [ ] Shows most recent 10 commissions
- [ ] Commission type (one-time/recurring) displayed
- [ ] Product type displayed
- [ ] Amount and rate correct
- [ ] Status colored (approved=green, blocked=red, etc.)
- [ ] "Showing X of Y" message when more than 10

### Withdrawal Form

- [ ] Form visible when no pending request
- [ ] Form hidden when pending/approved request exists
- [ ] Pending request info shown with amount and status
- [ ] Min amount enforced in HTML input
- [ ] Max amount set to available balance
- [ ] Wise email pre-filled from affiliate profile
- [ ] Submit creates withdrawal request
- [ ] Success message shown after submission
- [ ] Error message shown for insufficient balance
- [ ] Error message shown for below minimum
- [ ] Error message shown for existing pending request

### Withdrawal History

- [ ] Shows recent withdrawal requests
- [ ] Status colored correctly
- [ ] Processed date shown or dash for pending

### Admin Fee Status

- [ ] Section hidden when fees are paid
- [ ] Section visible when unpaid/overdue fees exist
- [ ] Banner at top warns commissions are paused
- [ ] Outstanding amount displayed correctly

### Escaping

- [ ] No unescaped output in the view file
- [ ] All `$data` values escaped with `esc_html()` or `esc_attr()`
- [ ] Status HTML uses `wp_kses()` with allowed span/style

### Responsive

- [ ] Dashboard readable on mobile (< 600px)
- [ ] Stats grid collapses to 2 columns
- [ ] Tables scroll horizontally if needed
- [ ] Withdrawal form full-width on mobile
