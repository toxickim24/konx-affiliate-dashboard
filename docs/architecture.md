# KonX Affiliate Dashboard — Architecture

## 1. Plugin Purpose

KonX Affiliate Dashboard is a fully custom WordPress/WooCommerce plugin for konx.world that manages affiliate registration, referral tracking, tiered commission calculation, wallet balances, admin fee enforcement, milestone bonuses, withdrawal requests, and reporting. It replaces any dependency on third-party affiliate plugins (such as Coupon Affiliates for WooCommerce) with a purpose-built system tailored to KonX business rules.

## 2. Scope and Non-Scope

### In Scope

- Affiliate registration (self-registration with referral link or admin-assigned)
- Five affiliate types: Business Affiliate, Referral Affiliate, Team Agent, Marketing Agent, Sales Agent
- Referral attribution via unique referral codes and tracking cookies
- One-time commission on pack purchases (Starter Pack, Pro Pack, eCard Pack)
- Recurring commission (10%) on monthly subscriptions and eCard renewals via YITH WooCommerce Subscription
- Wallet ledger for each affiliate (credits, debits, running balance)
- Admin fee enforcement — unpaid admin fees block commission earnings
- 100-sale milestone bonus (repeating)
- Withdrawal request and manual payout (via Wise, marked complete by admin)
- Admin panel for managing affiliates, commissions, withdrawals, and settings
- Frontend affiliate dashboard via shortcodes (compatible with Elementor)
- Reporting for admin and affiliates

### Out of Scope

- app.konx.world integration (separate system, not part of this phase)
- Automatic bank transfers or payment gateway payouts (admin pays manually via Wise)
- Product creation or WooCommerce store setup (products and gateways already exist)
- Elementor widget development (shortcodes are used inside Elementor pages)
- Multi-currency support
- REST API for external consumers (may be added in a future phase)

## 3. Main Modules

| Module | Responsibility |
|---|---|
| **Core** | Plugin bootstrap, dependency checks, constants, autoloading |
| **Affiliate** | Registration, profile management, affiliate type assignment |
| **Referral** | Referral link generation, cookie tracking, attribution to orders |
| **Commission** | Commission calculation engine (one-time and recurring) |
| **Wallet** | Ledger entries, balance tracking, credit/debit operations |
| **Admin Fee** | Fee tracking, enforcement (block commissions when unpaid) |
| **Milestone** | 100-sale milestone detection and bonus crediting |
| **Withdrawal** | Affiliate withdrawal requests, admin approval/completion |
| **Admin** | Admin menu pages, affiliate management, settings, reports |
| **Frontend** | Shortcodes for affiliate dashboard, registration, reports |
| **Notifications** | Email notifications for key events |
| **Install** | Database table creation, default options, upgrade routines |

## 4. File and Class Architecture

```
konx-affiliate-dashboard/
├── konx-affiliate-dashboard.php          # Bootstrap, constants, dependency check
├── uninstall.php                         # Cleanup on uninstall
├── readme.txt                            # WordPress plugin readme
│
├── includes/
│   ├── class-konx-autoloader.php         # PSR-4 style autoloader
│   ├── class-konx-install.php            # Activation: tables, options, roles
│   ├── class-konx-affiliate.php          # Affiliate CRUD and type management
│   ├── class-konx-referral.php           # Referral link generation and cookie tracking
│   ├── class-konx-commission.php         # Commission calculation engine
│   ├── class-konx-wallet.php             # Wallet ledger operations
│   ├── class-konx-admin-fee.php          # Admin fee tracking and enforcement
│   ├── class-konx-milestone.php          # Milestone detection and bonus
│   ├── class-konx-withdrawal.php         # Withdrawal request lifecycle
│   └── class-konx-notifications.php      # Email notifications
│
├── admin/
│   ├── class-konx-admin.php              # Admin menu registration, enqueue
│   ├── class-konx-admin-affiliates.php   # Affiliate list/edit screens
│   ├── class-konx-admin-commissions.php  # Commission report screens
│   ├── class-konx-admin-withdrawals.php  # Withdrawal management screens
│   ├── class-konx-admin-settings.php     # Plugin settings page
│   └── views/
│       ├── affiliates-list.php
│       ├── affiliate-edit.php
│       ├── commissions-list.php
│       ├── withdrawals-list.php
│       ├── dashboard.php
│       └── settings.php
│
├── public/
│   ├── class-konx-public.php             # Public enqueue, shortcode registration
│   ├── class-konx-shortcodes.php         # Shortcode definitions
│   └── views/
│       ├── dashboard.php
│       ├── registration.php
│       ├── referral-link.php
│       ├── commissions.php
│       ├── wallet.php
│       ├── withdrawals.php
│       └── milestone.php
│
├── templates/
│   └── emails/
│       ├── commission-earned.php
│       ├── withdrawal-approved.php
│       ├── withdrawal-completed.php
│       ├── admin-fee-reminder.php
│       └── milestone-bonus.php
│
├── assets/
│   ├── css/
│   │   ├── admin.css
│   │   └── public.css
│   └── js/
│       ├── admin.js
│       └── public.js
│
├── languages/
│   └── konx-affiliate-dashboard.pot
│
└── docs/
    ├── architecture.md
    ├── database-schema.md
    ├── development-plan.md
    └── changelog.md
```

### Class Naming Convention

All classes use the `Konx_` prefix. File names follow WordPress convention: `class-konx-{module}.php`. Classes are loaded via a custom autoloader registered in the bootstrap file.

## 5. WordPress Roles and Capabilities

### Custom Role

| Role | Slug | Description |
|---|---|---|
| KonX Affiliate | `konx_affiliate` | Assigned to users who register as affiliates. Inherits `subscriber` capabilities. |

### Custom Capabilities

| Capability | Assigned To | Purpose |
|---|---|---|
| `konx_view_dashboard` | `konx_affiliate` | Access the frontend affiliate dashboard |
| `konx_request_withdrawal` | `konx_affiliate` | Submit withdrawal requests |
| `konx_manage_affiliates` | `administrator` | Full admin access to affiliate management |
| `konx_manage_commissions` | `administrator` | View and adjust commissions |
| `konx_manage_withdrawals` | `administrator` | Approve and complete withdrawals |
| `konx_manage_settings` | `administrator` | Edit plugin settings |

### Affiliate Types (Stored as User Meta)

Affiliate type is not a WordPress role — it is stored as user meta (`konx_affiliate_type`) on users who have the `konx_affiliate` role. This allows a single role with type-specific commission logic.

| Affiliate Type | Meta Value |
|---|---|
| Business Affiliate | `business` |
| Referral Affiliate | `referral` |
| Team Agent | `team_agent` |
| Marketing Agent | `marketing_agent` |
| Sales Agent | `sales_agent` |

Affiliate type changes are admin-only. Affiliates cannot change their own type.

## 6. WooCommerce Integration Plan

### Hook into Order Lifecycle

| WooCommerce Hook | Plugin Action |
|---|---|
| `woocommerce_order_status_completed` | Calculate and credit one-time commissions |
| `woocommerce_order_status_completed` | Increment affiliate sale count for milestone tracking |
| `woocommerce_thankyou` | Clear referral tracking cookie after successful purchase |
| `woocommerce_before_cart` | (Optional) Display referral attribution notice |

### Product Identification

Products are identified by their WooCommerce product ID. The plugin settings page stores a mapping of product IDs to commission-eligible product types:

| Product Type (internal) | WooCommerce Product | Price |
|---|---|---|
| `starter_pack` | KonX Starter Pack | $100 |
| `pro_pack` | KonX Pro Pack | $200 |
| `ecard_pack` | KonX eCard Pack | $500 |
| `ecard_single` | KonX eCard | $55 |
| `basic_pro_conference` | Basic Pro Conference Room | $25/month |
| `enterprise_conference` | Enterprise Conference Room | $81/month or $809/year |
| `business_conference` | Business Conference Room | $28/month or $289/year |
| `corporate_conference` | Corporate Conference Room | $51/month or $509/year |

Product-to-type mapping is stored in plugin settings (wp_options) so that if product IDs change, the admin can update the mapping without code changes.

### Commission Base

Commissions are calculated from the **full product price before gateway fees and taxes**. The plugin reads the product price from the WooCommerce order line item, not the order total after deductions.

## 7. YITH WooCommerce Subscription Integration Plan

YITH WooCommerce Subscription manages recurring billing for conference room subscriptions and eCard renewals.

### Hook into Subscription Renewals

| YITH Hook | Plugin Action |
|---|---|
| `ywsbs_renew_order_payed` | Calculate and credit recurring commission (10%) |
| `ywsbs_subscription_status_changed` | Track subscription status for reporting |

### Recurring Commission Rules

- All affiliate types earn a flat **10% recurring commission** on subscription renewals and eCard renewals.
- The commission is calculated from the renewal order amount (full product price).
- Recurring commissions follow the same wallet crediting flow as one-time commissions.
- Admin fee enforcement applies — if the affiliate has unpaid admin fees, recurring commissions are blocked.

### Attribution Persistence

When a customer is initially referred by an affiliate and purchases a subscription, the affiliate is stored as the referrer on the subscription record (via order meta or a custom table). All future renewals of that subscription credit the same affiliate. Attribution does not expire for subscriptions.

## 8. Referral Attribution Flow

```
1. Affiliate shares referral URL:
   https://konx.world/?ref=ABC123

2. Visitor clicks the link:
   - Plugin reads `ref` query parameter
   - Sets a first-party cookie: konx_ref=ABC123 (30-day expiry)
   - Stores referral code in PHP session as fallback

3. Visitor browses the site:
   - Cookie persists across page views
   - No further action until checkout

4. Visitor completes a WooCommerce purchase:
   - On order creation, plugin reads the cookie/session
   - Looks up affiliate by referral code
   - Stores affiliate ID as order meta: _konx_referrer_id
   - Clears the cookie after attribution

5. Order reaches "completed" status:
   - Commission engine reads _konx_referrer_id from order meta
   - Calculates commission based on affiliate type and product
   - Credits wallet
```

### Referral Code Format

Each affiliate receives a unique alphanumeric referral code (8 characters, uppercase) generated at registration. The code is stored in the `konx_affiliates` table and in user meta for quick lookup. Affiliates can view (but not change) their referral code on the frontend dashboard.

### Attribution Rules

- **First-click attribution**: The first referral link clicked sets the cookie. Subsequent clicks from different affiliates overwrite the cookie (last-click wins in practice, but the cookie is set on first visit within the 30-day window).
- **Cookie duration**: 30 days (configurable via admin settings).
- **Self-referral prevention**: If the logged-in user's affiliate ID matches the referral code, the cookie is not set.

## 9. Commission Calculation Flow

### One-Time Commission Rates

| Affiliate Type | Starter Pack ($100) | Pro Pack ($200) | eCard Pack ($500) |
|---|---|---|---|
| Business Affiliate | 40% = $40 | 40% = $80 | 40% = $200 |
| Referral Affiliate | 20% = $20 | 20% = $40 | 20% = $100 |
| Team Agent | 40% = $40 | 40% = $80 | 40% = $200 |
| Marketing Agent | 40% = $40 | 20% = $40 | 20% = $100 |
| Sales Agent | 20% = $20 | 20% = $40 | 20% = $100 |

### Recurring Commission Rate

All affiliate types: **10%** on monthly subscriptions and eCard renewals.

### Calculation Steps

```
1. Order status changes to "completed"
2. Read _konx_referrer_id from order meta
3. If no referrer, skip (organic sale)
4. Look up affiliate record and type
5. Check admin fee status:
   - If admin fee is unpaid → log as "blocked", do NOT credit wallet
   - If admin fee is paid → continue
6. For each line item in the order:
   a. Determine product type from product-to-type mapping
   b. Look up commission rate based on affiliate type + product type
   c. Calculate: commission = product_price × rate
   d. Insert commission record (status: "approved")
   e. Credit affiliate wallet
7. Increment affiliate's completed sale count
8. Check milestone (see Section 13)
```

### Commission Statuses

| Status | Meaning |
|---|---|
| `pending` | Order not yet completed |
| `approved` | Order completed, commission credited to wallet |
| `blocked` | Commission earned but not credited due to unpaid admin fee |
| `reversed` | Order refunded, commission debited from wallet |

## 10. Wallet Ledger Flow

The wallet is a ledger-based system. Every credit and debit is a row in the ledger table. The affiliate's balance is the sum of all ledger entries.

### Ledger Entry Types

| Type | Direction | Trigger |
|---|---|---|
| `commission` | Credit (+) | Order completed, commission approved |
| `recurring_commission` | Credit (+) | Subscription renewal paid |
| `milestone_bonus` | Credit (+) | 100-sale milestone reached |
| `withdrawal` | Debit (−) | Withdrawal request completed |
| `reversal` | Debit (−) | Order refunded, commission reversed |
| `adjustment` | Credit/Debit | Manual admin adjustment |

### Balance Calculation

```
balance = SUM(amount) WHERE affiliate_id = X
```

Credits are stored as positive values. Debits are stored as negative values. No separate "balance" column is maintained — it is always derived from the ledger to ensure consistency.

### Ledger Integrity

- Every ledger entry references its source (commission ID, withdrawal ID, or admin note).
- Ledger entries are append-only in normal operation. Corrections are made by inserting a new `adjustment` or `reversal` entry, never by updating or deleting existing rows.
- A database transaction wraps commission crediting to prevent partial writes.

## 11. Withdrawal Request Flow

```
1. Affiliate views wallet balance on frontend dashboard
2. Affiliate submits withdrawal request:
   - Enters requested amount
   - Amount must be ≤ available balance
   - Amount must meet minimum withdrawal threshold (configurable, e.g., $50)
3. Withdrawal record created with status "pending"
4. Admin receives notification (email or admin dashboard alert)
5. Admin reviews withdrawal request in admin panel
6. Admin pays the affiliate manually via Wise
7. Admin marks withdrawal as "completed" in the plugin
8. Plugin inserts a debit ledger entry for the withdrawal amount
9. Affiliate receives email notification of completed withdrawal
```

### Withdrawal Statuses

| Status | Meaning |
|---|---|
| `pending` | Submitted by affiliate, awaiting admin action |
| `approved` | Admin has approved, payment in progress |
| `completed` | Admin has paid via Wise and marked complete |
| `rejected` | Admin rejected the request (with reason) |

### Withdrawal Rules

- Affiliates can only have **one pending withdrawal** at a time.
- The wallet balance is not reduced until the withdrawal is marked `completed`.
- Rejected withdrawals include an admin-provided reason visible to the affiliate.
- Minimum withdrawal amount is configurable in plugin settings.

## 12. Admin Fee Enforcement Flow

Admin fees are periodic fees that affiliates must pay to maintain their active status. When an admin fee is unpaid, the affiliate's commission earnings are blocked.

### Enforcement Logic

```
1. Admin sets admin fee amount and due date in plugin settings
   (or per-affiliate if needed)
2. System checks affiliate's admin fee status before crediting commissions
3. If admin fee is marked "unpaid":
   - Commission is calculated but recorded with status "blocked"
   - Commission is NOT credited to wallet
   - Affiliate sees a notice on their dashboard
4. When admin marks the fee as "paid":
   - All "blocked" commissions for that affiliate are re-processed
   - Blocked commissions change to "approved" and are credited to wallet
5. Admin can send reminder emails for unpaid fees
```

### Admin Fee Tracking

| Field | Description |
|---|---|
| `affiliate_id` | The affiliate |
| `fee_amount` | Amount due |
| `due_date` | When the fee is due |
| `status` | `paid`, `unpaid`, `overdue` |
| `paid_date` | When payment was recorded |
| `notes` | Admin notes |

## 13. Milestone Bonus Flow

The milestone bonus rewards affiliates for reaching every 100 paid completed sales.

### Rules

- The milestone triggers at every 100th completed sale (100, 200, 300, ...).
- The bonus amount equals the **total commission earned from that 100-sale block** (sales 1–100, 101–200, etc.).
- The bonus is credited to the affiliate's wallet as a `milestone_bonus` ledger entry.
- Milestone bonuses are subject to admin fee enforcement (if admin fee is unpaid, the bonus is blocked).

### Detection Logic

```
1. After each commission credit, read affiliate's total completed sale count
2. If sale_count % 100 === 0:
   a. Determine the sale block: start = sale_count - 99, end = sale_count
   b. Sum all approved commissions for orders in that block
   c. Credit milestone bonus to wallet
   d. Insert milestone record for audit
   e. Send milestone notification email
```

### Milestone Record

| Field | Description |
|---|---|
| `affiliate_id` | The affiliate |
| `milestone_number` | Which milestone (1, 2, 3, ...) |
| `sale_count` | The sale count that triggered it (100, 200, ...) |
| `bonus_amount` | Total commission from the 100-sale block |
| `created_at` | When the milestone was reached |

## 14. Frontend Shortcode Architecture

All frontend views are rendered via WordPress shortcodes. This allows the site admin to place them on any Elementor page.

| Shortcode | Description | Access |
|---|---|---|
| `[konx_affiliate_dashboard]` | Main dashboard: summary stats, quick links | Logged-in affiliates |
| `[konx_affiliate_registration]` | Registration form for new affiliates | Logged-out or non-affiliate users |
| `[konx_affiliate_referral_link]` | Display referral link with copy button | Logged-in affiliates |
| `[konx_affiliate_commissions]` | Commission history table with filters | Logged-in affiliates |
| `[konx_affiliate_wallet]` | Wallet balance and ledger history | Logged-in affiliates |
| `[konx_affiliate_withdrawals]` | Withdrawal history and request form | Logged-in affiliates |
| `[konx_affiliate_milestones]` | Milestone progress and history | Logged-in affiliates |

### Shortcode Behavior

- All shortcodes check `current_user_can('konx_view_dashboard')` before rendering.
- If the user is not logged in, a login prompt is shown.
- If the user is logged in but not an affiliate, a message is shown (or the registration form).
- Shortcodes load view files from `public/views/` which can be overridden by copying to the active theme under `konx-affiliate-dashboard/`.
- Assets (CSS/JS) are enqueued only on pages that contain the shortcodes.

## 15. Admin Panel Architecture

### Menu Structure

```
KonX Affiliates (top-level menu)
├── Dashboard          — Overview stats, recent activity
├── Affiliates         — List, search, edit affiliates and their types
├── Commissions        — Commission log, filters by affiliate/date/status
├── Withdrawals        — Pending, approved, completed, rejected requests
├── Admin Fees         — Fee status per affiliate, mark paid/unpaid
├── Milestones         — Milestone history per affiliate
└── Settings           — Product mapping, cookie duration, minimum withdrawal, fee amounts
```

### Admin List Tables

Affiliate, commission, withdrawal, and milestone lists use `WP_List_Table` for consistency with WordPress admin UI patterns. This provides built-in pagination, sorting, bulk actions, and search.

### Settings Storage

Plugin settings are stored in `wp_options` under a single serialized option key: `konx_affiliate_settings`. Settings include:

- Product ID to product type mapping
- Commission rates per affiliate type per product type
- Recurring commission rate
- Referral cookie duration (days)
- Minimum withdrawal amount
- Admin fee amount and schedule
- Email notification toggles

## 16. Security Plan

### Direct File Access

All PHP files begin with:

```php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
```

### Data Validation and Sanitization

- All user input is sanitized using WordPress functions (`sanitize_text_field`, `absint`, `sanitize_email`, etc.).
- All database queries use `$wpdb->prepare()` for parameterized queries — no raw SQL interpolation.
- All output is escaped using `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post` as appropriate.

### Nonce Verification

- All form submissions and AJAX requests are protected with WordPress nonces.
- Admin actions (type change, withdrawal approval, fee marking) verify nonces and capabilities.

### Capability Checks

- Every admin page checks `current_user_can('konx_manage_affiliates')` (or the relevant capability) before rendering.
- Every frontend shortcode checks `current_user_can('konx_view_dashboard')`.
- Affiliates can only view and act on their own data.

### Cookie Security

- The referral tracking cookie is a first-party, HTTP-only cookie.
- The cookie value is a referral code (not a user ID or sensitive data).
- Cookie is set with `SameSite=Lax` and `Secure` flag (if on HTTPS).

### Rate Limiting

- Withdrawal requests are limited to one pending request at a time per affiliate.
- Registration is protected by WordPress nonce and optional honeypot field.

### Audit Trail

- All wallet ledger entries are append-only with timestamps and source references.
- Admin actions (type changes, fee status changes, withdrawal approvals) are logged with the admin user ID and timestamp.

## 17. Data Migration Considerations

### Existing Users

Users from Powerof10 already exist in the konx.world WordPress installation. The plugin must account for:

- **Existing customers** may become affiliates. The plugin assigns the `konx_affiliate` role as an additional role (not replacing their existing role) when they register as an affiliate.
- **Existing orders** placed before the plugin is activated will not have referral attribution. The plugin only tracks commissions on orders placed after activation.
- **User meta conflicts**: All plugin user meta keys are prefixed with `konx_` to avoid conflicts with existing meta from other plugins or themes.

### Activation Routine

On activation, the plugin:

1. Creates custom database tables (if they do not exist).
2. Registers the `konx_affiliate` role and custom capabilities.
3. Adds capabilities to the `administrator` role.
4. Sets default plugin options (if not already set).

### Deactivation vs. Uninstall

- **Deactivation**: Removes the `konx_affiliate` role and custom capabilities. Does not delete data.
- **Uninstall** (plugin deletion): Drops custom database tables, deletes plugin options, and removes custom user meta. This is handled in `uninstall.php` and will only be implemented when data storage is built.

## 18. Future Scalability Considerations

### Performance

- Wallet balance queries will use indexed `affiliate_id` columns. If ledger tables grow large, a cached balance column can be added as an optimization (updated on each ledger write).
- Commission calculations happen synchronously on order completion. If order volume grows significantly, these can be moved to a background queue using `wp_schedule_single_event` or Action Scheduler (bundled with WooCommerce).
- Admin list tables will use server-side pagination and will not load all records at once.

### Extensibility

- Commission rates are stored in settings, not hardcoded. New affiliate types or product types can be added via the admin settings page.
- The plugin fires custom WordPress action hooks at key points (commission credited, withdrawal requested, milestone reached) so that other plugins or custom code can extend behavior.
- Email templates are stored in `templates/emails/` and can be overridden by the active theme.

### Potential Future Additions

- REST API endpoints for headless or mobile integrations.
- Integration with app.konx.world (out of scope for this phase).
- Automated payout via payment APIs (currently manual via Wise).
- Multi-level affiliate trees (not in current requirements).
- Affiliate performance tiers with automatic type promotion.

## 19. Development Phases

### Phase 1 — Foundation (Current)

- Plugin bootstrap and file structure
- Architecture documentation
- Database schema design

### Phase 2 — Data Layer

- Custom database tables (affiliates, commissions, wallet ledger, withdrawals, admin fees, milestones)
- Activation/deactivation/uninstall routines
- Custom role and capabilities

### Phase 3 — Core Engine

- Affiliate registration and profile management
- Referral link generation and cookie tracking
- Referral attribution on WooCommerce orders

### Phase 4 — Commission System

- Commission calculation engine (one-time)
- YITH subscription integration (recurring commissions)
- Wallet ledger operations
- Admin fee enforcement

### Phase 5 — Milestone and Withdrawal

- 100-sale milestone detection and bonus
- Withdrawal request flow
- Admin withdrawal management

### Phase 6 — Admin Panel

- Admin menu and dashboard
- Affiliate list and edit screens
- Commission and withdrawal management screens
- Settings page with product mapping and rate configuration

### Phase 7 — Frontend Dashboard

- Shortcode registration and rendering
- Affiliate dashboard, wallet, commissions, withdrawals views
- Referral link display with copy functionality
- Milestone progress display

### Phase 8 — Notifications and Polish

- Email notifications for key events
- CSS styling for admin and frontend
- Translation-ready strings and POT file
- Testing and bug fixes

### Phase 9 — Launch

- Final QA on konx.world staging
- Data migration verification (existing users)
- Production deployment
- Monitoring and post-launch fixes
