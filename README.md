# AlphaBridge MCP — free plugin

[![Listed on mcpservers.org](https://mcpservers.org/badge.svg)](https://mcpservers.org/servers/alphabridge-mcp-com)

**Your WordPress site, managed in conversation.** AlphaBridge MCP turns a WordPress site into a
native [Model Context Protocol](https://modelcontextprotocol.io) server. Claude and other MCP
clients connect over one authenticated HTTPS endpoint and manage content, media, taxonomies,
comments, widgets and site settings — through structured tools that check WordPress capabilities
on every single call.

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
- **40 structured tools** across content, media, taxonomies, comments, widgets, site settings,
  site info, SEO reads and search.
- **Page builders** — `wp_get_builder_layout` reads a page as an outline: its elements in page
  order, with their visible text, link and image fields; elements that cannot be read safely,
  such as code, forms or unknown elements, are listed as locked, with the reason and without
  their content. As of 1 October 2026:
  - Read: WordPress blocks, Elementor, Beaver Builder, SiteOrigin Page Builder, SeedProd,
    GenerateBlocks, Kadence Blocks, Spectra, Stackable, Pagelayer, Otter Blocks and CoBlocks
    (both as plain blocks), WPBakery Page Builder, Divi 4, Avada (Fusion Builder), Flatsome (UX
    Builder) and Enfold (Avia Layout Builder).
  - Read from the vendors' documentation and code, not yet checked on a live installation (the
    answer says so): WPBakery Page Builder, Divi 4, Avada, Flatsome and Enfold.
  - Recognised, not read: Brizy, Themify Builder, Zion Builder, Live Composer, Cornerstone,
    Thrive Architect, Bricks, Breakdance, Oxygen 6, Oxygen Classic, BeTheme (BeBuilder), Visual
    Composer Website Builder, Divi 5 and Etch.

  Where a builder shows its own data and post_content is only a copy — Elementor, Beaver
  Builder, SiteOrigin Page Builder and Enfold — `wp_update_post` refuses a change to the content
  while the builder is active, because it would not show, and says how to change the page
  instead.

  `wp_get_post` names the builder a post was made with (`built_with`), `wp_list_posts` filters
  by it, and `wp_duplicate_post` copies a page with its custom fields and builder data
  (Elementor elements get new ids where the layout can be read, otherwise it is copied unchanged
  and the answer says so; builder data only for accounts with `unfiltered_html`).
- **OAuth 2.1 with PKCE** — connect from Claude without copying tokens; the consent screen is
  your own login-protected site and offers Read only, Content and Full access, each described in
  one sentence. Apps register with the site (RFC 7591) or identify themselves with a client
  metadata document (CIMD), which the site fetches only once a logged-in user who may approve
  connections opens the consent screen; that can be switched off. Header authentication
  (`Authorization` / `X-Api-Key`) for clients without a Connect button.
- **Answers that name the way** — where AlphaBridge's own checks refuse a call or report a
  failure, the answer says what did not work, why, and what does: the tool that finds an id, the
  right it takes, the switch, the accepted values. Errors WordPress itself reports are passed on
  as WordPress words them.
- **Free means free** — no license keys, no registration, no usage limits, no locked features.

## Security model

Handing an AI access to a site should feel safe, so control comes first:

- Every connection acts as a **real WordPress user**; every tool enforces the matching
  capability, including object-level checks. What that user may not do, the AI cannot do.
- **Scoped connections** — read-only or content-only keys with optional expiry, rotatable in
  one click.
- **Tool groups you can switch off** entirely; disabled tools vanish from the MCP surface.
  Plus a global read-only mode.
- **Profiles** set all tool switches in one click: *Simple*, how the plugin ships (read the
  site, edit content, every tool marked Mighty off); *Expert*, every tool on; and, where an add-on
  or the site marks Mighty groups Advanced, *Advanced*, which adds just those (this plugin marks
  none of its own). Single groups and tools stay switchable; the page then shows *Custom*. A
  profile changes only the tool switches, never connections or read-only mode. Choosing a profile
  replaces every tool switch; until you choose one, switches set before stay as they are. Code
  that registers a group can mark its level, or use the `ab_mcp_group_levels` filter.
- **Positive allowlists instead of blocklists** — arbitrary options and transients cannot be
  read at all; only a fixed list of common site settings is exposed.
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
- **Audit log** of every tool call, plus a fixed rate limit against request bursts.

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

A separate commercial add-on, **AlphaBridge MCP Pro**, adds tool groups for the database, users,
plugin and theme files, WooCommerce, SEO writes, install/update, migration, multisite, and an undo
for changes made through it (undo points expire after 7 days by default, adjustable from 1 to 30
days). Its Agency plan adds Site Deploy: files, themes and whole builds published onto your own
hosting over SFTP. Both are entirely optional: this free plugin is complete on its own and stays
fully functional without them. Their source is not part of this repository.
Details at https://alphabridge-mcp.com.

## Contributing & support

Bug reports and security findings are welcome — see [SECURITY.md](SECURITY.md) for the reporting
path. General support questions are best raised in the
[WordPress.org support forum](https://wordpress.org/support/plugin/alphabridge-mcp/).

This repository mirrors released versions; day-to-day development happens elsewhere, so pull
requests may be applied by hand rather than merged directly.

## License

GPL-2.0-or-later — see [LICENSE](LICENSE).

A product of [CultureClub Kulturagentur UG (haftungsbeschränkt)](https://cultureclub.dev),
developed in Switzerland.
