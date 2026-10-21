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

Run the steps in **one shell session**: they share `$R` and the paths below.
The command blocks carry no comments, so they paste into zsh as well as bash
(zsh without `INTERACTIVE_COMMENTS` runs `#` as a command). The paths are those
of the release machine; adjust them once, here — `FREE_REPO` is this
repository (any checkout or worktree), `WPORG` holds the build copy and
`build.sh` (local, not on GitHub), `SVN` is the WordPress.org SVN working copy:

    FREE_REPO=~/Entwicklung/alphabridge-mcp-free
    WPORG=~/Entwicklung/alphabridge/wporg-4.0.0
    SVN=~/Entwicklung/alphabridge/svn-wporg

1. **Bump `Stable tag` in `readme.txt` to the new version.** Nothing reaches users
   until this line sits in the WordPress.org SVN (step 4).
2. **Commit the Stable tag first** (step 1 goes through a pull request like
   everything else), then fill the build copy at
   `$WPORG/alphabridge-mcp/` **from `git archive` of that
   commit**, not from the working tree. Name the commit explicitly: a local
   `main` can lag behind `origin/main` (on 21.10.2026 the checkout still stood
   on 4.6.4 while 4.6.5 was released), and an uncommitted Stable tag is in no
   archive at all:

       git -C "$FREE_REPO" fetch && R=$(git -C "$FREE_REPO" rev-parse origin/main)
       VERSION=$(git -C "$FREE_REPO" show "${R}:alphabridge-mcp.php" | grep -m1 -oE '^ \* Version: +[0-9.]+' | grep -oE '[0-9.]+')
       git -C "$FREE_REPO" log --oneline -1 "$R" && echo "$VERSION"
       git -C "$FREE_REPO" archive -o /tmp/alphabridge-release.tar "$R" \
         && rm -rf "$WPORG/alphabridge-mcp" && mkdir -p "$WPORG/alphabridge-mcp" \
         && tar -xf /tmp/alphabridge-release.tar -C "$WPORG/alphabridge-mcp"

   The commit shown must be the merge of the Stable tag, and `$VERSION` the
   version being released; the later steps use both. The archive is written to a file first and only then unpacked, and the old
   build copy is removed only after `git archive` succeeded: in a pipe the
   shell sees only the exit status of `tar`, so an archive that broke off
   halfway would go unnoticed.

   `git archive` honours the `export-ignore` rules in `.gitattributes` — the same
   tree the CI job "ZIP stays clean" inspects. An `rsync` of the working tree
   does not: on 22.09.2026 it carried README.md, SECURITY.md, glama.json,
   RELEASE.md and .gitattributes into the SVN staging, and `build.sh`'s own
   forbidden-file check did not object. That folder, not this repository, is
   what `build.sh` packs and what goes to SVN trunk (step 4).
3. **Build and check the ZIP:**

       (cd "$WPORG" && RELEASE_COMMIT="${R:?release commit missing}" FREE_SRC="$FREE_REPO" sh build.sh)

   It reads the version from the plugin header (a hardcoded constant once
   produced a ZIP named 4.2.2 containing 4.2.3 code), refuses to finish if a
   forbidden file is inside, and with `RELEASE_COMMIT` compares the ZIP file by
   file with `git archive` of that commit (repository `FREE_SRC`). Without
   `RELEASE_COMMIT` it builds without that comparison and says so; `${R:?…}`
   stops the step instead of falling back to it when `$R` is empty. The last
   line must read «ZIP gleich git archive …» and count the files in
   `languages/` (eighteen since 4.6.7: .po and .mo for de_DE, de_DE_formal,
   de_AT, de_CH, de_CH_informal, fr_FR, it_IT, es_ES and pt_PT): the
   plugin ships its bundled translations there since 4.4.0
   (`tests/BundledTranslationsTest.php`). Until 21.10.2026 `build.sh` left
   `languages/` out of the ZIP.
4. **Bring SVN trunk to the build copy, then tag.** trunk comes from the build
   copy, never from `build/` or an unpacked ZIP of an older `build.sh`, which
   lacked `languages/` — copied to trunk with `--delete` it would have removed
   the translations from WordPress.org:

       cd "$SVN"
       svn update
       rsync -a --delete --exclude .svn "$WPORG/alphabridge-mcp/" trunk/
       svn status trunk
       diff -rq -x .svn trunk "$WPORG/alphabridge-mcp"
       svn ci trunk -m "AlphaBridge MCP ${VERSION:?version missing}"
       svn update && svn cp trunk "tags/${VERSION:?version missing}" && svn ci "tags/$VERSION" -m "Tag $VERSION"

   The first `svn update` matters because the working copy lags when a release
   was made from elsewhere. `svn status trunk` must list only what this release
   changes (`svn add` the `?`, `svn rm` the `!`), and `diff -rq` must print
   nothing. `svn update` again before `svn cp`: a working copy with mixed
   revisions makes the tag commit fail.
5. **Verify what WordPress.org serves:**

       curl -s -g 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=alphabridge-mcp&request[fields][sections]=0'
       curl -s -o /tmp/served.zip "https://downloads.wordpress.org/plugin/alphabridge-mcp.${VERSION:?version missing}.zip" && unzip -tq /tmp/served.zip
       unzip -Z1 /tmp/served.zip | grep -cE '^alphabridge-mcp/languages/.*\.(po|mo)$'
       git -C "$FREE_REPO" ls-tree -r --name-only "$R" languages | grep -cE '\.(po|mo)$'

   The API must report the new version (`-g`, or curl reads `request[slug]` as
   a glob and the answer is empty), the served ZIP must be intact, and the two
   counts must be equal: it carries every translation file of the release
   commit. Right after the tag the
   download can answer with nine bytes «Not found» while WordPress.org builds
   the archive; try again a minute later.

## After

- [ ] Tag the commit in this repository with the same version:
      `git -C "$FREE_REPO" tag -a "v${VERSION:?version missing}" -m "AlphaBridge MCP $VERSION" "$R" && git -C "$FREE_REPO" push origin "v$VERSION"`.
- [ ] If the release changes the site contract (the `_meta` self-description),
      raise the minimum version in the hub configuration (connect.alphabridge-mcp.com).

## Readme only

A change to `readme.txt` alone (a link, a sentence, `Tested up to`) needs no
new version: WordPress.org shows the readme of the stable tag. Merge it like
any change, then copy the readme of `origin/main` into SVN trunk and into the
tag `Stable tag` names. `svn diff` must show only the intended change; if
`main` already carries an unreleased version (a new changelog entry), do not
copy it into the tag, release instead.

    git -C "$FREE_REPO" fetch && git -C "$FREE_REPO" show origin/main:readme.txt > /tmp/alphabridge-readme.txt
    cd "$SVN" && svn update
    STABLE=$(grep -m1 -oE '^Stable tag: *[0-9.]+' /tmp/alphabridge-readme.txt | grep -oE '[0-9.]+')
    cp /tmp/alphabridge-readme.txt trunk/readme.txt && cp /tmp/alphabridge-readme.txt "tags/${STABLE:?stable tag missing}/readme.txt"
    svn diff trunk/readme.txt "tags/$STABLE/readme.txt"
    svn ci trunk/readme.txt "tags/$STABLE/readme.txt" -m "Readme update"

## Version 4.3.0 specifically

4.3.0 is the first release the hub can link. Its revoke
endpoint and `_meta` self-description are what the hub requires, so the hub's
configured minimum version is 4.3.0. Publishing an older version after it would
strand hub connections.
