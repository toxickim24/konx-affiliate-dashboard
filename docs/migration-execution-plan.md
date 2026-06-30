# Migration Execution Plan

Technical planning document for implementing the PowerOf10-to-KonX
affiliate data migration. This document defines architecture, safety
mechanisms, and policies. **No execution code exists yet.**

Status: Planning only. Requires explicit approval before implementation.

---

## 1. Execution Phases

Migration execution runs in six sequential phases. Each phase must
complete successfully before the next can begin. Any phase can abort
without leaving partial state.

### Phase 1 — Preflight Checks

Automated checks that run before any data is touched.

| Check | Requirement | Blocks execution if failed |
|-------|-------------|---------------------------|
| WooCommerce active | `konx_affiliate_is_woocommerce_active()` | Yes |
| All 13 DB tables present | Schema v1.1.0+ verified | Yes |
| Dry run completed | `$state['dry_run']` exists | Yes |
| Migration plan approved | `$state['approved'] === true` | Yes |
| No existing migration lock | Transient `konx_migration_lock` absent | Yes |
| PHP memory | `memory_limit >= 256M` | Warning only |
| PHP execution time | `max_execution_time >= 300` or `0` | Warning only |
| Source records loaded | `$state['csv_records']` or DB connection valid | Yes |
| Admin user has `manage_konx_settings` | Capability check | Yes |

### Phase 2 — Backup / Pre-Migration Snapshot

Before writing any data, the system creates a restorable snapshot.

**Export tables to CSV files:**

```
wp-content/konx-migration-backups/{timestamp}/
  konx_affiliates.csv
  wp_users_snapshot.csv       (id, email, roles — for rollback reference)
  migration_state.json        (full state at time of execution)
```

**Store metadata:**

```php
update_option( 'konx_migration_backup', array(
    'timestamp'  => current_time( 'mysql', true ),
    'backup_dir' => $backup_path,
    'admin_id'   => get_current_user_id(),
    'dry_run'    => $state['dry_run'],       // snapshot of projections
    'records'    => count( $source_records ),
) );
```

The backup directory must be outside `uploads/` to avoid public access.
Use `.htaccess` deny-all protection.

### Phase 3 — Staged Import (Batched)

Records are imported in batches of 50 within database transactions.

**Per-batch sequence:**

1. Start transaction (`$wpdb->query('START TRANSACTION')`)
2. For each record in batch:
   a. Create WordPress user if needed (`wp_insert_user`)
   b. Assign affiliate role (`$user->set_role`)
   c. Create affiliate profile (`Konx_Affiliate_Manager::create_affiliate_profile`)
   d. Store `external_id` = `po10_{id}`
   e. Log to migration results table
3. Commit transaction
4. Update progress counter
5. Yield control (allow WP cron heartbeat)

**If any record in a batch fails:**
- Roll back the entire batch transaction
- Log the failure with row-level detail
- Continue to next batch (failed records are retried later or marked as skipped)

### Phase 4 — Sponsor Linking (Second Pass)

After all records are imported, resolve parent-child relationships.

1. Query all imported affiliates where `parent_referral_code` is set
2. For each, look up the parent affiliate by referral code
3. If found: set `parent_affiliate_id` on the child record
4. If not found: log as orphan (no parent assigned)

**Why a second pass?** Parent records may appear after children in the
source data. Importing everything first guarantees all potential parents
exist before linking.

### Phase 5 — Post-Migration Verification

Automated checks that run after import completes.

| Check | Method |
|-------|--------|
| Record count matches | Compare imported count vs. dry-run projection |
| No duplicate referral codes | `SELECT referral_code, COUNT(*) ... HAVING COUNT(*) > 1` |
| No duplicate user IDs | `SELECT user_id, COUNT(*) ... HAVING COUNT(*) > 1` |
| All external IDs populated | `WHERE external_id LIKE 'po10_%'` count matches |
| Sponsor links resolved | Count linked vs. orphan sponsors |
| No zero-balance anomalies | All new affiliates should have `cached_balance = 0.00` |
| WordPress roles assigned | All imported users have a `konx_*` role |

### Phase 6 — Audit Log Entry

A single summary record is written to `konx_audit_log`:

```php
array(
    'event_type'  => 'migration_executed',
    'object_type' => 'migration',
    'object_id'   => null,
    'actor_id'    => get_current_user_id(),
    'description' => 'Migration executed: {N} affiliates created, {M} users created, {K} sponsors linked',
    'old_value'   => null,
    'new_value'   => json_encode( $results_summary ),
)
```

---

## 2. Database Requirements

### New Table: `konx_migration_log`

Tracks every record processed during execution. Required for rollback
and post-migration audit.

```sql
CREATE TABLE {prefix}konx_migration_log (
    id             bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    migration_id   varchar(36)     NOT NULL,  -- UUID for the migration run
    batch_number   int unsigned    NOT NULL,
    source_id      varchar(50)     NOT NULL,  -- e.g. 'po10_1234'
    source_email   varchar(255)    NOT NULL,
    action         varchar(20)     NOT NULL,  -- 'created', 'skipped', 'failed', 'rolled_back'
    wp_user_id     bigint(20) unsigned DEFAULT NULL,
    affiliate_id   bigint(20) unsigned DEFAULT NULL,
    user_created   tinyint(1)      NOT NULL DEFAULT 0,
    error_message  text,
    created_at     datetime        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY  (id),
    KEY idx_migration_id (migration_id),
    KEY idx_source_id (source_id),
    KEY idx_action (action)
);
```

### Existing Table Modifications

No schema changes to existing tables. The `external_id` column on
`konx_affiliates` (added in schema v1.1.0) is sufficient for tracking
imported records.

---

## 3. Admin Permissions & Confirmation

### Required Capability

`manage_konx_settings` — same as all admin operations.

### Confirmation Requirements

1. **Dry run must be completed** — cannot execute without dry-run data
2. **Migration plan must be approved** — the approval step records user ID + timestamp
3. **Double confirmation at execution time:**
   - Checkbox: "I understand this will create WordPress users and affiliate records"
   - Checkbox: "I have verified the backup was created"
   - Text input: type "MIGRATE" to confirm
4. **Nonce verification** on the execution form
5. **Migration lock** — a 30-minute transient prevents concurrent executions

### Audit Trail

Every execution attempt (successful or not) is logged to `konx_audit_log`
with the acting user's ID, timestamp, and result.

---

## 4. Rollback Strategy

### Rollback Scope

Rollback removes all records created during a specific migration run,
identified by `migration_id`.

### Rollback Steps

1. Query `konx_migration_log` for all records with the target `migration_id`
2. For each record where `action = 'created'`:
   a. Delete the `konx_affiliates` row by `affiliate_id`
   b. If `user_created = 1`, delete the WordPress user (or reassign to subscriber)
   c. Remove any `konx_wallet_ledger` entries for that affiliate
   d. Update `konx_migration_log` action to `'rolled_back'`
3. Write audit log entry: `migration_rolled_back`
4. Clear migration status option

### Rollback Safety

- Rollback only touches records with matching `migration_id`
- Records that existed before migration are never affected
- Commissions, clicks, and conversions created after migration
  are NOT rolled back (they represent real activity)
- Rollback requires `manage_konx_settings` + confirmation checkbox

### Rollback Window

Rollback is available for 30 days after execution. After that, the
migration is considered permanent and rollback is disabled. The
migration log data is retained indefinitely for audit purposes.

---

## 5. Error Handling Strategy

### Record-Level Errors

| Error | Handling |
|-------|----------|
| Invalid email | Skip record, log to migration_log |
| Duplicate email (in source) | Skip second occurrence |
| Duplicate email (existing WP user) | Link to existing user, create affiliate |
| Duplicate referral code | Skip record, log error |
| Referral code too long (>50 chars) | Skip record, log error |
| Empty referral code | Skip record, log error |
| `wp_insert_user` fails | Skip record, log WP error message |
| `create_affiliate_profile` fails | Roll back user creation for this record, log error |

### Batch-Level Errors

| Error | Handling |
|-------|----------|
| Transaction fails | Roll back entire batch, log, continue to next |
| PHP timeout approaching | Save progress, allow resume from last completed batch |
| Memory exhaustion | Save progress, advise admin to increase `memory_limit` |

### System-Level Errors

| Error | Handling |
|-------|----------|
| Database connection lost | Abort, log, preserve progress for resume |
| Migration lock expired | Abort current batch, require manual restart |
| Plugin deactivated mid-migration | Progress saved, resume available on reactivation |

### Resume Capability

If execution is interrupted, it can resume from the last completed batch.
The `konx_migration_log` tracks which source IDs have been processed.
On resume, already-processed IDs are skipped.

---

## 6. Duplicate Handling Rules

### Email Duplicates

| Scenario | Action |
|----------|--------|
| Email appears twice in source CSV | Import first occurrence, skip second |
| Email matches existing WP user (no affiliate) | Link to existing user, create affiliate profile |
| Email matches existing WP user (with affiliate) | Skip entirely — record already migrated or manually created |
| Email matches WP user created in earlier batch | Skip — batch dedup catches this |

### Referral Code Duplicates

| Scenario | Action |
|----------|--------|
| Code appears twice in source CSV | Import first occurrence, skip second |
| Code matches existing KonX affiliate | Skip — cannot create duplicate codes |
| Code is empty | Skip record (referral code is required) |
| Code exceeds 50 characters | Skip record (schema limit) |

### External ID Dedup

Every imported record gets `external_id = 'po10_{source_id}'`. Before
creating, check if an affiliate with that external_id already exists.
This prevents re-importing on retry/resume.

---

## 7. Sponsor Hierarchy Handling

### Resolution Order

1. Look up `referrer_team_name` as a referral code in `konx_affiliates`
2. If not found, check if the sponsor was imported in this same migration
   (by referral code from the source data)
3. If still not found, leave `parent_affiliate_id` as NULL (orphan)

### Self-Referrals

If `referrer_team_name === team_name` (self-referral), set
`parent_affiliate_id = NULL`. Log as warning but do not skip the record.

### Circular References

The system only supports single-level parent linking. The schema does
not enforce hierarchy depth, but the migration engine does not attempt
to build multi-level chains. Each affiliate gets at most one parent.

### Orphan Policy

Orphan affiliates (no resolvable parent) are imported successfully with
`parent_affiliate_id = NULL`. They function normally — they just have
no upline sponsor. Orphan count is reported in post-migration verification.

---

## 8. User Creation Policy

### WordPress User Creation

| Field | Source | Fallback |
|-------|--------|----------|
| `user_login` | email (local part) | `po10_{id}` if login taken |
| `user_email` | `email` column | Required — skip if missing |
| `first_name` | `user_fname` | Empty string |
| `last_name` | `user_lname` | Empty string |
| `user_pass` | Random 24-char password | — |
| `role` | Mapped from `affiliate_type` | `konx_sales_agent` |

### Password Policy

Imported users receive a randomly generated password. They must use
WordPress "Lost your password?" to set their own. No passwords are
imported from the source system.

### Role Assignment

| Source Type | WordPress Role |
|-------------|---------------|
| `business` | `konx_business_affiliate` |
| `team_agent` | `konx_team_agent` |
| `marketing_agent` | `konx_marketing_agent` |
| `sales_agent` | `konx_sales_agent` |

### Existing Users

If a WordPress user with the same email already exists:
- Do NOT create a new user
- Do NOT change their existing password or role
- Create an affiliate profile linked to their existing `user_id`
- The affiliate role is added as an additional role (not replacement)

---

## 9. Affiliate Creation Policy

### Record Construction

| KonX Field | Source | Notes |
|------------|--------|-------|
| `user_id` | Created or matched WP user | Required |
| `affiliate_type` | Normalized from `promotional_title` | Default: `sales_agent` |
| `referral_code` | `team_name` | Unique, max 50 chars |
| `status` | `'active'` | All imports start active |
| `completed_sales` | `0` | Fresh start |
| `cached_balance` | `0.00` | No balance import |
| `parent_affiliate_id` | Resolved in second pass | NULL if orphan |
| `payment_email` | NULL | Affiliate sets this themselves |
| `external_id` | `'po10_{id}'` | Links back to source |
| `phone` | `user_phone` | Optional |
| `registered_at` | `created_at` from source | Preserves original date |

### What Is NOT Imported

- Balances (all start at $0.00)
- Commissions (no commission history from PO10)
- Withdrawals
- Wallet ledger entries
- Referral clicks / conversions
- Admin fees

### Activation Policy

All imported affiliates are set to `status = 'active'` immediately.
Business affiliates do not require manual approval (per client
requirements from Phase 23).

---

## 10. Post-Migration Verification Checklist

Manual and automated checks to run after execution completes.

### Automated (built into the execution engine)

- [ ] Total affiliates created matches dry-run projection
- [ ] Total WP users created matches projection
- [ ] No duplicate referral codes in `konx_affiliates`
- [ ] No duplicate `user_id` values in `konx_affiliates`
- [ ] All imported records have `external_id` starting with `po10_`
- [ ] Sponsor links count matches projection
- [ ] All new affiliates have `cached_balance = 0.00`
- [ ] All new affiliates have `status = 'active'`
- [ ] Migration log record count matches source record count

### Manual (admin review)

- [ ] Spot-check 5 random imported affiliates in admin panel
- [ ] Verify affiliate can log in and see dashboard
- [ ] Verify referral link works for an imported affiliate
- [ ] Check that existing (pre-migration) affiliates are unaffected
- [ ] Review the migration audit report export
- [ ] Confirm backup files exist and are non-empty
- [ ] Test rollback on staging before running on production

---

## 11. Execution Architecture

### AJAX-Based Batch Processing

Migration runs via WordPress AJAX to avoid PHP timeout issues.

```
Browser                        Server
  |                               |
  |-- POST /admin-ajax.php ------>|
  |   action: konx_run_batch     |
  |   batch: 1                   |-- Process 50 records
  |                               |-- Commit transaction
  |<-- JSON { done: 50,       ---|
  |          total: 2000,         |
  |          batch: 1 }           |
  |                               |
  |-- POST (batch: 2) ---------->|
  |   ...repeats...               |
```

### Progress Tracking

```php
update_option( 'konx_migration_progress', array(
    'migration_id' => $uuid,
    'status'       => 'running',       // running | completed | failed | rolled_back
    'total'        => 2000,
    'processed'    => 150,
    'created'      => 140,
    'skipped'      => 10,
    'failed'       => 0,
    'current_batch' => 3,
    'started_at'   => '2026-07-01 10:00:00',
    'updated_at'   => '2026-07-01 10:01:23',
) );
```

### Concurrency Prevention

- Set transient `konx_migration_lock` with 30-minute expiry on start
- Check lock before each batch
- Clear lock on completion or manual abort

---

## 12. Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Duplicate affiliates created | Low | High | External ID dedup + code uniqueness constraint |
| Existing affiliates overwritten | Very low | Critical | Check-before-create, never update existing records |
| PHP timeout during batch | Medium | Low | AJAX batching, resume capability |
| Memory exhaustion | Low | Low | 50-record batches, no full dataset in memory |
| Orphan sponsors cause confusion | Medium | Low | Logged and reported, affiliates still functional |
| Rollback misses dependent data | Low | Medium | Rollback only touches migration-created records |
| Concurrent execution | Very low | High | Transient lock prevents parallel runs |
| Backup files inaccessible | Low | Medium | Verify backup before allowing execution start |

---

## 13. Required Future Approvals

Before implementation begins, the following must be confirmed:

1. **Client approval** of the user creation policy (random passwords, no email notification)
2. **Client approval** of the activation policy (all active immediately)
3. **Decision** on whether to send welcome emails to imported users
4. **Decision** on rollback window duration (recommended: 30 days)
5. **Staging test** — full execution must succeed on staging before production
6. **Backup verification** — admin must confirm backup is restorable

---

## 14. Implementation Phases (Future)

| Phase | Scope | Estimated Complexity |
|-------|-------|---------------------|
| 27B | Migration log table + schema upgrade | Small |
| 27C | Backup/export engine | Medium |
| 27D | Batch execution engine (AJAX) | Large |
| 27E | Sponsor linking (second pass) | Small |
| 27F | Post-migration verification | Medium |
| 27G | Rollback engine | Medium |
| 27H | Execution UI (wizard step 15) | Medium |
| 27I | Staging test + production run | Operational |

Total estimated: 8 implementation phases.
