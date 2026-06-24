# CSV Validation Preview

The Migration Wizard validates every record from the data source
against business rules before allowing the administrator to proceed
to analysis and dry-run stages.

## Validation Rules

### Email

| Rule | Severity | Action |
|------|----------|--------|
| Missing email | Error | Record skipped during migration |
| Invalid email format | Error | Record skipped |
| Duplicate email (same as earlier row) | Error | Record skipped |

### Team Name (Referral Code)

| Rule | Severity | Action |
|------|----------|--------|
| Missing team name | Error | Record skipped |
| Length exceeds 50 characters | Error | Record skipped |
| Duplicate team name (case-insensitive) | Error | Record skipped |

### Affiliate Type

| Rule | Severity | Action |
|------|----------|--------|
| Missing affiliate type | Warning | Defaults to Sales Agent |
| Unknown type value | Warning | Defaults to Sales Agent |

### Sponsor

| Rule | Severity | Action |
|------|----------|--------|
| Self-referral (sponsor = own team name) | Warning | Parent set to NULL |
| Sponsor not found in source data | Warning | Parent set to NULL |

### Names

| Rule | Severity | Action |
|------|----------|--------|
| Missing first name | Warning | Empty value used |
| Missing last name | Warning | Empty value used |

## Severity Levels

| Level | Meaning | Blocks Continue? |
|-------|---------|-----------------|
| Error | Record will be skipped during migration | No (but shown prominently) |
| Warning | Record imported with noted limitation | No |

## Summary Counts

The validation summary shows:

- **Total Records**: All records in the source
- **Valid**: Records with no issues
- **Warnings**: Records with non-critical issues
- **Errors**: Records that will be skipped

Math: Valid + Warnings + Errors = Total

## Category Breakdown

Issues are grouped by field (Email, Team Name, Affiliate Type,
Sponsor, First Name, Last Name) showing error and warning counts
per category.

## Issue Details Table

The first 50 issues are displayed in a table showing:

- Row number
- Severity badge (ERROR / WARNING)
- Field name
- Issue description
- Value that caused the issue

## Validation Report Export

Click "Download Validation Report (CSV)" to get a full export.

### CSV Format

```csv
Row,Severity,Field,Issue,Value
14,ERROR,email,Missing email,
27,WARNING,referrer_team_name,Sponsor not found in source data,rillo
42,ERROR,team_name,Duplicate team name (first seen row 12),Blessed
```

## State Storage

Validation results are stored in `konx_migration_state['validation_results']`.
No production data is modified.

## Test Results (PowerOf10 2,363 users)

```
Total:    2,363
Valid:    1,594
Warnings:   755
Errors:      14

By category:
  email:              10 errors
  team_name:           4 errors
  referrer_team_name: 707 warnings
  promotional_title:   52 warnings
  user_lname:           3 warnings
  user_fname:           1 warning
```
