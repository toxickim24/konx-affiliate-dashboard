# KonX Affiliates REST API

## Endpoint

```
POST /wp-json/konx-affiliates/v1/users
```

Creates a WordPress user and KonX affiliate profile. Designed for
PowerOf10 to push new affiliate registrations to WordPress.

## Authentication

Every request must include an API key via the `X-KONX-API-Key` header.

```
X-KONX-API-Key: konx_aBcDeFgHiJkLmNoPqRsTuVwXyZ1234567890abcd
```

API keys are generated in the KonX Affiliates admin. The plaintext key
is shown once at creation. Only a SHA-256 hash is stored.

## Request Payload

```json
{
  "external_id": "po10_2305",
  "email": "user@example.com",
  "first_name": "John",
  "last_name": "Doe",
  "affiliate_type": "sales_agent",
  "referral_code": "MyTeamName",
  "password": "optional_plaintext",
  "parent_referral_code": "SponsorTeamName",
  "phone": "+1-555-0123",
  "source": "powerof10",
  "country": "US",
  "status": "active"
}
```

### Required Fields

| Field | Type | Description |
|-------|------|-------------|
| external_id | string | Unique ID from the source system (e.g. `po10_2305`) |
| email | string | Valid email address |
| first_name | string | First name |
| last_name | string | Last name |
| affiliate_type | string | One of: `business`, `team_agent`, `marketing_agent`, `sales_agent` |
| referral_code | string | Unique referral/team code (max 50 chars) |

### Optional Fields

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| password | string | auto-generated | WordPress account password |
| parent_referral_code | string | — | Sponsor's referral code |
| phone | string | — | Phone number (max 30 chars) |
| source | string | — | Origin system identifier |
| country | string | — | Country code |
| status | string | `active` | One of: `active`, `pending`, `suspended`, `inactive` |

### Type Normalization

The endpoint automatically normalizes common type variants:

| Input | Normalized To |
|-------|--------------|
| `salesagent` | `sales_agent` |
| `teamagent` | `team_agent` |
| `marketingagent` | `marketing_agent` |
| `business_affiliate` | `business` |

## Responses

### 201 Created — New affiliate

```json
{
  "success": true,
  "created": true,
  "user_id": 2400,
  "affiliate_id": 1925,
  "external_id": "po10_2305",
  "referral_code": "MyTeamName",
  "affiliate_type": "sales_agent",
  "status": "active",
  "parent_resolved": true,
  "warnings": []
}

```

### 200 OK — Already exists (idempotent)

```json
{
  "success": true,
  "created": false,
  "reason": "external_id_exists",
  "user_id": 2400,
  "affiliate_id": 1925,
  "external_id": "po10_2305",
  "referral_code": "MyTeamName"
}
```

### 401 Unauthorized

```json
{
  "code": "missing_api_key",
  "message": "API key is required. Send it via the X-KONX-API-Key header.",
  "data": { "status": 401 }
}
```

### 400 Bad Request

```json
{
  "code": "invalid_payload",
  "message": "Missing required fields: external_id, referral_code",
  "data": { "status": 400 }
}
```

### 409 Conflict

```json
{
  "code": "referral_code_conflict",
  "message": "This referral code is already in use.",
  "data": { "status": 409 }
}
```

### 422 Unprocessable

```json
{
  "code": "invalid_affiliate_type",
  "message": "Invalid affiliate type: \"unknown\". Valid types: business, team_agent, marketing_agent, sales_agent",
  "data": { "status": 422 }
}
```

## Idempotency

The endpoint is safe to call multiple times with the same data:

1. If `external_id` matches an existing affiliate, returns it without changes.
2. If `email` matches a WordPress user who already has a KonX affiliate
   profile, returns the existing profile.
3. If `email` matches a WordPress user without a profile, creates the
   affiliate profile only (no duplicate WP user).
4. If nothing matches, creates both the WP user and affiliate profile.

## Sponsor Resolution

If `parent_referral_code` is provided, the endpoint looks up the
sponsor by referral code. If not found, the affiliate is still created
with `parent_affiliate_id = null` and a `parent_not_found` warning is
included in the response.

## Security

- No unauthenticated access (API key required on every request).
- API keys stored as SHA-256 hashes — plaintext never persisted.
- Passwords are never logged or returned in responses.
- All inputs are sanitized via WordPress sanitization functions.
- All database queries use `$wpdb->prepare()`.
- Client IP is hashed before logging (privacy-safe).
- New user notification emails are suppressed during API creation.

## PowerOf10 Integration

After the migration wizard imports existing users, configure PowerOf10
to call this endpoint instead of the old theme endpoint:

**Old (insecure, no auth):**
```
POST https://konx.world/wp-json/konx/v1/create_and_approve_user
```

**New (authenticated):**
```
POST https://konx.world/wp-json/konx-affiliates/v1/users
```

### PowerOf10 Changes Required

In `FrontController::showPaymentPage()` and
`AdminController::saveAffiliate()`, replace the HTTP calls with:

```php
$response = Http::withHeaders([
    'X-KONX-API-Key' => config('services.konx.api_key'),
])->post(config('services.konx.api_url') . '/users', [
    'external_id'         => 'po10_' . $user->id,
    'email'               => $user->email,
    'first_name'          => ucwords($user->user_fname),
    'last_name'           => ucwords($user->user_lname),
    'affiliate_type'      => $user->promotional_title,
    'referral_code'       => $user->team_name,
    'parent_referral_code' => $user->referrer_team_name,
    'phone'               => $user->user_phone,
    'source'              => 'powerof10',
    'country'             => $user->country_code,
]);
```

## Testing with curl

### 1. Missing API key (expect 401)
```bash
curl -s -X POST http://127.0.0.1/konx.world/wp-json/konx-affiliates/v1/users \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com"}'
```

### 2. Invalid API key (expect 401)
```bash
curl -s -X POST http://127.0.0.1/konx.world/wp-json/konx-affiliates/v1/users \
  -H "Content-Type: application/json" \
  -H "X-KONX-API-Key: invalid_key" \
  -d '{"email":"test@example.com"}'
```

### 3. Valid creation (expect 201)
```bash
curl -s -X POST http://127.0.0.1/konx.world/wp-json/konx-affiliates/v1/users \
  -H "Content-Type: application/json" \
  -H "X-KONX-API-Key: YOUR_KEY_HERE" \
  -d '{
    "external_id": "po10_99999",
    "email": "apitest@example.com",
    "first_name": "API",
    "last_name": "Test",
    "affiliate_type": "sales_agent",
    "referral_code": "ApiTestCode"
  }'
```

### 4. Duplicate external_id (expect 200, created=false)
```bash
# Run the same curl as test 3 again
```

### 5. Invalid affiliate type (expect 422)
```bash
curl -s -X POST http://127.0.0.1/konx.world/wp-json/konx-affiliates/v1/users \
  -H "Content-Type: application/json" \
  -H "X-KONX-API-Key: YOUR_KEY_HERE" \
  -d '{
    "external_id": "po10_88888",
    "email": "badtype@example.com",
    "first_name": "Bad",
    "last_name": "Type",
    "affiliate_type": "unknown_type",
    "referral_code": "BadTypeCode"
  }'
```
