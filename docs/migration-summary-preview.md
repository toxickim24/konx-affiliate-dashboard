# Migration Summary Preview

Consolidates all migration review data into a single administrator-
facing summary with readiness assessment.

## Location

Migration Wizard > Step 6 (Summary) — between Conflicts and Preview.

## Readiness Status

The summary calculates one of three readiness levels:

| Status | Condition | Color |
|--------|-----------|-------|
| Ready for Review | No errors, no significant warnings | Green |
| Needs Attention | Warnings present (orphans, duplicates) | Amber |
| Blocked | No scan data, or no records found | Red |

### Readiness Rules

- **Blocked** if no scan has been performed
- **Blocked** if zero records in source
- **Needs Attention** if records have errors (will be skipped)
- **Needs Attention** if records have warnings
- **Needs Attention** if >50 orphan sponsor references
- **Needs Attention** if duplicate referral codes exist
- **Ready** if none of the above

## Summary Sections

### Record Counts
Total, valid, warnings, errors — from validation results.

### Affiliate Type Breakdown
Counts by type from dry-run results or type analysis.
Shows: Team Agent, Sales Agent, Marketing Agent, Business.

### Sponsor Hierarchy
Resolved, missing/orphaned, self-referrals from scan data.
Highlights large orphan groups (>100).

### Validation Summary
Error and warning counts with top issue categories.

### Dry Run Projection
If dry-run has been performed: affiliates to create, records to skip,
new WordPress users, sponsor links to establish.

## Export Format

Download Summary Report (CSV):

```csv
Section,Metric,Value
Records,Total,2363
Records,Valid,1594
Records,Warnings,755
Records,Errors,14
Affiliate Types,Team Agent,357
Affiliate Types,Sales Agent,1989
Affiliate Types,Marketing Agent,4
Sponsors,Total References,2357
Sponsors,Resolved,1669
Sponsors,Missing/Orphaned,684
Sponsors,Self-Referrals,4
Validation,Total Errors,14
Validation,Total Warnings,763
Projection,Affiliates to Create,2350
Projection,Records to Skip,13
Readiness,Status,Needs Attention
Readiness,Reason,14 records have errors and will be skipped.
```

## State

The summary is computed on-the-fly from `konx_migration_state` — it
does not store additional data. The readiness assessment uses scan,
validation, and dry-run data when available.

## No Data Writes

The summary step is read-only. No database tables are modified.
