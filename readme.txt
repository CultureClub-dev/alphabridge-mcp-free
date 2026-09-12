=== AlphaBridge MCP ===
Contributors: cultureclub
Tags: mcp, ai, automation, rest-api, tools
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 4.2.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Talk to your WordPress site. Claude and other AI assistants manage content, media and settings over one secure, native MCP endpoint.

== Description ==

**Your WordPress site, managed in conversation.**

Tell Claude what you want done — "draft a post from these notes, add last week's photos, fix the SEO titles and schedule everything for Friday" — and it happens on your site. Not through screen-clicking or raw admin access, but through structured tools that respect WordPress permissions on every single call.

AlphaBridge MCP turns your WordPress site into a native **Model Context Protocol (MCP) server**. AI clients such as Claude connect over one authenticated HTTPS endpoint and manage content, media, taxonomies, comments, widgets and site settings.

**You stay in control**

Handing an AI the keys to your site should feel safe — so control comes first:

* Every connection acts as a real WordPress user, and every tool checks the matching WordPress capability. What that user may not do, the AI cannot do.
* Scoped connections: hand out a read-only or content-only key with an optional expiry instead of full access. Rotate a connection's secret in one click.
* Tool groups you can switch off entirely — disabled tools vanish from the MCP surface. Plus a global read-only mode.
* An audit log records every tool call, and a fixed rate limit stops abusive request bursts.

**Connected in two minutes**

Paste your endpoint URL into Claude, click Connect, approve on your own site's login-protected consent screen — no token copying (standard OAuth 2.1 with PKCE; the login is your WordPress login, no account with us). For clients without a Connect button, create a token manually and paste one ready-made config.

**Nothing extra to host**

Unlike bridge-based solutions, AlphaBridge speaks MCP directly in PHP inside WordPress. No Node middleware, no external service, nothing else to run or pay for — it works on ordinary WordPress hosting, which makes it faster and more stable.

**Free means free**

Everything in this plugin is fully functional: no license keys, no registration, no plan-based, cumulative or time-based usage limits, no locked features.

**What's inside**

* Native MCP endpoint (JSON-RPC 2.0 over HTTP POST; protocol versions 2024-11-05, 2025-03-26, 2025-06-18) — no external server required.
* 39 structured tools across content, media, taxonomies, comments, widgets, site settings, site info, SEO reads and search.
* Bearer-token authentication mapped to a real WordPress user, with per-tool capability checks.
* Unlimited connections — create one deliberately limited key per client.

Need more? A separate commercial add-on, AlphaBridge MCP Pro, adds tool groups for the database, users, plugin and theme files, WooCommerce, migration and one-step site deployment over SFTP. It is entirely optional — this free plugin is complete on its own and stays fully functional without it. Details are on the plugin website.

AlphaBridge is our own product brand for this project. MCP (Model Context Protocol) is an open protocol standard; this plugin is an independent implementation and is not affiliated with or endorsed by the protocol's authors or by any other vendor.

== Installation ==

1. Upload the `alphabridge-mcp` folder to `/wp-content/plugins/` (or install the ZIP via Plugins → Add New → Upload).
2. Activate the plugin.
3. In Claude (Settings → Connectors → Add custom connector) add the endpoint `https://your-site.tld/wp-json/alphabridge/v1/mcp` and click Connect — you approve on your own site's login-protected consent screen. Done.
4. For clients without a Connect button (Cursor, Claude Code, scripts): open **Settings → AlphaBridge MCP**, create a connection manually and copy its token.

== Frequently Asked Questions ==

= Is it secure? =
Every request needs a token bound to a WordPress user; each tool enforces the matching WordPress capability, including object-level checks for the specific post, attachment or taxonomy (non-public taxonomies additionally require that taxonomy's own capability). Powerful tools are off by default and only run once the admin enables their group. All calls are logged. Arbitrary option or transient values cannot be read through this plugin at all — only a fixed list of common site settings is exposed. Post, term and user meta is layered-protected: protected ("_"-prefixed) keys, keys flagged by is_protected_meta(), and two kinds of credential-shaped key are refused: keys whose whole name is a credential word, singular or plural (token, secret, password, passphrase, passcode, pwd, otp, credential), and keys containing one of a fixed list of compound credential patterns (api_key, access_token, client_secret, license_key, oauth, _token, _secret, _password, passwd, …). Case and surrounding whitespace are ignored. The list is matched literally, which makes this guard deliberately conservative rather than exhaustive: token_count, password_hint, credential_type, api_version, counters such as maxTokens and camelCase spellings such as accessToken all pass it, and the layers around it do the real work — and every generic post, term and user meta read or write additionally passes WordPress's own per-key meta capability (edit_post_meta / edit_term_meta / edit_user_meta), which honours auth_callback rules that other plugins register via register_meta() (the media and SEO tools read only their own fixed keys). A key you may not edit is not exposed over MCP either. Tokens are accepted via the Authorization or X-Api-Key header — header authentication is the default. An admin can optionally enable a connector URL that carries the token in its path (served with Referrer-Policy: no-referrer and Cache-Control: no-store); this is off by default, because a token in a URL leaks more easily. Query-string tokens are never accepted. If you turn the connector URL on, treat it like a password: it contains the token — rotate the connection if the URL is shared, logged or pasted anywhere.

= Does it work on shared hosting? =
Yes. It is pure PHP and uses the WordPress REST API. PHP 8.0+ and HTTPS are recommended.

= Is the free plugin limited? =
No. Every feature in this plugin works without payment, registration or license keys, and there are no plan-based, cumulative or time-based usage limits. A uniform security throttle (120 requests/minute, identical for every user) protects your server from abusive request bursts.

= Does the plugin send data anywhere? =
No. It contacts no external service on its own. The only outbound request happens when you explicitly ask a tool to fetch a file from a URL you provide (see External services).

== Screenshots ==

1. Set up the Claude.ai connector in two steps — copy the endpoint, then create a connection. Existing connections are listed and managed in the same place.
2. The moment you create a connection, everything you need is shown once — the ready-made connector URL for Claude.ai, the Bearer token, and a copy-paste config for Cursor / Claude Code — with a reminder to save it, because the token is shown in full only once.
3. Optional advanced settings for a connection: a label, a read-only or content-only access scope, the WordPress user it acts as, and an optional expiry.
4. Enable or disable tools by functional group. Powerful ("mighty") tools are off by default, and one switch turns on a global read-only mode.
5. Every tool call is written to the activity log with its status (allowed or denied).

== External services ==

This plugin makes no automatic outbound requests and sends no telemetry. One tool can contact an external address, and only on your explicit instruction: when you call `wp_upload_media_from_url` with a URL, the plugin downloads that file from the address you provide (and up to a few safely re-validated redirects; SSRF-guarded, type- and size-checked). The plugin itself initiates no other outbound requests.

== Changelog ==

= 4.3.0 =
* **Breaking (Pro): the WooCommerce tools are renamed.** `wc_list_orders` becomes `wp_wc_list_orders`, and so on for all nine. Same reason as below, and the same one-time move of any per-tool on/off override you had set.
* **Breaking: the two SEO tools are renamed.** `seo_detect` becomes `wp_seo_detect` and `seo_get` becomes `wp_seo_get`. Every other tool already carried the `wp_` prefix; these two did not. The prefix is what keeps a WordPress tool from colliding with a same-named tool from another CMS when a client reaches several sites at once — without it, two different tools merge into one and one of the two sites gets a description that does not fit it. If you have saved instructions or automations that call the tools by name, change those two names. Nothing else about what they do has changed. If you had switched one of them off in the settings, it stays off — the stored override moves to the new name during the update.
* New: a revocation endpoint (RFC 7009) at `/wp-json/alphabridge/v1/oauth/revoke`. A client that holds a connection token can now hand it back and end the connection itself, instead of the connection lingering until somebody deletes it in the settings. The endpoint is announced in the discovery document, so clients find it without being told the path. It answers the same way whether or not the token existed, which is what the standard requires: the answer must not reveal whether a token is valid.
* New: the `initialize` response now carries a short self-description under `_meta` — which CMS answered, its version, the plugin version, the licensed edition and the scope of the token that was used. A hub or client that speaks to several sites can tell them apart without guessing from version strings. Nothing about your content is included.
* Change: a connection created through AlphaBridge Connect is now labelled with that name alone in the connections list, instead of "AlphaBridge Connect · Claude Connect".

= 4.2.3 =
* Fix: a backslash in a title, name, description, comment or custom field was silently dropped when writing. WordPress expects the data it is given to be escaped and removes one level of escaping itself, so passing it through unchanged turned "C:\Docs" into "C:Docs" — with no error anywhere. Every write path now escapes what it hands over: posts and pages, media titles and alt text, terms, post meta and comments. Values without a backslash are unaffected, and nothing already stored changes.

= 4.2.2 =
* Hardening: the meta-key credential guard now refuses keys whose whole name is a bare credential word — token, secret, password, passphrase, passcode, pwd, otp, credential, and their plurals. Previously only compound patterns such as api_token or client_secret were caught, so a key named exactly "token" could be read or written by a user who already held the key's own edit capability. The compound list itself is unchanged apart from license_key, so everything 4.2.0 refused stays refused and no ordinary key changes behaviour. If one of your fields is named exactly like one of those words, its value stays untouched in the database but is no longer readable or writable over MCP.
* Docs: the security answer in the FAQ now describes the rule exactly — which names are refused as a whole, that the compound list is matched literally, and where the guard deliberately stops — instead of implying that any key containing "token" or "secret" is refused.

= 4.2.0 =
* New: Connect from Claude. The site now speaks the OAuth flow MCP clients expect: add the endpoint URL in Claude (web, desktop or mobile) and click Connect — Claude discovers the site, you approve on a login-protected consent screen (choosing full, content-only or read-only access), and the approved connection appears in the connections list like any other, revocable at any time. Technically: RFC 9728/8414 discovery metadata under /.well-known/, dynamic client registration (RFC 7591), authorization-code grant with PKCE (S256 required) and resource indication (RFC 8707); 401 responses point clients at the metadata via WWW-Authenticate. Issued tokens are ordinary hashed connection tokens — nothing new is stored in readable form. No account with us, no external service: the login is your own WordPress login. Can be switched off under “Connect from Claude (OAuth, advanced)”; manual tokens keep working unchanged.

= 4.1.6 =
* The connector URL is now only offered once connector-URL authentication is actually enabled — if it is off, a one-click “Enable and show the connector URL” button (with the same security note as the setting itself) appears in its place. A copied connector URL therefore always authenticates; previously the URL was shown with only a small note and would be rejected until the setting was switched on. The Bearer token and the Cursor / Claude Code config are unaffected and always work.

= 4.1.5 =
* When you create a connection, the settings screen now shows everything ready for copy-paste: the full connector URL with the token embedded (for Claude.ai), the Bearer token, and a ready-made config snippet for Cursor / Claude Code — plus a clear reminder to save it, because the token is shown in full only once.

= 4.1.4 =
* Simpler, clearer connector setup: the settings screen now shows one “Claude.ai Connector” box with a two-step flow — copy the endpoint, then click one “Create connection” button (label, access scope, user and expiry moved into optional advanced settings). The token appears right below the button, and existing connections are listed in the same box. Removes the previous duplicate create buttons.

= 4.1.3 =
* Uninstall no longer removes this plugin's data while the separately distributed AlphaBridge MCP Pro plugin is still installed — Pro shares this core data (connections, tool state, settings, log), so removing the free plugin alone keeps Pro fully configured. Uninstalling last (or alone) cleans up as before.

= 4.1.2 =
* Added an informational box about the separately distributed AlphaBridge MCP Pro plugin to the plugin's own settings page. Nothing in this free plugin changed — it remains complete and fully functional on its own.

= 4.1.1 =
* Hardened connection handling: the token is shown once at creation and only its hash is stored; scope values are validated fail-closed; header authentication is the default and connector-URL authentication is an explicit opt-in.
* User-meta reads limited to a fixed list of standard profile fields.
* Improved capability checks and packaging consistency.

= 4.1.0 =
* Added connection scopes, an optional expiry and one-click rotation.
* Improved transport and object-level security.

= 4.0.0 =
* Rebuilt the directory package as a fully standalone free plugin.
* Connections are unlimited and every bundled tool is available without restriction.
* Removed the raw option/transient readers; site settings are available through wp_get_site_settings.
* Update status is read from WordPress's cache and performs no remote request.
* Broadened object-level and per-key capability checks across posts, meta, terms, taxonomies, media and search.
* Simplified the settings screen and the distribution package.

= 3.0.0 =
* MCP tool annotations (readOnlyHint, destructiveHint, idempotentHint, openWorldHint) for every tool.
* MCP protocol negotiation: the server echoes the client's requested protocol version when supported (2024-11-05, 2025-03-26, 2025-06-18).
* New global read-only mode: one switch blocks every writing tool; read, list and search tools keep working.
* Better error messages: argument validation reports all missing or invalid fields at once.

= 2.0.0 =
* Restructured for the WordPress.org directory: this plugin contains the core free toolset.
* Settings-screen JavaScript is enqueued from assets/admin.js (2.0.2).

= 1.x =
* Initial development line: MCP endpoint, token auth, tool groups, security hardening (SSRF guards, path traversal guards, capability checks, rate limiting, audit log), 26 bundled translations.
