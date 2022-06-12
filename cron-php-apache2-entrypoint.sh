#!/bin/bash

env >> /etc/environment

# Start cron in the foreground (replacing the current process)
coproc "cron -f"

# Execute all parameters passed to the entrypoint, while unpacking environmental variables
echo "$@"
/bin/bash -c "$@"

# Start php and launch apache2 via native entrypoint
exec docker-php-entrypoint apache2-foreground
