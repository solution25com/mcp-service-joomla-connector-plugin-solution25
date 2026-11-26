# solution25 AI Connector

Lets Claude, ChatGPT, Gemini and other AI assistants work with your Joomla site. You choose what the
assistant may do (view, edit and create articles, view menus) and can change or remove that access at
any time. The assistant works through its own restricted user, never an administrator.

The extension connects through the solution25 MCP gateway at `https://mcp.solution25.com/joomla`.

## Quick start

1. Download `pkg_aiconnector-<version>.zip` from the
   [latest release](https://github.com/solution25com/mcp-service-joomla-connector-plugin-solution25/releases/latest)
   and install it in **System > Install > Extensions**.
2. In your AI client, add the connector `https://mcp.solution25.com/joomla` and sign in. You get a
   one-time code like `S25-K7QM-4XTD`.
3. In Joomla, open **Components > AI connector**, enter the site's public https address (for example
   `https://www.example.com`) and the code, click *Check code*, check the request, pick the permissions
   and click *Connect*.

Requirements: Joomla 6, PHP 8.3 or later. Updates are offered in Joomla's own update manager.

## For developers

Connects a Joomla site to AI clients through the solution25 MCP gateway (`node-mcp`, platform
`joomla`, address `/joomla`). An admin pairs the site with the one-time code shown on the gateway's
sign-in page, picks what the AI client may do, and can change or remove that access at any time. The
gateway then works with the site through Joomla's own Web Services API and a few routes this package
adds for HikaShop.

- Package `pkg_aiconnector`: component `com_aiconnector`, system plugin `plg_system_aiconnector`
  (API guard), web services plugin `plg_webservices_aiconnector` (routes)
- Joomla 6 (tested on 6.2), PHP 8.3+; HikaShop optional (shop permissions need it)
- Admin page: **Components > AI connector**, only for admins with the *AI connector: manage* permission

## Build and install

```bash
build/build.sh                # writes dist/pkg_aiconnector-<version>.zip
php cli/joomla.php extension:install --path=/path/to/pkg_aiconnector-<version>.zip
```

Or upload `dist/pkg_aiconnector-<version>.zip` in **System > Install > Extensions**. The *Web Services - AI
connector* and *System - AI connector* plugins are enabled on install.

## How it works

**Pairing.** In the AI client the user adds the solution25 connector and signs in. The gateway's sign-in
page shows a code like `S25-K7QM-4XTD` (single use). The admin pastes it on the admin page, checks who
asks for what, picks permissions and clicks *Connect*. An admin can only grant rights they hold
themselves. One connection per site.

On connect the extension, in one database transaction:

1. creates a user group under *Public* with only *API login* and the rights of the picked permissions,
   and a connector user in it (random unknown password, never a Super User). The group gets Joomla's
   *Default Forbidden List* text filter, as Author, Editor and Publisher have: normal HTML stays, while
   scripts, iframes and event handlers are removed. Public's *No HTML* would otherwise strip the article
   text on every save, even a title-only one;
2. creates an API token for that user, in the format of Joomla's API tokens, and sends it once to the
   gateway in `pairing/complete` (never shown or logged). Its seed is stored under the connector's own
   profile keys, so Joomla's token plugin never accepts it: only the connector's *API Authentication -
   AI connector token* plugin does, and only while the component and the API guard plugin are enabled
   and the connection can be read. Disabling any of them stops the token (401), so the connector fails
   closed;
3. stores the connection.

If anything fails, all of it is rolled back, and if the gateway had already paired, it is told to
disconnect.

**Disconnect** blocks the user and deletes the token, group (with its text filter) and user first (access ends at once, even
if the gateway is unreachable), then tells the gateway. A `404` from the gateway means it was already
disconnected.

**Permissions** can be changed later, within what the AI client asked for. They apply on the site first,
then the gateway is told.

**Audit log.** Connect, permission change and disconnect attempts are recorded with an outcome
(`success`, `failure`, `gateway_not_told` or `pending`), except as noted below; the admin page shows
the 20 most recent entries. Requests the admin page rejects before they reach the connection service,
such as an expired form token, a missing pairing request or a stale connect form, are not recorded. A
failure on the site is recorded as `failure`, even when a disconnect had already blocked the client's
access. Once the change is saved, a `pending` entry is written in the same transaction and settled when
the action finishes; for permission changes and disconnects that is after the site tries to tell the
connector server, and the outcome is `gateway_not_told` if it could not be told. If the request stops
before then, the entry stays `pending`. If a database lock or connection error stops the pending entry
from being written, the change is rolled back and recorded as `failure`. If it fails for any other
reason, the change goes ahead and its outcome is written as a new entry when the action finishes, so a
request that stops before then leaves no entry. When an outcome cannot be written, the admin page shows
a warning after the action (an uninstall does not), and the cause is written to the AI connector log if
that log can be written. Entries older than 180 days are pruned whenever an entry is written or
settled; uninstalling disconnects without saying whether the connector server was told, and removes the
audit log.

Connect, change permissions and disconnect run one at a time (lock).

## Permissions and Joomla rights

| Permission | Rights of the connector group |
| --- | --- |
| `content.viewer` | `com_content`: manage |
| `content.editor` | `com_content`: manage, edit, edit state. The guard lets an edit change only the title, the text, the meta description and the state (published or unpublished) |
| `content.creator` | `com_content`: manage, create. The guard lets a new article set only the title, category, text, meta description and language; it is saved unpublished |
| `menu.viewer` | `com_menus`: manage. The guard allows the site menu lists only, not a menu or menu item by id |
| `product.viewer`, `order.viewer`, `customer.viewer` | `com_hikashop`: manage (not offered until the HikaShop routes exist) |
| `product.editor`, `order.editor` | `com_hikashop`: manage, edit (not offered until the HikaShop routes exist) |

Editor and creator each need the viewer permission of the same area. The group never gets rights over
users, groups, access levels, global configuration, extensions, plugins, templates, the installer or
the token plugin.

**API guard.** Joomla's Web Services check write rights but not read rights. The system plugin
therefore only lets the connector user reach the routes its permissions allow, and every
`aiconnector/*` controller checks the permission again. If the guard cannot read the connection, it
refuses the connector user.

## API

Base: `https://<site>/api/index.php/v1`, `Authorization: Bearer <API token>` (or `X-Joomla-Token`).
The full contract with the gateway, including the `aiconnector/*` routes, is in
[docs/GatewayContract.md](docs/GatewayContract.md).

## Configuration

- **Gateway address:** fixed to `GatewayConfiguration::DEFAULT_MCP_URL` (`https://mcp.solution25.com/joomla`).
  The admin page shows it as the address to add in the AI client, and `/mcp` on the same gateway for
  a client that should also reach shops and sites of other platforms. For development the server
  environment can override it with `AI_CONNECTOR_GATEWAY_URL`, e.g. `https://<ngrok>/joomla` (https, or
  http only to `localhost`, `127.0.0.1` or `host.docker.internal`).
- **Site address:** the admin enters it next to the connection code when connecting; it is pre-filled
  from `$live_site` when that is set. It must be https on the site's own public domain, is shown again on
  the review screen, and is the only address sent to the gateway. The connector never takes it from the
  request's host. In development (gateway override or debug mode) `.ddev.site` is allowed. The gateway
  refuses private addresses, so a local site needs a public https URL (`ddev share` or ngrok).

## Status

Done: pairing, connector user and token, permission changes, disconnect, audit log, API guard,
`aiconnector/status`. Still to build: the HikaShop routes (`aiconnector/products`, `orders`,
`order-statuses`, `reports/sales`, `customers`) described in the contract. Until they exist, the
extension does not offer the shop permissions (`product.*`, `order.*`, `customer.viewer`).

## Out of scope

Joomla 4, VirtueMart, uploads, translations, modules and templates, several connections per site.

## License

GNU General Public License version 2 or later. See [LICENSE](LICENSE).
