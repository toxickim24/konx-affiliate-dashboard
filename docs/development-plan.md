# KonX Affiliate Dashboard — Development Plan

## 1. Development Phases

The plugin is built in 9 phases. Each phase produces a working, testable increment. No phase depends on a later phase, so development can pause between phases without leaving the plugin in a broken state.

---

### Phase 1 — Plugin Skeleton

**Status: Complete**

| Task | Status |
|---|---|
| Create plugin directory structure | Done |
| Write bootstrap file with constants and WooCommerce dependency check | Done |
| Create `uninstall.php` placeholder | Done |
| Create `readme.txt` in WordPress format | Done |
| Write architecture document | Done |
| Write database schema document | Done |
| Write development plan document | Done |

**Exit criteria:** Plugin can be activated on a WordPress site with WooCommerce. Shows admin notice if WooCommerce is missing. No functionality yet.

---

### Phase 2 — Roles, Capabilities, and Database

| Task | Status |
|---|---|
| Build autoloader (`class-konx-autoloader.php`) | Not started |
| Create activation routine (`class-konx-install.php`) | Not started |
| Register `konx_affiliate` custom role with subscriber capabilities | Not started |
| Add custom capabilities to `konx_affiliate` and `administrator` roles | Not started |
| Create all 10 custom database tables via `dbDelta()` | Not started |
| Seed default commission rules into `wp_konx_commission_rules` | Not started |
| Build deactivation routine (remove role and capabilities, preserve data) | Not started |
| Build uninstall routine (drop tables, delete options, clean user meta) | Not started |
| Implement plugin version tracking and upgrade routine scaffold | Not started |

**Exit criteria:** Activating the plugin creates all tables and roles. Deactivating removes roles. Deleting the plugin removes all data. Tables match the database schema document exactly.

---

### Phase 3 — Admin Settings and Product Mapping

| Task | Status |
|---|---|
| Register admin menu structure under "KonX Affiliates" top-level menu | Not started |
| Build settings page (`class-konx-admin-settings.php`) | Not started |
| Implement product mapping UI — map WooCommerce product IDs to internal types | Not started |
| Implement commission rate configuration UI | Not started |
| Store settings in `wp_options` under `konx_affiliate_settings` | Not started |
| Save/load product mappings to `wp_konx_product_map` table | Not started |
| Save/load commission rules to `wp_konx_commission_rules` table | Not started |
| Add referral cookie duration setting (default 30 days) | Not started |
| Add minimum withdrawal amount setting (default $50) | Not started |
| Add admin fee configuration (amount, period, schedule) | Not started |

**Exit criteria:** Admin can configure all plugin settings. Product mapping and commission rates are stored in the database and retrievable via helper functions.

---

### Phase 4 — Affiliate Profile Management

| Task | Status |
|---|---|
| Build affiliate registration form (frontend shortcode) | Not started |
| Create affiliate record on registration (`wp_konx_affiliates`) | Not started |
| Generate unique 8-character referral code | Not started |
| Assign `konx_affiliate` role as additional role (not replacing existing) | Not started |
| Store affiliate meta in `wp_usermeta` (`konx_affiliate_id`, `konx_affiliate_type`, `konx_referral_code`) | Not started |
| Build admin affiliate list page using `WP_List_Table` | Not started |
| Build admin affiliate edit page (view/edit type, status, payment email, notes) | Not started |
| Restrict affiliate type changes to admin only | Not started |
| Add affiliate status management (active, inactive, pending) | Not started |
| Self-referral prevention (do not allow user to refer themselves) | Not started |

**Exit criteria:** Users can register as affiliates. Admin can view, edit, and manage affiliates. Affiliate type is changeable by admin only. Each affiliate has a unique referral code.

---

### Phase 5 — Referral Tracking and Order Attribution

| Task | Status |
|---|---|
| Implement `?ref=` query parameter detection on page load | Not started |
| Set first-party cookie (`konx_ref`, 30-day expiry, SameSite=Lax, Secure) | Not started |
| Store referral code in PHP session as fallback | Not started |
| Log referral click to `wp_konx_referral_clicks` (with IP hash, dedup) | Not started |
| On WooCommerce order creation, read cookie/session and attribute order | Not started |
| Store `_konx_referrer_id` as WooCommerce order meta | Not started |
| Create conversion record in `wp_konx_referral_conversions` | Not started |
| Clear referral cookie after attribution | Not started |
| Handle guest checkout (store `customer_user_id` as NULL) | Not started |
| Handle self-referral prevention at checkout | Not started |

**Exit criteria:** Clicking a referral link sets a cookie. Completing a purchase attributes the order to the affiliate. Conversion records link affiliates to orders. Duplicate clicks within 24 hours are suppressed.

---

### Phase 6 — Commission Engine (One-Time)

| Task | Status |
|---|---|
| Hook into `woocommerce_order_status_completed` | Not started |
| Read `_konx_referrer_id` from order meta | Not started |
| Look up affiliate type and status | Not started |
| Check admin fee status before crediting | Not started |
| For each line item: look up product in `wp_konx_product_map` | Not started |
| Look up commission rate from `wp_konx_commission_rules` | Not started |
| Calculate commission: `product_price × rate` | Not started |
| Insert commission record in `wp_konx_commissions` | Not started |
| Snapshot `affiliate_type_at_sale` on commission record | Not started |
| Handle `blocked` status when admin fee is unpaid | Not started |
| Skip non-mapped products (no commission) | Not started |
| Increment `completed_sales` on affiliate record | Not started |
| Handle order refunds — reverse commissions | Not started |

**Exit criteria:** When a referred order is completed, commissions are calculated per line item at the correct rate. Blocked commissions are recorded but not credited. Refunded orders reverse commissions.

---

### Phase 7 — Recurring Commission (YITH Subscription)

| Task | Status |
|---|---|
| Hook into `ywsbs_renew_order_payed` for subscription renewals | Not started |
| Copy `_konx_referrer_id` from original subscription order to renewal order | Not started |
| Create conversion record with `is_subscription_renewal = 1` | Not started |
| Calculate recurring commission at 10% flat rate | Not started |
| Insert commission record with `commission_type = 'recurring'` | Not started |
| Handle subscription cancellation (no future commissions) | Not started |
| Handle subscription status changes for reporting | Not started |

**Exit criteria:** When a YITH subscription renews and payment succeeds, the original referring affiliate earns a 10% recurring commission. Attribution persists across all renewals of the same subscription.

---

### Phase 8 — Wallet Ledger

| Task | Status |
|---|---|
| Build wallet credit function (insert ledger entry, update running balance) | Not started |
| Build wallet debit function | Not started |
| Calculate balance from `SUM(amount)` as source of truth | Not started |
| Link commission approval to wallet credit (set `ledger_entry_id` on commission) | Not started |
| Build wallet balance query helper | Not started |
| Build ledger history query with pagination | Not started |
| Handle concurrent operations (use database transactions) | Not started |

**Exit criteria:** Every commission approval creates a wallet credit. Balance is always derivable from the ledger sum. Running balance column stays in sync.

---

### Phase 9 — Withdrawal Requests

| Task | Status |
|---|---|
| Build withdrawal request form (frontend shortcode) | Not started |
| Validate amount against available balance | Not started |
| Validate minimum withdrawal amount | Not started |
| Enforce one pending/approved withdrawal at a time | Not started |
| Insert withdrawal record with status `pending` | Not started |
| Send notification to admin on new request | Not started |
| Build admin withdrawal list page (filter by status) | Not started |
| Admin approve action (status → `approved`) | Not started |
| Admin complete action (status → `completed`, debit wallet, record Wise reference) | Not started |
| Admin reject action (status → `rejected`, require reason) | Not started |
| Send email notification to affiliate on status change | Not started |

**Exit criteria:** Affiliates can request withdrawals. Admin can approve, complete (with Wise reference), or reject. Wallet is debited only on completion. One pending request enforced.

---

### Phase 10 — Admin Fee Enforcement

| Task | Status |
|---|---|
| Build admin fee management page | Not started |
| Create fee records per affiliate per period | Not started |
| Mark fees as paid/unpaid/overdue/waived | Not started |
| Check fee status in commission engine (block if unpaid) | Not started |
| Re-process blocked commissions when fee is marked paid | Not started |
| Add admin fee status indicator on affiliate dashboard | Not started |
| Send reminder emails for unpaid/overdue fees | Not started |
| Overdue detection via WP-Cron (check `due_date` daily) | Not started |

**Exit criteria:** Unpaid admin fees block new commissions. Paying the fee releases blocked commissions. Overdue fees are automatically detected. Affiliates see their fee status.

---

### Phase 11 — Milestone Bonus

| Task | Status |
|---|---|
| After each sale, check if `completed_sales % 100 === 0` | Not started |
| Determine sale block range (start, end) | Not started |
| Sum approved commissions for the 100-sale block | Not started |
| Insert milestone record in `wp_konx_milestones` | Not started |
| Credit bonus to wallet (or block if admin fee unpaid) | Not started |
| Prevent duplicate milestones via unique index | Not started |
| Send milestone notification email | Not started |
| Build milestone history view (frontend and admin) | Not started |

**Exit criteria:** Every 100th completed sale triggers a milestone bonus equal to the sum of commissions from that block. Bonus is credited to wallet. Duplicate milestones are impossible.

---

### Phase 12 — Affiliate Frontend Dashboard

| Task | Status |
|---|---|
| Register all shortcodes in `class-konx-shortcodes.php` | Not started |
| `[konx_affiliate_dashboard]` — summary stats, quick links | Not started |
| `[konx_affiliate_referral_link]` — display link with copy-to-clipboard | Not started |
| `[konx_affiliate_commissions]` — commission history with filters | Not started |
| `[konx_affiliate_wallet]` — balance and ledger history | Not started |
| `[konx_affiliate_withdrawals]` — request form and history | Not started |
| `[konx_affiliate_milestones]` — progress bar and history | Not started |
| `[konx_affiliate_registration]` — registration form for non-affiliates | Not started |
| Capability check on every shortcode (require `konx_view_dashboard`) | Not started |
| Login redirect for non-authenticated users | Not started |
| Conditional CSS/JS enqueue (only on pages with shortcodes) | Not started |
| Template override support (theme can override views) | Not started |
| Style public-facing views (`public.css`) | Not started |

**Exit criteria:** All shortcodes render correctly. Non-affiliates see registration form or message. Non-authenticated users are prompted to log in. Affiliates see only their own data.

---

### Phase 13 — Admin Reports and Dashboard

| Task | Status |
|---|---|
| Build admin dashboard page — total affiliates, commissions, withdrawals, recent activity | Not started |
| Build commission report page with filters (affiliate, date range, status, type) | Not started |
| Build withdrawal report page with filters | Not started |
| Build admin fee report page | Not started |
| Build milestone report page | Not started |
| Add export to CSV for commission and withdrawal reports | Not started |
| Style admin views (`admin.css`) | Not started |

**Exit criteria:** Admin has a complete overview of the affiliate program. Reports can be filtered and exported.

---

### Phase 14 — Email Notifications

| Task | Status |
|---|---|
| Commission earned — notify affiliate | Not started |
| Withdrawal status change — notify affiliate | Not started |
| Admin fee reminder — notify affiliate | Not started |
| Milestone bonus — notify affiliate | Not started |
| New withdrawal request — notify admin | Not started |
| New affiliate registration — notify admin | Not started |
| Build email templates in `templates/emails/` | Not started |
| Allow email enable/disable in settings | Not started |
| Use WooCommerce email system (`WC_Email`) or `wp_mail` | Not started |

**Exit criteria:** All key events trigger email notifications. Emails can be toggled on/off in settings. Templates can be overridden by theme.

---

### Phase 15 — QA, Hardening, and Deployment

| Task | Status |
|---|---|
| Full security audit (nonces, capability checks, SQL injection, XSS) | Not started |
| Performance testing with simulated data volume | Not started |
| Cross-browser testing of frontend dashboard | Not started |
| WooCommerce HPOS compatibility verification | Not started |
| WordPress multisite compatibility check | Not started |
| Translation readiness — audit all strings use `__()` / `esc_html__()` | Not started |
| Generate `.pot` file for translations | Not started |
| Write inline code documentation | Not started |
| Deploy to staging environment | Not started |
| UAT (user acceptance testing) with real affiliate workflows | Not started |
| Deploy to production | Not started |
| Post-launch monitoring | Not started |

**Exit criteria:** Plugin passes all test checklists. Deployed to production on konx.world with no critical bugs.

---

## 2. Estimated Timeline

| Phase | Description | Estimated Duration |
|---|---|---|
| 1 | Plugin Skeleton | 1 day |
| 2 | Roles, Capabilities, and Database | 2 days |
| 3 | Admin Settings and Product Mapping | 2 days |
| 4 | Affiliate Profile Management | 3 days |
| 5 | Referral Tracking and Order Attribution | 3 days |
| 6 | Commission Engine (One-Time) | 3 days |
| 7 | Recurring Commission (YITH) | 2 days |
| 8 | Wallet Ledger | 2 days |
| 9 | Withdrawal Requests | 2 days |
| 10 | Admin Fee Enforcement | 2 days |
| 11 | Milestone Bonus | 1 day |
| 12 | Affiliate Frontend Dashboard | 3 days |
| 13 | Admin Reports and Dashboard | 2 days |
| 14 | Email Notifications | 2 days |
| 15 | QA, Hardening, and Deployment | 3 days |
| — | **Total** | **~31 working days** |

### Notes on Timeline

- Estimates assume a single developer working full-time on this plugin.
- Each phase includes unit testing and manual QA for that phase's deliverables.
- Phase durations may overlap if multiple developers are involved.
- Buffer days are not included — add 20–30% buffer for unexpected issues.
- The timeline does not include stakeholder review/feedback cycles between phases.

---

## 3. Module Build Order

The modules are built in dependency order. Each module can only be built after its dependencies are complete.

```
Phase 1:  Plugin Skeleton
              │
Phase 2:  Roles & Capabilities ──→ Database Tables
              │                         │
Phase 3:  Admin Settings ────────→ Product Mapping
              │                         │
Phase 4:  Affiliate Profile Management
              │
Phase 5:  Referral Tracking ──→ Order Attribution
              │                       │
Phase 6:  Commission Engine (One-Time)
              │
Phase 7:  Recurring Commission (YITH) ──┐
              │                          │
Phase 8:  Wallet Ledger ←───────────────┘
              │
         ┌────┴────┐
         │         │
Phase 9:  Withdrawals    Phase 10: Admin Fee Enforcement
         │         │
         └────┬────┘
              │
Phase 11: Milestone Bonus
              │
         ┌────┴────┐
         │         │
Phase 12: Frontend   Phase 13: Admin Reports
         │         │
         └────┬────┘
              │
Phase 14: Email Notifications
              │
Phase 15: QA & Deployment
```

### Critical Path

The longest dependency chain runs through: Skeleton → Database → Settings → Affiliates → Referral Tracking → Commission Engine → Wallet → Milestone → Frontend → QA. This is the critical path — delays here delay the entire project.

### Parallelizable Work

If multiple developers are available:
- Phase 9 (Withdrawals) and Phase 10 (Admin Fee Enforcement) can be built in parallel.
- Phase 12 (Frontend Dashboard) and Phase 13 (Admin Reports) can be built in parallel.
- Phase 14 (Email Notifications) can begin during Phase 12/13 if notification triggers are defined.

---

## 4. Testing Checklist — General

### Per-Phase Testing

Every phase must pass these checks before moving to the next:

- [ ] All new functions have been manually tested with valid input
- [ ] All new functions have been tested with invalid/edge-case input
- [ ] No PHP errors, warnings, or notices in `WP_DEBUG` mode
- [ ] No JavaScript console errors
- [ ] All database queries use `$wpdb->prepare()` — no raw interpolation
- [ ] All user input is sanitized (`sanitize_text_field`, `absint`, etc.)
- [ ] All output is escaped (`esc_html`, `esc_attr`, `esc_url`, etc.)
- [ ] All form submissions verify nonces
- [ ] All admin pages check capabilities
- [ ] Plugin activates cleanly on a fresh WordPress install
- [ ] Plugin deactivates cleanly without errors
- [ ] Plugin does not break when WooCommerce is deactivated

---

## 5. WooCommerce Testing Plan

### Order Lifecycle

- [ ] Place an order as a referred customer → verify `_konx_referrer_id` is set on the order
- [ ] Complete the order → verify commission records are created
- [ ] Refund the order → verify commissions are reversed
- [ ] Partially refund the order → verify only affected line item commissions are reversed
- [ ] Place an order with no referral → verify no commission is created
- [ ] Place an order with a referral for non-mapped products → verify no commission is created
- [ ] Place an order with mixed mapped and non-mapped products → verify only mapped products earn commissions

### Order Statuses

- [ ] Order status: pending → no commission created yet
- [ ] Order status: processing → no commission created yet
- [ ] Order status: completed → commissions created and credited
- [ ] Order status: completed → refunded → commissions reversed
- [ ] Order status: cancelled → no commission created
- [ ] Order status: failed → no commission created

### Product Types

- [ ] Starter Pack ($100) → correct commission per affiliate type
- [ ] Pro Pack ($200) → correct commission per affiliate type
- [ ] eCard Pack ($500) → correct commission per affiliate type
- [ ] Non-commission product → no commission
- [ ] Multiple commission products in one order → one commission per line item

### Payment Gateways

- [ ] Test with each active payment gateway on konx.world
- [ ] Verify commission is calculated from product price, not post-gateway amount
- [ ] Verify commission calculation ignores tax amounts

### WooCommerce HPOS

- [ ] Test with HPOS enabled (High-Performance Order Storage)
- [ ] Verify `_konx_referrer_id` is stored and readable with HPOS
- [ ] Verify order ID references work with HPOS table structure

### Edge Cases

- [ ] Customer places two orders via same referral link → both orders attributed
- [ ] Customer uses referral link but already has an account → attribution works
- [ ] Guest checkout with referral link → attribution works (`customer_user_id` = NULL)
- [ ] Order with quantity > 1 of the same product → commission calculated on full line total
- [ ] Order placed by the affiliate themselves → self-referral prevented

---

## 6. Subscription Testing Plan (YITH WooCommerce Subscription)

### Initial Purchase

- [ ] Purchase a subscription via referral link → order attributed to affiliate
- [ ] Subscription order completed → one-time commission (if applicable) or recurring commission credited
- [ ] `_konx_referrer_id` stored on the subscription/order

### Renewal

- [ ] Subscription renews automatically → renewal order created
- [ ] Renewal order is paid → `ywsbs_renew_order_payed` fires
- [ ] Plugin copies `_konx_referrer_id` from original order to renewal order
- [ ] Conversion record created with `is_subscription_renewal = 1`
- [ ] Commission created with `commission_type = 'recurring'` at 10% rate
- [ ] Recurring commission credited to wallet

### Subscription Lifecycle

- [ ] Subscription cancelled → no future renewal commissions
- [ ] Subscription paused → no commissions during pause
- [ ] Subscription resumed → commissions resume on next renewal
- [ ] Subscription expired → no further commissions

### Attribution Persistence

- [ ] Verify same affiliate is credited across 3+ consecutive renewals
- [ ] Verify attribution survives even if referral cookie has expired
- [ ] Verify attribution persists if affiliate type changes between renewals (rate stays at 10%)

### Products

- [ ] Basic Pro Conference Room ($25/month) renewal → 10% = $2.50 commission
- [ ] Enterprise Conference Room ($81/month) renewal → 10% = $8.10 commission
- [ ] Enterprise Conference Room ($809/year) renewal → 10% = $80.90 commission
- [ ] Business Conference Room ($28/month) renewal → 10% = $2.80 commission
- [ ] Business Conference Room ($289/year) renewal → 10% = $28.90 commission
- [ ] Corporate Conference Room ($51/month) renewal → 10% = $5.10 commission
- [ ] Corporate Conference Room ($509/year) renewal → 10% = $50.90 commission

---

## 7. Security Testing Plan

### SQL Injection

- [ ] Test all `$wpdb->prepare()` queries with malicious input: `'; DROP TABLE--`
- [ ] Test search fields, filter parameters, and form inputs
- [ ] Verify no raw `$_GET`/`$_POST` values are used in SQL queries
- [ ] Test referral code parameter with SQL injection payloads

### Cross-Site Scripting (XSS)

- [ ] Test all form fields with `<script>alert('xss')</script>`
- [ ] Test referral code display, affiliate name display, admin notes display
- [ ] Verify all output uses `esc_html()`, `esc_attr()`, `esc_url()`, or `wp_kses_post()`
- [ ] Test URL parameters reflected in page output

### Cross-Site Request Forgery (CSRF)

- [ ] Verify all form submissions include and validate WordPress nonces
- [ ] Verify all AJAX requests include and validate nonces
- [ ] Verify all admin actions (type change, approval, rejection) check nonces
- [ ] Test submitting forms with expired or missing nonces → should be rejected

### Capability and Access Control

- [ ] Non-logged-in user cannot access affiliate dashboard shortcodes
- [ ] Logged-in non-affiliate user cannot access dashboard shortcodes
- [ ] Affiliate user cannot access admin pages
- [ ] Affiliate user cannot view another affiliate's data
- [ ] Affiliate user cannot change their own affiliate type
- [ ] Subscriber role cannot access affiliate management
- [ ] Editor role cannot access affiliate management
- [ ] Only administrator can change affiliate types, approve withdrawals, mark fees

### Cookie Security

- [ ] Referral cookie is HTTP-only
- [ ] Referral cookie has `SameSite=Lax`
- [ ] Referral cookie has `Secure` flag on HTTPS sites
- [ ] Cookie value cannot be used to extract user information

### Data Exposure

- [ ] Affiliate cannot see other affiliates' commissions, wallet, or withdrawal data
- [ ] API endpoints (if any) require authentication
- [ ] Direct access to PHP files returns no output (ABSPATH check)
- [ ] No sensitive data in HTML source or JavaScript variables

---

## 8. Admin Testing Plan

### Affiliate Management

- [ ] View affiliate list with pagination and search
- [ ] Edit affiliate profile (type, status, payment email, notes)
- [ ] Change affiliate type → future commissions use new type, past commissions unchanged
- [ ] Deactivate an affiliate → commissions stop, dashboard access blocked
- [ ] Reactivate an affiliate → commissions resume

### Commission Management

- [ ] View all commissions with filters (affiliate, date, status, type)
- [ ] Filter by affiliate type
- [ ] Filter by commission type (one-time vs recurring)
- [ ] View commission details (order link, product, rate, amount)
- [ ] Export commissions to CSV

### Withdrawal Management

- [ ] View pending withdrawals
- [ ] Approve a withdrawal → status changes to `approved`
- [ ] Complete a withdrawal → enter Wise reference, status changes to `completed`, wallet debited
- [ ] Reject a withdrawal → enter reason, status changes to `rejected`
- [ ] View completed/rejected withdrawal history

### Admin Fee Management

- [ ] Create fee records for affiliates
- [ ] Mark fee as paid → blocked commissions are released
- [ ] Mark fee as unpaid → future commissions are blocked
- [ ] Waive a fee
- [ ] View overdue fees

### Settings

- [ ] Save and load all settings correctly
- [ ] Update product mapping → commission engine uses new mapping
- [ ] Update commission rates → future commissions use new rates, past unchanged
- [ ] Update cookie duration
- [ ] Update minimum withdrawal amount

### Admin Dashboard

- [ ] Verify stats are accurate (total affiliates, total commissions, pending withdrawals)
- [ ] Verify recent activity feed is correct

---

## 9. Affiliate Dashboard Testing Plan

### Dashboard Home (`[konx_affiliate_dashboard]`)

- [ ] Displays correct total earnings
- [ ] Displays correct available balance (wallet)
- [ ] Displays correct pending commissions count
- [ ] Displays completed sales count
- [ ] Shows next milestone progress (e.g., "67/100 sales to next bonus")

### Referral Link (`[konx_affiliate_referral_link]`)

- [ ] Displays correct referral URL
- [ ] Copy-to-clipboard button works
- [ ] Referral code matches the affiliate's assigned code

### Commissions (`[konx_affiliate_commissions]`)

- [ ] Lists all commissions for the logged-in affiliate only
- [ ] Pagination works
- [ ] Filters by date range work
- [ ] Filters by status work
- [ ] Shows correct commission details (product, amount, rate, status, date)

### Wallet (`[konx_affiliate_wallet]`)

- [ ] Displays correct current balance
- [ ] Lists ledger entries in reverse chronological order
- [ ] Each entry shows type, amount, running balance, date
- [ ] Pagination works

### Withdrawals (`[konx_affiliate_withdrawals]`)

- [ ] Withdrawal request form shows current balance
- [ ] Cannot request more than available balance
- [ ] Cannot request below minimum withdrawal amount
- [ ] Cannot submit if a pending/approved withdrawal exists → shows message
- [ ] Submitted request appears in withdrawal history
- [ ] Status updates are visible (pending → approved → completed or rejected)
- [ ] Rejected withdrawal shows admin reason

### Milestones (`[konx_affiliate_milestones]`)

- [ ] Shows progress toward next milestone
- [ ] Lists past milestones with bonus amounts
- [ ] Bonus amounts match the sum of commissions from the corresponding 100-sale block

### Registration (`[konx_affiliate_registration]`)

- [ ] Non-affiliate logged-in user sees registration form
- [ ] Registration creates affiliate record and assigns role
- [ ] Existing affiliate sees "already registered" message
- [ ] Non-logged-in user sees login prompt or login + register option

### Access Control

- [ ] All shortcodes require login
- [ ] All shortcodes require `konx_affiliate` role (except registration)
- [ ] Affiliate can only see their own data across all views

---

## 10. Deployment Plan

### Environment Setup

| Environment | URL | Purpose |
|---|---|---|
| Local | `localhost` (WAMP) | Active development and unit testing |
| Staging | Staging subdomain or separate WordPress install | Integration testing, UAT, client review |
| Production | `konx.world` | Live site |

### Local → Staging

1. **Pre-deployment checks:**
   - All tests in the relevant phase checklist pass on local
   - No PHP errors/warnings with `WP_DEBUG = true`
   - Plugin activates cleanly on a fresh WordPress + WooCommerce install
   - All committed code is pushed to the remote repository

2. **Deploy to staging:**
   - Pull latest code from the repository to the staging server's `wp-content/plugins/` directory
   - Activate the plugin on staging
   - Run through the activation routine (tables created, roles added)
   - Verify database tables exist and match schema

3. **Staging testing:**
   - Run the full testing checklist for all completed phases
   - Test with real WooCommerce products (mapped to staging product IDs)
   - Test with YITH WooCommerce Subscription on staging
   - Test with Elementor pages containing the shortcodes
   - Verify existing users (from Powerof10) are unaffected
   - Perform UAT with stakeholders

### Staging → Production

1. **Pre-deployment checks:**
   - All staging tests pass
   - Stakeholder sign-off received
   - Production database is backed up (full WordPress backup)
   - Maintenance mode plan ready (if needed)

2. **Deploy to production:**
   - Pull latest code from the repository to production `wp-content/plugins/`
   - Activate the plugin
   - Verify activation routine completes (tables, roles)
   - Update product mapping in settings to production WooCommerce product IDs
   - Configure commission rates if different from defaults
   - Test one end-to-end referral flow with a test affiliate account

3. **Post-deployment:**
   - Monitor PHP error log for 24 hours
   - Verify WooCommerce orders are processing normally
   - Verify no conflicts with existing plugins (Elementor, YITH, etc.)
   - Monitor database table sizes
   - Confirm email notifications are sending

---

## 11. Rollback Plan

### Plugin Deactivation (Minimal Rollback)

If the plugin causes issues on production:

1. Deactivate the plugin via WordPress admin (`Plugins → Deactivate`)
2. This removes the custom role and capabilities but preserves all data
3. WooCommerce orders continue processing normally (plugin hooks are removed)
4. Affiliate shortcode pages will show empty shortcode tags — update pages or add a notice

### Code Rollback (Version Rollback)

If a specific release introduces a bug:

1. Identify the last known good commit via `git log`
2. On the server: `git checkout <last-good-commit>`
3. Deactivate and reactivate the plugin to run any necessary downgrade routines
4. If database schema changed between versions, a migration rollback may be needed (see below)

### Database Rollback

If a database migration causes data corruption:

1. Restore the pre-deployment database backup
2. Roll back the plugin code to the previous version
3. Reactivate the plugin

**Prevention:** All schema changes are additive (add columns, add tables). Destructive changes (drop columns, alter types) are avoided. If a destructive change is necessary, it is preceded by a data migration script and tested thoroughly on staging.

### Emergency Contacts

| Role | Action |
|---|---|
| Developer | Diagnose issue, roll back code, fix bugs |
| Server admin | Restore database backups, manage server access |
| Stakeholder | Approve rollback decisions, communicate to affected affiliates |

---

## 12. Git Workflow

### Branch Strategy

| Branch | Purpose | Merges Into |
|---|---|---|
| `main` | Stable, deployable code. Reflects what is on production. | — |
| `develop` | Integration branch. All feature work merges here first. | `main` |
| `feature/*` | Individual feature branches (one per phase or task). | `develop` |
| `hotfix/*` | Urgent fixes for production bugs. | `main` and `develop` |

### Branch Naming Convention

```
feature/phase-2-database-tables
feature/phase-5-referral-tracking
feature/commission-engine
feature/admin-settings-page
hotfix/commission-rounding-fix
```

### Commit Message Convention

```
<type>: <short description>

[optional body with more detail]

Co-Authored-By: ...
```

**Types:**
- `feat` — new feature or functionality
- `fix` — bug fix
- `refactor` — code restructuring without behavior change
- `docs` — documentation only
- `style` — CSS or formatting changes (no logic change)
- `test` — adding or updating tests
- `chore` — build, tooling, or config changes

**Examples:**
```
feat: Add commission calculation engine

Hook into woocommerce_order_status_completed to calculate
and credit one-time commissions per line item.

fix: Prevent duplicate commissions on order status re-trigger

Check for existing commission record before inserting.
Uses unique index on (order_id, order_item_id).

docs: Add database schema documentation
```

### Workflow Per Phase

1. Create a feature branch from `develop`: `git checkout -b feature/phase-X-description develop`
2. Develop and commit incrementally within the branch
3. When the phase is complete and tested locally, push the branch
4. Create a pull request from `feature/*` into `develop`
5. Review the PR (self-review or peer review)
6. Merge into `develop`
7. Deploy `develop` to staging for testing
8. When staging passes, merge `develop` into `main`
9. Tag the release: `git tag -a v1.X.0 -m "Phase X complete"`
10. Deploy `main` to production

### Hotfix Workflow

1. Create a hotfix branch from `main`: `git checkout -b hotfix/description main`
2. Fix the issue and commit
3. Merge into `main` and deploy to production
4. Merge into `develop` to keep branches in sync
5. Tag the release: `git tag -a v1.X.1 -m "Hotfix: description"`

### Tags and Releases

| Tag | When |
|---|---|
| `v0.1.0` | Phase 1 complete (skeleton) |
| `v0.2.0` | Phase 2 complete (database + roles) |
| `v0.3.0` | Phase 3 complete (settings) |
| ... | Each phase completion |
| `v1.0.0` | Phase 15 complete — production launch |
| `v1.0.1+` | Hotfixes |

### Repository Rules

- Never push directly to `main` after Phase 1 is complete — always go through `develop` or a hotfix branch.
- Every merge into `main` produces a deployable state.
- Keep commits atomic — one logical change per commit.
- Do not commit debug code, `var_dump`, `console.log`, or commented-out blocks.
- Do not commit credentials, API keys, or `.env` files.
