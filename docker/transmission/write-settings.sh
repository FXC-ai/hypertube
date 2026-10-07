#!/bin/sh
# Applied on every start, so the settings the Client Torrent relies on cannot drift.
# The RPC whitelist and the lack of login come from WHITELIST in docker-compose.yml.
#
# - Files are written straight at their final path (no incomplete-dir, no ".part" suffix):
#   the stream endpoint reads them there while they download.
# - No queueing: every download Laravel starts runs at once instead of waiting.
set -e

settings=/config/settings.json
jq '."rename-partial-files" = false
  | ."incomplete-dir-enabled" = false
  | ."download-queue-enabled" = false
  | ."seed-queue-enabled" = false
  | ."download-dir" = "/var/www/html/storage/app/public/movies"' "$settings" > "$settings.tmp"
mv "$settings.tmp" "$settings"
chown abc:abc "$settings"
