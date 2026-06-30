# PowerOf10 CSV Export Guide

How to export user data from PowerOf10 for import into KonX Affiliates.

## Required CSV Format

The CSV must include a header row with these column names:

### Required Columns

| Column | Description | Example |
|--------|-------------|---------|
| `id` | PowerOf10 user ID | `2305` |
| `email` | Email address | `user@example.com` |
| `user_fname` | First name | `John` |
| `user_lname` | Last name | `Doe` |
| `promotional_title` | Affiliate type | `sales_agent` |
| `team_name` | Referral/team code | `MyTeamName` |

### Optional Columns

| Column | Description | Example |
|--------|-------------|---------|
| `user_phone` | Phone number | `+1-555-0123` |
| `referrer_team_name` | Sponsor's team code | `SponsorName` |
| `source` | Origin system | `powerof10` |
| `country_code` | Country code | `US` |
| `created_at` | Registration date | `2024-01-15 08:00:00` |

## Example CSV

```csv
id,email,user_fname,user_lname,user_phone,promotional_title,team_name,referrer_team_name,source,country_code,created_at
1,admin@konx.biz,VtexCom,"Global, Inc.","+1 (818) 536-8839",team_agent,VtexCom,,powerof10,AU,2019-03-31 00:12:16
2,user@gmail.com,John,Doe,817-829-4592,team_agent,TeamJohn,VtexCom,,US,2022-09-25 08:00:00
3,sales@example.com,Jane,Smith,,sales_agent,JaneTeam,TeamJohn,,US,2023-01-15 10:30:00
```

## Export from PowerOf10

### Option A: MySQL Export (Recommended)

Run this query against the PowerOf10 database and export as CSV:

```sql
SELECT
    id, email, user_fname, user_lname,
    COALESCE(user_phone, '') AS user_phone,
    COALESCE(promotional_title, '') AS promotional_title,
    COALESCE(team_name, '') AS team_name,
    COALESCE(referrer_team_name, '') AS referrer_team_name,
    COALESCE(source, '') AS source,
    COALESCE(country_code, '') AS country_code,
    COALESCE(created_at, '') AS created_at
FROM users
ORDER BY id;
```

Use phpMyAdmin "Export" with CSV format, or `mysql --batch` with
`sed 's/\t/,/g'` for tab-delimited output.

### Option B: Laravel Artisan Command (Future)

A future `php artisan konx:export-users` command can be added to the
PowerOf10 Laravel project to produce the CSV automatically.

## Validation Rules

The KonX Migration Wizard validates the CSV on upload:

- File extension must be `.csv`
- Maximum file size: 10 MB
- All required columns must be present
- At least 1 data row required
- No duplicate column headers

Row-level validation during analysis:

- `email` must be a valid email format
- `team_name` must not be empty
- `team_name` must be 50 characters or fewer
- Duplicate `team_name` values (case-insensitive) are flagged
- Duplicate `email` values are flagged
- Empty/null `promotional_title` defaults to `sales_agent`

## Production Migration

1. Export CSV from PowerOf10 database (Option A above)
2. Go to **KonX Affiliates > Migration** in WordPress admin
3. Choose **CSV Upload** as the data source
4. Upload the CSV file
5. Review the Health Check, Type Mapping, Sponsors, and Conflicts
6. Run the Dry Run to verify
7. Approve the migration plan
