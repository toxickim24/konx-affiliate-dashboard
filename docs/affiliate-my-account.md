# WooCommerce My Account Integration

## Overview

The KonX Affiliate program is integrated directly into the WooCommerce My Account navigation:

- **Approved affiliates** see an "Affiliate Dashboard" tab.
- **Non-affiliates** see a "Become an Affiliate" tab and a CTA card on the dashboard.
- **Pending affiliates** see the "Affiliate Dashboard" tab and a review notice on the dashboard.

## Affiliate Dashboard Endpoint

- **Slug:** `affiliate-dashboard`
- **Pretty permalink:** `/my-account/affiliate-dashboard/`
- **Plain permalink:** `?pagename=my-account&affiliate-dashboard=`
- **Registered via:** `add_rewrite_endpoint()` with `EP_ROOT | EP_PAGES`

## Become an Affiliate Menu Item

- **Key:** `become-an-affiliate`
- **Not a WooCommerce endpoint** — the URL is overridden via `woocommerce_get_endpoint_url` to link directly to the registration page.
- **Registration page detection:** Finds the published page containing the `[konx_affiliate_register]` shortcode via a database query.
- **If no registration page exists:** The menu item and CTA are not shown. No broken links.

## Menu Hook

- **Filter:** `woocommerce_account_menu_items`
- **Priority:** 10
- **Position:** Inserted immediately before "Logout" in the My Account navigation

### Menu Order for Affiliates

1. Dashboard
2. Orders
3. Downloads
4. Addresses
5. Account Details
6. **Affiliate Dashboard**
7. Logout

### Menu Order for Non-Affiliates

1. Dashboard
2. Orders
3. Downloads
4. Addresses
5. Account Details
6. **Become an Affiliate**
7. Logout

## Visibility Rules

| User State | Menu Item | Dashboard CTA |
|---|---|---|
| Active affiliate | Affiliate Dashboard | None |
| Pending affiliate | Affiliate Dashboard | "Application under review" notice |
| Inactive/suspended affiliate | Affiliate Dashboard | None |
| Non-affiliate (reg page exists) | Become an Affiliate | "Become a KonX Affiliate" card + Apply Now |
| Non-affiliate (no reg page) | None | None |
| Not logged in | None | None |

The visibility check uses `Konx_Affiliate_Manager::get_affiliate_by_user()` which queries the `wp_konx_affiliates` table directly.

## Pending Application Behavior

When a user has an affiliate record with `status = 'pending'`:

- The "Affiliate Dashboard" menu item is shown (endpoint renders the dashboard).
- A yellow notice card appears on the My Account dashboard:
  - Title: "Your affiliate application is under review."
  - Body: "We will notify you once your application has been reviewed."
- The "Become an Affiliate" CTA is NOT shown (they already applied).

## Dashboard CTA Card

Appears on the main My Account dashboard page (`woocommerce_account_dashboard` action) for non-affiliate users:

- **Title:** Become a KonX Affiliate
- **Copy:** Earn commissions by sharing your referral link and helping others discover KonX.
- **Button:** Apply Now (links to the registration page)
- **Style:** Blue border card matching WooCommerce admin palette

## Registration Page Detection

The registration page is detected by querying for a published page containing the `[konx_affiliate_register]` shortcode:

```sql
SELECT ID FROM wp_posts
WHERE post_type = 'page'
  AND post_status = 'publish'
  AND post_content LIKE '%[konx_affiliate_register]%'
LIMIT 1
```

If no page is found, both the menu item and the CTA card are silently hidden. To enable:

1. Create a new WordPress page.
2. Add the `[konx_affiliate_register]` shortcode to the page content.
3. Publish the page.

The menu item and CTA will appear automatically.

## Routing

### Content Rendering

The endpoint content action `woocommerce_account_affiliate-dashboard_endpoint` calls `Konx_My_Account::render_endpoint()`, which delegates to the existing `Konx_Dashboard::render_shortcode()`. This ensures the same dashboard experience whether accessed via the My Account tab or the standalone `[konx_affiliate_dashboard]` shortcode page.

### Page Title

The `the_title` filter sets the page title to "Affiliate Dashboard" when the endpoint is active.

### Asset Loading

Dashboard CSS and JS are enqueued only when the endpoint is active, using `is_account_page()` combined with a query var check.

## Compatibility

### Permalink Structures

- **Pretty permalinks:** Uses WooCommerce's standard endpoint routing (`/my-account/affiliate-dashboard/`)
- **Plain permalinks:** Falls back to query string format (`?affiliate-dashboard=`)
- **Rewrite rules** are flushed once after plugin activation via a transient flag

### WooCommerce Templates

- No WooCommerce core files are modified
- Uses only hooks and endpoints
- Compatible with WooCommerce HPOS (declared in main plugin file)
- Works with default and custom WooCommerce account templates

### Previous Integration

The previous integration used a `woocommerce_before_my_account` banner ("You have an affiliate account with KonX." + "Go to Affiliate Dashboard" button). This has been removed in favor of the native navigation tab.

## Files

| File | Change |
|---|---|
| `public/class-konx-my-account.php` | Endpoint, menu items, CTA, pending notice, registration page detection |
| `public/class-konx-dashboard.php` | Removed banner and redirect methods (AX-1) |
| `konx-affiliate-dashboard.php` | Added `Konx_My_Account::init()` (AX-1) |
| `includes/class-konx-install.php` | Schedule rewrite flush on activation (AX-1) |

## Class Reference

### `Konx_My_Account`

| Method | Description |
|---|---|
| `init()` | Registers all hooks |
| `register_endpoint()` | Adds the WooCommerce rewrite endpoint |
| `add_menu_item( $items )` | Filters menu items based on affiliate status |
| `override_become_affiliate_url()` | Redirects "Become an Affiliate" URL to registration page |
| `render_dashboard_cta()` | Renders CTA card or pending notice on dashboard |
| `render_endpoint()` | Renders affiliate dashboard content |
| `endpoint_title( $title, $id )` | Sets page title |
| `maybe_enqueue_assets()` | Enqueues CSS/JS on endpoint |
| `get_registration_page_url()` | Finds the registration page URL |
| `insert_before_logout()` | Helper to position menu items before Logout |
| `schedule_flush()` | Schedules rewrite rule flush |
| `maybe_flush_rewrite_rules()` | Executes scheduled flush |
