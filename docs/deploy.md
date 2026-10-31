# Deploying a plugin or theme zip

CloseHub Connector can install or replace a plugin or theme from a zip, the same way the admin upload screens do. For real packages, upload the zip from disk with `bin/closehub-deploy` (or curl). The MCP abilities `install-plugin-zip` / `install-theme-zip` take the zip as base64 inside the call, which only works for very small zips: an AI agent cannot write hundreds of thousands of base64 characters reliably.

No application password, API key or other long-lived credential is needed. The upload is authorized by a **deploy ticket** that the agent requests through the MCP connection it already has:

```
Mac (agent)                                    Site
───────────                                    ────
wdis → {slug}-{version}.zip in Google Drive
MCP closehub/create-deploy-ticket ───────────▶ ticket for (current user, type, slug), 15 min, single use
bin/closehub-deploy (ticket in header) ─multipart─▶ POST /wp-json/closehub/v1/packages/install
                                               ├─ burn ticket, act as its user
                                               ├─ zip has a single folder == slug?
                                               ├─ back up the installed copy
                                               ├─ Plugin_Upgrader / Theme_Upgrader (overwrite_package)
                                               └─ clear WP Rocket + OPcache
MCP closehub/get-installed-version ──────────▶ version, active, backups
MCP closehub/rollback-package ───────────────▶ reinstall a backup (if needed)
```

## Why a ticket instead of an application password

An application password is a permanent credential with every capability of its user, over the whole REST API, until someone revokes it. If it leaks, the site is exposed.

A deploy ticket:

- is created by a user who is already authenticated over MCP (OAuth or CloseHub API key), and needs `install_plugins` / `install_themes`;
- only works for the `type` and `slug` it was issued for, so it can never install or replace anything else;
- expires after 15 minutes and is burned on first use, even when that use fails;
- is stored on the site only as a SHA-256 hash.

The upload route accepts nothing else: cookies, application passwords and the CloseHub API key are ignored there.

The ticket does pass through the agent's conversation, since the agent copies it from the ability's output into the upload command. That is acceptable because it is useless once used or expired, and only for that one package.

## What the site checks

- **Who:** the user the ticket was issued to, with their capabilities at upload time. Installing needs `install_plugins` / `install_themes`; replacing an installed copy also needs `update_plugins` / `update_themes`; `activate` needs `activate_plugins` / `switch_themes`. On multisite only super admins have these.
- **What:** the zip must contain exactly one top-level folder, it must equal `slug`, and it must look like the type (a PHP file for a plugin, `style.css` for a theme). A zip for another plugin or theme is rejected before anything changes.
- **Size:** the upload must fit `upload_max_filesize`, `post_max_size` and WordPress's upload limit. Otherwise the route answers `413` naming the limit that was hit.
- **File mods:** sites with `DISALLOW_FILE_MODS` refuse to issue tickets and answer `403`.

## Backups and rollback

Before replacing, the installed folder is zipped to `wp-content/uploads/closehub-backups/{type}/{slug}/{version}--{date}-{random}.zip`. The folder blocks direct access with `.htaccess` and `index.php` files, and the random suffix covers servers that ignore `.htaccess`. Each plugin or theme keeps its last 5 backups.

The `closehub/rollback-package` ability reinstalls the most recent backup, or the one named in `backup` (names come from `closehub/get-installed-version`). The copy being replaced is backed up first, so a rollback can itself be undone.

## One-time setup

1. The site runs a CloseHub Connector version that includes the `closehub/create-deploy-ticket` ability, and the MCP connection to the site works.
2. Put the script on your PATH, from the repository folder:

   ```bash
   ln -s "$PWD/bin/closehub-deploy" /usr/local/bin/closehub-deploy
   ```

Nothing to store in the Keychain.

## Example: `close-reclamafacturas-theme-1.1.2.zip` to reclamafacturas.com

1. Through MCP, call `closehub/create-deploy-ticket`:

   ```json
   { "type": "theme", "slug": "close-reclamafacturas-theme" }
   ```

   Response:

   ```json
   {
     "ticket": "3f9c…64 hex characters…a1",
     "expires_at": "2026-10-31T15:12:00+00:00",
     "expires_in": 900,
     "type": "theme",
     "slug": "close-reclamafacturas-theme",
     "upload_url": "https://reclamafacturas.com/wp-json/closehub/v1/packages/install?type=theme&slug=close-reclamafacturas-theme",
     "header": "X-CloseHub-Deploy-Ticket"
   }
   ```

2. Upload the zip within 15 minutes:

   ```bash
   CLOSEHUB_DEPLOY_TICKET=3f9c…a1 closehub-deploy https://reclamafacturas.com theme \
     "$HOME/Google Drive/Mi unidad/WordPress Plugins/proyectos/close-reclamafacturas-theme-1.1.2.zip"
   ```

   The slug defaults to the file name without `-{version}.zip` (`close-reclamafacturas-theme`); pass `--slug` when it differs. Before uploading, the script checks that the zip contains only that folder, so a wrong zip does not burn the ticket. Other options: `--activate`, `--no-overwrite`, `--no-cache`. Response:

   ```json
   {
     "type": "theme",
     "slug": "close-reclamafacturas-theme",
     "name": "Close Reclamafacturas",
     "previous_version": "1.1.1",
     "new_version": "1.1.2",
     "replaced": true,
     "active": true,
     "backup": "1.1.1--20261031-124714-ouq1tmuf.zip",
     "upgrader_messages": ["Unpacking the package…", "Installing the theme…", "Removing the old version of the theme…", "Theme installed successfully."],
     "cache": { "wp_rocket": true, "opcache": true }
   }
   ```

   In `cache`, `null` means that cache is not present on the site.

   The same upload with plain curl (the script passes the header through stdin instead, so it does not show in the process list):

   ```bash
   curl -sS -H "X-CloseHub-Deploy-Ticket: 3f9c…a1" \
     -F "file=@close-reclamafacturas-theme-1.1.2.zip;type=application/zip" \
     "https://reclamafacturas.com/wp-json/closehub/v1/packages/install?type=theme&slug=close-reclamafacturas-theme&overwrite=1&activate=0&clear_cache=1"
   ```

### Verify

- Through MCP, `closehub/get-installed-version` with `{ "type": "theme", "slug": "close-reclamafacturas-theme" }` must show `"version": "1.1.2"` and `"active": true`.
- The home page must answer `200`:

  ```bash
  curl -sS -o /dev/null -w '%{http_code}\n' https://reclamafacturas.com/
  ```

### Roll back

Through MCP, `closehub/rollback-package` with `{ "type": "theme", "slug": "close-reclamafacturas-theme" }`.

## Troubleshooting

| Response | Cause and fix |
|---|---|
| `401 closehub_deploy_ticket_missing` | No ticket in the `X-CloseHub-Deploy-Ticket` header, or it was mangled. Some proxies strip unknown headers; check the hosting's WAF. |
| `401 closehub_deploy_ticket_invalid` | The ticket expired (15 minutes), was already used, or was burned by a failed upload. Create a new one. |
| `403 closehub_deploy_ticket_mismatch` | The ticket was created for another type or slug. Create one for the slug inside the zip. |
| `403 closehub_forbidden` | The user lost `install_*` since creating the ticket, or lacks `update_*` / `activate_*` for this upload. |
| `400 closehub_package_slug_mismatch` | The zip holds another folder than `slug`. Check the zip or `--slug`. |
| `409 closehub_package_exists` | Sent with `--no-overwrite` and it is already installed. |
| `413 closehub_package_too_large` | Raise `upload_max_filesize` and `post_max_size` (the message names the limit). |
| `404` on `/packages/install` | The site runs an older CloseHub Connector. |
