# KonX Affiliate Dashboard — Admin Affiliate Management

## Overview

The Affiliates admin page allows administrators to view, search,
filter, and manage all affiliate profiles. Admins can change types
(including assigning agent roles), update statuses, and activate
pending Business Affiliates after purchase verification.

## Menu Location

**KonX Affiliates > Affiliates**

Capability required: `manage_konx_affiliates`

## List View

### Columns

| Column | Source |
|---|---|
| ID | `wp_konx_affiliates.id` |
| Name | `wp_users.display_name` |
| Email | `wp_users.user_email` |
| Type | `affiliate_type` (formatted) |
| Status | `status` (color-coded) |
| Code | `referral_code` |
| Sales | `completed_sales` |
| Balance | `cached_balance` |
| Registered | `registered_at` |
| Actions | View button → detail page |

### Filters

- **Type**: All, Business, Referral, Team Agent, Marketing Agent, Sales Agent
- **Status**: All, Active, Pending, Suspended, Inactive
- **Search**: name, email, or referral code

### Pagination

20 affiliates per page with standard WordPress pagination links.

## Detail View

Accessed by clicking "View" on any affiliate in the list. Shows:

### Profile Panel

- Affiliate ID, WordPress user info
- Referral code and full referral link
- Payment email, registration date
- Referred-by (parent affiliate ID)

### Manage Panel

Editable form with:
- **Affiliate Type** dropdown (all 5 types including agent roles)
- **Status** dropdown (active, pending, suspended, inactive)
- **Notes** textarea
- Save Changes button (with nonce)

### Stats Row (6 cards)

- Total Sales
- Lifetime Earnings
- Balance
- Withdrawn
- Milestones achieved
- Admin Fee status (OK or outstanding amount)

### Recent Activity

- Recent 5 commissions (date, product, amount, status)
- Recent 5 withdrawals (date, amount, status)

## Admin Workflows

### Activating a Pending Business Affiliate

1. Business Affiliate registers → status set to `pending`
2. Admin verifies pack purchase (Starter, Pro, or eCard Pack)
3. Admin navigates to Affiliates > [affiliate] detail
4. Changes Status from `pending` to `active`
5. Clicks Save Changes
6. Affiliate can now earn commissions

### Assigning an Agent Role

1. Navigate to affiliate detail
2. Change Type dropdown to Team Agent / Marketing Agent / Sales Agent
3. Click Save Changes
4. `Konx_Affiliate_Manager::update_affiliate_type()`:
   - Updates `wp_konx_affiliates.affiliate_type`
   - Updates `konx_affiliate_type` user meta
   - Removes old WordPress role
   - Adds new WordPress role
   - Logs to audit log

### Suspending an Affiliate

1. Change Status to `suspended`
2. Save Changes
3. Commission engine checks `status === 'active'` → suspended affiliates don't earn
4. Affiliate dashboard shows suspended status

### Notes

Admin can add internal notes visible only in the admin panel. Notes
are saved to `wp_konx_affiliates.notes` on every Save.

## Role/Type Synchronization

All type changes go through `Konx_Affiliate_Manager::update_affiliate_type()`:

```
1. Validate new type against whitelist
2. Update wp_konx_affiliates.affiliate_type
3. Update user meta: konx_affiliate_type
4. Remove old WordPress role via $user->remove_role()
5. Add new WordPress role via $user->add_role()
6. Log old_type -> new_type to audit log
```

This ensures the custom table, user meta, and WordPress role are
always in sync. No other code path modifies affiliate type.

## Status Transitions

All transitions go through `Konx_Affiliate_Manager::update_affiliate_status()`:

| Transition | Common Scenario |
|---|---|
| pending → active | Business Affiliate pack purchase confirmed |
| active → suspended | Admin investigation or policy violation |
| active → inactive | Affiliate leaves the program |
| suspended → active | Investigation cleared |
| inactive → active | Affiliate rejoins |

Any status can transition to any other status. The commission engine
only awards commissions when status is `active`.

## Security

| Check | Implementation |
|---|---|
| Capability | `manage_konx_affiliates` on page and handler |
| Nonce | Per-affiliate: `konx_update_affiliate_{id}` |
| Input sanitization | `sanitize_text_field`, `sanitize_textarea_field`, `absint` |
| Output escaping | `esc_html`, `esc_attr`, `esc_url`, `esc_textarea` |
| SQL | `$wpdb->prepare()` for all queries, `$wpdb->esc_like()` for search |
| Type validation | Via `Konx_Affiliate_Manager::update_affiliate_type()` whitelist |
| Status validation | Via `Konx_Affiliate_Manager::update_affiliate_status()` whitelist |

## Testing Checklist

### List View

- [ ] All affiliates displayed with correct data
- [ ] Filter by type works (each type)
- [ ] Filter by status works (each status)
- [ ] Search by name works
- [ ] Search by email works
- [ ] Search by referral code works
- [ ] Pagination works
- [ ] View button navigates to detail page

### Detail View

- [ ] Profile info displayed correctly
- [ ] Referral code and link shown
- [ ] Stats cards show correct values
- [ ] Recent commissions table populated
- [ ] Recent withdrawals table populated
- [ ] Admin fee status shown (OK or outstanding)
- [ ] Back to List button works

### Type Change

- [ ] Change from Referral to Business → type updated, role swapped
- [ ] Change from Business to Team Agent → agent role assigned
- [ ] Change to Marketing Agent → correct role
- [ ] Change to Sales Agent → correct role
- [ ] Type change logged to audit log
- [ ] User meta synced after type change
- [ ] WordPress role synced after type change

### Status Change

- [ ] Pending → Active works
- [ ] Active → Suspended works
- [ ] Active → Inactive works
- [ ] Suspended → Active works
- [ ] Status change logged to audit log
- [ ] Suspended affiliate does not earn commissions

### Notes

- [ ] Notes saved on submit
- [ ] Notes displayed in textarea on reload
- [ ] Notes preserved when changing type/status

### Security

- [ ] Unauthorized user cannot access list page
- [ ] Unauthorized user cannot access detail page
- [ ] Unauthorized user cannot submit update form
- [ ] Invalid nonce rejected
- [ ] Invalid type rejected by Konx_Affiliate_Manager
- [ ] Invalid status rejected by Konx_Affiliate_Manager
