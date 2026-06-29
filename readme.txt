=== CloseHub Connector ===
Contributors: closetechnology, davidperez
Tags: api, integration, closehub, woocommerce, gravity-forms
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your WordPress site to CloseHub with a single API key — no separate WooCommerce keys, Application Passwords, or Gravity Forms credentials needed.

== Description ==

CloseHub Connector replaces the multiple credentials previously required to link a WordPress site with [CloseHub](https://close.marketing/closehub/) — a marketing project management platform.

Once the plugin is activated, it generates a secure API key and exposes a dedicated REST API namespace (`/wp-json/closehub/v1/`) that CloseHub uses to interact with your site.

**What it replaces:**

* WordPress Application Password (username + password)
* WooCommerce REST API consumer key and consumer secret
* Gravity Forms API key

**You only need to share two things with CloseHub:**

1. Your site URL
2. The generated API key (found at Settings → CloseHub)

**Available endpoints:**

* `GET /closehub/v1/ping` — verify the connection
* `POST /closehub/v1/posts` — publish or draft a post
* `GET /closehub/v1/woocommerce/orders` — fetch order data (requires WooCommerce)
* `GET /closehub/v1/gravity-forms/forms` — list forms (requires Gravity Forms)
* `GET /closehub/v1/gravity-forms/forms/{id}` — get form details
* `GET /closehub/v1/gravity-forms/forms/{id}/entries` — count form entries by date range

WooCommerce and Gravity Forms endpoints return a clear error if those plugins are not active — they are not required.

== Installation ==

1. Upload the `closehub-connector` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen in WordPress admin.
3. Go to **Settings → CloseHub**.
4. Copy the **Site URL** and **API Key** shown on that page.
5. Paste both values into CloseHub under your project's WordPress connection settings.

== Frequently Asked Questions ==

= Where do I find my API key? =

Go to **Settings → CloseHub** in your WordPress admin. The key is displayed there along with a copy button.

= Can I regenerate the API key? =

Yes. Click **Regenerate Key** on the Settings → CloseHub page. The old key stops working immediately — update CloseHub with the new key right away.

= Do I need WooCommerce or Gravity Forms installed? =

No. Both are optional. If they are not active, those endpoints return a `503` response with a clear message instead of crashing.

= How is the API key secured? =

The key is stored in `wp_options` (never exposed on the frontend), transmitted only via HTTPS, and verified using constant-time comparison (`hash_equals`) to prevent timing attacks. It is never logged or included in REST responses.

= Can I use this plugin on a multisite network? =

The plugin works on individual sites within a multisite network. Each site generates and manages its own API key independently.

= Is this plugin affiliated with WooCommerce or Gravity Forms? =

No. It integrates with those plugins using their public PHP APIs but is not developed, endorsed, or supported by WooCommerce or Gravity Forms.

== Screenshots ==

1. Settings page showing the Site URL, API key, and available endpoints.
2. Regenerate Key button with confirmation notice.

== Changelog ==

= 1.0.0 =
* Initial release.
* Single API key replaces WordPress Application Password, WooCommerce REST credentials, and Gravity Forms API key.
* Endpoints: ping, posts, woocommerce/orders, gravity-forms/forms.
* Admin settings page at Settings → CloseHub with copy buttons and regenerate key action.

== Upgrade Notice ==

= 1.0.0 =
Initial release — no upgrade steps required.
