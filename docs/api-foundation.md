# API Foundation — Schema & Infrastructure (v1.1.0)

## Overview

Database version 1.1.0 prepares KonX Affiliates for PowerOf10 integration
by widening the referral code column, adding external ID and phone fields,
creating API key infrastructure, and removing the deprecated referral
affiliate type.

## Schema Changes

### Modified Tables

**wp_konx_affiliates**
- `referral_code` widened from `varchar(12)` to `varchar(50)` — supports
  imported PowerOf10 team names (up to 35 chars observed).
- `affiliate_type` default changed from `'referral'` to `'sales_agent'`.
- Added `external_id varchar(50) DEFAULT NULL` — stores PowerOf10 user ID
  in format `po10_{id}`. Indexed for fast idempotency lookups.
- Added `phone varchar(30) DEFAULT NULL` — imported phone numbers.

**wp_konx_referral_clicks**
- `referral_code` widened from `varchar(12)` to `varchar(50)`.

**wp_konx_referral_conversions**
- `referral_code` widened from `varchar(12)` to `varchar(50)`.

### New Tables

**wp_konx_api_keys**
Stores SHA-256 hashed API keys for REST endpoint authentication.

| Column | Type | Purpose |
|--------|------|---------|
| id | bigint PK | Auto-increment |
| key_name | varchar(100) | Human-readable label |
| key_hash | varchar(64) UNIQUE | SHA-256 hash of plaintext key |
| key_prefix | varchar(8) | First 8 chars for identification |
| permissions | varchar(50) | Permission level (default: read_write) |
| created_by | bigint | WordPress user ID |
| last_used_at | datetime | Updated on each API call |
| created_at | datetime | Creation timestamp |
| revoked_at | datetime | NULL if active, timestamp if revoked |

**wp_konx_api_log**
Privacy-safe API request audit log.

| Column | Type | Purpose |
|--------|------|---------|
| id | bigint PK | Auto-increment |
| api_key_id | bigint | Which key was used (NULL if unauthenticated) |
| endpoint | varchar(100) | REST route path |
| request_method | varchar(10) | HTTP method |
| request_ip_hash | varchar(64) | SHA-256 hashed IP (privacy-safe) |
| response_code | smallint | HTTP response code |
| error_message | varchar(500) | Error details if request failed |
| created_at | datetime | Request timestamp |

## Upgrade Flow

1. Plugin detects `konx_affiliate_db_version` != `1.1.0` on `plugins_loaded`.
2. `Konx_Install::maybe_upgrade('1.0.0')` is called.
3. `upgrade_to_110()` runs data migrations:
   - Deactivates referral affiliate commission rules (`is_active = 0`).
   - Updates cookie duration from 30 to 90 days (if still at default).
4. `create_tables()` runs `dbDelta()` which:
   - Widens varchar columns (non-destructive).
   - Adds new nullable columns.
   - Creates new tables.
5. `konx_affiliate_db_version` option updated to `1.1.0`.

The upgrade is idempotent — safe to run multiple times.

## API Key Architecture

### Security Model

- Plaintext keys are generated once and shown to the admin exactly once.
- Only the SHA-256 hash is stored in the database.
- Keys use the prefix `konx_` followed by 40 random alphanumeric characters.
- The first 8 characters are stored as `key_prefix` for identification
  without exposing the full key.

### Key Lifecycle

1. Admin generates key via Settings page (future UI).
2. `Konx_Api_Helper::generate_key()` returns plaintext + stores hash.
3. PowerOf10 stores the plaintext key in its `.env` file.
4. On each API request, the key is sent via `X-KONX-API-Key` header.
5. `Konx_Api_Helper::validate_key()` hashes and looks up the key.
6. `last_used_at` is updated on successful validation.
7. Admin can revoke keys via `Konx_Api_Helper::revoke_key()`.

### Request Logging

Every API request is logged to `wp_konx_api_log` with:
- Which key was used (or NULL if unauthenticated attempt).
- The endpoint and HTTP method.
- A SHA-256 hash of the client IP (not the raw IP).
- The HTTP response code and any error message.

## Referral Type Removal

The `referral` affiliate type is removed from:
- `Konx_Affiliate_Manager::$valid_types`
- `Konx_Roles::$affiliate_roles` and mapping methods
- `Konx_Settings_Page::$affiliate_types`
- `Konx_Affiliates_Page` filter and edit dropdowns
- `Konx_Registration::$registration_types`
- `Konx_Dashboard::format_type()` labels
- Commission rule seeds

Existing `referral` affiliates in the database are preserved. Their
commission rules are deactivated (not deleted). The `referral` value
remains valid in the `affiliate_type_at_sale` column on historical
commission records.

## Cookie Duration

Default referral cookie duration changed from 30 days to 90 days.
Updated in:
- `Konx_Referral_Tracker::DEFAULT_COOKIE_DAYS` constant
- `Konx_Settings_Page` render, save, and getter methods

The upgrade routine updates the stored option for existing installations
that were still using the 30-day default.

## Future Endpoint Integration

The API helper class (`Konx_Api_Helper`) provides all infrastructure
needed for the REST API endpoint (Phase 24C-2):

- `generate_key()` — create keys from admin UI
- `validate_key()` — authenticate incoming requests
- `get_key_from_request()` — extract key from REST request header
- `log_request()` — audit every API call
- `revoke_key()` — disable compromised keys

The REST endpoint will use these methods for authentication and logging
without duplicating any security logic.
