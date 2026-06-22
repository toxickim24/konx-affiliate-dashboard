# KonX Affiliate Dashboard — Production Readiness Audit

**Date:** 2026-06-23
**Version:** 1.1.0
**Auditor:** Architecture Review

---

## Section 14 — Admin Workflow Audit

### Can an admin manage the entire affiliate program without touching the database?

**Verdict: Partially. Critical gaps exist.**

| Workflow | Supported | Notes |
|---|---|---|
| View all affiliates | Yes | List + detail view |
| Change affiliate type | Yes | All 5 types via dropdown |
| Change affiliate status | Yes | Active/pending/suspended/inactive |
| Approve pending Business Affiliate | Yes | Change status to active |
| View commissions | Yes | Reports page |
| View withdrawals | Yes | Withdrawals page |
| Approve/complete/reject withdrawals | Yes | Full lifecycle |
| Manage admin fees | Yes | Create, mark paid/overdue/waived |
| Map products | Yes | AJAX product search |
| Configure rates | Yes | Settings page matrix |

### Missing Admin Features

| Feature | Why It Matters | Priority |
|---|---|---|
| **Manual affiliate creation from admin** | Admin cannot create affiliates for users — must use frontend registration form or direct code. Needed for onboarding existing partners. | **Critical** |
| **Manual balance adjustment UI** | Wallet class supports `adjustment` entries but no admin form exists. Admin cannot credit/debit without code. Needed for dispute resolution. | **Critical** |
| **Release blocked commissions** | When admin marks fee as paid, blocked commissions are NOT auto-released. No UI to release them manually. Money gets stuck. | **Critical** |
| **Manual commission creation** | Admin cannot create ad-hoc commissions (e.g., bonus, correction). Only system-generated commissions exist. | **High** |
| **Affiliate profile editing** | Admin cannot edit payment email, referral code, or notes inline on the detail page. Limited edit form. | **High** |
| **Bulk actions on affiliate list** | Cannot bulk activate, suspend, or change type for multiple affiliates. Must edit one at a time. | **Medium** |
| **CSV affiliate import** | No way to import existing affiliates from a spreadsheet. | **Medium** |
| **Referral code reset** | Admin cannot regenerate an affiliate's referral code. | **Low** |
| **Login as affiliate** | Admin cannot preview the affiliate dashboard as a specific user. | **Low** |

---

## Section 15 — Affiliate User Experience Audit

### What would confuse a new affiliate?

| Issue | Impact |
|---|---|
| No explanation of commission rates | Affiliate doesn't know how much they earn per product |
| No explanation of how milestones work | "100 Sales Milestone" is mentioned but not explained |
| No guide on how to use the referral link | Copy button exists but no instructions |
| No explanation of admin fees | Fee warning appears but no context on what fees are |
| No profile/settings page | Cannot update Wise email after registration |
| Withdrawal minimum shown in form but not explained | "$50 minimum" with no context |
| Status badges without legend | "Blocked" status on commission with no explanation |

### Missing Affiliate-Facing Features

| Feature | Why It Matters | Priority |
|---|---|---|
| **Commission rate card** | Affiliates need to see what they earn per product type | **High** |
| **Profile settings page** | Update payment email, name, contact info | **High** |
| **How it works guide** | In-dashboard explanation of the affiliate program | **High** |
| **Referral click counter** | Show how many clicks the affiliate's link has received | **Medium** |
| **Pending vs converted referrals** | Show which clicks led to sales | **Medium** |
| **Withdrawal status timeline** | Show progress: pending → approved → completed | **Medium** |
| **Notification history** | Emails sent, status changes, milestones reached | **Low** |
| **Achievement badges** | Gamification for sales milestones | **Low** |

---

## Section 16 — Registration Flow Audit

### Current State

- Public registration via `[konx_affiliate_register]` shortcode
- Two self-service types: Referral (auto-active) and Business (auto-pending)
- Agent types (Team, Marketing, Sales) are admin-assigned only
- No way to disable registration
- No approval workflow for Referral affiliates
- Business affiliates require manual admin activation

### Recommendations for KonX

| Option | Pros | Cons | Recommendation |
|---|---|---|---|
| **Public Registration** (current) | Fastest growth, low friction | Spam risk, unqualified affiliates | Use for Referral type |
| **Approval Required** | Quality control, prevent abuse | Slower onboarding, admin workload | Use for Business type (already implemented) |
| **Invite Only** | Highest quality, controlled growth | Limits reach, requires admin effort | Not recommended for MVP |

**Best practice for KonX:**
- Keep Referral Affiliate registration public (current behavior)
- Keep Business Affiliate as pending until pack purchase verified (current behavior)
- **Add admin toggle** to enable/disable public registration (missing)
- **Add CAPTCHA or honeypot** to prevent spam registrations (missing)

---

## Section 17 — Affiliate Portal Audit

### Current Dashboard Sections

| Section | Present | Quality |
|---|---|---|
| Welcome / Hero | Yes | Good — shows name, status, balance |
| Financial Summary | Yes | Good — 6 stat cards |
| User Journey | Yes | Good — 10-step onboarding |
| Milestone Progress | Yes | Good — progress bar, history |
| Referral Tools | Yes | Good — copy, social share |
| Commission History | Yes | Adequate — tabbed, recent 10 |
| Withdrawal Form | Yes | Adequate — basic form |
| Withdrawal History | Yes | Adequate — in tab |
| Admin Fee Warning | Yes | Adequate — shown when unpaid |

### Missing Sections

| Section | Business Value | Priority |
|---|---|---|
| **Profile Settings** | Affiliates cannot update their Wise email, name, or password after registration | **Critical** |
| **Commission Rate Card** | Shows "what do I earn?" — currently hidden; affiliates see rates only in transaction history | **High** |
| **Referral Statistics** | Click count, conversion rate, top performing links | **Medium** |
| **How It Works** | Collapsible FAQ explaining commissions, milestones, withdrawals, fees | **Medium** |
| **Downloadable Resources** | Marketing materials, brand assets, email templates | **Low** |
| **Monthly Earnings Chart** | Visual trend of earnings over time (Chart.js) | **Low** |

---

## Section 18 — Role & Permission Audit

### Current State

All 5 affiliate roles have identical capabilities:
- `view_konx_dashboard`
- `request_withdrawal`
- `view_commissions`
- `view_wallet`

All roles see the exact same dashboard with the same data.

### Should Different Roles See Different Dashboards?

**For MVP: No.** All affiliates need the same core information (earnings, referral link, withdrawals). The difference between roles is the commission rate, which is already handled by the engine.

**For v2.0:** Consider:
- Agent roles could see team performance (affiliates they referred)
- Business affiliates could see pack purchase status
- Agents could see a "My Team" section showing sub-affiliates

### Recommendation

| Phase | Action |
|---|---|
| MVP (v1.x) | Keep identical dashboards. Commission rates are the differentiator. |
| v2.0 | Add "My Team" section for agents showing referred affiliates and their performance |
| v2.0 | Add role-specific welcome messages explaining the commission structure |

---

## Section 19 — Admin Features Missing

| Feature | Business Value | Priority |
|---|---|---|
| **Manual Affiliate Creation** | Onboard existing partners without making them use the registration form | **Critical** |
| **Manual Balance Adjustment** | Resolve disputes, issue corrections, credit bonuses | **Critical** |
| **Release Blocked Commissions** | When fee is paid, unblock and credit all blocked commissions | **Critical** |
| **Affiliate Notes System** | Admin can track communications, issues, and decisions per affiliate | **High** |
| **Bulk Status Change** | Activate/suspend multiple affiliates at once | **High** |
| **CSV Import** | Migrate existing affiliates from another system (e.g., Coupon Affiliates) | **High** |
| **Commission Adjustment** | Manually adjust or override a commission amount | **Medium** |
| **Affiliate Search** | Search across all admin pages by name, email, or code | **Medium** |
| **Registration Toggle** | Enable/disable public affiliate registration from Settings | **Medium** |
| **Email Affiliates** | Send announcements to all affiliates or filtered groups | **Medium** |
| **Affiliate Tags/Groups** | Organize affiliates by campaign, region, or tier | **Low** |
| **Login As Affiliate** | Preview the affiliate dashboard as a specific user | **Low** |
| **Referral Code Reset** | Regenerate a compromised or unwanted referral code | **Low** |
| **Duplicate Detection** | Detect affiliates with same IP, device, or payment email | **Low** |

---

## Section 20 — Affiliate Features Missing

| Feature | Business Value | Priority |
|---|---|---|
| **Profile Settings Page** | Update Wise email, name, password | **Critical** |
| **Commission Rate Card** | Know earnings per product before promoting | **High** |
| **Program Guide / FAQ** | Reduce support questions, increase affiliate confidence | **High** |
| **Referral Click Stats** | See how many clicks the referral link received | **Medium** |
| **Conversion Rate Display** | Clicks vs sales — helps affiliates optimize | **Medium** |
| **Monthly Earnings Chart** | Visual trend motivates continued promotion | **Medium** |
| **Withdrawal Timeline** | See progress through pending → approved → completed | **Medium** |
| **Referral QR Code** | Alternative to link sharing, good for in-person events | **Medium** |
| **Notification Preferences** | Choose which emails to receive | **Low** |
| **Achievement Badges** | Gamification: Bronze/Silver/Gold/Diamond based on sales | **Low** |
| **Leaderboard** | See ranking among other affiliates (optional, privacy-aware) | **Low** |
| **Marketing Resources** | Downloadable banners, email templates, social media assets | **Low** |
| **Referral History** | See which referred users signed up (anonymized) | **Low** |

---

## Section 21 — MVP Release Recommendation

### Must Have Before Launch

| Item | Reason |
|---|---|
| **Manual affiliate creation from admin** | Cannot onboard existing KonX partners otherwise. They'd have to use the public form. |
| **Manual balance adjustment UI** | Financial disputes will arise on day 1. Admin cannot resolve without code access. |
| **Release blocked commissions** | Without this, paying admin fee doesn't actually unblock earnings. Trust-breaking bug. |
| **Profile settings page** | Affiliates cannot update Wise email. If they entered wrong email at registration, payouts go nowhere. |
| **Commission rate card on dashboard** | Affiliates will immediately ask "how much do I earn?" — must be self-service. |

### Should Have Before Launch

| Item | Reason |
|---|---|
| Registration enable/disable toggle | Admin should be able to close registration during maintenance or before the program is ready. |
| Referral click statistics | Affiliates need to know their links are working. Zero visibility = zero confidence. |
| How It Works guide on dashboard | Reduces support tickets from day 1. |
| CAPTCHA/honeypot on registration | Prevents spam sign-ups from bots. |
| Email templates (at least registration + withdrawal) | Professional communication builds trust. |

### Can Wait Until v1.1

| Item | Reason |
|---|---|
| CSV affiliate import | Coupon Affiliates migration can be handled ad-hoc initially. |
| Bulk actions | Manageable with < 50 affiliates at launch. |
| Monthly earnings chart | Nice-to-have, not blocking. |
| Affiliate tags/groups | Not needed until affiliate count grows significantly. |
| Withdrawal timeline UI | Current status display is adequate. |

### Can Wait Until v2.0

| Item | Reason |
|---|---|
| REST API | Only needed for headless/mobile integration. |
| PDF exports | CSV is sufficient for MVP. |
| Achievement badges | Gamification layer — value grows with scale. |
| Team/agent sub-affiliate views | Agents currently earn commissions correctly; team visibility is a v2 enhancement. |
| Leaderboard | Privacy and competitive dynamics need careful design. |
| Login as affiliate | Admin can create a test affiliate account instead. |
| QR code generation | Social share links cover the same use case. |

---

## Summary

The plugin's **backend architecture is production-ready**. Commission calculation, wallet integrity, referral tracking, and financial flows are solid and well-tested.

The gaps are in **admin operations** and **affiliate self-service**:

1. **Admin cannot create affiliates or adjust balances from the UI** — this is the #1 blocker for launch.
2. **Affiliates cannot update their profile** — this will generate support tickets immediately.
3. **Blocked commissions stay blocked** even after fee payment — this is a trust-breaking issue.
4. **No rate transparency** — affiliates don't know what they earn until their first commission appears.

Fixing these 4-5 items transforms the plugin from "developer tool" to "production platform."
