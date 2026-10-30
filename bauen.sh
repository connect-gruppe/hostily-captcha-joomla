#!/bin/sh
# Baut das Zip: dist/plg_captcha_hostily-<Version>.zip (dist/ ist nicht eingecheckt).
# Der Zip-Inhalt beginnt mit hostily.xml (so verlangt es der Joomla-Installer: Manifest im Hauptordner des Zips).
set -e
cd "$(dirname "$0")"
V=$(sed -n 's/^[[:space:]]*<version>\([^<]*\)<\/version>.*/\1/p' plg_captcha_hostily/hostily.xml | head -1)
[ -n "$V" ] || { echo "Version nicht gefunden" >&2; exit 1; }
mkdir -p dist
python3 - "$V" <<'PY'
import sys, os, zipfile
v = sys.argv[1]
out = f"dist/plg_captcha_hostily-{v}.zip"
if os.path.exists(out): os.remove(out)
base = "plg_captcha_hostily"
with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
    # sortierte Namen, feste Zeit und feste Rechte: gleicher Inhalt gibt gleiche Datei
    for root, dirs, files in os.walk(base):
        dirs.sort()
        for f in sorted(files):
            p = os.path.join(root, f)
            i = zipfile.ZipInfo(os.path.relpath(p, base), (2026, 10, 28, 0, 0, 0))
            i.external_attr = 0o644 << 16
            i.compress_type = zipfile.ZIP_DEFLATED
            with open(p, "rb") as h:
                z.writestr(i, h.read())
print(out)
PY
unzip -l "dist/plg_captcha_hostily-$V.zip"
# Feste Download-Adresse fuer Kunden
mkdir -p ../../website/downloads
cp "dist/plg_captcha_hostily-$V.zip" ../../website/downloads/hostily-captcha-joomla.zip
# Update-Server (im Manifest unter <updateservers>): Joomla meldet neue Versionen dann selbst
SHA=$(sha512sum "dist/plg_captcha_hostily-$V.zip" | cut -d' ' -f1)
cat > ../../website/downloads/joomla-update.xml <<XML
<?xml version="1.0" encoding="utf-8"?>
<updates>
	<update>
		<name>hostily Captcha</name>
		<description>hostily Captcha</description>
		<element>hostily</element>
		<type>plugin</type>
		<folder>captcha</folder>
		<client>site</client>
		<version>$V</version>
		<infourl title="hostily Captcha">https://captcha.hostily.de</infourl>
		<downloads>
			<downloadurl type="full" format="zip">https://captcha.hostily.de/downloads/hostily-captcha-joomla.zip</downloadurl>
		</downloads>
		<sha512>$SHA</sha512>
		<maintainer>hostily</maintainer>
		<maintainerurl>https://captcha.hostily.de</maintainerurl>
		<targetplatform name="joomla" version="(4\.4|5|6)\..*"/>
		<php_minimum>8.1</php_minimum>
	</update>
</updates>
XML
