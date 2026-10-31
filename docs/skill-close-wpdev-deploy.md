---
name: close-wpdev-deploy
description: "Use this skill to deploy a plugin or theme zip to a CLOSE client site running CloseHub Connector: build it with close-wpdev-wdis, upload it from disk with closehub-deploy, verify the installed version, and roll back if needed. Triggers on: 'despliega el tema en', 'sube el plugin a producción', 'deploy theme', 'deploy plugin', 'actualiza el tema de [cliente]', 'rollback del tema', '/close-wpdev-deploy'."
---

# Deploy a plugin or theme zip (CloseHub) — CLOSE

Builds the zip, uploads it to the site, and verifies it, without the zip ever passing through the model. The upload uses the `closehub-deploy` script from the closehub-connector repository (`bin/closehub-deploy`), which reads the zip from disk and the credentials from the macOS Keychain.

Never put credentials in the prompt, the repository or this skill. Never upload with the `install-*-zip` MCP abilities: base64 inside a call breaks on real zips.

## Inputs

- Site URL (`https://…`) and type (`plugin` or `theme`).
- The plugin/theme folder to build, or an existing zip in `~/Google Drive/Mi unidad/WordPress Plugins/proyectos`.
- Whether to activate it (default: no; a replaced item that was active stays active).

## Procedure

### 1) Preflight

```bash
command -v closehub-deploy || echo "MISSING closehub-deploy"
security find-generic-password -s "closehub-deploy:<host>" >/dev/null 2>&1 || echo "MISSING credentials"
```

| Missing | Fix |
|---|---|
| closehub-deploy | `ln -s <closehub-connector repo>/bin/closehub-deploy /usr/local/bin/closehub-deploy` |
| credentials | The user creates an application password on the site (Users → Profile → Application Passwords) and runs `security add-generic-password -U -s "closehub-deploy:<host>" -a "<wp-username>" -w` themself. Do not ask them to paste it in the chat. |

Check what is installed now, and keep it for the report:

```bash
closehub-deploy version <site> <type> <slug>
```

A `404` means the site's CloseHub Connector is too old: stop and tell the user.

### 2) Build

If there is no zip for this version yet, run **close-wpdev-wdis** (the deploy uses the stable version on the default branch; ask before deploying a beta zip to production). The zip is `{slug}-{version}.zip` in the shared Drive folder.

### 3) Confirm

Deploying replaces code on a live site. Before uploading, show the user the site, the slug, the installed version, the new version and the zip path, and wait for an explicit yes. A yes covers this deploy only.

### 4) Upload

```bash
closehub-deploy install <site> <type> "<zip path>" [--activate]
```

The script checks that the zip only holds the `<slug>` folder before uploading. The site backs up the installed copy, replaces it, and clears WP Rocket and OPcache.

### 5) Verify

```bash
closehub-deploy version <site> <type> <slug>
curl -sS -o /dev/null -w '%{http_code}\n' <site>/
```

- `version` equals the new version, and `active` is what was expected.
- The home page answers `200`. If it answers `500` or the theme looks broken, roll back right away (step 6) and report it.
- If `cache.wp_rocket` was `false`, run the `closehub/clear-cache` ability.

### 6) Roll back (if needed)

```bash
closehub-deploy rollback <site> <type> <slug>
```

### 7) Report

Site, slug, previous → new version, active, backup file name, cache result, and the verification results.
