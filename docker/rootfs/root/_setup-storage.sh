#!/command/with-contenv bash

uid=$(s6-envuidgid -i -u nobody importas UID UID s6-echo '$UID')
gid=$(s6-envuidgid -i -g nobody importas GID GID s6-echo '$GID')
legacy=("webtlo.db" "config.ini" "logs" "tfiles")

s6-echo "Creating storage directory in $WEBTLO_DIR"
s6-mkdir -p -m 0755 "$WEBTLO_DIR"

for name in "${legacy[@]}"; do
  old="$WEBTLO_DIR/../$name"
  new="$WEBTLO_DIR/$name"
  if test -e "$old"; then
    s6-echo "Found old $name, moving to new location"
    mv "$old" "$new"
  fi
done
s6-echo "Fixing permissions inside storage directory"
s6-chown -u "$uid" -g "$gid" "$WEBTLO_DIR"
s6-chmod 02755 "$WEBTLO_DIR"
