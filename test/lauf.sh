#!/bin/bash
# Containertest des Joomla-Plugins (offizielles Abbild joomla:<Tag> + MariaDB, nur gezogen, nicht gebaut), installiert aus dem Zip.
# Pro Version: Plugin installieren und aktivieren, Standard-Captcha einstellen, Kontakt/Registrierung gegen die Attrappe des
# Dienstes (stub-dienst.php), danach der Browsertest (Playwright) gegen das echte Pruefprojekt 19. Raeumt die Container auf.
#   BEHALTEN=1 laesst Container und Attrappe stehen (zum Suchen von Fehlern; von Hand entfernen).
#   flock /tmp/claude-0/ap-docker.lock integrationen/joomla/test/lauf.sh [Abbild-Tag, Vorgabe: 5.4.10-php8.3-apache]
# Getestet: 4.4.14-php8.2-apache, 5.4.10-php8.3-apache, 6.2.0-php8.3-apache
set -e
RC=0
T="$(cd "$(dirname "$0")" && pwd)"
TAG="${1:-5.4.10-php8.3-apache}"
VER="${TAG%%-*}"
DB=w1-joomla-db
APP=w1-joomla-app
PORT=18172
SECRETFILE=/mnt/user/appdata/geheim/captcha19.secret
free -g | awk '/^Mem/ { if ($7 < 8) { print "zu wenig Speicher frei (" $7 " GB), abbrechen"; exit 1 } }'
"$T/../bauen.sh" >/dev/null
ZIP="$(ls "$T"/../dist/plg_captcha_hostily-*.zip | tail -1)"
aufraeumen() { docker rm -fv $APP $DB >/dev/null 2>&1 || true; pkill -f "S 0.0.0.0:$PORT .*joomla/test/stub-dienst.php" 2>/dev/null || true; rm -f "$T/.secret"; }
[ -n "$BEHALTEN" ] || trap aufraeumen EXIT
aufraeumen
# Der Container sieht den Host ueber das Gateway der Standard-Bruecke (docker network create geht hier nicht, --add-host nicht mit container:)
GW=$(docker network inspect bridge --format '{{(index .IPAM.Config 0).Gateway}}')
# Attrappe des Dienstes auf dem Host (die Unraid-PHP hat eine Prepend-Datei, die die Ausgabe schluckt);
# ohne die geerbten Datei-Kennungen, damit sie die Docker-Sperre nicht festhaelt
(setsid php -d auto_prepend_file= -S 0.0.0.0:$PORT "$T/stub-dienst.php" >/dev/null 2>&1 3>&- 4>&- 5>&- 6>&- 7>&- 8>&- 9>&- &) 
sleep 1
STUB=$(pgrep -f "php -d auto_prepend_file= -S 0.0.0.0:$PORT" | head -1)

docker run -d --name $DB -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=joomla mariadb:11 >/dev/null
for i in $(seq 1 60); do docker exec $DB mariadb -uroot -proot -e "select 1" >/dev/null 2>&1 && break; sleep 2; done
docker run -d --name $APP --network container:$DB \
  -e JOOMLA_DB_HOST=127.0.0.1 -e JOOMLA_DB_USER=root -e JOOMLA_DB_PASSWORD=root -e JOOMLA_DB_NAME=joomla \
  -e JOOMLA_SITE_NAME=Test -e JOOMLA_ADMIN_USER=Admin -e JOOMLA_ADMIN_USERNAME=admin -e JOOMLA_ADMIN_PASSWORD=Passw0rt-Test-12345 \
  -e JOOMLA_ADMIN_EMAIL=admin@example.org joomla:$TAG >/dev/null
echo "warte auf die Einrichtung von Joomla $TAG ..."
for i in $(seq 1 90); do
  docker exec $APP sh -c 'test -f /var/www/html/configuration.php && php -r "exit(@file_get_contents(\"http://localhost/\") === false ? 1 : 0);"' 2>/dev/null && break
  sleep 4
done
JV="$VER"
echo "=== Joomla ${JV:-?} ($TAG), PHP $(docker exec $APP php -r 'echo PHP_VERSION;') ==="

docker cp "$ZIP" $APP:/tmp/plg.zip
docker exec -u www-data $APP php cli/joomla.php extension:install --path=/tmp/plg.zip 2>&1 | grep -i "success\|error\|exception\|fail" || true
docker cp "$T/einrichten.php" $APP:/tmp/einrichten.php
docker cp "$T/forms.php" $APP:/tmp/forms.php
docker exec -u root -w /var/www/html $APP php /tmp/einrichten.php basis
# PHP-OPcache der Seite liest die geaenderte configuration.php erst nach einigen Sekunden
sleep 8
docker exec -u www-data -w /var/www/html $APP php -l /var/www/html/plugins/captcha/hostily/src/Service/Client.php
echo "=== Kernformulare (PHP/curl) gegen die Attrappe, Joomla ${JV:-?} ==="
docker exec -u www-data -w /var/www/html $APP php /tmp/forms.php http://localhost http://$GW:$PORT || RC=1

# Browsertest gegen das echte Pruefprojekt 19 (Site-Key erlaubt jede Herkunft); Secret nur per Datei in den Container
docker exec -u www-data -w /var/www/html $APP php /tmp/einrichten.php setze site_key=8cdc48a989b7c79151d6 service_url=https://captcha.hostily.de on_error=pass
docker cp "$SECRETFILE" $APP:/tmp/captcha19.secret
docker exec -u root -w /var/www/html $APP sh -c 'php /tmp/einrichten.php setze "secret=$(tr -d "\r\n" < /tmp/captcha19.secret)"; rm -f /tmp/captcha19.secret'
echo "=== Browser (Playwright), Joomla ${JV:-?} ==="
docker run --rm --network container:$DB -v "$T":/t:ro -v "$T":/out -e J_URL=http://localhost -e J_VERSION=$VER mcr.microsoft.com/playwright:v1.55.0-noble \
  sh -c 'mkdir -p /w && cp /t/browser.spec.mjs /w/ && cd /w && npm init -y >/dev/null && npm i --ignore-scripts --no-audit --no-fund playwright@1.55.0 >/dev/null 2>&1 && node browser.spec.mjs' || RC=1
exit ${RC:-0}
