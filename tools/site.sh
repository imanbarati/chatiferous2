# Sourced by the scripts (which set $ROOT, the repository folder): loads site.env
# and sets $SSH (ssh with your port and key), $HOST and $PHP (PHP on the server).
[ -f "$ROOT/site.env" ] || { echo "Missing site.env: copy site.env.sample to site.env and fill it in." >&2; exit 1; }
. "$ROOT/site.env"
SSH="ssh -p ${SSH_PORT:-22} -i $SSH_KEY -o BatchMode=yes"
HOST=$SSH_HOST
PHP=${REMOTE_PHP:-php}
