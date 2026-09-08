# Releasing AlphaBridge MCP (free)

The version in the repository is always the version being prepared. WordPress.org
serves whatever `Stable tag` in `readme.txt` points at, which is deliberately the
**last published** version until the moment of release. That gap is normal; this
file exists so the closing step is never guessed.

## Before

- [ ] `alphabridge-mcp.php` header `Version:` and `AB_MCP_VERSION` agree (CI checks this).
- [ ] `readme.txt` has a `== Changelog ==` entry for the new version.
- [ ] `Tested up to:` still matches the newest WordPress that was actually tried.
- [ ] CI green: PHPUnit on every supported PHP version, and the archive check.
- [ ] The archive check shows no `tests/`, `composer.json`, `phpunit.xml.dist`,
      `.github/` or `vendor/`.

## Release

1. **Bump `Stable tag` in `readme.txt` to the new version.** Nothing reaches users
   until this happens — and once it does, it happens immediately.
2. Sync the code into the build copy at `alphabridge/wporg-4.0.0/alphabridge-mcp/`.
   That folder, not this repository, is what `build.sh` packs. They drifted apart
   once before; compare with `diff -r` rather than trusting that a copy happened.
3. `sh build.sh` — it reads the version from the plugin header (a hardcoded
   constant once produced a ZIP named 4.2.2 containing 4.2.3 code) and refuses to
   finish if a forbidden file is inside.
4. Commit the ZIP contents to SVN trunk, then tag.
5. Verify on wordpress.org that the served version is the new one.

## After

- [ ] Tag the commit in this repository with the same version.
- [ ] If the release changes the site contract (the `_meta` self-description),
      raise the minimum version in the AlphaBridge Connect hub configuration.

## Version 4.3.0 specifically

4.3.0 is the first release the AlphaBridge Connect hub can link. Its revoke
endpoint and `_meta` self-description are what the hub requires, so the hub's
configured minimum version is 4.3.0. Publishing an older version after it would
strand hub connections.
