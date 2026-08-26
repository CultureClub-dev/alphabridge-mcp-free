# AlphaBridge MCP — free plugin

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
  (protocol versions 2024-11-05, 2025-03-26, 2025-06-18). Pure PHP inside WordPress: no Node
  middleware, no external service, nothing extra to host.
- **39 structured tools** across content, media, taxonomies, comments, widgets, site settings,
  site info, SEO reads and search.
- **OAuth 2.1 with PKCE** — connect from Claude without copying tokens; the consent screen is
  your own login-protected site. Header authentication (`Authorization` / `X-Api-Key`) for
  clients without a Connect button.
- **Free means free** — no license keys, no registration, no usage limits, no locked features.

## Security model

Handing an AI access to a site should feel safe, so control comes first:

- Every connection acts as a **real WordPress user**; every tool enforces the matching
  capability, including object-level checks. What that user may not do, the AI cannot do.
- **Scoped connections** — read-only or content-only keys with optional expiry, rotatable in
  one click.
- **Tool groups you can switch off** entirely; disabled tools vanish from the MCP surface.
  Plus a global read-only mode.
- **Positive allowlists instead of blocklists** — arbitrary options and transients cannot be
  read at all; only a fixed list of common site settings is exposed.
- **Layered meta protection** — protected keys, `is_protected_meta()` keys and keys matching
  compound credential patterns (`api_key`, `access_token`, `client_secret`, `oauth`, …) are
  refused. The patterns are deliberately compound, so that ordinary keys such as `token_count`
  or `password_hint` are not caught; they are defence-in-depth, not the primary control. Generic
  meta access additionally passes WordPress's own per-key meta capability
  (`edit_post_meta` / `edit_term_meta` / `edit_user_meta`), which honours `auth_callback` rules
  registered by other plugins.
- **Audit log** of every tool call, plus a fixed rate limit against request bursts.

Details: https://alphabridge-mcp.com/security.html

## Requirements

WordPress 6.5+ (tested up to 7.0) · PHP 8.0+

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
plugin and theme files, WooCommerce, SEO writes, install/update, migration, multisite — and
one-step site deployment over FTP/SFTP. It is entirely optional: this free plugin is complete on
its own and stays fully functional without it. Its source is not part of this repository.
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
