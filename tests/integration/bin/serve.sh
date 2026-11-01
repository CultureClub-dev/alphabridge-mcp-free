#!/usr/bin/env bash
# Start or stop the `php -S` of the two integration sites (CI and Mac).
#
#   tests/integration/bin/serve.sh start
#   tests/integration/bin/serve.sh stop
#
# Reads the state bin/setup.php wrote (AB_IT_STATE, or state.json in
# AB_IT_ROOT). start runs one `php -S 127.0.0.1:<port>` per site with four
# workers (a request that asks its own site, as the plugin's PHP probe does,
# would wait for itself on one), its PID in <root>/run/site-<key>.pid, and
# waits until each answers. stop ends exactly those processes and their
# workers (children of that PID), after checking that the command line is
# the server of that document root; nothing else is touched.
#
# OPcache is off for the servers: a mutation run (tests/mutation/run.php)
# changes a file and asks the sites at once.
set -euo pipefail

here=$(cd "$(dirname "$0")" && pwd)
state=${AB_IT_STATE:-${AB_IT_ROOT:?AB_IT_ROOT or AB_IT_STATE must name the integration sites}/state.json}
[ -f "$state" ] || { echo "serve: no state at $state; run tests/integration/bin/setup.php first" >&2; exit 1; }

field() {
	php -r '$v = json_decode(file_get_contents($argv[1]), true); foreach (array_slice($argv, 2) as $k) { $v = $v[$k]; } echo $v;' "$state" "$@"
}

root=$(field root)

# site_pid KEY: the PID of the server of site KEY from its PID file, or
# nothing. The document root on its command line is compared by identity
# (-ef); a PID that went to another program makes the file stale; a server of
# another document root stops the script, its file kept.
site_pid() {
	local key=$1 pidfile="$root/run/site-$1.pid" pid docroot command started
	[ -f "$pidfile" ] || return 0
	pid=$(cat "$pidfile")
	if ! kill -0 "$pid" 2>/dev/null; then
		rm -f "$pidfile"
		return 0
	fi
	docroot=$(field sites "$key" docroot)
	command=$(ps -ww -p "$pid" -o command= || true)
	case "$command" in
	*" -S "*" -t "*" $here/router.php"*) started=${command#* -t }; started=${started%% "$here"/router.php*} ;;
	*)
		echo "serve: pid $pid is no server of this suite any more; its file was stale" >&2
		rm -f "$pidfile"
		return 0
		;;
	esac
	if ! [ "$started" -ef "$docroot" ]; then
		echo "serve: pid $pid serves $started, not site $key; left alone, $pidfile kept" >&2
		exit 1
	fi
	echo "$pid"
}

start() {
	for key in a b; do
		local pidfile="$root/run/site-$key.pid" port docroot log running
		port=$(field sites "$key" port)
		docroot=$(field sites "$key" docroot)
		log=$(field sites "$key" server_log)
		running=$(site_pid "$key")
		if [ -n "$running" ]; then
			echo "serve: site $key already runs (pid $running)"
			continue
		fi
		PHP_CLI_SERVER_WORKERS=4 php -d opcache.enable=0 -d opcache.enable_cli=0 \
			-S "127.0.0.1:$port" -t "$docroot" "$here/router.php" >>"$log" 2>&1 &
		echo $! >"$pidfile"
	done
	for key in a b; do
		local url ok=0
		url=$(field sites "$key" url)
		for _ in $(seq 1 60); do
			if curl -fsS -o /dev/null --max-time 2 "$url/?rest_route=/"; then ok=1; break; fi
			sleep 0.5
		done
		[ "$ok" = 1 ] || { echo "serve: site $key does not answer at $url (log: $(field sites "$key" server_log))" >&2; exit 1; }
		echo "serve: site $key answers at $url (pid $(cat "$root/run/site-$key.pid"))"
	done
}

stop() {
	for key in a b; do
		local pid children
		pid=$(site_pid "$key")
		[ -n "$pid" ] || continue
		children=$(pgrep -P "$pid" || true)
		kill "$pid"
		for _ in $(seq 1 20); do kill -0 "$pid" 2>/dev/null || break; sleep 0.25; done
		for child in $children; do
			kill -0 "$child" 2>/dev/null && kill "$child" || true
		done
		echo "serve: site $key stopped (pid $pid)"
		rm -f "$root/run/site-$key.pid"
	done
}

case "${1:-}" in
start) start ;;
stop) stop ;;
*) echo "usage: $0 start|stop" >&2; exit 2 ;;
esac
