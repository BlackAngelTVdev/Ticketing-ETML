freebu# GLPI SSO Bridge

A GLPI plugin that bridges an external SSO portal (e.g. `apps.pm2etml.ch/auth`)
to GLPI. Once a user is authenticated on the SSO portal, the plugin finds the
matching GLPI user account and opens a GLPI session for it.

This is a rework of the standalone "SSO bridge" PHP files so that it works
with GLPI:

* the folder was renamed `sso-bridge` → `ssobridge` (GLPI plugin keys cannot
  contain hyphens);
* the plugin now installs like a regular GLPI plugin (Setup ▸ Plugins) and
  its pages are served by GLPI 11's router;
* `callback.php` no longer stops at a `TODO`: it logs the validated user into
  GLPI (`Auth` + `Session::init`, same machinery as a normal login, 2FA/MFA
  included);
* configuration moved from `config.ini.php` to an environment / `.env` file:
  access token and callback URI.

## Flow

1. The GLPI login page shows two buttons: **Connexion Eduvaud** (SSO) and
   **Connexion classique** (legacy email / password form, shown after a click
   on the button). When
   `SSO_AUTO_REDIRECT` is enabled, the login page is skipped altogether and
   anonymous visitors are redirected straight to the SSO portal (append
   `?nosso=1` to any GLPI URL to keep the regular login form).
2. `front/login.php` generates a correlation id, stores it in the PHP session
   and redirects to the SSO portal.
3. The portal authenticates the user and redirects back to
   `front/callback.php`.
4. `callback.php` exchanges the correlation id against the validated identity
   (email + username) through the portal `bridge/check` API.
5. The plugin finds the GLPI user (email first, then username), creates the
   account automatically if needed, and starts the GLPI session. The browser
   ends on the GLPI home page (or on the `redirect` parameter if one was
   passed).
6. `front/logout.php` destroys the GLPI session and invalidates the SSO
   session on the portal.

## Installation

1. Copy the `ssobridge` folder into GLPI's `plugins` directory
   (`glpi/plugins/ssobridge`).
2. Configure the plugin: copy `.env.example` to `.env` and set the values
   below (or export real environment variables, e.g. in Docker).
3. Install and activate the plugin:
   * UI: *Setup ▸ Plugins* → find **SSO Bridge** → *Install* → *Enable*, or
   * CLI:
     ```bash
     php bin/console plugin:install ssobridge
     php bin/console plugin:activate ssobridge
     ```
4. Open the GLPI login page: the **Connexion Eduvaud** (SSO) and
   **Connexion classique** buttons are displayed.

> The plugin is served by GLPI at
> `/plugins/ssobridge/front/login.php` (start) and
> `/plugins/ssobridge/front/callback.php` (portal callback). If the SSO
> portal requires a whitelisted callback address, set `SSO_CALLBACK_URI`.

## Configuration

| Variable               | Description                                                                          |
| ---------------------- | ------------------------------------------------------------------------------------ |
| `SSO_PORTAL_URL`       | Base URL of the SSO portal (default `https://apps.pm2etml.ch/auth/`).                |
| `SSO_ACCESS_TOKEN`     | **Access token (API key)** given by the SSO portal maintainer. Required.             |
| `SSO_CALLBACK_URI`     | Callback URL/URI used in the SSO redirect. Leave empty to auto-build it.             |
| `SSO_AUTO_CREATE_USERS`| `1` to create the GLPI user when the SSO account has no match (default `1`).          |
| `SSO_DEFAULT_PROFILE`  | Profile for auto-created users: empty = GLPI default profile, `none` = no profile,   |
|                        | or a profile name (e.g. `Self-Service`).                                             |
| `SSO_AUTO_REDIRECT`    | `1` to redirect anonymous visitors of the login page straight to the SSO portal  |
|                        | (default `0` = show the two login buttons). With   |
|                        | auto-redirect on, append `?nosso=1` to any GLPI URL to reach the login form.   |

Real environment variables take precedence over the `.env` file.

Example `.env`:

```dotenv
SSO_PORTAL_URL=https://apps.pm2etml.ch/auth/
SSO_ACCESS_TOKEN=my-access-token
SSO_CALLBACK_URI=https://glpi.example.org/plugins/ssobridge/front/callback.php
SSO_AUTO_CREATE_USERS=1
SSO_DEFAULT_PROFILE=Self-Service
```

## User matching

* the GLPI user is looked up by **email** first (unique `glpi_useremails`
  entry), then by **username** (active, non-deleted account);
* when no account matches and `SSO_AUTO_CREATE_USERS` is enabled, the user is
  created with the external auth type (`authtype = external`) and, when a
  profile is configured or a GLPI default profile exists, that profile is
  granted on the root entity;
* if the account exists but is deactivated/deleted, the login is refused;
* if the account has no profile at all, GLPI itself refuses the session
  ("you are not allowed to connect") until an administrator grants rights;
* users with 2FA enabled (or with mandatory 2FA setup) go through the normal
  GLPI MFA pages before the session is created.

## Docker image (self-contained GLPI + plugins)

A `Dockerfile` sits at the GLPI source root (`glpi/Dockerfile`) and builds a
production image containing the current GLPI sources **and the plugins**
shipped in `glpi/plugins/` (including this one). Rebuilding the image
always refreshes the plugin code:

```bash
# from the GLPI source directory (glpi/)
docker compose -f docker-compose.prod.yaml up -d --build
```

On first start the container:

1. waits for MariaDB,
2. installs GLPI (`config_db.php` + schema) when no configuration exists,
3. installs and activates the bundled plugins (`plugin:install ssobridge`,
   `plugin:activate ssobridge`),
4. starts Apache.

The SSO values are read from `glpi/plugins/ssobridge/.env` at runtime (the
compose file passes it through `env_file`), or can be set directly in the
`environment:` block of `docker-compose.prod.yaml`. Secrets are excluded
from the image build (`.dockerignore`) and never baked in.

The web UI is exposed on `http://localhost:8081` by default (the dev
environment already uses 8080; adjust the port mapping in
`docker-compose.prod.yaml` if needed). Once GLPI is up, you still have to
finish the installation in the browser (admin account) if no admin user was
created by the CLI install.

## Troubleshooting

* **"SSO Bridge is not configured"**: set `SSO_ACCESS_TOKEN` in `.env` or in
  the environment.
* **"No pending SSO login was found"**: the PHP session was lost between the
  login page and the callback (cookie issue, session save path, or the portal
  did not preserve cookies).
* **"Cannot reach the SSO portal"**: network/DNS issue; PHP `curl` (or
  `allow_url_fopen`) must be enabled — both are standard in GLPI 11.
* **Plugin not shown in Setup ▸ Plugins**: check the folder name is exactly
  `ssobridge` (lowercase, no hyphen) in `glpi/plugins/`.
* **"Your session has expired. Please log in again." right after the portal
  login**: the browser lands on a GLPI page while still anonymous, so the GLPI
  session was never opened. Since the plugin now intercepts any GLPI page
  carrying a pending correlation id (see "Callback URL" below), this should no
  longer happen; if it does, check that cookies work (same domain between the
  portal and GLPI, no `session.cookie_secure` on plain HTTP) and that
  `SSO_CALLBACK_URI`, when set, points to
  `https://<host>/plugins/ssobridge/front/callback.php`.

## Callback URL

The `redirectUri` (callback URL) given to the portal can be either:

* the plugin callback: `https://<host>/plugins/ssobridge/front/callback.php`
  (recommended, set it explicitly with `SSO_CALLBACK_URI` if needed); **or**
* any GLPI page URL on the same host, e.g. `https://domaine.ex/ServiceCatalog`
  or `https://<host>/Helpdesk`. The plugin detects the pending login on that
  page, opens the GLPI session right there and leaves the user on it — no
  "session expired" loop.

Redirect targets passed as `?redirect=` (or stored for the round-trip) accept
local paths (`/ServiceCatalog`, `/Helpdesk?x=1`) and same-host absolute URLs;
external hosts are refused (open redirect protection).
