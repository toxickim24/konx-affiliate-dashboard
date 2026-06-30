# Migration Wizard

Admin UI for reviewing PowerOf10 data before migration execution.
Located under KonX Affiliates > Migration in the WordPress admin menu.

## Wizard Flow

The wizard has 9 steps. Each step uses the Migration Engine
(`Konx_Migration_Engine`) for read-only data analysis.

### Step 1 — Welcome

Shows summary cards (PO10 users, WP users, KonX affiliates, missing
users) and links to the data source selection step.

### Step 2 — Data Source

Choose between CSV Upload (recommended for production) and Local
Database Scan (developer only). CSV uploads are validated for required
columns and row count before proceeding. See `csv-import.md`.

### Step 2 — Health Check

13-row status table with green/yellow/red badges for each data metric.
Uses `scan_data_sources()` results cached in state.

### Step 3 — Type Mapping

Shows source-to-normalized type mapping table. Counts for auto-mapped,
normalized, defaulted, and unmapped types. Uses `analyze_affiliate_types()`.

### Step 4 — Sponsors

Displays resolved/orphaned/self-referral sponsor stats. Tables for
top 20 orphaned sponsor codes and largest teams. Sample tree visualization.
Uses `analyze_sponsors()`.

### Step 5 — Conflicts

Categorized conflict tables: duplicate codes (critical), invalid emails
(warning), self-referrals (warning). Shows affected records with
severity badges. Uses `detect_conflicts()`.

### Step 6 — Preview

Paginated table of first 50 records per page. Shows PO10 ID, email,
name, type, code, sponsor, and planned action (Create/Skip).
Uses `prepare_batch()`.

### Step 7 — Dry Run

Full simulation triggered by button. Shows summary cards (users to
create, affiliates to create, records to skip, sponsor links, orphans,
estimated batches). Lists all skipped records with reasons. "No changes
have been made" notice. Uses `dry_run()`.

### Step 8 — Approval

Checklist of completed steps. Approval requires checkbox confirmation
and stores approval state (user ID, timestamp). Does NOT execute
migration.

## State Management

All wizard state is stored in `wp_options` key `konx_migration_state`:

- `scan` — Cached scan results
- `scan_at` — Scan timestamp
- `dry_run` — Cached dry-run results
- `dry_run_at` — Dry-run timestamp
- `approved` — Boolean approval flag
- `approved_by` — WordPress user ID
- `approved_at` — Approval timestamp

State resets: fresh scan clears dry-run and approval. New dry-run
clears approval. This ensures approval is always based on current data.

## Security

- `manage_konx_settings` capability required on all pages and handlers
- Nonce verification on scan, dry-run, and approval form submissions
- All output escaped with `esc_html()`, `esc_attr()`, `esc_url()`
- No direct SQL in view code — all queries via Migration Engine
- State stored in `wp_options` (not user-visible)

## Navigation

- Horizontal progress bar shows all 8 steps with completion state
- Back/Continue buttons on every step
- Steps can be revisited in any order via progress bar clicks
- No step dependencies enforced (admin can navigate freely to review)

## Future Execution Phase

The execution phase (Phase 24C-6) will:

1. Check that approval state exists
2. Create a database backup
3. Execute migration in AJAX batches using `prepare_batch()`
4. Run post-migration validation
5. Generate migration report

The wizard UI is ready to host these additional steps once the
execution engine is built.
