# Migration Engine

Backend engine for importing PowerOf10 users into KonX Affiliates.
Read-only analysis and dry-run simulation — no data writes.

## Data Sources

The engine supports two data sources:

- **CSV Upload** (recommended for production) — parsed via `load_from_csv()`
- **Local Database** (developer/staging only) — cross-database query via `$wpdb`

All 8 analysis methods work identically regardless of source via the
`get_source_records()` abstraction. See `csv-import.md` for CSV details.

### Field Mapping

| KonX Field | PO10 Source | Notes |
|------------|-------------|-------|
| external_id | `po10_{users.id}` | Idempotency key |
| email | `users.email` | WP user lookup |
| first_name | `users.user_fname` | |
| last_name | `users.user_lname` | |
| affiliate_type | `users.promotional_title` | Normalized |
| referral_code | `users.team_name` | Preserved as-is |
| parent_referral_code | `users.referrer_team_name` | Resolved by code |
| phone | `users.user_phone` | |
| registered_at | `users.created_at` | Original date |
| status | — | Set to `active` |

**Do NOT use** `users.referrer` (integer) — always 0 or 1, unreliable.

## Engine Methods

### 1. scan_data_sources()

Returns counts for all data sources: PO10 users, WP users, KonX
affiliates, Coupon Affiliates, matched users, missing WP users,
referral codes, sponsor relationships, conflicts.

### 2. analyze_referral_codes()

Analyzes code uniqueness, length distribution, case-insensitive
duplicates, and conflicts with existing KonX codes.

### 3. analyze_affiliate_types()

Detects all `promotional_title` values and maps them to KonX types.

| Source | Normalized | Status |
|--------|-----------|--------|
| `sales_agent` | `sales_agent` | auto |
| `salesagent` | `sales_agent` | normalized |
| `team_agent` | `team_agent` | auto |
| `teamagent` | `team_agent` | normalized |
| `marketing_agent` | `marketing_agent` | auto |
| `business` | `business` | auto |
| `business_affiliate` | `business` | normalized |
| (empty/null) | `sales_agent` | defaulted |

### 4. analyze_sponsors()

Resolves `referrer_team_name` against `team_name` to build sponsor
hierarchy. Reports resolved, orphaned, and self-referral counts.
Includes sample tree and largest team rankings.

### 5. detect_conflicts()

Detects blocking and warning-level conflicts:

| Conflict | Severity |
|----------|----------|
| Case-insensitive duplicate referral codes | Critical |
| Referral code conflicts with existing KonX | Critical |
| Invalid/missing email | Warning |
| Self-referrals | Warning |
| Duplicate emails within PO10 | Critical |
| Existing KonX affiliate for same email | Info |

### 6. build_migration_preview()

Returns the first N records with planned action, normalized type,
referral code, parent code, and any errors/warnings.

### 7. dry_run()

Full simulation across all PO10 users. Checks WP user existence,
affiliate existence, code conflicts, sponsor resolution, and type
normalization. Returns summary with create/skip counts, by-type
breakdown, error list, warning list, and batch estimate.

### 8. prepare_batch()

Returns a slice of migration-ready records by offset/limit for
future batch execution.

## State Management

State is stored in `wp_options` key `konx_migration_state`. The engine
provides `save_state()` and `get_state()` for the future wizard UI.

## Dry-Run Results (Local Test)

```
total_records:          2,363
will_create_users:        436
will_create_affiliates: 2,350
will_skip:                 13
will_link_sponsors:     1,641
orphan_sponsors:          700
type_normalized:           25
type_defaulted:            52
estimated_batches:         47
errors:                    13  (9 invalid emails, 4 code dupes)
warnings:                 703  (700 orphan sponsors, 3 self-referrals)
```

## Known Limitations

- Cross-database query requires WP DB user to have SELECT access to
  the PO10 database. Will need configurable connection for production
  if databases are on different servers.
- Dry-run checks WP user existence via `get_user_by()` which is slow
  for 2,300+ records (~15 seconds). Future optimization: batch query.
- Sponsor resolution in dry-run uses in-memory set of PO10 team_names.
  Does not check KonX codes for sponsors — will be handled during
  actual execution.

## Future UI Integration

The migration wizard (Phase 24C-5+) will call these methods:

1. Health check → `test_po10_connection()`
2. Scan step → `scan_data_sources()`
3. Code analysis → `analyze_referral_codes()`
4. Type mapping → `analyze_affiliate_types()`
5. Sponsor preview → `analyze_sponsors()`
6. Conflict resolution → `detect_conflicts()`
7. Import preview → `build_migration_preview()`
8. Dry run → `dry_run()`
9. Batch execution → `prepare_batch()` (data only — execution class TBD)
