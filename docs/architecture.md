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
- Structured audit log for admin and system actions

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
| **Referral** | Referral link generation, cookie tracking, localStorage fallback, attribution to orders |
| **Commission** | Commission calculation engine (one-time and recurring), sale sequencing |
| **Wallet** | Ledger entries, balance tracking, credit/debit operations |
| **Admin Fee** | Fee tracking, enforcement (block commissions when unpaid) |
| **Milestone** | 100-sale milestone detection and bonus crediting |
| **Withdrawal** | Affiliate withdrawal requests, balance re-validation, admin approval/completion |
| **Admin** | Admin menu pages, affiliate management, settings, reports |
| **Frontend** | Shortcodes for affiliate dashboard, registration, reports |
| **Notifications** | Email notifications for key events |
| **Audit Log** | Structured logging of admin actions and system events |
| **Install** | Database table creation, default options, upgrade routines |

## 4. File and Class Architecture

```
konx-affiliate-dashboard/
├── konx-affiliate-dashboard.php          # Bootstrap, constants, dependency check, HPOS declaration
├── uninstall.php                         # Cleanup on uninstall
├── readme.txt                            # WordPress plugin readme
│
├── includes/
│   ├── class-konx-autoloader.php         # PSR-4 style autoloader
│   ├── class-konx-install.php            # Activation: tables, options, roles
│   ├── class-konx-affiliate.php          # Affiliate CRUD and type management
│   ├── class-konx-referral.php           # Referral link generation and cookie/localStorage tracking
│   ├── class-konx-commission.php         # Commission calculation engine
│   ├── class-konx-wallet.php             # Wallet ledger operations
│   ├── class-konx-admin-fee.php          # Admin fee tracking and enforcement
│   ├── class-konx-milestone.php          # Milestone detection and bonus
│   ├── class-konx-withdrawal.php         # Withdrawal request lifecycle
│   ├── class-konx-audit-log.php          # Structured audit log
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

### Affiliate Type Sync Convention

Affiliate type is stored in two places: `wp_konx_affiliates.affiliate_type` and `wp_usermeta.konx_affiliate_type`. All type changes must go through `Konx_Affiliate::update_type( $affiliate_id, $new_type )`, which updates both locations atomically. No other code path may modify affiliate type directly.

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

### HPOS Compatibility

The plugin declares full compatibility with WooCommerce High-Performance Order Storage (HPOS). The bootstrap file registers compatibility on the `before_woocommerce_init` action:

```php
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables', __FILE__, true
        );
    }
});
```

All order data access uses WooCommerce CRUD methods (`$order->get_id()`, `$order->get_meta()`, `$order->update_meta_data()`) rather than direct `wp_postmeta` queries. This ensures compatibility with both classic post-based storage and HPOS.

### Multisite WooCommerce Check

The WooCommerce dependency check supports both single-site and multisite installations:

```php
function konx_affiliate_is_woocommerce_active() {
    $active_plugins = apply_filters( 'active_plugins', get_option( 'active_plugins' ) );
    if ( in_array( 'woocommerce/woocommerce.php', $active_plugins, true ) ) {
        return true;
    }
    if ( is_multisite() ) {
        $network_plugins = get_site_option( 'active_sitewide_plugins' );
        if ( isset( $network_plugins['woocommerce/woocommerce.php'] ) ) {
            return true;
        }
    }
    return false;
}
```

### Hook into Order Lifecycle

| WooCommerce Hook | Plugin Action |
|---|---|
| `woocommerce_order_status_completed` | Calculate and credit one-time commissions (idempotent) |
| `woocommerce_order_status_completed` | Increment affiliate sale sequence for milestone tracking |
| `woocommerce_order_status_refunded` | Reverse commissions for fully refunded orders |
| `woocommerce_order_partially_refunded` | Reverse commissions for specific refunded line items |
| `woocommerce_thankyou` | Clear referral tracking cookie after successful purchase |
| `woocommerce_before_cart` | (Optional) Display referral attribution notice |

### Idempotent Commission Processing

The `woocommerce_order_status_completed` hook can fire multiple times for the same order (admin manually re-triggers, status toggled away and back). The commission engine must be **idempotent**:

1. Before inserting commission records, check if commissions already exist for the `(order_id, order_item_id)` pair.
2. If records exist, skip silently — do not throw an error or create duplicates.
3. The `UNIQUE` index on `wp_konx_commissions(order_id, order_item_id)` serves as a database-level safety net.
4. Use `INSERT IGNORE` or check-before-insert pattern in the implementation.

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

Product-to-type mapping is stored in the `wp_konx_product_map` table so that if product IDs change, the admin can update the mapping without code changes.

### Product Variation Handling

WooCommerce variable products have a parent product ID and separate variation IDs. When a customer purchases a variation, `$item->get_product_id()` returns the parent ID, and `$item->get_variation_id()` returns the variation ID (or 0 for simple products).

The product map must use **variation IDs** for variable products, not the parent product ID. The commission engine lookup checks both:

```
1. Look up $item->get_variation_id() in wp_konx_product_map
2. If not found and variation_id > 0, look up $item->get_product_id() (parent)
3. If not found, skip — no commission for this product
```

For products with monthly/yearly variants (e.g., Enterprise Conference Room at $81/month and $809/year), each variant is a separate row in the product map with the same `product_type` but different `product_id`.

### Commission Base and Coupon Handling

Commissions are calculated from the **full product price before discounts, coupons, gateway fees, and taxes**.

The commission engine reads the price via `$item->get_subtotal()` (line total before discounts and before taxes), **not** `$item->get_total()` (which is after discounts, before taxes).

| WooCommerce Method | Returns | Used For |
|---|---|---|
| `$item->get_subtotal()` | Line total before discounts, before tax | **Commission base (use this)** |
| `$item->get_total()` | Line total after discounts, before tax | Not used for commissions |
| `$item->get_subtotal_tax()` | Tax on subtotal | Not used |

**Rationale:** The affiliate drove the sale regardless of whether the customer used a coupon. Commission is based on the product's value, not the amount the customer paid after discounts. This also prevents gaming via affiliate + coupon stacking to reduce the sale price while preserving commission.

**Example:** Customer buys Pro Pack ($200) with a 20% coupon.
- `$item->get_subtotal()` = $200 (commission base)
- `$item->get_total()` = $160 (not used)
- Business Affiliate commission: $200 × 40% = $80

## 7. YITH WooCommerce Subscription Integration Plan

### YITH Dependency Handling

YITH WooCommerce Subscription is a **soft dependency**. The plugin functions without YITH (one-time commissions work normally), but recurring commissions require YITH to be active.

**On `plugins_loaded`:**
1. Check if YITH WooCommerce Subscription is active (same pattern as WooCommerce check).
2. If active, register subscription-related hooks.
3. If not active, show an admin notice: "KonX Affiliate Dashboard: Recurring commissions require YITH WooCommerce Subscription to be installed and activated. One-time commissions are unaffected."
4. Store YITH status in a runtime flag so other modules can check `konx_is_yith_active()`.

**On YITH deactivation:** Subscription hooks silently stop firing. No data loss. Existing recurring commission records remain. When YITH is reactivated, hooks resume for future renewals.

### Hook into Subscription Renewals

| YITH Hook | Plugin Action |
|---|---|
| `ywsbs_renew_order_payed` | Calculate and credit recurring commission (10%) |
| `ywsbs_subscription_status_changed` | Track subscription status for reporting |

**Note:** The hook name `ywsbs_renew_order_payed` uses the YITH spelling ("payed" not "paid"). This must be verified against the installed YITH version on konx.world during Phase 8 implementation. YITH may change hook names between major versions.

### Recurring Commission Rules

- All affiliate types earn a flat **10% recurring commission** on subscription renewals and eCard renewals.
- The commission is calculated from the renewal order amount (full product price via `get_subtotal()`).
- Recurring commissions follow the same wallet crediting flow as one-time commissions.
- Admin fee enforcement applies — if the affiliate has unpaid admin fees, recurring commissions are blocked.

### Attribution Persistence

When a customer is initially referred by an affiliate and purchases a subscription, the affiliate is stored as the referrer on the subscription record (via order meta). All future renewals of that subscription credit the same affiliate. Attribution does not expire for subscriptions.

The renewal attribution chain:
```
1. Renewal order created by YITH
2. Plugin reads the renewal order's subscription ID
3. Finds the original (parent) order for that subscription
4. Copies _konx_referrer_id from the original order to the renewal order
5. Creates a conversion record with is_subscription_renewal = 1
6. Credits recurring commission to the original affiliate
```

## 8. Referral Attribution Flow

```
1. Affiliate shares referral URL:
   https://konx.world/?ref=ABC123

2. Visitor clicks the link:
   - Plugin reads `ref` query parameter
   - Sets a first-party cookie: konx_ref=ABC123 (30-day expiry)
   - JavaScript stores referral code in localStorage as fallback
   - Logs click to wp_konx_referral_clicks

3. Visitor browses the site:
   - Cookie persists across page views
   - localStorage persists independently of cookies
   - No further action until checkout

4. Visitor reaches the checkout page:
   - Plugin reads referral code from cookie
   - If cookie is absent, JavaScript reads from localStorage
     and injects the code into a hidden form field
   - Looks up affiliate by referral code
   - Validates affiliate is active

5. Visitor completes a WooCommerce purchase:
   - On order creation, plugin reads the referral code (cookie or hidden field)
   - Stores affiliate ID as order meta: _konx_referrer_id
   - Creates conversion record in wp_konx_referral_conversions
   - Clears the cookie and localStorage after attribution

6. Order reaches "completed" status:
   - Commission engine reads _konx_referrer_id from order meta
   - Calculates commission based on affiliate type and product
   - Credits wallet
```

### Referral Code Format

Each affiliate receives a unique alphanumeric referral code (8 characters, uppercase) generated at registration. The code is stored in the `konx_affiliates` table and in user meta for quick lookup. Affiliates can view (but not change) their referral code on the frontend dashboard.

### Attribution Rules

- **Last-click attribution**: The most recent referral link click sets/overwrites the cookie and localStorage value.
- **Cookie duration**: 30 days (configurable via admin settings).
- **Self-referral prevention**: If the logged-in user's affiliate ID matches the referral code, the cookie is not set and localStorage is not updated.

### localStorage Fallback

PHP sessions are unreliable in WordPress environments due to full-page caching plugins (WP Super Cache, W3 Total Cache) and CDNs (Cloudflare). The plugin uses `localStorage` as the fallback instead of PHP sessions:

1. **On referral landing page:** A small inline JavaScript writes the referral code to `localStorage.setItem('konx_ref', 'ABC123')`.
2. **On checkout page:** JavaScript reads `localStorage.getItem('konx_ref')` and populates a hidden input field in the checkout form.
3. **On order completion:** The server reads the hidden field value if the cookie is absent.
4. **On attribution:** JavaScript clears the localStorage entry via `localStorage.removeItem('konx_ref')`.

This approach survives full-page caching, CDN edge caching, and hosting environments that disable PHP sessions.

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

2. IDEMPOTENCY CHECK: Query wp_konx_commissions for existing records
   with this order_id. If commissions already exist, stop — this is
   a re-trigger. Do not create duplicates.

3. Read _konx_referrer_id from order meta
   If no referrer, skip (organic sale)

4. Look up affiliate record
   a. If affiliate status is NOT "active", skip entirely.
      Do not create commission records for inactive affiliates.
   b. Read affiliate type

5. Check admin fee status ONCE for the entire order:
   - Query wp_konx_admin_fees WHERE affiliate_id = X
     AND status IN ('unpaid', 'overdue')
   - Store result in a variable: $is_fee_blocked = true/false
   - Do NOT re-query inside the line item loop

6. For each line item in the order:
   a. Look up product in wp_konx_product_map:
      - First check variation_id (if > 0)
      - Then check product_id (parent)
      - If not found, skip (no commission for this product)
   b. Look up commission rate from wp_konx_commission_rules
      based on affiliate type + product type
   c. Calculate: commission = $item->get_subtotal() × rate
      (subtotal = full price before discounts/coupons/taxes)
   d. Assign the next sale_sequence number for this affiliate
   e. Insert commission record:
      - If $is_fee_blocked: status = "blocked", blocked_reason = "unpaid_admin_fee"
      - Else: status = "approved"
   f. If approved: credit affiliate wallet via ledger entry

7. Check milestone (see Section 13) using the new sale_sequence value
```

### Sale Sequencing

Each commission record receives a `sale_sequence` number that is unique per affiliate and monotonically increasing. This is assigned as:

```
sale_sequence = (SELECT COALESCE(MAX(sale_sequence), 0) + 1
                 FROM wp_konx_commissions
                 WHERE affiliate_id = X)
```

The sale sequence is assigned inside the same database transaction as the commission insert to prevent gaps or duplicates under concurrent orders.

**Purpose:** Sale sequencing provides a reliable mechanism for milestone bonus calculation. Instead of fragile `created_at` ordering, milestones can query `WHERE sale_sequence BETWEEN 1 AND 100` deterministically.

**Relationship to `completed_sales`:** The `completed_sales` counter on `wp_konx_affiliates` is a convenience denormalization of `MAX(sale_sequence)`. Both are updated in the same transaction. If they drift, `MAX(sale_sequence)` from the commissions table is the authoritative count.

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
| `withdrawal` | Debit (-) | Withdrawal request completed |
| `reversal` | Debit (-) | Order refunded, commission reversed |
| `adjustment` | Credit/Debit | Manual admin adjustment |

### Balance Calculation

```
balance = SUM(amount) WHERE affiliate_id = X
```

Credits are stored as positive values. Debits are stored as negative values. The authoritative balance is always derived from the ledger SUM.

### Cached Balance

A `cached_balance` column on `wp_konx_affiliates` stores the current balance for fast reads. It is updated atomically in the same database transaction as each ledger insert:

```sql
BEGIN TRANSACTION;
INSERT INTO wp_konx_wallet_ledger (...) VALUES (...);
UPDATE wp_konx_affiliates SET cached_balance = cached_balance + :amount WHERE id = :affiliate_id;
COMMIT;
```

The SUM query remains the authoritative source. The cached balance is used for:
- Display in the affiliate dashboard
- Quick balance checks in withdrawal validation
- Admin reports and list tables

A reconciliation function compares `cached_balance` against `SUM(wallet_ledger.amount)` per affiliate and corrects any drift.

### Ledger Integrity

- Every ledger entry references its source (commission ID, withdrawal ID, or admin note).
- Ledger entries are append-only in normal operation. Corrections are made by inserting a new `adjustment` or `reversal` entry, never by updating or deleting existing rows.
- A database transaction wraps commission crediting to prevent partial writes.
- Concurrent operations on the same affiliate's wallet are serialized by locking the affiliate row with `SELECT ... FOR UPDATE` before inserting the ledger entry.

## 11. Withdrawal Request Flow

```
1. Affiliate views wallet balance on frontend dashboard
2. Affiliate submits withdrawal request:
   - Enters requested amount
   - Amount must be <= available balance (cached_balance)
   - Amount must meet minimum withdrawal threshold (configurable, e.g., $50)
3. Withdrawal record created with status "pending"
4. Admin receives notification (email or admin dashboard alert)
5. Admin reviews withdrawal request in admin panel
6. Admin approves the withdrawal (status -> "approved")
7. Admin pays the affiliate manually via Wise
8. Admin marks withdrawal as "completed":
   a. BALANCE RE-VALIDATION: System checks current wallet balance
      - If balance >= withdrawal amount: proceed
      - If balance < withdrawal amount: block completion, show warning
        to admin with current balance. Admin can adjust the amount
        or reject the withdrawal.
   b. Insert a debit ledger entry for the withdrawal amount
   c. Update cached_balance on affiliate record
   d. Record Wise transaction reference
9. Affiliate receives email notification of completed withdrawal
```

### Balance Re-Validation

Between the time a withdrawal is requested and when the admin marks it as completed, the affiliate's balance can change due to:
- Commission reversals from refunded orders
- Admin adjustments
- Previously blocked commissions being released (increasing balance)

The completion action must re-validate the balance to prevent the wallet from going negative:

```
current_balance = SUM(amount) FROM wp_konx_wallet_ledger WHERE affiliate_id = X
if current_balance < withdrawal_amount:
    Block completion
    Show admin: "Cannot complete. Affiliate balance is $X, withdrawal is $Y."
    Options: adjust withdrawal amount, reject withdrawal
```

### Withdrawal Statuses

| Status | Meaning |
|---|---|
| `pending` | Submitted by affiliate, awaiting admin action |
| `approved` | Admin has approved, payment in progress |
| `completed` | Admin has paid via Wise and marked complete |
| `rejected` | Admin rejected the request (with reason) |

### Withdrawal Rules

- Affiliates can only have **one pending or approved withdrawal** at a time.
- The wallet balance is not reduced until the withdrawal is marked `completed`.
- Rejected withdrawals include an admin-provided reason visible to the affiliate.
- Minimum withdrawal amount is configurable in plugin settings.

## 12. Admin Fee Enforcement Flow

Admin fees are periodic fees that affiliates must pay to maintain their active status. When an admin fee is unpaid, the affiliate's commission earnings are blocked.

### Enforcement Logic

```
1. Admin sets admin fee amount and due date in plugin settings
   (or per-affiliate if needed)
2. System checks affiliate's admin fee status ONCE before processing
   an order's line items (not once per line item)
3. If admin fee is marked "unpaid" or "overdue":
   - All commissions for the order are recorded with status "blocked"
   - Commissions are NOT credited to wallet
   - Affiliate sees a notice on their dashboard
4. When admin marks the fee as "paid":
   - All "blocked" commissions for that affiliate are re-processed
   - Blocked commissions change to "approved" and are credited to wallet
   - Audit log entry records the unblocking action
5. Admin can send reminder emails for unpaid fees
```

### Admin Fee Tracking

| Field | Description |
|---|---|
| `affiliate_id` | The affiliate |
| `fee_amount` | Amount due |
| `due_date` | When the fee is due |
| `status` | `paid`, `unpaid`, `overdue`, `waived` |
| `paid_date` | When payment was recorded |
| `notes` | Admin notes |

## 13. Milestone Bonus Flow

The milestone bonus rewards affiliates for reaching every 100 paid completed sales.

### Rules

- The milestone triggers at every 100th completed sale (100, 200, 300, ...).
- The bonus amount equals the **total commission earned from that 100-sale block** (sales 1-100, 101-200, etc.).
- The bonus is credited to the affiliate's wallet as a `milestone_bonus` ledger entry.
- Milestone bonuses are subject to admin fee enforcement (if admin fee is unpaid, the bonus is blocked).

### Detection Logic (Using Sale Sequence)

```
1. After each commission credit, read the new sale_sequence value
2. If sale_sequence % 100 === 0:
   a. milestone_number = sale_sequence / 100
   b. sale_block_start = sale_sequence - 99
   c. sale_block_end = sale_sequence
   d. Sum approved commissions:
      SELECT SUM(commission_amount)
      FROM wp_konx_commissions
      WHERE affiliate_id = X
        AND sale_sequence BETWEEN sale_block_start AND sale_block_end
        AND status = 'approved'
   e. Credit milestone bonus to wallet
   f. Insert milestone record for audit
   g. Send milestone notification email
```

The `sale_sequence` column provides deterministic block boundaries. Unlike `created_at` ordering (which can have ties and is fragile), `sale_sequence` guarantees each commission belongs to exactly one 100-sale block.

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
├── Audit Log          — Searchable log of admin and system actions
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

### Multisite-Aware Dependency Check

The WooCommerce active check supports both single-site and multisite by checking `get_option('active_plugins')` and `get_site_option('active_sitewide_plugins')`. See Section 6 for implementation.

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

### IP Hash Salting

Referral click tracking stores a hashed visitor IP for duplicate suppression. The hash uses a secret salt to prevent rainbow table attacks against the small IPv4 address space:

```php
$ip_hash = hash( 'sha256', $ip_address . $salt );
```

The salt is a random 32-character string generated on plugin activation and stored in `wp_options` as `konx_ip_hash_salt`. It is never exposed to the frontend or included in exports.

### Rate Limiting

- Withdrawal requests are limited to one pending/approved request at a time per affiliate.
- Registration is protected by WordPress nonce and optional honeypot field.

### Audit Trail

- All wallet ledger entries are append-only with timestamps and source references.
- Admin actions (type changes, fee status changes, withdrawal approvals) are logged in the structured audit log table with the admin user ID, timestamp, and event details.

## 17. Audit Log Architecture

The audit log provides a structured, queryable record of significant admin and system actions. Unlike the wallet ledger (which tracks financial transactions only), the audit log captures non-financial events that affect affiliate state.

### Logged Events

| Event | Actor | Details Captured |
|---|---|---|
| Affiliate type changed | Admin | Old type, new type, affiliate ID |
| Affiliate status changed | Admin | Old status, new status, affiliate ID |
| Admin fee marked paid | Admin | Fee ID, affiliate ID |
| Admin fee marked unpaid | Admin | Fee ID, affiliate ID |
| Admin fee waived | Admin | Fee ID, affiliate ID, reason |
| Blocked commissions released | System | Affiliate ID, count of commissions released |
| Withdrawal approved | Admin | Withdrawal ID, amount |
| Withdrawal completed | Admin | Withdrawal ID, amount, Wise reference |
| Withdrawal rejected | Admin | Withdrawal ID, amount, reason |
| Commission manually adjusted | Admin | Ledger entry ID, amount, reason |
| Commission rate changed | Admin | Old rate, new rate, affiliate type, product type |
| Product mapping updated | Admin | Product ID, old type, new type |
| Affiliate registered | System/User | Affiliate ID, user ID, referral code |
| Balance reconciliation run | Admin | Affiliate ID, discrepancy amount (if any) |

### Audit Log Table

See `wp_konx_audit_log` in database-schema.md (Table 11) for the complete schema.

### Retention

Audit log entries are retained indefinitely. They are low-volume (admin actions, not per-click) and serve as a compliance and dispute resolution record.

## 18. Data Migration Considerations

### Existing Users

Users from Powerof10 already exist in the konx.world WordPress installation. The plugin must account for:

- **Existing customers** may become affiliates. The plugin assigns the `konx_affiliate` role as an additional role (not replacing their existing role) when they register as an affiliate.
- **Existing orders** placed before the plugin is activated will not have referral attribution. The plugin only tracks commissions on orders placed after activation.
- **User meta conflicts**: All plugin user meta keys are prefixed with `konx_` to avoid conflicts with existing meta from other plugins or themes.

### Coupon Affiliates for WooCommerce Migration

If Coupon Affiliates for WooCommerce is currently active on konx.world with existing data, the following must be addressed before launch:

1. **Audit existing data**: Identify affiliates, referral relationships, and any accumulated balances in Coupon Affiliates.
2. **Import affiliates**: Create `wp_konx_affiliates` records for existing affiliates, generate new referral codes, and assign the `konx_affiliate` role.
3. **Seed initial balances**: If affiliates have existing balances, create `adjustment` ledger entries to establish starting balances.
4. **Deactivate Coupon Affiliates**: Both plugins hooking into `woocommerce_order_status_completed` would cause double commission processing. Coupon Affiliates must be deactivated before KonX is activated.
5. **Communicate to affiliates**: Notify existing affiliates of their new referral codes and dashboard URL.

If Coupon Affiliates has no production data (was considered but not deployed), simply deactivate and remove it before activating KonX.

### Activation Routine

On activation, the plugin:

1. Creates custom database tables (if they do not exist).
2. Registers the `konx_affiliate` role and custom capabilities.
3. Adds capabilities to the `administrator` role.
4. Sets default plugin options (if not already set).
5. Generates and stores the IP hash salt (if not already set).
6. Declares HPOS compatibility.

### Deactivation vs. Uninstall

- **Deactivation**: Removes the `konx_affiliate` role and custom capabilities. Does not delete data.
- **Uninstall** (plugin deletion): Drops custom database tables, deletes plugin options, and removes custom user meta. This is handled in `uninstall.php` and will only be implemented when data storage is built.

## 19. Future Scalability Considerations

### Performance

- Wallet balance reads use the `cached_balance` column on `wp_konx_affiliates` for fast access. The SUM query is reserved for reconciliation and validation.
- Commission calculations happen synchronously on order completion. If order volume grows significantly, these can be moved to a background queue using Action Scheduler (bundled with WooCommerce).
- Admin list tables will use server-side pagination and will not load all records at once.
- The referral click table uses a retention policy to prevent unbounded growth.

### Extensibility

- Commission rates are stored in the database, not hardcoded. New affiliate types or product types can be added via the admin settings page.
- The plugin fires custom WordPress action hooks at key points (commission credited, withdrawal requested, milestone reached) so that other plugins or custom code can extend behavior.
- Email templates are stored in `templates/emails/` and can be overridden by the active theme.

### Potential Future Additions

- REST API endpoints for headless or mobile integrations.
- Integration with app.konx.world (out of scope for this phase).
- Automated payout via payment APIs (currently manual via Wise).
- Multi-level affiliate trees (not in current requirements).
- Affiliate performance tiers with automatic type promotion.
- WP-CLI commands for reconciliation and bulk operations.

## 20. Development Phases

### Phase 1 — Foundation (Current)

- Plugin bootstrap and file structure
- Architecture documentation
- Database schema design

### Phase 2 — Data Layer

- Custom database tables (affiliates, commissions, wallet ledger, withdrawals, admin fees, milestones, audit log)
- HPOS compatibility declaration
- Activation/deactivation/uninstall routines
- Custom role and capabilities

### Phase 3 — Core Engine

- Affiliate registration and profile management
- Referral link generation and cookie/localStorage tracking
- Referral attribution on WooCommerce orders

### Phase 4 — Wallet and Commission

- Wallet ledger operations (credit, debit, balance)
- Commission calculation engine (one-time) with sale sequencing
- Admin fee check integration (single check per order)
- Commission idempotency handling

### Phase 5 — Recurring Commission

- YITH subscription integration (recurring commissions)
- YITH dependency check and admin notice

### Phase 6 — Admin Fee and Milestone

- Admin fee enforcement (block/release commissions)
- 100-sale milestone detection and bonus (using sale_sequence)

### Phase 7 — Withdrawal

- Withdrawal request flow with balance re-validation
- Admin withdrawal management

### Phase 8 — Admin Panel

- Admin menu and dashboard
- Affiliate list and edit screens
- Commission and withdrawal management screens
- Settings page with product mapping and rate configuration
- Audit log viewer

### Phase 9 — Frontend Dashboard

- Shortcode registration and rendering
- Affiliate dashboard, wallet, commissions, withdrawals views
- Referral link display with copy functionality
- Milestone progress display

### Phase 10 — Notifications and Polish

- Email notifications for key events
- CSS styling for admin and frontend
- Translation-ready strings and POT file
- Testing and bug fixes

### Phase 11 — Launch

- Final QA on konx.world staging
- Coupon Affiliates migration/deactivation (if applicable)
- Data migration verification (existing users)
- Production deployment
- Monitoring and post-launch fixes
