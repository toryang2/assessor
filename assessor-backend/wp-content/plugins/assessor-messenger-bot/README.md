# Assessor Facebook Messenger Bot (WordPress)

Connects your Facebook Page to **Assessor property search** via the public API (`Assessor_Public_API` on the same WordPress site).

**Webhook URL (Meta callback):**

```text
https://YOUR-SITE/wp-json/assessor/v1/messenger/webhook
```

Example production (from `assessor-frontend/.env.production`):

```text
https://archive.massokitaotao.net/wp-json/assessor/v1/messenger/webhook
```

---

## Quick start

### 1. WordPress

1. Activate **Assessor History Archiving API** and **Assessor Messenger Bot**.
2. **Settings → Permalinks → Save** (flushes REST routes).
3. **Settings → Messenger Bot** — set **Verify token** and **Page access token** (optional: **App secret** for signature checks).
4. Assessor app → **Settings → API Keys** — create a key if you use external tools; the built-in bot calls search **internally** (no API key in the plugin).

### 2. Meta Developer Console

1. [developers.facebook.com](https://developers.facebook.com/) → your app → **Messenger**.
2. **API setup** → add your Page → copy **Page access token** into WP **Messenger Bot** settings.
3. **Webhooks** → Callback URL = webhook URL above; **Verify token** = same as WP.
4. Subscribe to **`messages`** for your Page.

### 3. Test on Messenger

Message your Page: `help`, `search …`, `tdn …`, a **full TD** on one line, or a **name** fragment.

---

## Bot commands

| User sends | Action |
|------------|--------|
| `help`, `start`, `hi` | Command list |
| `search <query>` | Name search (`q`) or TD-only if `<query>` is one whole TD token |
| `tdn <number>` | Current declaration for that TD (chain head) |
| Any other text (2+ chars) | Name search, or TD-only if the whole message is one TD-shaped token |

Messenger **does not** support real modals or stacked text boxes. Combined **name + TD** search is disabled for now.

---

## Option B — External bot (ManyChat, Make, n8n, custom server)

HTTP call (credentials from Assessor → Settings → API Keys):

```http
GET https://YOUR-SITE/wp-json/assessor/v1/public/properties?q=senarosa&per_page=5
X-API-Key: assessor_xxxxxxxx
X-API-Secret: your_48_char_secret
```

Combined **name + tax_declaration_number** requests are disabled. Use one search type per call.

Lookup by TDN:

```http
GET https://YOUR-SITE/wp-json/assessor/v1/public/properties/by-tax-number/12-345-678
X-API-Key: ...
X-API-Secret: ...
```

---

## Legacy `.env` (optional)

If you previously used `.env`, copy `.env.example` → `.env`. On first load, empty WP options are filled from `.env` once. Prefer **Settings → Messenger Bot** in WP admin.

---

## Troubleshooting

| Problem | Fix |
|---------|-----|
| Webhook verify fails | Verify token matches Meta exactly; HTTPS URL reachable; permalinks saved |
| No reply | Page access token set in WP; check `wp-content/debug.log` for `Assessor Messenger` |
| Search always empty | Properties exist in DB; try same query in Assessor app search |
| 403 invalid signature | App secret in WP must match Meta app secret, or leave app secret empty to skip check |

---

## Security

- Do not commit `.env` or Page tokens to git.
- Use **App secret** in WP so Meta POST bodies are HMAC-validated.
- Public API keys are for **external** HTTP clients only; the built-in plugin uses internal PHP.
