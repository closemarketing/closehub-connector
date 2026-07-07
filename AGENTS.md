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

All endpoints live under the `closehub/v1` namespace and require the `X-CloseHub-Key` header (or `closehub_key` query param as fallback). Authentication is handled by `CloseHub_REST_API::check_api_key()` using `hash_equals()`. The endpoints and their URLs are identical on a single site and on a multisite network — there is no separate namespace, key header, or admin page for multisite; behavior branches internally on `is_multisite()`.

| Method | Endpoint | Class method | Notes |
|--------|----------|--------------|-------|
| GET | `/ping` | `ping()` | Returns site name, URL, WP version, plugin version |
| POST | `/posts` | `create_post()` | Creates a WP post; args: `title`, `content`, `excerpt`, `status` |
| GET | `/woocommerce/orders` | `get_woocommerce_orders()` | Returns order count, total sales, average order; args: `after`, `before`, `status` |
| GET | `/gravity-forms/forms` | `list_forms()` | Lists all forms with entry count |
| GET | `/gravity-forms/forms/{id}` | `get_form()` | Form details + last entry date |
| GET | `/gravity-forms/forms/{id}/entries` | `get_form_entries()` | Entry count for a date range |

WooCommerce and Gravity Forms endpoints return `503` with a clear message if those plugins are not active.

### Multisite behavior

Each public callback's business logic lives in a private `*_data()` method (e.g. `ping()` calls `get_ping_data()`). The callbacks don't call `rest_ensure_response()` directly — they go through `respond( $data_builder, $network_key = null )`:

- On a single site (`is_multisite() === false`): calls `$data_builder()` once and returns it via `rest_ensure_response()`, exactly like before multisite support existed. Response shape is unchanged.
- On a network (`is_multisite() === true`): calls `run_across_network( $data_builder, $network_key )`, which loops over `get_sites()`, does `switch_to_blog()` / `restore_current_blog()` around each call, and returns `{ "sites": [ { "site_id", "url", ...data or "error" }, ... ] }`. `$network_key` is only needed when `$data_builder()` returns a plain list rather than an associative array (e.g. `list_forms_data()` nests its result under `"forms"` per site instead of merging numeric keys into the entry).

This means `POST /posts` creates the post on every site of the network, `GET /woocommerce/orders` returns per-site order stats, etc. — the client always talks to the same URL and key; only the shape of a successful response differs (single object vs. `{ sites: [...] }`).

## Key classes

### `CloseHub_API_Key` (`includes/class-api-key.php`)

Manages the `closehub_api_key` option. On a single-site install it lives in that site's `wp_options` — behavior is untouched from before multisite support. On a multisite network it lives in `wp_sitemeta` via `get_site_option()`/`update_site_option()`, so every site in the network reads and writes the exact same value; there is no separate per-site key on multisite.

- `maybe_generate()` — called on activation; only generates if no key exists yet
- `get()` — returns current key, generates one if missing
- `verify( $candidate )` — constant-time comparison via `hash_equals()`
- `regenerate()` — generates a new key and overwrites the old one

Key format: `chk_` + 48 hex characters (`bin2hex( random_bytes( 24 ) )`). The storage backend (`stored()`/`persist()`) is the only thing that branches on `is_multisite()`; all public methods are identical for both cases.

### `CloseHub_REST_API` (`includes/class-rest-api.php`)

Registers all routes on `rest_api_init`, same routes regardless of multisite. Every route uses `check_api_key` as its `permission_callback`. Input is sanitized via `sanitize_callback` in the route arg definitions, not inside the callbacks. See "Multisite behavior" above for how `respond()`/`run_across_network()` fan a callback out across the network.

### `CloseHub_Admin` (`includes/class-admin.php`)

On a single site, adds a page at **Settings → CloseHub** (`manage_options` capability required); the regenerate action uses a nonce (`closehub_regenerate_key`) and redirects with a `closehub_notice=regenerated` query param on success.

On multisite, the per-site Settings page is not registered at all — instead a **Network Admin → Settings → CloseHub** page is added (`manage_network_options` capability required), showing the single key shared by the whole network plus a table of every site in it. This avoids a non-super-admin site admin being able to view/regenerate the network-wide key. `register()` picks one branch or the other based on `is_multisite()`.

## Conventions

- `defined( 'ABSPATH' ) || exit;` at the top of every file.
- No output buffering or `echo` outside the admin render method.
- All user-facing strings use `esc_html_e()` / `esc_html()` for output escaping.
- No direct `$wpdb` queries — uses WP/WooCommerce/GF APIs only.
- Classes are loaded manually; no PSR-4 autoloading at runtime.

## Adding a new endpoint

1. Put the business logic in a private `*_data()` method that returns `array|WP_Error`.
2. Add a `register_rest_route()` call inside `CloseHub_REST_API::register_routes()` with `'permission_callback' => [ $this, 'check_api_key' ]`.
3. Define `sanitize_callback` and `validate_callback` in the `args` array.
4. Wire the public callback through `$this->respond( fn() => $this->*_data( $request ) )` so it automatically works both on a single site and across a network — pass a string as the second argument only if `*_data()` returns a plain list rather than an associative array (see `list_forms()`).
5. Document it in the table above and in `readme.txt` (Available Endpoints section).

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
