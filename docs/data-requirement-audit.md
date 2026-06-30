# Data Requirement Audit

Read-only audit of all data sources to determine exactly which fields
are required for KonX and which source system contains each field.

## Data Inventory

### WordPress Users (1,930 records)

| Field | Coverage | Notes |
|-------|----------|-------|
| ID | 100% | Auto-increment |
| user_email | 100% | Unique, reliable |
| user_login | 100% | Often = team_name |
| display_name | 100% | Usually = team_name (NOT person name) |
| user_registered | 100% | Date account created in WP |
| first_name (meta) | 5.9% (113/1,930) | Mostly empty — only 113 have values |
| last_name (meta) | 2.8% (54/1,930) | Mostly empty |
| billing_phone (meta) | 0.4% (8/1,930) | Almost none |
| billing_country (meta) | 0.4% (8/1,930) | Almost none |

### Coupon Affiliates (1,924 records)

| Field | Coverage | Notes |
|-------|----------|-------|
| userid | 100% | Links to wp_users.ID |
| couponcode | 100% | = team_name / referral code |
| status | 100% | All "accepted" |
| date | 100% | Registration date |
| referrer | 0% | All empty |
| promote | 0% | All empty |
| type | 0% | All empty |
| website | 0% | All empty |

### KonX Affiliates (2 records — test data only)

| Field | Coverage | Notes |
|-------|----------|-------|
| user_id | 100% | Links to wp_users.ID |
| affiliate_type | 100% | The KonX type |
| referral_code | 100% | Unique 8-char or team_name |
| status | 100% | active/pending/suspended |
| parent_affiliate_id | 0% | Not set on test data |
| external_id | 0% | Not set on test data |
| phone | 0% | Not set on test data |
| payment_email | 50% | 1 of 2 set |

### PowerOf10 (2,363 records)

| Field | Coverage | Notes |
|-------|----------|-------|
| id | 100% | PO10 primary key |
| email | 99.96% | 2,362/2,363 |
| user_fname | 99.96% | Real person first name |
| user_lname | 99.87% | Real person last name |
| user_phone | 74.6% | 1,762/2,363 |
| country_code | 99.87% | 2,360/2,363 |
| promotional_title | 97.8% | Affiliate type (2,311/2,363) |
| team_name | 99.96% | Referral code / team name |
| referrer_team_name | 99.75% | Sponsor / upline (2,357/2,363) |
| source | 9.6% | Origin system (226/2,363) |
| created_at | 99.96% | Registration date |

## Field Matrix

| Business Field | WordPress | Coupon Affiliates | KonX | PowerOf10 | Best Source |
|---------------|-----------|-------------------|------|-----------|-------------|
| **Email** | YES (100%) | — | — | YES (100%) | Either (same) |
| **First Name** | 5.9% | NO | — | **99.96%** | **PowerOf10** |
| **Last Name** | 2.8% | NO | — | **99.87%** | **PowerOf10** |
| **Phone** | 0.4% | NO | column exists | **74.6%** | **PowerOf10** |
| **Country** | 0.4% | NO | — | **99.87%** | **PowerOf10** |
| **Affiliate Type** | NO | NO | column exists | **97.8%** | **PowerOf10** |
| **Team Name / Referral Code** | display_name (100%) | couponcode (100%) | referral_code | **99.96%** | Any (all have it) |
| **Sponsor / Upline** | NO | NO | parent_affiliate_id | **99.75%** | **PowerOf10 only** |
| **Registration Date** | user_registered (100%) | date (100%) | registered_at | **99.96%** | Any (all have it) |
| **Affiliate Status** | role-based | "accepted" | status column | NO | KonX (set at creation) |
| **External ID** | NO | NO | column exists | id (100%) | PowerOf10 |

## Unique PowerOf10 Data

Fields that exist **ONLY in PowerOf10**:

| Field | Why It Matters | Available Elsewhere? |
|-------|---------------|---------------------|
| **First Name** (real person) | Display name in WP is team_name, not person name. 94% of WP users have no first_name. | NO — WP has 5.9% coverage |
| **Last Name** (real person) | Same issue. 97% of WP users have no last_name. | NO — WP has 2.8% coverage |
| **Sponsor Hierarchy** | `referrer_team_name` is the ONLY source of who-recruited-whom. | NO — not in WP, not in CA |
| **Affiliate Type** | `promotional_title` determines commission rates and roles. | NO — not in WP or CA |
| **Phone Number** | 74.6% coverage in PO10 vs 0.4% in WP. | Effectively NO |
| **Country** | 99.87% coverage in PO10 vs 0.4% in WP. | Effectively NO |

## Migration Necessity Assessment

| Field | Required for KonX? | Available without PO10? | Migration Required? |
|-------|-------------------|------------------------|-------------------|
| Email | YES | YES (WP has it) | NO — already in WP |
| First Name | YES (for display) | NO (5.9% coverage) | **YES** |
| Last Name | YES (for display) | NO (2.8% coverage) | **YES** |
| Affiliate Type | YES (for commissions) | NO | **YES** |
| Team Name / Referral Code | YES | YES (in WP + CA) | NO — already exists |
| Sponsor / Parent | YES (for hierarchy) | NO | **YES** |
| Phone | Optional | NO | **YES** (if wanted) |
| Country | Optional | NO | **YES** (if wanted) |
| Registration Date | Optional | YES (WP user_registered) | NO |
| External ID | Recommended | NO | **YES** (for dedup) |

### Verdict

**PowerOf10 migration IS required.**

Without PowerOf10 data, KonX would have:
- 94% of affiliates with no real name (only team_name as display)
- 0% sponsor/upline relationships
- 0% affiliate type assignments (all would be default)
- 0% phone/country data

The sponsor hierarchy is the most critical — it cannot be reconstructed
from any other source.

## Minimum Viable CSV Export

### Required Columns (migration will fail without these)

```
id, email, user_fname, user_lname, promotional_title, team_name
```

### Strongly Recommended Columns

```
referrer_team_name
```

Without `referrer_team_name`, no sponsor hierarchy can be built.

### Optional but Valuable Columns

```
user_phone, country_code, created_at, source
```

### Full Recommended Export

```
id, email, user_fname, user_lname, user_phone, promotional_title,
team_name, referrer_team_name, source, country_code, created_at
```

### SQL for Export

```sql
SELECT id, email, user_fname, user_lname,
  COALESCE(user_phone, '') AS user_phone,
  COALESCE(promotional_title, '') AS promotional_title,
  COALESCE(team_name, '') AS team_name,
  COALESCE(referrer_team_name, '') AS referrer_team_name,
  COALESCE(source, '') AS source,
  COALESCE(country_code, '') AS country_code,
  COALESCE(created_at, '') AS created_at
FROM users ORDER BY id;
```

## What Data Already Exists?

| Data | In WP | In CA | In KonX | Notes |
|------|-------|-------|---------|-------|
| User accounts | 1,930 | — | — | 1,915 match PO10 by email |
| Coupon codes | — | 1,924 | — | Match PO10 team_names |
| Affiliate profiles | — | — | 2 | Test data only |
| WooCommerce roles | 1,919 CA roles | — | — | Will be replaced by KonX roles |

## What Data Is Duplicated?

| Data | Systems | Resolution |
|------|---------|------------|
| Email | WP + PO10 | Use WP as authority (already exists) |
| Team name | WP (display_name) + CA (couponcode) + PO10 (team_name) | All match — use PO10 as referral_code |
| Registration date | WP (user_registered) + CA (date) + PO10 (created_at) | Use PO10 for original date |

## What Exists Only in PowerOf10?

1. **Real person names** (first + last) — 94%+ of WP users lack these
2. **Sponsor hierarchy** (referrer_team_name) — exists nowhere else
3. **Affiliate type classification** (promotional_title) — not in WP or CA
4. **Phone numbers** (74.6% coverage) — not in WP
5. **Country codes** (99.87% coverage) — not in WP
6. **External IDs** — needed for API deduplication

## Final Recommendation

**PowerOf10 migration is required** for a functional affiliate program.

The minimum export must include the 6 required columns plus
`referrer_team_name` for sponsor hierarchy. Without PO10 data,
KonX cannot assign commission rates (no type), display affiliate
names (no names), or build team hierarchies (no sponsors).
