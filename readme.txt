=== AlphaBridge MCP – Connect Claude and ChatGPT to WordPress: MCP server with permissions per connection and audit log ===
Contributors: cultureclub
Tags: claude, chatgpt, mcp, mcp-server, ai
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 4.3.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect Claude and ChatGPT to your WordPress site through a native MCP server: rights per connection, audit log, optional hub for all sites.

== Description ==

**Connect Claude and ChatGPT to WordPress — with the rights you choose.**

AlphaBridge MCP turns your WordPress site into a native **MCP server** (Model Context Protocol). An AI assistant such as Claude — on Claude.ai, in Claude Desktop, Claude Code or Cursor — connects over one authenticated HTTPS endpoint and manages content, media, taxonomies, comments, widgets and site settings through structured tools that check WordPress permissions on every single call. ChatGPT connects the same way, as an MCP app on the Plus, Pro, Business, Enterprise and Edu plans. Tell Claude what you want done — "draft a post from these notes, add last week's photos, file it under the right categories and schedule it for Friday" — and it happens on your site, not through screen-clicking or raw admin access.

**You stay in control**

Handing an AI the keys to your site should feel safe — so control comes first:

* Every connection acts as a real WordPress user, and every tool checks the matching WordPress capability. What that user may not do, the AI cannot do.
* Scoped connections: hand out a read-only or content-only key with an optional expiry instead of full access. Rotate a connection's secret in one click.
* Tool groups you can switch off entirely — disabled tools vanish from the MCP surface. Plus a global read-only mode.
* An audit log records every tool call, and a fixed rate limit stops abusive request bursts.

**Connected in two minutes**

Paste your endpoint URL into Claude, click Connect, approve on your own site's login-protected consent screen — no token copying (standard OAuth 2.1 with PKCE; the login is your WordPress login, no account with us). For clients without a Connect button, create a token manually and paste one ready-made config. ChatGPT connects the same way: add the endpoint as an MCP app (Plugins → Add → Create MCP app, authentication OAuth) and approve on your site — checked on 26 September 2026 with ChatGPT Pro, directly and through the hub.

**Several sites, one connection — AlphaBridge Connect**

If you look after more than one WordPress site, you can add one connector in Claude or ChatGPT instead of one per site: the hosted hub at connect.alphabridge-mcp.com links your sites to a single connection in the client you use. Each site keeps its own rights and its own audit log, and you approve every site on that site's own login screen. The hub is optional and free, and this plugin never contacts it on its own — the hub connects to your site for authorization, connection setup and management, and to forward the tool calls you make through it. It stores the site's access key encrypted and passes content through without storing it; the details are in its privacy notice (https://connect.alphabridge-mcp.com/legal/privacy) and its data processing agreement (https://connect.alphabridge-mcp.com/legal/dpa).

**Nothing extra to host**

Unlike bridge-based solutions, AlphaBridge speaks MCP directly in PHP inside WordPress. With a direct connection there is no Node middleware, no external service and nothing else to run or pay for — it works on ordinary WordPress hosting, which makes it faster and more stable. The hub above is the one optional exception, and you choose whether to use it.

**Free means free**

Everything in this plugin is fully functional: no license keys, no registration, no plan-based, cumulative or time-based usage limits, no locked features.

**What's inside**

* Native MCP endpoint (JSON-RPC 2.0 over HTTP POST; protocol versions 2024-11-05, 2025-03-26, 2025-06-18) — no external server required.
* 39 structured tools across content, media, taxonomies, comments, widgets, site settings, site info, SEO reads and search.
* Bearer-token authentication mapped to a real WordPress user, with per-tool capability checks.
* Unlimited connections — create one deliberately limited key per client.

**How it compares**

A dated comparison with other WordPress MCP plugins, every cell checked against the vendors' own pages: https://alphabridge-mcp.com/compare.html

Need more? A separate commercial add-on, AlphaBridge MCP Pro, adds tool groups for the database, users, plugin and theme files, WooCommerce and migration, plus an undo for changes made through it (undo points expire after 24 hours). The Agency plan adds Site Deploy: files, themes and whole builds published onto your own hosting over SFTP; ZIP deploys can run atomically, with rollback when the swap fails. Both are entirely optional — this free plugin is complete on its own and stays fully functional without them. Details are on the plugin website.

AlphaBridge is our own product brand for this project. MCP (Model Context Protocol) is an open protocol standard; this plugin is an independent implementation and is not affiliated with or endorsed by the protocol's authors or by any other vendor.

== Installation ==

1. Upload the `alphabridge-mcp` folder to `/wp-content/plugins/` (or install the ZIP via Plugins → Add New → Upload).
2. Activate the plugin.
3. In Claude (Settings → Connectors → Add custom connector) add the endpoint `https://your-site.tld/wp-json/alphabridge/v1/mcp` and click Connect — you approve on your own site's login-protected consent screen. Done. In ChatGPT: Plugins → Add → Create MCP app with the same URL and OAuth.
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

1. Set up the Claude connector in two steps: copy the endpoint URL, then connect from Claude — or create a token manually for Cursor, Claude Code and scripts. Existing connections are listed and managed in the same place.
2. The moment you create a connection, the plugin shows what you need once — the Bearer token and a copy-paste config for Cursor / Claude Code, and the connector URL if connector-URL authentication is switched on — with a reminder to save it, because the token is shown in full only once.
3. Optional advanced settings for a connection: a label, the WordPress user it acts as, full, content-only or read-only access, and an optional expiry in days.
4. Enable or disable tools by functional group. Powerful ("mighty") tools are off by default, and one switch turns on a global read-only mode.
5. Connect from Claude is on by default: Claude discovers the site, you approve on a login-protected consent screen, and the approved connection appears in the list, revocable any time. Switching it off removes the OAuth endpoints.
6. Connector-URL authentication is off by default, because a token in a URL leaks more easily than one in a header; the setting explains the trade-off before you switch it on.

== External services ==

This plugin makes no automatic outbound requests and sends no telemetry. One tool can contact an external address, and only on your explicit instruction: when you call `wp_upload_media_from_url` with a URL, the plugin downloads that file from the address you provide (and up to a few safely re-validated redirects; SSRF-guarded, type- and size-checked). The plugin itself initiates no other outbound requests.

== Changelog ==

= 4.3.5 =
* A request for a review on WordPress.org, on the plugin's own settings page only. It appears once a site has been using the plugin for at least 14 days and has made at least 50 successful tool calls, links to the review form (whatever your verdict), and stays on that page until «Don't ask again» ends it for good — also across updates, and no tool call that is still counting can undo it. The counter stops once 50 successful calls are stored, so counting is a small, bounded cost rather than a write per call; nothing is sent anywhere.
* Times on the settings screen follow the site's timezone. «Last used» in the connections list measured against the site's local clock instead of real time and was off by the site's UTC offset: on a site in Central European summer time, a connection used a minute ago read «2 hours ago». The log showed the UTC clock without saying so. Both now read like every other time in WordPress.
* A connection made by approving an app on this site is labelled with the app's name and «OAuth», for example «ChatGPT · OAuth», instead of «… · Claude Connect», which put Claude's name on every app that connects this way. A connection through AlphaBridge Connect keeps that name alone. Existing connections keep the label they were given.
* The connections list marks an older connection when the same app has connected again for the same user. ChatGPT's «reconnect», for one, leaves the earlier connection working. Nothing is removed automatically, because a second connection of the same app can be wanted; compare «Last used» and delete the one no longer in use. Only connections made from this version on can be matched.
* Dates in the tools' answers are in the site's time with the UTC offset written out, for example «2026-06-16T09:00:00+02:00». They used to be the UTC time without saying so: a post published at 09:00 in Zurich read as 07:00, and a draft's date read «0000-00-00 00:00:00», where it now shows the planned time. wp_create_post takes such a date back unchanged, as well as site time as before; a date it cannot read is refused instead of being handed to WordPress.

= 4.3.4 =
* The capabilities screen says what to do after saving: a client that is already connected may cache the tool list it loaded, and this endpoint offers no server-initiated stream, so it cannot push `notifications/tools/list_changed`; if a change is not visible in such a client, refresh its tool list or reconnect it. The message after saving and a note under the Save button say so. Found by the acceptance test of 25 September 2026, where a newly enabled tool stayed invisible to a connected client until it reconnected.
* Widget ids are trimmed and matched to the end of the string. An id such as `text-2` followed by a newline was split into its base and number (the pattern ended in `$`, which also matches before a trailing newline) but never found in the sidebar map, so a delete removed the settings row and left the sidebar entry behind. Found while re-reading the widget tools after the September 2026 security review.
* `wp_upload_media_from_url` now says what it does. The site itself downloads the file from a public http(s) URL through WordPress' safe-URL check (private and internal hosts refused unless WordPress itself allows them, such as the site's own host; checked on every redirect), with at most 3 redirects, 20 seconds per request and 20 MB by default; the URL or the content type must say JPEG, PNG, GIF, WebP or PDF, and WordPress then checks the file as for any upload. Behaviour unchanged; the description is what Claude reads before choosing a tool, and the automatic check in Anthropic's connector portal asked for a description that names the source.

= 4.3.3 =
* **Security: a connection for another user can only be created by someone who may edit that user.** The token form accepted any user id: an administrator could issue a full-access connection in the name of any account — on a multisite network also in the name of a network administrator, whose rights a site administrator does not have — and rotating an existing entry had the same gap. Creating and rotating now require WordPress' own `edit_user` right for the account the connection acts as; on multisite a network administrator's connection can only be issued by a network administrator. Existing entries are left as they are: if connections for other users exist, look through the list. Found by an external code review in September 2026.
* **Security: password-protected posts no longer hand out their text to every token whose user may edit posts at all.** `wp_get_post` and `wp_duplicate_post` checked `read_post`, which for a published post means "may read the site" and does not look at the post password; a revision of such a post carries the text but never the password, so it had the same gap. Both tools now require the right to edit the post when a password is set, and a revision — its text as well as its excerpt in any listing — is available only to someone who may edit its parent, the rule the WordPress REST API applies in its edit context. `wp_list_revisions` follows the same rule, `wp_list_posts` lists revisions only for one post at a time and only for someone who may edit it, and `wp_search` does not search revisions at all — a bare count of matches would already tell of the text. Same review.
* **Hardening: the pending-consent record of a Claude connection is signed.** The record that carries user, scope and PKCE challenge from the consent page to the token endpoint lives in WordPress' transient store, which every plugin and any generic "set transient" tool can write to. It now carries a signature over its fields and its storage key, made with the site's auth salt; the token endpoint refuses a record without a valid signature, one older than a code's two minutes, or one naming an unknown scope. Consent records written before the update are refused too; they live for two minutes, so at most a connection started during the update has to be started again. Same review.

= 4.3.2 =
* **Change: the rate limit on client registration is thirty per sender and hour instead of ten, and the hour now starts over on its own.** The public registration endpoint (RFC 7591) counts requests per sender address. Until now every request that passed the limit gave the counter a fresh hour, so the count only started over after an hour without such a request: ten registrations, each less than an hour after the one before, reached the limit even when spread over an afternoon, and further registrations from that address were refused until an hour after the tenth. The count now starts over one hour after the first request it counted. A registration grants no access by itself, and `MAX_CLIENTS` with eviction keeps the client store from filling up.

= 4.3.1 =
* **Fix: four tools told MCP clients they only add data, while they overwrite it.** `wp_update_post`, `wp_update_media`, `wp_update_term` and `wp_moderate_comment` reported `destructiveHint: false`, which the Model Context Protocol defines as "performs only additive updates". Clients use that hint to decide whether to ask you before a call, so an overwrite could run without a prompt. All four now report `destructiveHint: true`. The hint no longer comes from the "off by default" flag, which answers a different question: anything that writes counts as destructive unless it only ever adds (create, upload, duplicate, reply).


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
