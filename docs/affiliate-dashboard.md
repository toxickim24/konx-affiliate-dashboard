# KonX Affiliate Dashboard — Frontend Dashboard

## Shortcode Usage

```
[konx_affiliate_dashboard]
```

Place this shortcode on any WordPress page. It renders the full
affiliate dashboard for logged-in affiliates. Compatible with
Elementor — use a Shortcode widget or text editor block.

Also accessible via WooCommerce My Account at `/my-account/affiliate-dashboard/`.

## Portal Navigation

The dashboard is structured as an Affiliate Portal with sidebar navigation on desktop and a collapsible menu on tablet/mobile.

### Navigation Items

| Nav Item | Anchor | Content |
|---|---|---|
| Overview | `#overview` | Stats cards, milestone progress, referral activity placeholder |
| Referral Tools | `#referral-tools` | Referral code/link, copy buttons, social sharing |
| Financial Activity | `#financial-activity` | Bonus history, commissions/withdrawals tabs, admin fees |
| Achievements | `#achievements` | Milestone count, journey steps, progress bar |
| Commission Rates | `#commission-rates` | Rate card by product type |
| Profile Settings | `#profile-settings` | Wise payment email form |

### Active Section Highlighting

Uses `IntersectionObserver` (vanilla JavaScript, no jQuery) to automatically highlight the current section in the sidebar as the user scrolls. The observer uses `rootMargin: '-20% 0px -60% 0px'` to trigger when a section enters the upper portion of the viewport.

### Smooth Scrolling

Clicking a nav link smooth-scrolls to the target section using `scrollIntoView({ behavior: 'smooth' })`. On mobile, the nav menu auto-closes after clicking a link.

## Dashboard Layout

### 1. Hero Bar (Full Width, Above Portal)

Compact gradient bar:
- Welcome message with affiliate's first name
- Status badge (Active/Pending/etc.)
- Affiliate type label
- Referral code (monospace, pill-styled)
- Copy Referral Link button
- Log Out button

### 2. Portal Layout (Sidebar + Content)

**Desktop:** Two-column grid — 200px sticky sidebar + flexible content area.
**Tablet/Mobile:** Single column with collapsible menu toggle.

### 3. Overview Section (`#overview`)

- **Statistics Cards**: Available Balance (with Request Withdrawal CTA), Total Earnings, Total Sales, Withdrawn
- **Milestone Progress**: Sales count, progress bar, estimated next bonus
- **Recent Referral Activity**: Coming Soon placeholder

### 4. Referral Tools Section (`#referral-tools`)

- Referral Code input with Copy button
- Referral Link input with Copy button
- Share row: Facebook, X/Twitter, LinkedIn, Email

### 5. Financial Activity Section (`#financial-activity`)

- Milestone Bonus History table (conditional)
- Financial Activity tabs: Commissions + Withdrawals
- Admin Fee Status (conditional)

### 6. Achievements Section (`#achievements`)

- Milestone count + label
- Achievement progress bar
- Journey step checklist
- Next step hint

### 7. Commission Rates Section (`#commission-rates`)

- Rate card table by product type

### 8. Profile Settings Section (`#profile-settings`)

- Wise Payment Email form

## Responsive Behavior

| Breakpoint | Sidebar | Cards | Layout |
|---|---|---|---|
| >900px (Desktop) | Sticky sidebar, always visible | 4 columns | Two-column portal |
| 768-900px (Tablet) | Collapsible menu | 2x2 grid | Single column |
| <480px (Mobile) | Collapsible menu | 1 column | Single column |

### Mobile Navigation

On tablet and mobile, the sidebar is replaced by:
- A full-width "Affiliate Portal Menu" toggle button
- Clicking it reveals/hides the nav menu
- Clicking a nav link closes the menu and smooth-scrolls to the section
- Uses `aria-expanded` and `aria-controls` for accessibility

## Anchor Structure

Each major section uses a `<section>` element with a unique `id`:

```html
<section id="overview" class="konx-portal-section">
<section id="referral-tools" class="konx-portal-section">
<section id="financial-activity" class="konx-portal-section">
<section id="achievements" class="konx-portal-section">
<section id="commission-rates" class="konx-portal-section">
<section id="profile-settings" class="konx-portal-section">
```

All sections have `scroll-margin-top: 20px` to prevent content from being hidden behind fixed headers.

## Future Section Strategy

The navigation structure supports easy addition of new sections. To add a future section:

1. Add a `<li>` to `.konx-portal-menu` with the new anchor
2. Add a `<section id="..." class="konx-portal-section">` to `.konx-portal-content`
3. The IntersectionObserver automatically picks up new sections

Planned future sections:
- Recent Referral Activity (replace placeholder)
- Team Statistics
- Withdrawal History (dedicated section)
- Documents
- Notifications

## Permissions

| User State | What They See |
|---|---|
| Not logged in | Login prompt with link |
| Logged in, not an affiliate | Message to contact admin |
| Logged in affiliate | Full portal dashboard |

## Security

| Check | Implementation |
|---|---|
| Login required | `is_user_logged_in()` before rendering |
| Affiliate required | `get_affiliate_by_user()` check |
| Data isolation | All queries scoped to affiliate_id |
| Withdrawal nonce | Per-affiliate: `konx_withdrawal_request_{affiliate_id}` |
| Input sanitization | `sanitize_text_field()`, `sanitize_email()`, `sanitize_textarea_field()` |
| Output escaping | `esc_html()`, `esc_attr()`, `esc_url()`, `esc_js()` |
| CSS scoped | All styles under `.konx-dashboard` to avoid theme conflicts |

## CSS

Stylesheet: `assets/css/konx-dashboard.css`

- Loaded only on pages containing the shortcode (`has_shortcode()`)
- Also loaded on My Account affiliate-dashboard endpoint
- Scoped under `.konx-dashboard` wrapper
- Portal layout uses CSS Grid
- Responsive at 900px, 768px, 480px breakpoints
- Sidebar uses `position: sticky` (desktop only)
- Works inside Elementor containers

## Testing Checklist

### Portal Navigation

- [ ] Sidebar visible on desktop (>900px)
- [ ] Sidebar sticky while scrolling
- [ ] Active section highlighted as user scrolls
- [ ] Smooth scroll on nav link click
- [ ] Mobile toggle button visible on tablet/mobile
- [ ] Mobile nav opens/closes
- [ ] Mobile nav closes after link click
- [ ] Keyboard navigation works (Tab, Enter)
- [ ] `aria-expanded` toggles correctly

### Section Anchors

- [ ] Direct URL with #anchor scrolls to section
- [ ] All 6 anchors resolve correctly
- [ ] `scroll-margin-top` prevents content cutoff

### Responsive

- [ ] Desktop: sidebar + content side by side
- [ ] Tablet: toggle menu, single column
- [ ] Mobile: toggle menu, single column, cards stack
- [ ] No horizontal overflow at any breakpoint

### Content Integrity

- [ ] All existing calculations unchanged
- [ ] All data values display correctly
- [ ] Commission table shows recent 10
- [ ] Withdrawal form works
- [ ] Profile form works
- [ ] Tab switching works
- [ ] Copy buttons work
