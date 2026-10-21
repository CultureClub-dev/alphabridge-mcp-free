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
   commit**, not from the working tree. Name the commit explicitly: a local
   `main` can lag behind `origin/main` (on 21.10.2026 the checkout still stood
   on 4.6.4 while 4.6.5 was released), and an uncommitted Stable tag is in no
   archive at all:

       git fetch && R=$(git rev-parse origin/main) && git log --oneline -1 "$R"   # must be the Stable tag merge
       rm -rf ../alphabridge/wporg-4.0.0/alphabridge-mcp && mkdir -p ../alphabridge/wporg-4.0.0/alphabridge-mcp
       git archive "$R" | tar -xf - -C ../alphabridge/wporg-4.0.0/alphabridge-mcp

   `git archive` honours the `export-ignore` rules in `.gitattributes` — the same
   tree the CI job "ZIP stays clean" inspects. An `rsync` of the working tree
   does not: on 22.09.2026 it carried README.md, SECURITY.md, glama.json,
   RELEASE.md and .gitattributes into the SVN staging, and `build.sh`'s own
   forbidden-file check did not object. That folder, not this repository, is
   what `build.sh` packs and what goes to SVN trunk (step 4).
3. **Build and check the ZIP** in `alphabridge/wporg-4.0.0/`:

       RELEASE_COMMIT=$R sh build.sh

   It reads the version from the plugin header (a hardcoded constant once
   produced a ZIP named 4.2.2 containing 4.2.3 code), refuses to finish if a
   forbidden file is inside, and with `RELEASE_COMMIT` compares the ZIP file by
   file with `git archive` of that commit (repository `FREE_SRC`, by default
   `../../alphabridge-mcp-free`). The last line must read «ZIP gleich git
   archive …» and count the files in `languages/` (ten on 21.10.2026: .po and
   .mo for five German locales): the plugin ships its bundled translations
   there since 4.4.0 (`tests/BundledTranslationsTest.php`). Until 21.10.2026
   `build.sh` left `languages/` out of the ZIP.
4. **Bring SVN trunk to the build copy, then tag.** trunk comes from the build
   copy, never from `build/` or an unpacked ZIP of an older `build.sh`, which
   lacked `languages/` — copied to trunk with `--delete` it would have removed
   the translations from WordPress.org:

       cd ../alphabridge/svn-wporg
       svn update                                          # the working copy lags when a release was made elsewhere
       rsync -a --delete --exclude .svn ../wporg-4.0.0/alphabridge-mcp/ trunk/
       svn status trunk                                    # only what this release changes; svn add the ?, svn rm the !
       diff -rq -x .svn trunk ../wporg-4.0.0/alphabridge-mcp   # must print nothing
       svn ci trunk -m "<version>: …"
       svn update && svn cp trunk tags/<version> && svn ci tags/<version> -m "Tag <version>"

   `svn update` before `svn cp`: a working copy with mixed revisions makes the
   tag commit fail.
5. **Verify what WordPress.org serves:**

       curl -s -g 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=alphabridge-mcp&request[fields][sections]=0'
       curl -s -o /tmp/served.zip https://downloads.wordpress.org/plugin/alphabridge-mcp.<version>.zip && unzip -l /tmp/served.zip | grep languages/

   The API must report the new version (`-g`, or curl reads `request[slug]` as
   a glob and the answer is empty), and the served ZIP must contain
   `languages/`. Right after the tag the download can answer with nine bytes
   «Not found» while WordPress.org builds the archive; try again a minute later.

## After

- [ ] Tag the commit in this repository with the same version:
      `git tag -a v<version> -m "AlphaBridge MCP <version>" "$R" && git push origin v<version>`.
- [ ] If the release changes the site contract (the `_meta` self-description),
      raise the minimum version in the hub configuration (connect.alphabridge-mcp.com).

## Version 4.3.0 specifically

4.3.0 is the first release the hub can link. Its revoke
endpoint and `_meta` self-description are what the hub requires, so the hub's
configured minimum version is 4.3.0. Publishing an older version after it would
strand hub connections.
