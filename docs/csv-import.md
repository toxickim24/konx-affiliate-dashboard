# CSV Import

The Migration Wizard supports CSV file upload as the primary data
source for importing PowerOf10 users into KonX Affiliates.

## Why CSV?

CSV upload is recommended over direct database access because:

- No production database credentials needed on the WordPress server
- No cross-database query permissions required
- Safer for production environments
- Portable — works on any hosting provider
- Auditable — the CSV file can be reviewed before upload
- Repeatable — the same CSV can be used for testing and production

## How It Works

1. Admin exports a CSV from PowerOf10 (see `powerof10-csv-export.md`)
2. In the Migration Wizard, admin chooses "CSV Upload" as data source
3. The CSV is uploaded and validated (columns, format, row count)
4. Parsed records are cached in `konx_migration_state` WordPress option
5. All wizard steps (health check, types, sponsors, conflicts, dry run)
   use the cached CSV data — no re-upload needed
6. The original CSV file is NOT stored permanently

## CSV Validation

### File-Level Checks

| Check | Requirement |
|-------|------------|
| Extension | `.csv` only |
| Size | Maximum 10 MB |
| Header row | Must be first row |
| Required columns | `id`, `email`, `user_fname`, `user_lname`, `promotional_title`, `team_name` |
| Duplicate headers | Not allowed |

### Row-Level Checks (During Analysis)

| Check | Action |
|-------|--------|
| Invalid email | Record skipped |
| Empty team_name | Record skipped |
| team_name > 50 chars | Record skipped |
| Duplicate team_name (case-insensitive) | Second occurrence skipped |
| Duplicate email | Second occurrence skipped |
| Self-referral | Parent set to NULL, warning logged |
| Empty promotional_title | Defaulted to `sales_agent` |

## Source Abstraction

The migration engine (`Konx_Migration_Engine`) supports both CSV and
database sources through a unified interface:

- `load_from_csv($path)` — parses CSV into the same object format as DB rows
- `get_source_records()` — returns records from whichever source is loaded
- All 8 analysis methods work identically regardless of source
- The `source` field in scan/dry-run results indicates which source was used

## State Caching

When a CSV is uploaded, the parsed records are serialized into
`konx_migration_state['csv_records']`. This allows:

- Navigating between wizard steps without re-uploading
- Running the dry-run from cached data
- Page refreshes preserve the data

Uploading a new CSV or running a database scan clears the cached
records and resets the wizard state.

## Security

- CSV file is processed from PHP's temporary upload location
- The file is NOT moved to a permanent location
- Parsed records are stored as sanitized arrays in wp_options
- No passwords or sensitive credentials are stored
- All output is escaped via WordPress escaping functions
- File upload uses WordPress nonce verification
