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

here=$(cd "$(dirname "$0")" && pwd)
repo=$(cd "$here/../../.." && pwd)
root=${AB_IT_ROOT:?set AB_IT_ROOT to a directory for the database and the two sites}
mkdir -p "$root"
root=$(cd "$root" && pwd)
export AB_IT_ROOT="$root"
export AB_IT_WP_CORE=${AB_IT_WP_CORE:-$HOME/Entwicklung/wp-core-7.1.3}
db_port=${AB_IT_DB_PORT:-3407}
export AB_IT_DB_HOST="127.0.0.1:$db_port" AB_IT_DB_USER=root AB_IT_DB_PASSWORD=''
datadir="$root/db"
pidfile="$root/run/mariadbd.pid"

db_up() {
	mkdir -p "$root/run" "$root/log"
	if [ -f "$pidfile" ] && kill -0 "$(cat "$pidfile")" 2>/dev/null; then
		echo "local: MariaDB already runs (pid $(cat "$pidfile"))"
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
	[ -f "$pidfile" ] || return 0
	local pid
	pid=$(cat "$pidfile")
	if kill -0 "$pid" 2>/dev/null; then
		case "$(ps -ww -p "$pid" -o command= || true)" in
		*"--datadir=$datadir "*)
			kill "$pid"
			for _ in $(seq 1 60); do kill -0 "$pid" 2>/dev/null || break; sleep 0.5; done
			if kill -0 "$pid" 2>/dev/null; then
				echo "local: MariaDB (pid $pid) did not stop" >&2
				exit 1
			fi
			echo "local: MariaDB stopped (pid $pid)"
			;;
		*) echo "local: pid $pid is not this MariaDB; left alone" >&2 ;;
		esac
	fi
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
	case "$root" in
	/ | "$HOME" | "$repo" | "$repo"/*) echo "local: will not remove $root" >&2; exit 1 ;;
	esac
	rm -rf "$root"
	echo "local: removed $root"
	;;
*) echo "usage: $0 up|test|down|run|purge" >&2; exit 2 ;;
esac
