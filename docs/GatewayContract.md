# Gateway contract

How the solution25 gateway (`node-mcp`, platform `joomla`, MCP address `/joomla`) talks to this extension. The gateway side lives in `node-mcp/src/joomla/` and `node-mcp/src/mcp/tools/joomla/`; its test fake is `node-mcp/test/support/fake-joomla-site.ts`.

## Calls from the extension to the gateway

All `POST` to `<gateway>/api/v1/…`, JSON, 10 s timeout, redirects not followed. Errors are `{ "reason": "…", "message": "…" }`; `204` means OK without a body.

| Call | Body | Answer |
| --- | --- | --- |
| `pairing/request` | `{ code }` | `{ clientName, permissions, startedAt, verifiedClient?, returnHost? }` |
| `pairing/complete` | see below | `{ connectionId, connectionSecret }` |
| `connections/{id}/permissions` | `{ accessLevel, permissions }`, `Authorization: Bearer <connectionSecret>` | `204` |
| `connections/{id}/disconnect` | `{}`, `Authorization: Bearer <connectionSecret>` | `204`; `404` means already disconnected |

`pairing/complete`:

```json
{
  "code": "S25-XXXX-XXXX",
  "shop": {
    "platform": "joomla",
    "url": "https://example.com",
    "apiUrl": "https://example.com/api/index.php/v1",
    "name": "Example",
    "joomlaVersion": "6.2.0",
    "extensionVersion": "1.0.0",
    "hikashopVersion": "6.8.0"
  },
  "credentials": { "apiToken": "<Joomla API token>" },
  "accessLevel": "Custom",
  "permissions": ["content.viewer", "product.viewer"]
}
```

- `url` is https, without a trailing slash; `apiUrl` must be exactly `url` + `/api/index.php/v1` or the gateway answers `400`.
- `hikashopVersion` is `null` when HikaShop is not installed and enabled.
- `apiToken` is the connector user's Joomla API token, base64 of `sha256:<user id>:<hmac>`. It is sent once here and never shown or logged.
- Keys: `content.viewer`, `content.editor`, `content.creator`, `menu.viewer`, `product.viewer`, `product.editor`, `order.viewer`, `order.editor`, `customer.viewer`. Editor and creator each need viewer of the same area (creator does not need editor). Unknown keys are dropped by the gateway.

## Calls from the gateway to the site

Every request goes to `apiUrl` + `/<resource>` with:

- `Authorization: Bearer <apiToken>` and `X-Joomla-Token: <apiToken>` (for servers that drop `Authorization`),
- `Accept: application/vnd.api+json, application/json`, and for writes `Content-Type: application/json` with a plain JSON object body,
- no redirects followed, 20 s timeout, at most 5 MiB answer.

Answers are JSON:API: `{ "data": { "type", "id", "attributes", "relationships"? } }` for one item, `{ "data": [ … ], "meta": { … }, "links": { … } }` for a list. Errors are Joomla's `{ "errors": [ { "title", "code" } ] }`; the gateway shows `title` (or `detail`). `401` means the connection ended, `403` that the permissions do not allow it, `404` that the item does not exist. An HTML answer is reported as an offline site (`503`) or a missing API.

### Joomla core routes (the API guard allows them per key)

| Key | Routes the gateway uses |
| --- | --- |
| `content.viewer` | `GET content/articles` with `filter[search]`, `filter[category]`, `filter[state]`, `list[ordering]=a.modified`, `list[direction]=DESC`, `page[limit]`, `page[offset]`; `GET content/articles/{id}`; `GET content/categories` (`filter[search]`, paging); `GET content/categories/{id}` |
| `content.creator` | `POST content/articles` with `{ title, catid, articletext, metadesc, language, state: 0 }` (`introtext` and `fulltext` are accepted instead of `articletext`) |
| `content.editor` | `PATCH content/articles/{id}` with any of `{ title, introtext, fulltext, metadesc, state }` (`state` 0 or 1) |
| `menu.viewer` | `GET menus/site`; `GET menus/site/items` with `filter[menutype]`, `page[limit]` |

`articletext` is intro text, then `<hr id="system-readmore">` and the full text when there is one, as Joomla's article form sends it. It works on `POST` only: on `PATCH`, Joomla 5.4 fills the stored `introtext` and `fulltext` into the save, and they override `articletext`, so an edit sends `introtext` and/or `fulltext` (each one given replaces that part; the other part stays). The guard still accepts `articletext` on `PATCH` for older gateways. The API returns the body as one `text` attribute: intro text, a space, then full text.

The guard answers `403` to a write that sends any other field (for example `access`, `catid` on an edit, `featured`, `alias`, `language` on an edit, dates or custom fields), a `state` other than 0 or 1 on an edit, or a `state` other than 0 on a create. Fields given as a `data[...]` query parameter count the same as body fields, as Joomla reads them first. The gateway reads the article's category from `relationships.category.data.id`, and the number of pages from `meta["total-pages"]` and `links.next`.

### The extension's own resources (`aiconnector/…`)

Each checks the connection's key in the controller (`ConnectorContext::require`), not only in the guard. Lists take `limit` (1–50, default 20) and `page` (from 1) and answer `meta: { total, page, limit }`. Dates are `YYYY-MM-DD HH:MM:SS` in UTC, or `null`. Amounts are numbers in the shop's main currency, without tax unless named otherwise; `currency` is the ISO code.

#### `GET aiconnector/status` (any key)

`attributes`: `{ siteUrl, permissions, joomlaVersion, extensionVersion, hikashopVersion, currency }`. Used by `ping`.

The HikaShop resources below are not built yet. Until they are, the extension does not offer `product.*`, `order.*` or `customer.viewer`, so a connection never holds them.

#### Products

`GET aiconnector/products` (`product.viewer`). Query: `q` (part of name or code; a number also matches the id), `published` (`1` or `0`), `maxQuantity` (leaves out unlimited stock), `sort` (`name` default, `quantity` ascending, `price` descending, `modified` newest first), `limit`, `page`. Items:

```json
{ "id": 1, "code": "MUG-1", "name": "Coffee mug", "price": 12.5, "currency": "EUR", "quantity": 40,
  "published": true, "parentId": 0, "variantCount": 2, "modifiedAt": "2026-11-01 08:00:00" }
```

`quantity` is `null` for unlimited stock (HikaShop's `-1`); `price` is the product's main price for quantity 1 in the main currency, `null` without one. Variants are included, with `parentId`.

`GET aiconnector/products/{id}` (`product.viewer`): the item plus `description` (HTML), `metaDescription`, `categories: [{ id, name }]`, `url` (the product page, or `""`), `createdAt`.

`PATCH aiconnector/products/{id}` (`product.editor`). Body with any of `price` (≥ 0), `quantity` (integer ≥ 0, or `null` for unlimited) and `published` (boolean). Only the given fields change, through HikaShop's product class so its caches and triggers run. Answers the changed product like `GET products/{id}`. `422` with a message for invalid values.

#### Orders

`GET aiconnector/order-statuses` (`order.viewer`): items `{ key, name }` with `id` = `key`, in HikaShop's order (`created`, `confirmed`, `cancelled`, `refunded`, `shipped`, …), `name` translated.

`GET aiconnector/orders` (`order.viewer`). Query: `q` (order number, customer name or e-mail; a number also matches the id), `status` (a key), `customerId` (HikaShop user id), `from`, `to` (`YYYY-MM-DD`, by creation date), `limit`, `page`; newest first. Items:

```json
{ "id": 2, "number": "A0002", "status": { "key": "created", "name": "Created" },
  "customer": { "id": 1, "name": "Ada Lovelace", "email": "ada@example.com" },
  "total": 17.5, "currency": "EUR", "itemCount": 1, "createdAt": "2026-11-03 11:30:00" }
```

`GET aiconnector/orders/{id}` (`order.viewer`): the item plus `billingAddress` and `shippingAddress` (`{ name, company, street, street2, city, postcode, state, country, phone }` or `null`), `paymentMethod`, `shippingMethod`, `products: [{ productId, name, code, quantity, price, total }]`, `subtotal`, `shipping`, `discount`, `tax`, `history: [{ status, comment, notified, createdAt }]` (oldest first) and `modifiedAt`.

`POST aiconnector/orders/{id}/status` (`order.editor`). Body `{ status: "<key>", comment: "", notify: false }`. Changes the status through HikaShop's order class, as the HikaShop administration does (history entry, stock, e-mail to the customer only when `notify` is true). Answers the order like `GET orders/{id}`. `422` for an unknown status.

#### Sales

`GET aiconnector/reports/sales` (`order.viewer`). Query: `from`, `to` (`YYYY-MM-DD`, at most 366 days, in the site's time zone), `groupBy` (`day` default, `week`, `month`). Counts the orders whose status HikaShop treats as sold (its "confirmed" and "shipped" statuses from the configuration). `attributes`:

```json
{ "from": "2026-11-01", "to": "2026-11-07", "groupBy": "day", "currency": "EUR",
  "statuses": ["Confirmed", "Shipped"], "orders": 2, "revenue": 47.5, "itemsSold": 3,
  "series": [{ "period": "2026-11-02", "orders": 1, "revenue": 30 }],
  "topProducts": [{ "productId": 1, "name": "Coffee mug", "quantity": 2, "revenue": 25 }] }
```

`topProducts` holds at most 10, by revenue.

#### Customers

`GET aiconnector/customers` (`customer.viewer`). Query: `q` (name or e-mail; a number also matches the id), `limit`, `page`. Items:

```json
{ "id": 1, "userId": 960, "name": "Ada Lovelace", "email": "ada@example.com", "blocked": false,
  "orderCount": 2, "totalSpent": 47.5, "currency": "EUR", "createdAt": "2026-10-01 08:00:00" }
```

`id` is the HikaShop user id; `userId` the Joomla user id, `null` for guests; `blocked` from the Joomla user, `null` for guests.

`GET aiconnector/customers/{id}` (`customer.viewer`): the item plus `addresses` (as in orders) and, only when the connection also has `order.viewer`, `recentOrders` (the five newest, as in the order list).
