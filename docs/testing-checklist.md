# KonX Affiliate Dashboard — Manual Testing Checklist

## 1. Plugin Lifecycle

### Activation
- [ ] Plugin activates without PHP errors
- [ ] All 11 database tables created
- [ ] 5 affiliate roles created (business, referral, team, marketing, sales)
- [ ] 8 capabilities added to administrator
- [ ] 20 default commission rules seeded
- [ ] IP hash salt generated in wp_options
- [ ] Daily overdue fee cron scheduled
- [ ] HPOS compatibility declared (no WooCommerce warnings)
- [ ] Plugin version stored in wp_options

### Deactivation
- [ ] Plugin deactivates without errors
- [ ] Custom roles removed
- [ ] Capabilities removed from administrator
- [ ] Cron events cleared
- [ ] Database tables preserved (not deleted)
- [ ] Options preserved
- [ ] User meta preserved

### Uninstall (with KONX_REMOVE_ALL_DATA)
- [ ] Options removed
- [ ] Roles removed
- [ ] Tables dropped
- [ ] User meta cleaned
- [ ] Order meta cleaned

## 2. Affiliate Registration

### Logged-Out Registration
- [ ] Full form displayed
- [ ] Valid submission creates WordPress user
- [ ] Affiliate profile created with referral code
- [ ] Referral Affiliate: status = active
- [ ] Business Affiliate: status = pending
- [ ] Initial admin fee created
- [ ] Admin email notification sent
- [ ] User confirmation email sent
- [ ] User auto-logged in

### Logged-In Registration
- [ ] Abbreviated form (no name/email/password)
- [ ] Affiliate profile created for existing user
- [ ] Duplicate registration blocked

### Validation
- [ ] Missing required fields → error
- [ ] Invalid email → error
- [ ] Short password → error
- [ ] Email exists → error
- [ ] Terms not accepted → error

## 3. Referral Tracking

### Click Tracking
- [ ] Visit ?ref=VALIDCODE → cookie set (30 days, HttpOnly, SameSite)
- [ ] Visit ?ref=INVALIDCODE → no cookie
- [ ] Inactive affiliate → no cookie
- [ ] Self-referral (logged in as affiliate) → no cookie
- [ ] Click logged to referral_clicks table
- [ ] IP hashed with salt
- [ ] Duplicate click within 24h → suppressed
- [ ] localStorage stores referral code

### Checkout Attribution
- [ ] Order with referral cookie → _konx_referrer_id meta set
- [ ] Order with localStorage fallback → meta set
- [ ] Order without referral → no meta
- [ ] Self-referral at checkout → no meta
- [ ] Guest checkout → customer_user_id = NULL in conversion
- [ ] Conversion record created
- [ ] Cookie cleared after attribution
- [ ] localStorage cleared on thank-you page

## 4. Commission Engine (One-Time)

### Basic Commission
- [ ] Completed order → commissions created per line item
- [ ] Rate matches commission_rules table
- [ ] Commission base = get_subtotal() (before discounts)
- [ ] Order with coupon → commission on full price
- [ ] Business 40% on all packs
- [ ] Referral 20% on all packs
- [ ] Marketing 40% starter, 20% pro/ecard
- [ ] sale_sequence assigned atomically
- [ ] completed_sales updated
- [ ] Wallet credited

### Idempotency
- [ ] Order status re-triggered → no duplicate commissions
- [ ] Wallet not double-credited

### Admin Fee Blocking
- [ ] Unpaid fees → commission status = blocked
- [ ] Blocked → wallet NOT credited
- [ ] Paid fees → commission approved and credited

### Product Mapping
- [ ] Mapped product → commission
- [ ] Unmapped product → no commission
- [ ] Variation ID → correct mapping

## 5. Commission Engine (Recurring)

### YITH Integration
- [ ] YITH active → hooks registered
- [ ] YITH inactive → no hooks, admin notice shown
- [ ] Renewal paid → recurring commission created
- [ ] Rate = 10% (from settings)
- [ ] Attribution traced to original affiliate

### Attribution Persistence
- [ ] Same affiliate across multiple renewals
- [ ] Attribution survives cookie expiry

## 6. Wallet Ledger

### Credits
- [ ] Commission credit → positive entry, balance increases
- [ ] Recurring commission → TYPE_RECURRING_COMMISSION entry
- [ ] Milestone bonus → TYPE_MILESTONE_BONUS entry

### Debits
- [ ] Withdrawal debit → negative entry, balance decreases
- [ ] Insufficient balance → debit blocked (non-forced)
- [ ] Reversal → forced debit (can go negative)

### Integrity
- [ ] running_balance updated atomically
- [ ] cached_balance matches SUM(amount)
- [ ] Reconciliation detects drift

### Idempotency
- [ ] Same commission ID → entry_exists() returns true
- [ ] Duplicate credit → WP_Error

## 7. Milestone Bonus

- [ ] 100th sale → milestone 1 awarded
- [ ] Bonus = SUM(approved commissions in block 1-100)
- [ ] 200th sale → milestone 2 awarded
- [ ] Catch-up: blocked 100th sale → milestone awarded at 101st
- [ ] Duplicate milestone prevented
- [ ] Admin fee blocking applies to milestones

## 8. Withdrawals

### Request
- [ ] Create with valid amount → pending
- [ ] Amount > balance → error
- [ ] Amount < minimum → error
- [ ] Duplicate pending → error

### Admin Actions
- [ ] Approve → approved
- [ ] Complete (with Wise ref) → wallet debited
- [ ] Balance re-validated before completion
- [ ] Insufficient balance → completion blocked
- [ ] Reject (with reason) → rejected
- [ ] Cancel → cancelled

## 9. Admin Fee Enforcement

- [ ] Fee created per affiliate per period
- [ ] Daily cron marks past-due as overdue
- [ ] Unpaid/overdue blocks commissions
- [ ] Mark paid → commissions unblocked for future orders
- [ ] Waive fee → commissions unblocked

## 10. Refund / Reversal

- [ ] Full refund → all commissions reversed, wallet debited
- [ ] Partial refund (item-level) → matching commission reversed
- [ ] Partial refund (proportional) → proportional wallet debit
- [ ] Cancelled order → commissions reversed
- [ ] Blocked commission refunded → marked reversed, no wallet entry
- [ ] Already-reversed → idempotent skip
- [ ] Negative balance allowed on reversal

## 11. Frontend Dashboard

- [ ] Not logged in → login prompt
- [ ] Non-affiliate → message
- [ ] Affiliate → full dashboard
- [ ] Financial summary correct
- [ ] Milestone progress bar
- [ ] Commission history table
- [ ] Withdrawal form (or pending notice)
- [ ] Admin fee warning when applicable
- [ ] Referral link with copy button

## 12. Admin Pages

- [ ] Overview: 8 stat cards, recent activity
- [ ] Affiliates: list, filter, search, detail/edit
- [ ] Product Mapping: add/remove mappings
- [ ] Admin Fees: create, mark paid/overdue/waived
- [ ] Withdrawals: approve, complete, reject
- [ ] Reports: date filter, 8 report sections
- [ ] Settings: rates, fees, withdrawal min, referral config

## 13. Security

- [ ] All admin pages check capabilities
- [ ] All forms verify nonces
- [ ] All inputs sanitized
- [ ] All outputs escaped
- [ ] No raw SQL (all prepared)
- [ ] No raw IPs stored (salted hash)
- [ ] Cookies: HttpOnly, SameSite, Secure
- [ ] ABSPATH guard on every PHP file
