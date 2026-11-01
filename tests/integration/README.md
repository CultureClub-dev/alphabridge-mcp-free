# Integration suite

Two WordPress 7.1.3 sites on MariaDB, for what the unit suite's stand-ins
cannot show: rows written and read by SQL, WordPress's own hooks, and two
sites talking over HTTP (Z8, Entscheid 34).

- **Site A** (`http://127.0.0.1:8781`, prefix `wpa_`) is the WordPress the
  suite runs in: `bootstrap.php` loads it into the test process. Its own
  `php -S` serves it as well.
- **Site B** (`http://127.0.0.1:8782`, prefix `wpb_`) is reached over HTTP
  and through its rows.
- Both sites live in one database, run the plugin of this working tree
  (`wp-content/plugins/alphabridge-mcp` is a link to it, activated by
  `activate_plugin()`), and get an administrator with an application
  password and a connection token of the plugin. The credentials are made
  anew by every setup and live only in `AB_IT_ROOT/state.json`.

Nothing of this directory ships: `/tests` is `export-ignore` and in
`.distignore`, and the unit suite leaves it out (`phpunit.xml.dist`).

## On a Mac

Needs Homebrew's MariaDB (`mariadb-install-db` and `mariadbd`; its service
stays off), PHP with mysqli, rsync, curl and a WordPress 7.1.3 core at
`~/Entwicklung/wp-core-7.1.3` (or `AB_IT_WP_CORE`).

```sh
export AB_IT_ROOT=~/tmp/ab-it          # new or empty, outside the working tree
tests/integration/bin/local.sh run      # MariaDB, both sites, the suite, all stopped again
```

Step by step: `local.sh up`, then `local.sh test` as often as needed (further
arguments go to PHPUnit, e.g. `--filter Smoke`), `local.sh down` (data
stays), `local.sh purge` (removes `AB_IT_ROOT`). The script marks the
directory with `.ab-integration-root` on first use and refuses a non-empty
one without it, so `purge` only ever removes a tree it built. MariaDB runs with its own
data directory under `AB_IT_ROOT/db`, `--no-defaults` and
`--skip-grant-tables`, on `127.0.0.1:3407` as in CI; its socket is named
relative and lands in the data directory, because a socket path may not
exceed 103 characters. Ports: `AB_IT_DB_PORT`, `AB_IT_PORT_A`,
`AB_IT_PORT_B`. The script ends only the processes it started, by their
PIDs.

The core is the one CI checks:

```sh
cd ~/Entwicklung/wp-core-7.1.3
find . -type f -not -path './.git/*' -print0 | LC_ALL=C sort -z | xargs -0 shasum -a 256 | shasum -a 256
# dbe227baf6382418efdc7c2f5587d2341ab12ffea5a027afc383522f674a8ffc
```

## In CI

Jobs `integration` and `mutation` in `.github/workflows/ci.yml`: MariaDB
11.4 as a service, WordPress from its Git mirror `WordPress/WordPress` at
commit `fc9832bef919c2a08541248db23af90d14ffb082` (tag 7.1.3), checked by
its tree hash and the SHA-256 above, then `bin/setup.php`, `bin/serve.sh
start` and the suite (or the mutation probes).

## Writing a case

- A `TestCase` like any other. Site A is this process: call WordPress and
  the plugin directly; `wp_set_current_user( AB_MCP_IT_Sites::site( 'a' )['admin']['user_id'] )`
  acts as its administrator.
- `AB_MCP_IT_Sites::reset( 'a' | 'b' )` puts a site back to the state right
  after setup (every table of its prefix replaced by the kept copy, caches
  of this process emptied). Call it in `setUp()` or `setUpBeforeClass()`.
- `rows( $site, 'options', array( 'option_name' => 'x' ) )` reads rows past
  every cache, comparing bytes; `option_row()` one option.
- `request( $site, 'GET', '/wp/v2/…' )` asks a site's REST API as its
  administrator (`'none'` without credentials, `'token'` with the plugin's
  token); `mcp( $site, 'tools/call', $params )` its MCP endpoint.
- `install( $site )` installs a site anew (slow; `reset()` is the usual way).
- The other site of a pair is reached through `AB_MCP_Test_Sync_Fetcher`
  (`::for_sites()`), handed to the code under test the way that code takes
  its fetcher. The production way, `AB_MCP_Outbound::get()`, refuses the
  local sites. The product code never gets a filter, a switch or a
  `class_exists()` for the test fetcher: `ShippedCodeKnowsNoTestFetcherTest`
  (unit suite), `TestFetcherIsolationTest` and the mutation
  `test-fetcher/switch-in-product-code` hold that.
- What these sites cannot show: DNS, TLS and streaming between two real
  servers. That is the live probe.

The sites' `wp-config.php` adds what a test environment needs and the
plugin never sees: no mail leaves (`pre_wp_mail`), application passwords
work over http, `WP_HTTP_BLOCK_EXTERNAL` keeps every request local,
`DISABLE_WP_CRON`. PHP notices go to `AB_IT_ROOT/log/site-*-debug.log`.

## Mutation probes

```sh
php tests/mutation/run.php [tests/mutation/lists/<list>.php …] [--only=<id>] [--show]
```

Each entry of a list takes one clause out of a shipped file, runs its tests
(green first, red with the mutation) and writes the file back, compared
byte for byte; the format is in the head of `tests/mutation/run.php`.
Lists that run the integration suite need the sites up (`local.sh up`).
Never commit while it runs.
