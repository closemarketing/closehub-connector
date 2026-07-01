# CloseHub Connector — Agent Guide

## Project purpose

This is a WordPress plugin that connects a WordPress site to **CloseHub** (a marketing agency management platform built on Laravel + Filament). It replaces the multiple credentials that CloseHub previously required — WordPress Application Password, WooCommerce consumer key/secret, and Gravity Forms API key — with a single generated API key.

The user installs the plugin, goes to **Settings → CloseHub**, and copies two values into CloseHub:

1. Site URL
2. API Key (`chk_` prefix, 48 hex chars)

## Stack

- **Language**: PHP 8.1+
- **Minimum WordPress**: 6.4
- **No build step** — plain PHP, no JavaScript compilation, no npm
- **No autoloader** — files are loaded manually via `require_once` in `closehub-connector.php`
- **Dev dependencies only**: `vendor/` contains PHPStan stubs for IDE type checking; nothing ships to production from `vendor/`

## File structure

```
closehub-connector.php          Bootstrap: plugin header, activation/deactivation hooks, init
uninstall.php                   Removes closehub_api_key from wp_options on uninstall
readme.txt                      WordPress.org plugin directory listing
composer.json                   Dev stubs only (wordpress, woocommerce, gravity-forms)
phpstan.neon                    PHPStan config (level 5)
.distignore                     Files excluded from the .zip release build
includes/
    class-api-key.php           Generate, store, verify, and regenerate the API key
    class-rest-api.php          All REST endpoints under /wp-json/closehub/v1/
    class-admin.php             Settings → CloseHub admin page
```

## REST API

All endpoints live under the `closehub/v1` namespace and require the `X-CloseHub-Key` header (or `closehub_key` query param as fallback). Authentication is handled by `CloseHub_REST_API::check_api_key()` using `hash_equals()`.

| Method | Endpoint | Class method | Notes |
|--------|----------|--------------|-------|
| GET | `/ping` | `ping()` | Returns site name, URL, WP version, plugin version |
| POST | `/posts` | `create_post()` | Creates a WP post; args: `title`, `content`, `excerpt`, `status` |
| GET | `/woocommerce/orders` | `get_woocommerce_orders()` | Returns order count, total sales, average order; args: `after`, `before`, `status` |
| GET | `/gravity-forms/forms` | `list_forms()` | Lists all forms with entry count |
| GET | `/gravity-forms/forms/{id}` | `get_form()` | Form details + last entry date |
| GET | `/gravity-forms/forms/{id}/entries` | `get_form_entries()` | Entry count for a date range |

WooCommerce and Gravity Forms endpoints return `503` with a clear message if those plugins are not active.

### Network (Multisite) endpoints

Only registered when `is_multisite()` is true. They live under `/closehub/v1/network/` and require the `X-CloseHub-Network-Key` header (or `closehub_network_key` query param as fallback), checked by `CloseHub_REST_API::check_network_api_key()` against the network-wide key.

| Method | Endpoint | Class method |
|--------|----------|--------------|
| GET | `/network/ping` | `network_ping()` |
| POST | `/network/posts` | `network_create_post()` |
| GET | `/network/woocommerce/orders` | `network_get_woocommerce_orders()` |
| GET | `/network/gravity-forms/forms` | `network_list_forms()` |
| GET | `/network/gravity-forms/forms/{id}` | `network_get_form()` |
| GET | `/network/gravity-forms/forms/{id}/entries` | `network_get_form_entries()` |

Each network callback delegates the actual work to the same private `*_data()` method used by its single-site counterpart (e.g. both `ping()` and `network_ping()` call `get_ping_data()`), then `run_across_network()` loops over `get_sites()`, calls `switch_to_blog()`/`restore_current_blog()`, and collects one entry per site into a top-level `sites` array (`site_id`, `url`, plus that site's data or an `error` message if the call failed on that site, e.g. WooCommerce not active). This keeps single-site responses byte-for-byte unchanged while reusing the same business logic for the network aggregation.

## Key classes

### `CloseHub_API_Key` (`includes/class-api-key.php`)

Manages the single `closehub_api_key` option in `wp_options`, plus (on multisite) a network-wide `closehub_network_api_key` in `wp_sitemeta`.

- `maybe_generate()` / `maybe_generate_network()` — called on activation; only generates if no key exists yet
- `get()` / `get_network()` — returns current key, generates one if missing
- `verify( $candidate )` / `verify_network( $candidate )` — constant-time comparison via `hash_equals()`
- `regenerate()` / `regenerate_network()` — generates a new key and overwrites the old one

Key format: `chk_` + 48 hex characters (`bin2hex( random_bytes( 24 ) )`), same for both site and network keys.

### `CloseHub_REST_API` (`includes/class-rest-api.php`)

Registers all routes on `rest_api_init`. Single-site routes use `check_api_key` as their `permission_callback`; network routes (only registered when `is_multisite()`) use `check_network_api_key`. Input is sanitized via `sanitize_callback` in the route arg definitions, not inside the callbacks. Each route's business logic lives in a private `*_data()` method so it can be reused by both the single-site callback and the network aggregator (`run_across_network()`).

### `CloseHub_Admin` (`includes/class-admin.php`)

Adds a page at **Settings → CloseHub** (`manage_options` capability required). The regenerate action uses a nonce (`closehub_regenerate_key`) and redirects with a `closehub_notice=regenerated` query param on success.

On multisite, it also adds a **Network Admin → Settings → CloseHub** page (`manage_network_options` capability required) for the network-wide key, following the same nonce/redirect pattern (`closehub_regenerate_network_key` nonce, `network_admin_menu` hook).

## Conventions

- `defined( 'ABSPATH' ) || exit;` at the top of every file.
- No output buffering or `echo` outside the admin render method.
- All user-facing strings use `esc_html_e()` / `esc_html()` for output escaping.
- No direct `$wpdb` queries — uses WP/WooCommerce/GF APIs only.
- Classes are loaded manually; no PSR-4 autoloading at runtime.

## Adding a new endpoint

1. Put the business logic in a private `*_data()` method that returns `array|WP_Error`.
2. Add a `register_rest_route()` call inside `CloseHub_REST_API::register_routes()` with `'permission_callback' => [ $this, 'check_api_key' ]`, wiring a thin public callback that calls the `*_data()` method and wraps it with `rest_ensure_response()`.
3. Define `sanitize_callback` and `validate_callback` in the `args` array.
4. If the endpoint makes sense across a network (most read endpoints do), register a `/network/...` counterpart guarded by `if ( is_multisite() )`, using `check_network_api_key` and a callback that calls `run_across_network( fn() => $this->*_data( $request ) )` (pass a string key as the second argument if `*_data()` returns a plain list rather than an associative array, e.g. `list_forms_data()`).
5. Document it in both tables above and in `readme.txt` (Available Endpoints section).

## CloseHub side (Laravel app)

The counterpart lives in `/Users/davidperez/Apps/app-closehub`. The WordPress integration is handled by:

- `app/Services/WordPress/WordPressClient.php` — publishes posts
- `app/Services/WordPress/WooCommerceClient.php` — fetches WooCommerce orders
- `app/Services/WordPress/GravityFormsClient.php` — lists forms and counts entries
- `app/Services/WordPress/WordPressAuth.php` — builds the authenticated HTTP client

Those classes currently use Basic Auth (username + app password) + WooCommerce consumer key/secret. They will need to be updated to send `X-CloseHub-Key` to the plugin endpoints instead.

## Release

The `.distignore` file controls what is excluded from the release `.zip`. `AGENTS.md`, `CLAUDE.md`, `vendor/`, `phpstan.neon`, and `composer.*` are all excluded. The release artifact contains only:

```
closehub-connector.php
uninstall.php
readme.txt
includes/
```
