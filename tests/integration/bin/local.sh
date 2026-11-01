#!/usr/bin/env bash
# The integration suite on a Mac, with a MariaDB of its own.
#
#   tests/integration/bin/local.sh up      MariaDB, the two sites, their servers
#   tests/integration/bin/local.sh test    the suite; further arguments go to PHPUnit
#   tests/integration/bin/local.sh down    servers and MariaDB stopped (data stays)
#   tests/integration/bin/local.sh run     up, test, down (down also on failure)
#   tests/integration/bin/local.sh purge   down, then AB_IT_ROOT removed
#
# Environment:
#   AB_IT_ROOT     required; everything lives here (database, sites, logs)
#   AB_IT_WP_CORE  default ~/Entwicklung/wp-core-7.1.3
#   AB_IT_DB_PORT  default 3407; AB_IT_PORT_A / AB_IT_PORT_B default 8781 / 8782
#
# MariaDB (Homebrew's mariadbd, not its service): mariadb-install-db into
# AB_IT_ROOT/db, then mariadbd --no-defaults (no my.cnf of the Mac) with
# --skip-grant-tables, on 127.0.0.1:AB_IT_DB_PORT as in CI. Its socket is
# named relative (mysqld.sock), so it lands in the data directory: an
# absolute one under AB_IT_ROOT would pass the 103 characters a socket path
# may have. Every process this script starts is ended by its own PID only,
# after checking its command line; nothing else on the Mac is touched.
set -euo pipefail

here=$(cd "$(dirname "$0")" && pwd -P)
repo=$(cd "$here/../../.." && pwd -P)
root=${AB_IT_ROOT:?set AB_IT_ROOT to a directory for the database and the two sites}
mkdir -p "$root"
root=$(cd "$root" && pwd -P)
export AB_IT_ROOT="$root"
# The marker says this directory belongs to the suite: a fresh, empty one
# gets it, any other is refused, so purge never removes a foreign tree.
marker="$root/.ab-integration-root"
if [ ! -f "$marker" ]; then
	if [ -n "$(ls -A "$root")" ]; then
		echo "local: $root is not empty and has no $(basename "$marker"); choose an empty directory" >&2
		exit 1
	fi
	: >"$marker"
fi
export AB_IT_WP_CORE=${AB_IT_WP_CORE:-$HOME/Entwicklung/wp-core-7.1.3}
db_port=${AB_IT_DB_PORT:-3407}
export AB_IT_DB_HOST="127.0.0.1:$db_port" AB_IT_DB_USER=root AB_IT_DB_PASSWORD=''
datadir="$root/db"
pidfile="$root/run/mariadbd.pid"

# db_pid: the PID of this suite's MariaDB from the PID file, or nothing. The
# data directory on its command line is compared by identity (-ef), so another
# spelling of AB_IT_ROOT neither misses this server nor hits another one. A PID
# that went to another program (after a restart, say) makes the file stale; a
# MariaDB of another data directory stops the script, its file kept.
db_pid() {
	[ -f "$pidfile" ] || return 0
	local pid command started
	pid=$(cat "$pidfile")
	if ! kill -0 "$pid" 2>/dev/null; then
		rm -f "$pidfile"
		return 0
	fi
	command=$(ps -ww -p "$pid" -o command= || true)
	case "$command" in
	*mariadbd*" --datadir="*" --port="*) started=${command#* --datadir=}; started=${started%% --port=*} ;;
	*)
		echo "local: pid $pid is no MariaDB any more; its file was stale" >&2
		rm -f "$pidfile"
		return 0
		;;
	esac
	if ! [ "$started" -ef "$datadir" ]; then
		echo "local: pid $pid is the MariaDB of $started, not of $datadir; left alone, $pidfile kept" >&2
		exit 1
	fi
	echo "$pid"
}

db_up() {
	mkdir -p "$root/run" "$root/log"
	local running
	running=$(db_pid)
	if [ -n "$running" ]; then
		echo "local: MariaDB already runs (pid $running)"
		return
	fi
	if lsof -nP -iTCP:"$db_port" -sTCP:LISTEN >/dev/null 2>&1; then
		echo "local: port $db_port is taken by another process; set AB_IT_DB_PORT" >&2
		exit 1
	fi
	if [ ! -d "$datadir/mysql" ]; then
		mariadb-install-db --no-defaults --datadir="$datadir" --auth-root-authentication-method=normal \
			--skip-test-db >"$root/log/mariadb-install.log" 2>&1
	fi
	mariadbd --no-defaults --datadir="$datadir" --port="$db_port" --bind-address=127.0.0.1 \
		--socket=mysqld.sock --skip-grant-tables --pid-file="$pidfile" \
		--log-error="$root/log/mariadb.err" >/dev/null 2>&1 &
	local started=$!
	for _ in $(seq 1 60); do
		if mariadb --no-defaults --skip-ssl -h 127.0.0.1 -P "$db_port" -u root -e 'SELECT 1' >/dev/null 2>&1; then
			echo "local: MariaDB $(mariadb --no-defaults --skip-ssl -h 127.0.0.1 -P "$db_port" -u root -N -e 'SELECT VERSION()') on 127.0.0.1:$db_port (pid $started)"
			return
		fi
		kill -0 "$started" 2>/dev/null || break
		sleep 0.5
	done
	echo "local: MariaDB did not start (log: $root/log/mariadb.err)" >&2
	exit 1
}

db_down() {
	local pid
	pid=$(db_pid)
	[ -n "$pid" ] || return 0
	kill "$pid"
	for _ in $(seq 1 60); do kill -0 "$pid" 2>/dev/null || break; sleep 0.5; done
	if kill -0 "$pid" 2>/dev/null; then
		echo "local: MariaDB (pid $pid) did not stop" >&2
		exit 1
	fi
	echo "local: MariaDB stopped (pid $pid)"
	rm -f "$pidfile"
}

up() {
	db_up
	php "$here/setup.php"
	"$here/serve.sh" start
}

down() {
	if [ -f "$root/state.json" ]; then "$here/serve.sh" stop; fi
	db_down
}

# holds DIR PATH: true when DIR is PATH or one of its parents, by identity.
holds() {
	local d
	d=$(cd "$2" 2>/dev/null && pwd -P) || return 1
	while :; do
		[ "$d" -ef "$1" ] && return 0
		[ "$d" = / ] && return 1
		d=$(dirname "$d")
	done
}

suite() {
	(cd "$repo" && vendor/bin/phpunit -c tests/integration/phpunit.xml.dist "$@")
}

case "${1:-}" in
up) up ;;
test) shift; suite "$@" ;;
down) down ;;
run)
	shift
	trap down EXIT
	up
	suite "$@"
	;;
purge)
	down
	[ -f "$marker" ] || { echo "local: $root has no $(basename "$marker")" >&2; exit 1; }
	# Only the entries this suite creates are removed, each after checking by
	# identity (-ef, so no spelling or alias of a path gets past it) that it
	# holds neither the checkout nor the home directory; the directory itself
	# goes with rmdir, which keeps it, and says so, if anything else is in it.
	for entry in db run log site-a site-b state.json; do
		[ -e "$root/$entry" ] || [ -L "$root/$entry" ] || continue
		if holds "$root/$entry" "$repo" || holds "$root/$entry" "$HOME"; then
			echo "local: will not remove $root/$entry, it holds the checkout or the home directory" >&2
			exit 1
		fi
		rm -rf "${root:?}/$entry"
	done
	rm -f "$marker"
	if rmdir "$root" 2>/dev/null; then
		echo "local: removed $root"
	else
		echo "local: removed the suite's entries; $root keeps entries of its own:" >&2
		ls -A "$root" >&2
		exit 1
	fi
	;;
*) echo "usage: $0 up|test|down|run|purge" >&2; exit 2 ;;
esac
