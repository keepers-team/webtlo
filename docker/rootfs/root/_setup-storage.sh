#!/command/with-contenv bash

uid=$(s6-envuidgid -i -u nobody importas UID UID s6-echo '$UID')
gid=$(s6-envuidgid -i -g nobody importas GID GID s6-echo '$GID')

s6-echo "Creating storage directory in $WEBTLO_DIR"
s6-mkdir -p -m 0755 "$WEBTLO_DIR"
s6-chown -u "$uid" -g "$gid" "$WEBTLO_DIR"
s6-chmod 02750 "$WEBTLO_DIR"
