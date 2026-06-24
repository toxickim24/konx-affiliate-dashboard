# Source Comparison Preview

Compares uploaded CSV data against existing WordPress users, KonX
affiliates, and Coupon Affiliates (if active) to detect duplicates
and reconcile orphan sponsors before migration.

## Comparison Sources

| Source | Match By | Required? |
|--------|----------|-----------|
| WordPress Users | Email (case-insensitive) | Always available |
| KonX Affiliates | Email + referral code | Always available |
| Coupon Affiliates | Coupon code (team name) | Optional — if plugin active |

## Coupon Affiliates Behaviour

- If active: scans `wp_wcusage_register` table, compares coupon codes
  against CSV team names, reports matches and differences.
- If inactive/uninstalled: shows "Coupon Affiliates not detected.
  Comparison skipped." as an informational message.
- Never blocks the migration wizard.

## Matching Rules

### WordPress Users
- CSV `email` matched against `wp_users.user_email` (lowercase)
- Result: "matched" (existing account) or "new" (needs creation)

### KonX Affiliates
- CSV `email` matched against KonX affiliate user emails
- CSV `team_name` matched against `konx_affiliates.referral_code`
- If matched: record will be skipped during migration
- Shows duplicate details: PO10 ID, email, CSV code, KonX code

### Coupon Affiliates
- CSV `team_name` matched against `wcusage_register.couponcode`
- Reports: matched, CSV-only, and CA-only counts

## Sponsor Reconciliation

For orphan sponsors (referrer_team_name not found in CSV):
1. Check WordPress users by display_name
2. Check KonX affiliates by referral_code
3. Check Coupon Affiliates by coupon code (if available)

Results:
- **Found**: Sponsor exists in site data (may be resolvable)
- **Missing**: Sponsor not found anywhere (affiliate will have no parent)

## Export Format

Download Comparison Report (CSV):

```csv
Source,Record,Match Type,Severity,Message
CSV vs WP Users,"1916 emails",match,INFO,"1916 CSV records match..."
CSV vs WP Users,"446 emails",new,INFO,"446 CSV records have no..."
Coupon Affiliates,"1923 records",info,INFO,"Coupon Affiliates detected..."
Sponsor Reconciliation,"703 references",missing,WARNING,"703 orphan..."

Sponsor Reconciliation,Sponsor Name,Affected Users,Found In,Status
,rillo,495,Not found,missing
,domingo,63,Not found,missing
```

## Test Results (2,363 PO10 records)

```
CSV Records:     2,363
WP Matches:      1,916
WP New:            446
KonX Matches:        0
CA Detected:       YES
CA Matches:      1,914
Sponsors Found:      0
Still Missing:     703
```
