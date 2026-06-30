# CSV Field Mapping

The Migration Wizard auto-detects how CSV columns map to KonX fields
and displays the mapping for administrator review before proceeding.

## Auto-Detection

When a CSV is uploaded, the field mapper reads the header row and
matches each column name against a library of known aliases.

### Confidence Levels

| Level | Meaning | Example |
|-------|---------|---------|
| Exact | Column name matches target field exactly | `email` → Email |
| Alias | A recognized alternative name was matched | `firstname` → First Name |
| None | No match found — column is unmapped | `custom_field` → (unmapped) |

### Alias Library

Each KonX field accepts multiple CSV column names:

| KonX Field | Accepted CSV Columns |
|-----------|---------------------|
| ID | `id`, `user_id`, `userid`, `po10_id`, `powerof10_id` |
| Email | `email`, `email_address`, `emailaddress`, `user_email`, `e-mail` |
| First Name | `user_fname`, `first_name`, `firstname`, `fname`, `given_name` |
| Last Name | `user_lname`, `last_name`, `lastname`, `lname`, `surname`, `family_name` |
| Affiliate Type | `promotional_title`, `affiliate_type`, `affiliatetype`, `type`, `role` |
| Team Name | `team_name`, `teamname`, `team`, `referral_code`, `referralcode`, `code`, `coupon_code` |
| Sponsor | `referrer_team_name`, `sponsor`, `sponsor_name`, `referrer`, `upline`, `parent` |
| Phone | `user_phone`, `phone`, `phone_number`, `mobile`, `tel` |
| Source | `source`, `origin` |
| Country | `country_code`, `country`, `countrycode` |
| Created Date | `created_at`, `created`, `registered_at`, `registration_date`, `join_date` |

## Required Fields

These fields must be mapped for migration to proceed:

1. ID
2. Email
3. First Name
4. Last Name
5. Affiliate Type
6. Team Name

## Validation Rules

| Rule | Severity |
|------|----------|
| All required fields mapped | Error if missing |
| No duplicate target fields | Error if duplicated |
| Unmapped columns present | Warning (ignored during import) |

## Storage

Field mappings are stored in `konx_migration_state['field_mappings']`
as an array of mapping objects. No data is written to production
tables.

## Integration

The field mapping step appears after CSV upload and before the Health
Check. When all required fields are mapped, the Continue button
becomes active.
