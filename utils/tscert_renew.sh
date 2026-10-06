#!/bin/bash
# tscert_renew.sh — odnowienie certyfikatu Let's Encrypt dla TYMOS_HOST_VPN z config.inc.php (vhost Tailscale
# w etc/apache2/tymos.conf). `tailscale cert` sam pilnuje waznosci: gdy cert ma jeszcze >1/3 zycia,
# zwraca ten sam bez odpytywania Let's Encrypt, wiec odpalanie co miesiac jest bezpieczne.
# Cron root: 0 4 1 * * /opt/tymos/utils/tscert_renew.sh
# Wymaga wlaczonego „HTTPS Certificates" w panelu Tailscale (wlaczone 2026-09-07).
CONF="$(dirname "$0")/../tymos/config.inc.php"
cfg() { sed -n "s/^define('$1', *'\([^']*\)'.*/\1/p" "$CONF"; }
NAME=$(cfg TYMOS_HOST_VPN)
DST=/opt/tymos/etc/apache2/ssl
TMP=$(mktemp -d)
if ! tailscale cert --cert-file "$TMP/c.crt" --key-file "$TMP/c.key" "$NAME" >"$TMP/log" 2>&1; then
    logger -t tscert_renew "BLAD tailscale cert: $(tail -1 "$TMP/log")"
    rm -rf "$TMP"; exit 1
fi
if ! cmp -s "$TMP/c.crt" "$DST/tymos-ts.crt"; then
    install -m 644 "$TMP/c.crt" "$DST/tymos-ts.crt"
    install -m 600 "$TMP/c.key" "$DST/tymos-ts.key"
    systemctl reload apache2
    logger -t tscert_renew "cert odnowiony, apache przeladowany ($(openssl x509 -in "$DST/tymos-ts.crt" -noout -enddate))"
fi
rm -rf "$TMP"
