#!/bin/bash
# Nie pozwala drukarce HP M282nw wykonac twardego auto-wylaczenia.
#
# DLACZEGO: firmware ma "Wylacz po braku aktywnosci" z opcjami tylko 2/4/8 h — nie ma
# "Nigdy". Po twardym wylaczeniu karta sieciowa jest martwa, wiec drukarka NIE obudzi
# sie z sieci, tylko przyciskiem. Uspienie (15 min) jest OK — z niego budzi ja zadanie
# druku. Chodzi wiec o to, zeby licznik 8 h bezczynnosci nigdy nie dobiegl konca.
#
# JAK: jedno polaczenie TCP do EWS drukarki resetuje licznik bezczynnosci (w EWS musi
# zostac zaznaczone "Opoznij wylaczenie: gdy porty sa aktywne"). Odpalane co 3 h, bo
# przy progu 8 h to az nadto — a kazde dotkniecie i tak wybudza formatter z uspienia,
# wiec im rzadziej, tym mniej pradu. Nie uzywamy SNMP (na Pi nie ma snmpget) ani portu
# 9100, zeby nie dotykac sciezki druku.
#
# Cron root: 0 */3 * * * /opt/tymos/utils/printer_keepalive.sh
CONF="$(dirname "$0")/../tymos/config.inc.php"
cfg() { sed -n "s/^define('$1', *'\([^']*\)'.*/\1/p" "$CONF"; }
IP=$(cfg PRINTER_IP)
curl -s -m 5 -o /dev/null "http://$IP/" \
    || logger -t printer_keepalive "brak odpowiedzi $IP"
