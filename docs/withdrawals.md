# KonX Affiliate Dashboard — Withdrawals

## Overview

Affiliates can request withdrawals from their wallet balance. Payments
are sent manually via Wise by the admin. The wallet is debited only when
the admin marks the withdrawal as completed (confirming the Wise payment
was sent).

## Withdrawal Lifecycle

```
Affiliate submits request
    |
    v
PENDING
    |
    +-- Admin approves --> APPROVED
    |     |
    |     +-- Admin pays via Wise
    |     |     |
    |     |     +-- Admin marks completed --> COMPLETED (wallet debited)
    |     |
    |     +-- Admin rejects (reason required) --> REJECTED
    |     +-- Admin cancels --> CANCELLED
    |
    +-- Admin rejects (reason required) --> REJECTED
    +-- Admin cancels --> CANCELLED
```

### Status Definitions

| Status | Meaning | Wallet Impact |
|---|---|---|
| `pending` | Submitted by affiliate, awaiting review | None |
| `approved` | Admin approved, Wise payment in progress | None |
| `rejected` | Admin rejected (reason visible to affiliate) | None |
| `cancelled` | Admin cancelled the request | None |
| `completed` | Admin paid via Wise and confirmed | **Wallet debited** |

## Wise Workflow

1. Affiliate submits withdrawal request with:
   - Amount (must meet minimum, must not exceed balance)
   - Wise email address
   - Account holder name (optional)
   - Currency (default: USD)

2. Admin reviews the request in **KonX Affiliates > Withdrawals**.

3. Admin approves the request (optional step — can skip to complete).

4. Admin sends payment via Wise externally.

5. Admin enters the Wise transaction reference and clicks "Complete".

6. System re-validates the affiliate's balance, debits the wallet,
   and records the transaction reference.

## Wallet Debit Timing

The wallet is **never debited** when a request is created, approved,
or rejected. The debit occurs **only** on completion.

```
create_request()   -> No wallet change
approve_request()  -> No wallet change
reject_request()   -> No wallet change
cancel_request()   -> No wallet change
complete_request() -> Konx_Wallet::debit() called
```

### Why?

Between request creation and admin completion, the balance can change:
- New commissions may increase it
- Commission reversals (refunds) may decrease it
- Another withdrawal may complete (reducing available balance)

By debiting only on completion, the system reflects the actual moment
money leaves the wallet.

## Balance Re-Validation

Before completing a withdrawal, `complete_request()`:

1. Reads the authoritative balance: `Konx_Wallet::get_available_balance()`
2. Compares it to the withdrawal amount
3. If balance < amount:
   - Blocks completion
   - Returns WP_Error with current balance vs requested amount
   - Logs `withdrawal_completion_failed` to audit log
   - Admin sees an error message with the amounts

This prevents the wallet from going negative due to balance changes
between request submission and admin completion.

## Request Creation Rules

| Rule | Enforcement |
|---|---|
| Minimum amount | Configurable via `konx_affiliate_settings['min_withdrawal']`, default $50 |
| Balance check | `Konx_Wallet::get_available_balance()` must be >= amount |
| One pending request | `get_user_pending_request()` blocks if pending/approved exists |
| Valid Wise email | `is_email()` validation |
| Valid affiliate | `Konx_Affiliate_Manager::get_affiliate()` must return a record |

## Security

| Aspect | Implementation |
|---|---|
| Admin capability | `manage_konx_withdrawals` required for all admin actions |
| Nonces | Per-request nonces for GET actions, form nonce for POST (complete) |
| Input sanitization | `absint()`, `sanitize_text_field()`, `sanitize_email()`, `sanitize_textarea_field()` |
| Output escaping | `esc_html()`, `esc_attr()`, `esc_url()`, `esc_js()` |
| SQL injection | `$wpdb->prepare()` on all queries, `$wpdb->esc_like()` for search |
| Wise details | Stored in DB, only visible on admin page (not exposed to frontend) |
| Affiliate privacy | Affiliates can only view their own requests (enforced in queries) |
| Status transitions | Only pending/approved requests can be completed/rejected/cancelled |

## Admin Page

Located at: **KonX Affiliates > Withdrawals**

### Features

- Filter by status (pending, approved, completed, rejected, cancelled)
- Search by affiliate name, email, or referral code
- Pagination
- Each row shows: amount, current wallet balance, Wise email, status, dates
- Action buttons per request (context-sensitive by status):
  - **Approve** — moves to approved (pending only)
  - **Complete** — requires Wise reference, confirms via dialog, debits wallet
  - **Reject** — prompts for reason
  - **Cancel** — confirms via dialog
- Completed requests show Wise transaction reference

### Complete Action

The "Complete" button submits a POST form with the Wise transaction
reference. It uses a separate nonce from the GET actions. Before
submission, a JavaScript `confirm()` dialog warns the admin that the
wallet will be debited.

## Testing Checklist

### Request Creation

- [ ] Create request with valid amount and email -> request created, status `pending`
- [ ] Create request with amount > balance -> WP_Error `insufficient_balance`
- [ ] Create request with amount < minimum -> WP_Error `below_minimum`
- [ ] Create request with invalid email -> WP_Error `invalid_email`
- [ ] Create request when pending request exists -> WP_Error `existing_request`
- [ ] Create request for non-existent affiliate -> WP_Error `invalid_affiliate`

### Status Transitions

- [ ] Pending -> approved -> works
- [ ] Pending -> rejected (with reason) -> works
- [ ] Pending -> cancelled -> works
- [ ] Approved -> completed (with Wise ref) -> works, wallet debited
- [ ] Approved -> rejected -> works
- [ ] Approved -> cancelled -> works
- [ ] Completed -> cannot transition further
- [ ] Rejected -> cannot transition further
- [ ] Cancelled -> cannot transition further
- [ ] Reject without reason -> WP_Error `reason_required`

### Wallet Integration

- [ ] Completion debits wallet via `Konx_Wallet::debit()`
- [ ] Ledger entry type = `withdrawal`
- [ ] Ledger reference = `withdrawal` with request ID
- [ ] `ledger_entry_id` stored on withdrawal record
- [ ] Balance re-validated before debit
- [ ] Insufficient balance blocks completion with error message
- [ ] Failed completion logged to audit

### Balance Re-Validation

- [ ] Request $100, balance is $150, complete -> success (balance $50)
- [ ] Request $100, balance drops to $80 before completion -> blocked
- [ ] Request $100, balance drops to $100 exactly -> success (balance $0)

### One Pending Request Rule

- [ ] Submit first request -> success
- [ ] Submit second while first is pending -> blocked
- [ ] Submit second while first is approved -> blocked
- [ ] First request completed, submit new -> success
- [ ] First request rejected, submit new -> success
- [ ] First request cancelled, submit new -> success

### Admin Page

- [ ] Filter by status works
- [ ] Search by affiliate name works
- [ ] Search by email works
- [ ] Pagination works
- [ ] Approve button visible for pending requests only
- [ ] Complete form visible for pending and approved requests
- [ ] Reject/Cancel visible for pending and approved requests
- [ ] Completed requests show Wise transaction reference
- [ ] Unauthorized user cannot access page

### Audit Trail

- [ ] Request created -> audit log entry
- [ ] Approved -> audit log entry
- [ ] Rejected -> audit log entry
- [ ] Cancelled -> audit log entry
- [ ] Completed -> audit log entry
- [ ] Completion blocked (balance) -> audit log entry
- [ ] Wallet debit failed -> audit log entry

### Wise Details

- [ ] Payment email stored on request
- [ ] Account holder stored in admin_note
- [ ] Currency stored in admin_note
- [ ] Affiliate notes stored in admin_note
- [ ] Wise details not exposed outside admin
