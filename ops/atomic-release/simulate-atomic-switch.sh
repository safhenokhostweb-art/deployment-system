#!/usr/bin/env bash
set -Eeuo pipefail

root="$(mktemp -d /tmp/atomic-release-simulation.XXXXXX)"
trap 'rm -rf -- "$root"' EXIT
mkdir -p "$root/releases/old/public" "$root/releases/new/public"
printf 'old\n' >"$root/releases/old/public/version"
printf 'new\n' >"$root/releases/new/public/version"
ln -s "$root/releases/old" "$root/current"

missing="$root/missing"
unexpected="$root/unexpected"
: >"$missing"
: >"$unexpected"

(
  for _ in $(seq 1 20000); do
    value="$(cat "$root/current/public/version" 2>/dev/null || true)"
    if [[ -z "$value" ]]; then
      printf 'missing\n' >>"$missing"
    elif [[ "$value" != old && "$value" != new ]]; then
      printf '%s\n' "$value" >>"$unexpected"
    fi
  done
) &
reader_pid=$!

for _ in $(seq 1 100); do
  ln -s "$root/releases/new" "$root/current.next"
  mv -Tf "$root/current.next" "$root/current"
  ln -s "$root/releases/old" "$root/current.next"
  mv -Tf "$root/current.next" "$root/current"
done
ln -s "$root/releases/new" "$root/current.next"
mv -Tf "$root/current.next" "$root/current"
wait "$reader_pid"

test -L "$root/current"
test "$(readlink -f "$root/current")" = "$root/releases/new"
test ! -s "$missing"
test ! -s "$unexpected"

# Simulated rollback uses the same single atomic rename and cannot expose an
# absent current path.
ln -s "$root/releases/old" "$root/current.rollback"
mv -Tf "$root/current.rollback" "$root/current"
test "$(cat "$root/current/public/version")" = old

printf 'ATOMIC_SWITCH_SIMULATION_OK reads=20000 switches=201 missing=0 unexpected=0 rollback=ok\n'
