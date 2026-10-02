# AlphaBridge MCP — free plugin

[![Listed on mcpservers.org](https://mcpservers.org/badge.svg)](https://mcpservers.org/servers/alphabridge-mcp-com)

**Your WordPress site, managed in conversation.** AlphaBridge MCP turns a WordPress site into a
native [Model Context Protocol](https://modelcontextprotocol.io) server. Claude, ChatGPT and other
MCP clients connect over one authenticated HTTPS endpoint. They write and edit posts, pages,
media, categories and tags, reply to and moderate comments, and read widgets, site settings and
the outline of pages built with blocks or a common page builder. Every tool checks WordPress
capabilities on every single call.

The site only reads until an administrator turns on one switch for write access. Then every tool
is on, for every connection. Switching it off takes one click.

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
  refused, and so is every reading tool that needs write access because it reads code, files, the
  database, logs or credentials, with an answer that says why and leads to the switch. The main
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

A separate commercial add-on, **AlphaBridge MCP Pro**, lets the assistant do more:

- change texts, links and images on pages of many page builders;
- run the abilities other plugins offer, and say so when such a run reports success on a post
  but nothing was saved;
- edit plugin and theme files and the PHP snippets of Code Snippets or WPCode;
- test the site after each change to active PHP code, and put the previous version back
  automatically when the site hits a fatal error;
- use tool groups for the database, users, menus, WooCommerce, SEO writes, install/update,
  migration and multisite;
- undo many of the changes made through it (undo points expire after 7 days by default,
  adjustable from 1 to 30 days).

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

A product of [CultureClub Kulturagentur UG (haftungsbeschränkt)](https://cultureclub.dev),
developed in Switzerland.
