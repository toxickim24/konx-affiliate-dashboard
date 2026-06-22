# KonX Affiliate Dashboard — Affiliate Registration

## Shortcode

```
[konx_affiliate_register]
```

Place on any WordPress page. Renders a registration form for new
affiliates. Compatible with Elementor.

## Form Fields

| Field | Required | Logged-Out | Logged-In |
|---|---|---|---|
| First Name | Yes | Text input | Hidden (from profile) |
| Last Name | Yes | Text input | Hidden (from profile) |
| Email | Yes | Text input | Hidden (from profile) |
| Password | Yes (new users) | Text input | Not shown |
| Affiliate Type | Yes | Dropdown | Dropdown |
| Wise Email | No | Text input | Text input |
| Terms Acceptance | Yes | Checkbox | Checkbox |
| Referral Code | Auto | Hidden (from URL/cookie) | Hidden |

## Registration Types

Only two types are available for self-registration:

| Type | Value | Initial Status | Notes |
|---|---|---|---|
| Referral Affiliate | `referral` | `active` | Immediately active |
| Business Affiliate | `business` | `pending` | Awaiting pack purchase confirmation |

Agent types (Team Agent, Marketing Agent, Sales Agent) are
**admin-assigned only** and do not appear in the registration form.

## Logged-Out Flow

```
1. Visitor sees full registration form
2. Fills in name, email, password, type, optional Wise email
3. Accepts terms and submits
4. System:
   a. Validates all fields
   b. Checks email not already registered (email_exists)
   c. Creates WordPress user via wp_create_user()
   d. Sets first/last name and display name
   e. Creates affiliate profile via Konx_Affiliate_Manager
   f. Assigns WordPress affiliate role
   g. If referral code present: sets parent_affiliate_id
   h. Business type: sets status to 'pending'
   i. Creates initial admin fee for current month
   j. Sends admin notification email
   k. Sends user confirmation email
   l. Logs user in automatically
   m. Redirects back with success message
```

## Logged-In Flow

```
1. Logged-in user sees abbreviated form (no name/email/password)
2. Selects type, optional Wise email
3. Accepts terms and submits
4. System:
   a. Checks user doesn't already have an affiliate profile
   b. Creates affiliate profile for existing user
   c. Same steps f–m as logged-out flow
```

## Business Affiliate Pending Status

Business Affiliates are created with `status = 'pending'` because
they require a pack purchase (Starter Pack, Pro Pack, or eCard Pack)
before activation.

While pending:
- The affiliate profile exists
- Referral code is generated
- But commissions are **not earned** (commission engine checks `active` status)
- Dashboard shows the pending status

Activation to `active` happens when:
- Admin manually changes status after confirming pack purchase, OR
- Future automation links WooCommerce product purchase to status change

## Referral Code Capture

The registration form captures the referral code from:
1. `?ref=CODE` in the current URL
2. `konx_ref` cookie (set by the referral tracker)

If found, it's stored as a hidden field and used to set the
`parent_affiliate_id` on the new affiliate record.

## Initial Admin Fee

After successful profile creation, the system creates an admin fee
record for the current month via `Konx_Admin_Fees::create_monthly_fee()`.

- Period: current month (e.g., `2026-07`)
- Due date: last day of the current month
- Amount: auto-calculated from settings based on affiliate type

## Email Notifications

### Admin Notification

Sent to `get_option('admin_email')` with:
- Affiliate name and email
- Affiliate type
- Affiliate ID
- Link to admin dashboard

### User Confirmation

Sent to the new affiliate with:
- Welcome message
- Affiliate type
- Status note (active or pending)
- Link to the site

## Security

| Check | Implementation |
|---|---|
| Nonce | `konx_affiliate_register` on form |
| Input sanitization | `sanitize_text_field`, `sanitize_email` |
| Password | Not sanitized (intentional — preserves special chars) |
| Password minimum | 8 characters enforced |
| Email validation | `is_email()` check |
| Duplicate email | `email_exists()` check |
| Duplicate profile | `get_affiliate_by_user()` check |
| Output escaping | `esc_html`, `esc_attr`, `esc_url` throughout |
| Type validation | Checked against `$registration_types` whitelist |
| CSS scoped | `.konx-registration` wrapper |
| CSS loaded conditionally | `has_shortcode()` check |

## Testing Checklist

### Logged-Out Registration

- [ ] Full form displayed with all fields
- [ ] Valid submission creates WordPress user
- [ ] User gets first/last name and display name set
- [ ] Affiliate profile created with correct type
- [ ] Referral Affiliate: status = `active`
- [ ] Business Affiliate: status = `pending`
- [ ] Referral code generated and stored
- [ ] WordPress role assigned correctly
- [ ] User logged in automatically after registration
- [ ] Success message displayed on redirect

### Logged-In Registration

- [ ] Abbreviated form (no name/email/password fields)
- [ ] Affiliate profile created for existing user
- [ ] Existing WordPress role preserved (add_role, not set_role)
- [ ] Duplicate submission blocked with error message

### Validation

- [ ] Missing first name -> error
- [ ] Missing email -> error
- [ ] Invalid email -> error
- [ ] Password < 8 chars -> error
- [ ] Email already exists -> error with login suggestion
- [ ] Terms not accepted -> error
- [ ] Invalid affiliate type -> error

### Referral Code Capture

- [ ] URL `?ref=CODE` -> stored in hidden field
- [ ] Cookie `konx_ref` -> stored in hidden field
- [ ] Valid code -> parent_affiliate_id set on profile
- [ ] Invalid code -> parent_affiliate_id stays null (no error)

### Admin Fee

- [ ] Admin fee created for current month after registration
- [ ] Fee period matches current month
- [ ] Fee due date is last day of current month

### Notifications

- [ ] Admin email sent on registration
- [ ] Admin email contains affiliate name, type, and ID
- [ ] User confirmation email sent
- [ ] User email contains type and status note

### Already Registered

- [ ] Logged-in affiliate sees "already registered" message
- [ ] No form displayed for existing affiliates

### Security

- [ ] Form submission without nonce -> rejected
- [ ] Direct POST to handler without form -> rejected
- [ ] XSS attempt in name field -> sanitized
- [ ] SQL injection in email field -> sanitized
