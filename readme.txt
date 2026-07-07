=== CloseHub Connector ===
Contributors: closetechnology, davidperez
Tags: api, integration, closehub, woocommerce, gravity-forms
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your WordPress site to CloseHub with a single API key in order to send statistics.

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

**Multisite networks:**

On a WordPress Multisite network, the same endpoints listed above are shared by every site in the network — there is no separate namespace or key to manage. Activate the plugin network-wide and go to **Network Admin → Settings → CloseHub** to find one API key shared by the whole network. Every request to those endpoints automatically returns a `sites` array with one entry per site in the network (`site_id`, `url`, and that site's data or an `error` message), instead of a single site's result — handy when a network is used to run the same company in multiple languages.

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

The key is stored in `wp_options` (or network-wide in `wp_sitemeta` on multisite), never exposed on the frontend, transmitted only via HTTPS, and verified using constant-time comparison (`hash_equals`) to prevent timing attacks. It is never logged or included in REST responses.

= Can I use this plugin on a multisite network? =

Yes. When network-activated, the plugin generates one API key shared by every site in the network (managed from **Network Admin → Settings → CloseHub** instead of a per-site Settings page), and the same REST endpoints automatically query every site and return combined, per-site results. On a regular, non-multisite install, everything works exactly as a single site as described above.

= Is this plugin affiliated with WooCommerce or Gravity Forms? =

No. It integrates with those plugins using their public PHP APIs but is not developed, endorsed, or supported by WooCommerce or Gravity Forms.

== Screenshots ==

1. Settings page showing the Site URL, API key, and available endpoints.
2. Regenerate Key button with confirmation notice.

== Changelog ==

= 1.0.2 =
* Added WordPress Multisite network support: one API key shared across the whole network, managed from Network Admin → Settings → CloseHub.
* On multisite, existing endpoints (ping, posts, woocommerce/orders, gravity-forms/*) now return combined results for every site in the network instead of a single site.

= 1.0.1 =
* Updated assets.

= 1.0.0 =
* Initial release.
* Single API key replaces WordPress Application Password, WooCommerce REST credentials, and Gravity Forms API key.
* Endpoints: ping, posts, woocommerce/orders, gravity-forms/forms.
* Admin settings page at Settings → CloseHub with copy buttons and regenerate key action.

== Upgrade Notice ==

= 1.0.0 =
Initial release — no upgrade steps required.
