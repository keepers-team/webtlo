#!/bin/bash

env >> /etc/environment

# Start cron
coproc "/usr/sbin/cron"

# Execute all parameters passed to the entrypoint if any are present, while unpacking environmental variables

if ![ $# -eq 0 ]
  then
	echo "$@"
	/bin/bash -c "$@"
fi
# Start php and launch apache2 via native entrypoint
exec docker-php-entrypoint apache2-foreground
