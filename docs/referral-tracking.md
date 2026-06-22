# KonX Affiliate Dashboard — Referral Tracking

## Referral Flow

```
1. Affiliate shares URL
   https://konx.world/?ref=ABC12345

2. Visitor clicks the link
   Server (Konx_Referral_Tracker::track_referral):
     a. Read ?ref= parameter
     b. Sanitize and uppercase the code
     c. Look up affiliate by code — must exist and be active
     d. Self-referral check — if logged-in user IS the affiliate, stop
     e. Set HttpOnly cookie: konx_ref=ABC12345 (30 days)
     f. Log click to wp_konx_referral_clicks (with dedup check)

   JS (konx-referral-tracking.js):
     a. Read ?ref= from URLSearchParams
     b. Store in localStorage under key "konx_ref"

3. Visitor browses the site
   Cookie persists across page views (server-side).
   localStorage persists independently (client-side).
   No further action until checkout.

4. Visitor reaches the checkout page
   PHP: Renders a hidden <input id="konx_referral_code"> in the billing form.
   JS:  Reads localStorage("konx_ref") and populates the hidden field.
        Re-populates on WooCommerce "updated_checkout" AJAX event.

5. Visitor places an order
   Konx_Order_Attribution::attach_referral_meta
   (woocommerce_checkout_create_order — before save):
     a. Read code from cookie first, then from POST hidden field
     b. Validate affiliate — must exist and be active
     c. Self-referral check — customer_id != affiliate.user_id
     d. Store _konx_referrer_id and _konx_referral_code as order meta
        via $order->update_meta_data() (HPOS-compatible)

   Konx_Order_Attribution::create_conversion_record
   (woocommerce_checkout_order_created — after save):
     a. Read _konx_referrer_id from order meta
     b. Check idempotency — skip if conversion already exists for this order
     c. Insert row into wp_konx_referral_conversions
     d. Clear the HttpOnly cookie

6. Visitor sees the thank-you page
   Konx_Order_Attribution::clear_referral_data
   (woocommerce_thankyou):
     a. Output inline <script> that calls localStorage.removeItem("konx_ref")
```

## Cookie Details

| Property | Value |
|---|---|
| Name | `konx_ref` |
| Value | Referral code (8 chars, uppercase alphanumeric) |
| Expiry | 30 days from first click |
| Path | `COOKIEPATH` (WordPress constant) |
| Domain | `COOKIE_DOMAIN` (WordPress constant) |
| Secure | `true` if site uses HTTPS |
| HttpOnly | `true` (not readable by JavaScript) |
| SameSite | `Lax` |

The cookie is HttpOnly so it cannot be stolen via XSS. JavaScript does not
read the cookie — it uses localStorage as a completely independent fallback
mechanism.

## localStorage Fallback

PHP sessions are unreliable in WordPress due to full-page caching (WP Super
Cache, W3 Total Cache) and CDNs (Cloudflare). The plugin uses localStorage
instead.

### How It Works

| Step | Where | What Happens |
|---|---|---|
| Landing page | JS | `localStorage.setItem("konx_ref", code)` |
| Checkout page | JS | Reads localStorage, writes value into hidden `<input>` |
| Checkout AJAX | JS | Re-populates hidden field on `updated_checkout` event |
| Order creation | PHP | Reads hidden field if cookie is absent |
| Thank-you page | JS | `localStorage.removeItem("konx_ref")` |

### Why Both Cookie and localStorage?

| Scenario | Cookie | localStorage | Attribution |
|---|---|---|---|
| Normal browser | Works | Works | Cookie used (primary) |
| Full-page cache strips cookies | Fails | Works | Hidden field used |
| Private browsing / localStorage blocked | Works | Fails | Cookie used |
| Both blocked | Fails | Fails | No attribution |

## Checkout Attribution Flow

```
resolve_referral_code()
  |
  +-- 1. Check cookie (Konx_Referral_Tracker::get_referral_code)
  |       If present -> use it
  |
  +-- 2. Check $_POST['konx_referral_code'] (hidden field from JS)
          If present -> sanitize and use it
          |
          +-- Empty -> no attribution (organic order)

attach_referral_meta($order)
  |
  +-- Idempotency check: $order->get_meta('_konx_referrer_id') already set?
  |     Yes -> return (no-op)
  |
  +-- Resolve code (above)
  |     Empty -> return
  |
  +-- Look up affiliate by code
  |     Not found or inactive -> return
  |
  +-- Self-referral check: order customer_id == affiliate.user_id?
  |     Yes -> return
  |
  +-- $order->update_meta_data('_konx_referrer_id', affiliate.id)
  +-- $order->update_meta_data('_konx_referral_code', code)
      (saved when WooCommerce calls $order->save())

create_conversion_record($order)
  |
  +-- Read _konx_referrer_id from order meta
  |     Empty -> return
  |
  +-- Idempotency: SELECT COUNT(*) WHERE order_id = X
  |     > 0 -> return
  |
  +-- INSERT into wp_konx_referral_conversions
  +-- Clear cookie
```

## Duplicate Click Prevention

Clicks are deduplicated by **salted IP hash + affiliate ID** within a
**24-hour window**.

| Field | Purpose |
|---|---|
| `ip_hash` | `hash('sha256', $_SERVER['REMOTE_ADDR'] . get_option('konx_ip_hash_salt'))` |
| `affiliate_id` | The affiliate whose link was clicked |
| Window | 24 hours (86400 seconds) |

Before inserting a click, the tracker runs:

```sql
SELECT COUNT(*) FROM wp_konx_referral_clicks
WHERE ip_hash = %s AND affiliate_id = %d AND clicked_at > %s
```

If count > 0, the click is silently dropped. The cookie is still set
(or overwritten) regardless — the dedup only affects the click log.

### What Is NOT Deduplicated

- Clicks from the **same IP to different affiliates** are logged separately.
- Clicks from the **same affiliate after 24 hours** are logged again.
- The **cookie is always set** even if the click is deduplicated.

## Self-Referral Prevention

Self-referral is blocked at two points:

### 1. At Click Time (Konx_Referral_Tracker)

If the visitor is logged in and their WordPress user ID matches the
affiliate's user ID, the cookie is **not set** and the click is **not
logged**.

### 2. At Checkout (Konx_Order_Attribution)

If the order's `customer_id` (from `$order->get_customer_id()`) matches
the affiliate's `user_id`, the attribution is **skipped**. The order
proceeds normally without referral meta.

This two-layer approach catches:
- Affiliates clicking their own link (blocked at click time)
- Affiliates who already have a cookie from another source and then
  place an order (blocked at checkout)
- Edge case: affiliate logs in AFTER clicking the link (blocked at checkout)

## HPOS Compatibility

All order meta is read and written via WooCommerce CRUD methods:

| Operation | Method Used |
|---|---|
| Write meta | `$order->update_meta_data( key, value )` |
| Read meta | `$order->get_meta( key )` |
| Get order ID | `$order->get_id()` |
| Get customer | `$order->get_customer_id()` |
| Get total | `$order->get_total()` |

No direct `wp_postmeta` queries are used. This ensures compatibility
with both classic post-based storage and WooCommerce HPOS.

## Manual Testing Checklist

### Referral Link

- [ ] Visit `https://site.test/?ref=VALIDCODE` — cookie is set
- [ ] Visit `https://site.test/?ref=INVALIDCODE` — no cookie set
- [ ] Visit with inactive affiliate code — no cookie set
- [ ] Visit logged in as the affiliate — no cookie set (self-referral blocked)
- [ ] Visit twice within 24 hours — only one click logged
- [ ] Visit after 24 hours — new click logged
- [ ] Visit with different affiliate code — cookie overwritten (last-click)
- [ ] Check localStorage in browser dev tools — code is stored

### Cookie Behavior

- [ ] Cookie has HttpOnly flag (not visible in `document.cookie`)
- [ ] Cookie has SameSite=Lax
- [ ] Cookie has Secure flag (on HTTPS site)
- [ ] Cookie expires after 30 days
- [ ] Cookie persists across page navigations

### localStorage Fallback

- [ ] Clear cookies, keep localStorage — hidden field populated at checkout
- [ ] Clear localStorage, keep cookie — cookie used for attribution
- [ ] Clear both — no attribution (organic order)
- [ ] Private browsing mode — cookie still works even if localStorage fails

### Checkout Attribution

- [ ] Place order with referral cookie — order has `_konx_referrer_id` meta
- [ ] Place order with localStorage only (cookies cleared) — meta is set
- [ ] Place order without any referral — no meta on order
- [ ] Place order as the affiliate (self-referral) — no meta on order
- [ ] Place order, check `wp_konx_referral_conversions` — one row created
- [ ] Place same order again (duplicate) — no duplicate conversion

### Thank-You Page

- [ ] After successful order — cookie is cleared
- [ ] After successful order — localStorage is cleared
- [ ] Inspect `wp_konx_referral_conversions` — conversion recorded

### Guest Checkout

- [ ] Guest checkout with referral — conversion has `customer_user_id = NULL`
- [ ] Logged-in checkout with referral — conversion has the user ID

### HPOS Compatibility

- [ ] Enable HPOS in WooCommerce settings
- [ ] Place a referred order — meta stored and readable
- [ ] Check order in admin — `_konx_referrer_id` visible in custom fields

### WooCommerce AJAX Checkout

- [ ] Change shipping method (triggers `updated_checkout`) — hidden field still populated
- [ ] Apply coupon (triggers `updated_checkout`) — hidden field still populated
