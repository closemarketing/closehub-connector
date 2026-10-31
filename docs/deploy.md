# Deploying a plugin or theme zip

CloseHub Connector can install or replace a plugin or theme from a zip, the same way the admin upload screens do. For real packages, upload the zip from disk with `bin/closehub-deploy` (or curl). The MCP abilities `install-plugin-zip` / `install-theme-zip` take the zip as base64 inside the call, which only works for very small zips: an AI agent cannot write hundreds of thousands of base64 characters reliably.

```
Mac (agent)                                  Site
───────────                                  ────
wdis → {slug}-{version}.zip in Google Drive
bin/closehub-deploy install … ──multipart──▶ POST /wp-json/closehub/v1/packages/install
                                             ├─ zip has a single folder == slug?
                                             ├─ back up the installed copy
                                             ├─ Plugin_Upgrader / Theme_Upgrader (overwrite_package)
                                             └─ clear WP Rocket + OPcache
bin/closehub-deploy version …  ────────────▶ GET  /wp-json/closehub/v1/packages/{type}/{slug}
bin/closehub-deploy rollback … ────────────▶ POST /wp-json/closehub/v1/packages/rollback
```

## What the site checks

- **Who:** a WordPress user authenticated with an application password. Installing needs `install_plugins` / `install_themes`; replacing an installed copy also needs `update_plugins` / `update_themes`; `activate` needs `activate_plugins` / `switch_themes`. On multisite only super admins have these.
- **What:** the zip must contain exactly one top-level folder, it must equal `slug`, and it must look like the type (a PHP file for a plugin, `style.css` for a theme). A zip for another plugin or theme is rejected before anything changes.
- **Size:** the upload must fit `upload_max_filesize`, `post_max_size` and WordPress's upload limit. Otherwise the route answers `413` naming the limit that was hit.
- **File mods:** sites with `DISALLOW_FILE_MODS` answer `403`.

## Backups and rollback

Before replacing, the installed folder is zipped to `wp-content/uploads/closehub-backups/{type}/{slug}/{version}--{date}-{random}.zip`. The folder blocks direct access with `.htaccess` and `index.php` files, and the random suffix covers servers that ignore `.htaccess`. Each plugin or theme keeps its last 5 backups.

`rollback` reinstalls the most recent backup, or the one named with `--backup` (names come from `version`). The copy being replaced is backed up first, so a rollback can itself be undone.

## One-time setup

### 1. Create an application password

On the site, sign in as an administrator, or as a dedicated deploy user with the capabilities above. Go to **Users → Profile → Application Passwords**, enter a name such as `closehub-deploy (David's Mac)` and click **Add New Application Password**. Copy the password; it is shown only once. Create one per person and Mac, so access can be revoked individually.

Application passwords need HTTPS. If the section is missing, a security plugin (Wordfence, Solid Security, etc.) may have disabled it.

### 2. Store it in the macOS Keychain

The script looks for a Keychain item whose service is `closehub-deploy:<host>`, whose account is the WordPress username, and whose password is the application password:

```bash
security add-generic-password -U -s "closehub-deploy:reclamafacturas.com" -a "wp-username" -w
```

`-w` with no value prompts for the password, so it never ends up in shell history. `-U` updates an existing item. To use it from CI, set `CLOSEHUB_DEPLOY_USER` and `CLOSEHUB_DEPLOY_PASSWORD` instead.

Never put the password in the repository, a skill, or a prompt.

### 3. Put the script on your PATH

```bash
ln -s "$PWD/bin/closehub-deploy" /usr/local/bin/closehub-deploy
```

The site must run a CloseHub Connector version that includes the `/packages` routes.

## Example: `close-reclamafacturas-theme-1.1.2.zip` to reclamafacturas.com

```bash
closehub-deploy install https://reclamafacturas.com theme \
  "$HOME/Google Drive/Mi unidad/WordPress Plugins/proyectos/close-reclamafacturas-theme-1.1.2.zip"
```

The slug defaults to the file name without `-{version}.zip` (`close-reclamafacturas-theme`); pass `--slug` when it differs. Before uploading, the script checks that the zip contains only that folder. Response:

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

The same call with plain curl. The `-u` value is visible in the process list, so prefer the script:

```bash
curl -sS -u "wp-username:xxxx xxxx xxxx xxxx xxxx xxxx" \
  -F "file=@close-reclamafacturas-theme-1.1.2.zip;type=application/zip" \
  "https://reclamafacturas.com/wp-json/closehub/v1/packages/install?type=theme&slug=close-reclamafacturas-theme&overwrite=1&activate=0&clear_cache=1"
```

### Verify

```bash
closehub-deploy version https://reclamafacturas.com theme close-reclamafacturas-theme
curl -sS -o /dev/null -w '%{http_code}\n' https://reclamafacturas.com/
```

The first command must show `"version": "1.1.2"` and `"active": true`, and the home page must answer `200`. From an MCP client, `closehub/get-installed-version` with `{ "type": "theme", "slug": "close-reclamafacturas-theme" }` returns the same data.

### Roll back

```bash
closehub-deploy rollback https://reclamafacturas.com theme close-reclamafacturas-theme
```

## Troubleshooting

| Response | Cause and fix |
|---|---|
| `401 closehub_unauthorized` | Wrong user or password, or the server drops the `Authorization` header. On Apache with CGI/FastCGI, add `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]` to `.htaccess`. |
| `403 closehub_forbidden` | The user lacks `install_*` / `update_*` / `activate_*`. |
| `400 closehub_package_slug_mismatch` | The zip holds another folder than `slug`. Check the zip or `--slug`. |
| `409 closehub_package_exists` | Sent with `--no-overwrite` and it is already installed. |
| `413 closehub_package_too_large` | Raise `upload_max_filesize` and `post_max_size` (the message names the limit). |
| `404` on `/packages/...` | The site runs an older CloseHub Connector. |
