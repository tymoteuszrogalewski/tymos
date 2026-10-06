<VirtualHost *:80>
    ServerName tymos.local
    ServerAlias tymos {{TYMOS_IP_LAN}} {{TYMOS_HOST_VPN}}
    DocumentRoot /opt/tymos/tymos
    RewriteEngine On
    RewriteCond %{HTTPS} off
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

    ErrorLog /dev/null
    CustomLog /dev/null common
</VirtualHost>

<VirtualHost *:443>
    ServerName tymos.local
    ServerAlias tymos {{TYMOS_IP_LAN}}
    DocumentRoot /opt/tymos/tymos

    SSLEngine on
    SSLCertificateFile /etc/apache2/ssl/tymos.crt
    SSLCertificateKeyFile /etc/apache2/ssl/tymos.key

    <Directory /opt/tymos/tymos>
        AllowOverride All
        Require all granted
    </Directory>

    # go2rtc proxy — bezposrednio do :1984 bez PHP posrednika (szybszy WHEP handshake)
    ProxyPass /g2r/ http://127.0.0.1:1984/
    ProxyPassReverse /g2r/ http://127.0.0.1:1984/

    # Cached snapshoty kamer w tmpfs (odswiezane co 10s przez system_snapshot_refresh)
    Alias /snap/ /tmp/tymos/
    <Directory /tmp/tymos>
        Require all granted
        # Krotki cache: poster zawsze swiezy przy load/reconnect
        Header set Cache-Control "public, max-age=5"
    </Directory>

    ErrorLog /dev/null
    CustomLog /dev/null common
</VirtualHost>

# VHOST TAILSCALE (2026-09-07): ta sama aplikacja pod nazwa MagicDNS z PRAWDZIWYM certyfikatem
# Let's Encrypt (tailscale cert). Po co: link „Odbierz w TymOS" w powiadomieniu Telegrama musi
# miec nazwe z TLD (Telegram nie linkuje https://tymos/), a wewnetrzna przegladarka Telegrama na iOS
# nie akceptuje samopodpisanego certu -> biala strona. Kiosk i https://tymos zostaja na starym vhoscie.
# Cert i klucz odnawia utils/tscert_renew.sh (cron root, 1. dnia miesiaca) — Let's Encrypt wazny 90 dni.
<VirtualHost *:443>
    ServerName {{TYMOS_HOST_VPN}}
    DocumentRoot /opt/tymos/tymos

    SSLEngine on
    SSLCertificateFile /opt/tymos/etc/apache2/ssl/tymos-ts.crt
    SSLCertificateKeyFile /opt/tymos/etc/apache2/ssl/tymos-ts.key

    <Directory /opt/tymos/tymos>
        AllowOverride All
        Require all granted
    </Directory>

    ProxyPass /g2r/ http://127.0.0.1:1984/
    ProxyPassReverse /g2r/ http://127.0.0.1:1984/

    Alias /snap/ /tmp/tymos/
    <Directory /tmp/tymos>
        Require all granted
        Header set Cache-Control "public, max-age=5"
    </Directory>

    ErrorLog /dev/null
    CustomLog /dev/null common
</VirtualHost>
