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
   until this line sits in the WordPress.org SVN (step 4).
2. **Commit the Stable tag first** (step 1 goes through a pull request like
   everything else), then fill the build copy at
   `alphabridge/wporg-4.0.0/alphabridge-mcp/` **from `git archive` of that
   commit**, not from the working tree — `git archive` reads HEAD, so an
   uncommitted Stable tag would not be in it:

       rm -rf ../alphabridge/wporg-4.0.0/alphabridge-mcp && mkdir -p ../alphabridge/wporg-4.0.0/alphabridge-mcp
       git archive HEAD | tar -xf - -C ../alphabridge/wporg-4.0.0/alphabridge-mcp

   `git archive` honours the `export-ignore` rules in `.gitattributes` — the same
   tree the CI job "ZIP stays clean" inspects. An `rsync` of the working tree
   does not: on 22.09.2026 it carried README.md, SECURITY.md, glama.json,
   RELEASE.md and .gitattributes into the SVN staging, and `build.sh`'s own
   forbidden-file check did not object. That folder, not this repository, is
   what `build.sh` packs. Check the file list against
   `git archive HEAD | tar -tf -` rather than trusting that a copy happened
   (that compares names, not contents — the contents are what `git archive`
   just wrote).
3. `sh build.sh` **in `alphabridge/wporg-4.0.0/`** — it reads the version from the plugin header (a hardcoded
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
