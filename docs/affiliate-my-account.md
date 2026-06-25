# WooCommerce My Account Integration

## Overview

The Affiliate Dashboard is integrated directly into the WooCommerce My Account navigation as a first-class menu item. Approved affiliates see an "Affiliate Dashboard" tab; non-affiliates see nothing.

## Endpoint

- **Slug:** `affiliate-dashboard`
- **Pretty permalink:** `/my-account/affiliate-dashboard/`
- **Plain permalink:** `?pagename=my-account&affiliate-dashboard=`
- **Registered via:** `add_rewrite_endpoint()` with `EP_ROOT | EP_PAGES`

## Menu Hook

- **Filter:** `woocommerce_account_menu_items`
- **Priority:** 10
- **Position:** Inserted immediately before "Logout" in the My Account navigation

Menu order (default WooCommerce + this integration):

1. Dashboard
2. Orders
3. Downloads
4. Addresses
5. Account Details
6. **Affiliate Dashboard** (new)
7. Logout

## Visibility Rules

| Condition | Menu Visible | Content Accessible |
|---|---|---|
| User has active affiliate record in `wp_konx_affiliates` | Yes | Yes |
| User has no affiliate record | No | Informational message |
| User not logged in | No | Login prompt |

The visibility check uses `Konx_Affiliate_Manager::get_affiliate_by_user()` which queries the `wp_konx_affiliates` table directly.

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
| `public/class-konx-my-account.php` | New - endpoint registration, menu filter, content rendering |
| `public/class-konx-dashboard.php` | Modified - removed banner and redirect methods |
| `konx-affiliate-dashboard.php` | Modified - added `Konx_My_Account::init()` |
| `includes/class-konx-install.php` | Modified - schedule rewrite flush on activation |

## Class Reference

### `Konx_My_Account`

| Method | Description |
|---|---|
| `init()` | Registers all hooks |
| `register_endpoint()` | Adds the WooCommerce rewrite endpoint |
| `add_menu_item( $items )` | Filters menu items (affiliates only) |
| `render_endpoint()` | Renders dashboard content |
| `endpoint_title( $title, $id )` | Sets page title |
| `maybe_enqueue_assets()` | Enqueues CSS/JS on endpoint |
| `schedule_flush()` | Schedules rewrite rule flush |
| `maybe_flush_rewrite_rules()` | Executes scheduled flush |
