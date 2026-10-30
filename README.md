# AlphaBridge MCP — free plugin

[![Listed on mcpservers.org](https://mcpservers.org/badge.svg)](https://mcpservers.org/servers/alphabridge-mcp-com)

**Your WordPress site, managed in conversation.** AlphaBridge MCP turns a WordPress site into a
native [Model Context Protocol](https://modelcontextprotocol.io) server. Claude, ChatGPT and other
MCP clients connect over one authenticated HTTPS endpoint. They write and edit posts, pages,
media, categories and tags, reply to and moderate comments, set SEO titles and descriptions,
manage user accounts, widgets and site settings, change prices, stock and orders in WooCommerce,
read the outline of pages built with blocks or a common page builder, and undo a change with one
sentence. Every tool checks WordPress capabilities on every single call.

The site only reads until an administrator turns on one switch for write access. Then every tool
is on; each connection keeps its access level (full, content only or read only). Switching it
off takes one click.

This repository holds the **source of the free plugin**, published on the WordPress.org plugin
directory. It is the same code WordPress.org ships.

- **Plugin page:** https://wordpress.org/plugins/alphabridge-mcp/
- **Website & documentation:** https://alphabridge-mcp.com
- **Tool reference:** https://alphabridge-mcp.com/docs.html
- **Security model:** https://alphabridge-mcp.com/security.html

## What it does

- **Native MCP endpoint** — JSON-RPC 2.0 over HTTP POST at `/wp-json/alphabridge/v1/mcp`
  (protocol versions 2024-11-05, 2025-03-26, 2025-06-18 and the stateless 2026-07-28, side by
  side; the newest one can be switched off). Pure PHP inside WordPress: no Node middleware, no
  external service, nothing extra to host.
- **98 structured tools** across content, media, taxonomies, comments, widgets, menus, site
  settings, site info, users, plugins and themes (install, update, activate, delete), WordPress
  updates, cron and the error log, SEO fields, WooCommerce, maintenance, page builders, search and
  replace, bulk status changes, a connection check and the abilities of other plugins, each one
  once an administrator has switched it on.
- **A preview first for what has no way back** — `wp_update_core`, the two ZIP installs and the
  two deletes run only with the `confirm_token` from their own preview (HMAC over the tool, the
  arguments, the account, the access level and the state the preview showed; 10 minutes).
- **Undo** — `wp_update_post`, `wp_bulk_update_status`, `wp_update_site_settings`,
  `wp_update_builder_element`, `wp_search_replace` (page-builder pages and option rows) and
  `wp_activate_theme` leave an
  undo point with the previous values that expires after 7 days; `wp_undo_list`, `wp_undo` (with a dry run) and
  `wp_undo_discard` work on the account's own points, and a restore checks the rights of the
  original change again.
- **Page builders** — `wp_get_builder_layout` reads a page as an outline: its elements in page
  order, with their visible text, link and image fields; elements that cannot be read safely,
  such as code, forms or unknown elements, are listed as locked, with the reason and without
  their content.
  - Read: WordPress blocks, Elementor, Beaver Builder, SiteOrigin Page Builder, SeedProd,
    GenerateBlocks, Kadence Blocks, Spectra, Stackable, Pagelayer, Otter Blocks and CoBlocks
    (both as plain blocks), WPBakery Page Builder, Divi 4, Avada (Fusion Builder), Flatsome (UX
    Builder) and Enfold (Avia Layout Builder).
  - Read according to the vendors' documentation: WPBakery Page Builder, Divi 4, Avada, Flatsome
    and Enfold.
  - Recognised, not read: Brizy, Themify Builder, Zion Builder, Live Composer, Cornerstone,
    Thrive Architect, Bricks, Breakdance, Oxygen 6, Oxygen Classic, BeTheme (BeBuilder), Visual
    Composer Website Builder, Divi 5 and Etch.

  Where a builder shows its own data and post_content is only a copy — Elementor, Beaver
  Builder, SiteOrigin Page Builder and Enfold — `wp_update_post` refuses a change to the content
  while the builder is active, because it would not show, and says how to change the page
  instead.

  `wp_update_builder_element` changes the text, link or image of one element right in the
  builder (WordPress blocks, GenerateBlocks, Kadence Blocks, Spectra, Stackable, Pagelayer,
  Elementor, Beaver Builder, SiteOrigin Page Builder, SeedProd, WPBakery Page Builder, Divi 4,
  Avada, Flatsome and Enfold), with a preview by default, an undo point before the write and a
  read-back after it.

  `wp_get_post` names the builder a post was made with (`built_with`), `wp_list_posts` filters
  by it, and `wp_duplicate_post` copies a page with its custom fields and builder data
  (Elementor elements get new ids where the layout can be read, otherwise it is copied unchanged
  and the answer says so; builder data only for accounts with `unfiltered_html`).
- **Search and replace** — `wp_search_replace` over posts, postmeta, options and terms, safe for
  serialized and JSON values, with a dry run per table; on page-builder pages (`scope` text) only
  through the builder's writer, with matches in locked places counted in `locked_matches`.
  `post_ids` keeps a run to the posts it names, `expected_count` writes only when the dry run still
  fits, protected options are never touched, and changed option rows get an undo point of their
  own (`options_undo_token`).
- **OAuth 2.1 with PKCE** — connect from Claude without copying tokens; the consent screen is
  your own login-protected site and offers Read only, Content and Full access, each described in
  one sentence, with Full preselected; the switch for write access on the site decides whether
  the connection may write. Apps register with the site (RFC 7591) or identify themselves with a client
  metadata document (CIMD), which the site fetches only once a logged-in user who may approve
  connections opens the consent screen; that can be switched off. Header authentication
  (`Authorization` / `X-Api-Key`) for clients without a Connect button.
- **Answers that name the way** — where AlphaBridge's own checks refuse a call or report a
  failure, the answer says what did not work, why, and what does: the tool that finds an id, the
  right it takes, the switch, the accepted values. A refusal because write access is off, the
  access level or the role does not reach, or a tool is switched off adds the steps for the
  person, a direct link and the request to pass it on kindly and try again; a tool of the separate
  AlphaBridge MCP Pro is named as such instead of «Unknown tool». Errors WordPress itself reports
  are passed on as WordPress words them.
- **Free means free** — no license keys, no registration, no usage limits, no locked features.

## Security model

Handing an AI access to a site should feel safe, so control comes first:

- **Write access off out of the box.** A new site, and every site after updating from a version
  before 4.4.0, starts with *write access off*: assistants can read content, media, terms,
  comments, settings and the structure of the site. Every tool that creates, changes or deletes is
  refused, and so is every reading tool noted *only with write access* (in this plugin the readers
  of user profile fields and of the error log), with an answer that says why and leads to the switch. The main
  switch *Write access for AI assistants* at the top of Settings → AlphaBridge MCP holds for every
  connection (Claude, ChatGPT, Cursor and all others); an administrator switches it on after
  confirming, with a ticked box, that changes take effect immediately, that it is at the site
  owner's own risk and that a current backup exists. The account, the time, the version of that
  notice and its wording are recorded. Switching on switches every tool on; switching off takes
  one click.
- Every connection acts as a **real WordPress user**; every tool enforces the matching
  capability, including object-level checks. What that user may not do, the AI cannot do.
- **Scoped connections** — read-only or content-only keys with optional expiry, rotatable in
  one click.
- **Fine-tuning** — every tool is on, and switching write access on switches every tool on
  again; switch single tools or whole groups off, and they vanish from the MCP surface until write
  access is switched on the next time. Each tool shows a short name in the admin's language and
  says whether it reads or writes.
  Code reads the switch through `AB_MCP_Site_Mode` (`get()`, `is_full()`, `allows()`,
  `runs_in_read()`; the slugs `read` and `full` are write access off and on); a tool that reads
  code, files, the database, logs or credentials is marked with `'dangerous' => true`, which
  keeps it out while write access is off. The action `ab_mcp_site_mode_changed` fires when write
  access is switched, `ab_mcp_reset_switches` when a switch from off to on switched everything on
  (add-ons switch their own items on there). The fine-tuning takes an add-on's fields into its one form
  (filter `ab_mcp_fine_group_html`, action `ab_mcp_fine_save`).
- **WordPress abilities for the MCP Adapter — off out of the box.** A switch under Fine-tuning
  offers the plugin's tools as WordPress abilities (`alphabridge/<tool name with - for _>`) for
  the WordPress MCP Adapter. The adapter is a second way in, without an AlphaBridge connection, its
  token and its access level: every account that signs in there (with an application password,
  for example) can use the tools with its WordPress rights. Each call runs through the same code as
  `tools/call` (`AB_MCP_REST_Controller::run_tool()`): capability, write access, the tool switches,
  the confirmation token of the tools without a way back, the rate limit and the log, where it is
  marked *via the MCP Adapter*. Not offered: undo, `wp_list_abilities` and `wp_run_ability`, Site
  Deploy, the tools of Pro (also where Pro replaces a tool of this plugin) and any tool without a
  capability. The abilities set neither `show_in_rest` nor `public`, so the REST routes of the
  Abilities API do not open them, and `wp_run_ability` never runs them.
- **Positive allowlists instead of blocklists** — arbitrary options and transients cannot be
  read at all; only a fixed list of common site settings is exposed, and the settings of
  registered widgets through `wp_get_widgets`, without the values whose key the credential guard
  below refuses.
- **Layered meta protection** — protected keys, `is_protected_meta()` keys and two kinds of
  credential-shaped key are refused: keys whose whole name is a credential word, singular or
  plural (`token`, `secret`, `password`, `passphrase`, `passcode`, `pwd`, `otp`, `credential`),
  and keys containing one of a fixed list of compound patterns (`api_key`, `access_token`,
  `client_secret`, `license_key`, `oauth`, `_token`, `_secret`, `_password`, …). The list is
  matched literally, which makes the guard deliberately conservative rather than exhaustive:
  ordinary keys such as `token_count`, `password_hint` and counters such as `maxTokens` pass it,
  and so do camelCase spellings such as `accessToken`. It is defence-in-depth, not the primary
  control. Generic meta access additionally passes WordPress's own per-key meta capability
  (`edit_post_meta` / `edit_term_meta` / `edit_user_meta`), which honours `auth_callback` rules
  registered by other plugins — that is the layer doing the real work. Page-builder data that
  ends up in the page as markup or code, also where a builder keeps it under a key without `_`
  (such as `panels_data`, `dslc_code`, `pagelayer-data`, `brizy`, `mfn-page-items` or
  `tve_updated_post`), is written through the `meta` argument of `wp_create_post` and
  `wp_update_post` only for accounts with the `unfiltered_html` capability; for any other
  account the call is refused before anything is written. One read-only tool
  reaches further, by design: `wp_get_builder_layout`, for an account that may edit the post,
  reads the page builder's own stored data of that post, protected keys included, and returns
  only the visible text, link and image fields of its elements — never the raw meta, code,
  styling or attributes; separate keys that hold a page's own scripts or CSS are not read at
  all.
  `wp_duplicate_post` copies protected keys too, into the new draft only and only for an
  account that may edit the original: WordPress's own page template, featured image and list of
  removed hooked blocks, and — with the `unfiltered_html` capability, because it holds markup —
  the post meta of page builders, each builder's keys together or not at all. Credential-shaped
  keys, the original's editing state, the meta of a revision,
  builder caches and other plugins' protected keys are not copied.
- **Audit log** of the latest 200 tool calls, plus a rate limit of 120 requests a minute against request bursts.

Details: https://alphabridge-mcp.com/security.html

## Requirements

WordPress 6.5+ (tested up to 7.1) · PHP 8.0+

## Installation

Install **AlphaBridge MCP** from your WordPress admin under *Plugins → Add New*, or from
[WordPress.org](https://wordpress.org/plugins/alphabridge-mcp/).

To run this repository directly, clone it into your plugins directory as `alphabridge-mcp`:

```bash
git clone https://github.com/CultureClub-dev/alphabridge-mcp-free.git wp-content/plugins/alphabridge-mcp
```

Then activate it and open *Settings → AlphaBridge MCP* to create a connection.

## Paid add-on

A separate commercial add-on, **AlphaBridge MCP Pro**, lets the assistant do more:

- edit plugin and theme files and the PHP snippets of Code Snippets or WPCode;
- check the site with test requests after saving active PHP code, and put the previous
  version back automatically when one of them ends with a fatal error;
- use tool groups for the database, options, migration and multisite;
- undo its own changes too (files, PHP snippets, options); undo points expire after 7 days by
  default, adjustable from 1 to 30 days.

Its Agency plan adds Site Deploy for agencies and developers: files, themes and whole builds
published over SFTP onto the server the site runs on; ZIP deploys can run atomically, with
rollback when the swap fails.

Pro can be tried free for 7 days, no card needed. Both are entirely optional: this free plugin is
complete on its own and stays fully functional without them. Their source is not part of this
repository. Details at https://alphabridge-mcp.com.

## Contributing & support

Bug reports and security findings are welcome — see [SECURITY.md](SECURITY.md) for the reporting
path. General support questions are best raised in the
[WordPress.org support forum](https://wordpress.org/support/plugin/alphabridge-mcp/).

This repository mirrors released versions; day-to-day development happens elsewhere, so pull
requests may be applied by hand rather than merged directly.

## License

GPL-2.0-or-later — see [LICENSE](LICENSE).

A product of [CultureClub Kulturagentur UG (haftungsbeschränkt)](https://cultureclub.dev).
