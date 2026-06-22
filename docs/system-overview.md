# KonX Affiliate Dashboard — System Overview

## Architecture

```
WordPress + WooCommerce + YITH Subscription
         |
    konx-affiliate-dashboard (plugin)
         |
    +----+----+----+----+----+----+----+
    |    |    |    |    |    |    |    |
  Referral Commission Wallet Milestone Withdrawal Admin  Frontend
  Tracker  Engine    Ledger  Bonus    Manager    Fees   Dashboard
```

## Database (11 Custom Tables)

| Table | Purpose |
|---|---|
| `konx_affiliates` | Affiliate profiles, status, cached balance |
| `konx_referral_clicks` | Raw click tracking (IP hash, dedup) |
| `konx_referral_conversions` | Order-to-affiliate attribution |
| `konx_commissions` | Commission records with sale sequence |
| `konx_wallet_ledger` | Append-only financial ledger |
| `konx_withdrawals` | Withdrawal request lifecycle |
| `konx_admin_fees` | Monthly fee obligations |
| `konx_milestones` | 100-sale milestone bonuses |
| `konx_commission_rules` | Configurable rate matrix |
| `konx_product_map` | WooCommerce product → category mapping |
| `konx_audit_log` | Admin/system action history |

## Financial Flow

```
Customer clicks referral link → Cookie set
Customer purchases → Order attributed to affiliate
Order completed → Commission calculated:
  - Product price × rate from rules table
  - Commission record created with sale_sequence
  - Wallet credited (append-only ledger)
  - Cached balance updated atomically
  - Milestone check triggered

Order refunded → Commission reversed:
  - Commission status → reversed
  - Wallet debit (negative entry)
  - Balance can go negative
```

## Referral Flow

```
Affiliate shares: https://site.com/?ref=CODE
Visitor clicks → cookie (30d) + localStorage
Visitor buys → cookie/hidden field read at checkout
Order meta: _konx_referrer_id = affiliate_id
Conversion record created
Cookie + localStorage cleared
```

## Commission Rates

| Type | Starter | Pro | eCard |
|---|---|---|---|
| Business | 40% | 40% | 40% |
| Referral | 20% | 20% | 20% |
| Team Agent | 40% | 40% | 40% |
| Marketing Agent | 40% | 20% | 20% |
| Sales Agent | 20% | 20% | 20% |
| Recurring (all) | 10% | 10% | 10% |

## Milestone Flow

Every 100 completed sales → bonus = SUM(approved commissions in block)
- Block 1: sales 1-100
- Block 2: sales 101-200
- Repeats indefinitely
- Uses sale_sequence for deterministic block boundaries

## Withdrawal Flow

```
Affiliate requests → pending
Admin approves → approved
Admin pays via Wise → marks completed
System re-validates balance → debits wallet
```

## Admin Workflow

1. Configure products (Product Mapping)
2. Set rates (Settings)
3. Manage affiliates (Affiliates page)
4. Monitor commissions (Overview + Reports)
5. Process withdrawals (Withdrawals page)
6. Track admin fees (Admin Fees page)
7. Check health (System Status)
8. Export data (CSV exports)

## Affiliate Workflow

1. Register via [konx_affiliate_register]
2. Get referral code and link
3. Share link → earn commissions
4. Track performance on [konx_affiliate_dashboard]
5. Request withdrawals
6. Reach milestones for bonuses
