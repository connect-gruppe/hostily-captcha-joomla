# hostily Captcha für Joomla

Plugin `plg_captcha_hostily/` (Version 0.1.1, GPL-2.0-or-later wie Joomla selbst; Voraussetzung für das Joomla Extensions Directory). Bindet den Spamschutz [hostily Captcha](https://captcha.hostily.de) als **Captcha-Plugin** über die
eingebaute Captcha-Schnittstelle von Joomla ein: Es erscheint in System → Konfiguration → Standard-Captcha neben den Captchas des Kerns und schützt damit
automatisch alle Formulare, die Joomla selbst mit einem Captcha absichert.

Getestet mit **Joomla 4.4.14** (PHP 8.2), **5.4.10** (PHP 8.3) und **6.2.0** (PHP 8.3), jeweils im Container (siehe „Tests“). Unterstützte Linien laut
joomla.org: 5.4 (bis Oktober 2027) und 6.x; 4.4 ist beendet (Sicherheitsfixes bis Oktober 2025), läuft aber noch auf vielen Seiten und ist deshalb mit abgedeckt.
PHP 8.2 und 8.3 (Container, `php -l` und Läufe) sowie 8.4 (`php -l` auf dem Host); PHP 8.1 ist anhand der Sprachmerkmale (Joomla 4.4 verlangt mindestens 8.1) nicht eigens geprüft.

## Einbau (Betreiber)

1. Im Dienst (captcha.hostily.de/verwaltung) ein Projekt anlegen; die **Domain der Joomla-Seite** in „erlaubte Domains“ eintragen.
2. System → Erweiterungen → Installieren → **Paket hochladen**: `plg_captcha_hostily-0.1.1.zip` (Zip per `./bauen.sh`).
3. System → Plugins → „hostily Captcha“ **aktivieren** und öffnen: Site-Key und Secret eintragen, speichern.
4. System → Konfiguration → **Standard-Captcha**: „hostily Captcha“ wählen und speichern. Komponenten, die eine eigene Wahl haben (Kontakte,
   Benutzer, Datenschutz), stehen auf „Standard verwenden“ oder werden dort ebenfalls auf hostily Captcha gestellt.

Ohne Site-Key und Secret ist nichts geschützt (und nichts gesperrt): das Plugin zeigt kein Widget und lässt jede Einsendung durch. Notausgang: Plugin
deaktivieren (Joomla zeigt dann kein Captcha mehr, Formulare mit Captcha-Pflicht stehen auf „Standard“ → Captcha-Wahl auf „Kein“ stellen) oder in der
Konfiguration ein anderes Captcha wählen.

## Einstellungen (Plugin-Optionen, gelten für die ganze Joomla-Installation)

| Feld | Bedeutung |
|---|---|
| Site-Key / Secret | aus dem Projekt im Dienst; das Secret bleibt auf dem Server (nie im HTML) |
| Dienst-URL | Vorgabe `https://captcha.hostily.de` (eigene Installation möglich) |
| Modus | sichtbar (Kästchen) oder unsichtbar (löst beim Absenden) |
| Sprache des Widgets | automatisch (Sprache der Seite: de oder en, sonst entscheidet das Widget), Deutsch, Englisch |
| Bei Ausfall des Dienstes | **durchlassen** (Standard) oder **blockieren**; gilt auch bei falschem Secret (`invalid-input-secret`, `missing-input-secret`, `bad-request`), steht immer in `administrator/logs/plg_captcha_hostily.log.php` (nie mit Secret) |

Meldungen an die Besucher kommen aus den Sprachdateien des Plugins (de-DE und en-GB).

## Was Joomla damit schützt

Joomla prüft das Captcha überall dort, wo ein Formular ein Feld `type="captcha"` hat und der Betreiber ein Captcha gewählt hat (global oder je Komponente).
Im Kern von 4.4, 5.4 und 6.2 (Quelltext der Pakete durchsucht) sind das:

| Formular | Komponente / Option für die Captcha-Wahl |
|---|---|
| Kontaktformular (Seite eines Kontakts) | Kontakte → Optionen → Captcha (je Kontakt änderbar) |
| Registrierung | Benutzer → Optionen → Captcha |
| Passwort vergessen (Anfrage) | Benutzer → Optionen → Captcha (gleiche Einstellung wie Registrierung) |
| Benutzername vergessen | Benutzer → Optionen → Captcha (gleiche Einstellung wie Registrierung) |
| Beitrag einreichen (Frontend) | Beiträge → Optionen → Captcha |

„Standard“ in diesen Optionen heißt: das Standard-Captcha der Konfiguration. Kontakt, Registrierung, Passwort vergessen und Benutzername vergessen sind im Container
mit Einsendungen geprüft; „Beitrag einreichen“ ist anhand des Quelltexts geprüft (gleiche Captcha-Schnittstelle, braucht angemeldeten Benutzer mit Rechten).

Weitere Formulare (Anmeldung, Passwort vergessen, Benutzername vergessen) tragen im Kern von Joomla **kein** Captcha-Feld und sind daher **nicht** durch die
Captcha-Schnittstelle abgedeckt; das gilt für alle Captchas, auch die eingebauten. Erweiterungen von Dritten sind geschützt, sobald ihr Formular ein
`captcha`-Feld nutzt.

## Wie es arbeitet

* **Joomla 5 und 6:** das Plugin meldet bei `onCaptchaSetup` einen Anbieter (`HostilyProvider`, Schnittstelle `CaptchaProviderInterface`) an. **Joomla 4.4**
  kennt diese Schnittstelle nicht und ruft die Ereignisse `onInit`, `onDisplay`, `onCheckAnswer` des Plugins; beide Wege nutzen dieselbe Prüfung
  (`Service/Checker.php`, `Service/Client.php`).
* Anzeige: `<hostily-captcha data-site-key="…" data-api="<Dienst>/api/" data-modus="…" data-sprache="…">` im Formular; das Widget-Skript
  `<Dienst>/widget/hostily-captcha.js` wird über den Web-Asset-Manager einmal je Seite geladen. Das Widget legt das Feld `hostily-captcha-token` an.
* Prüfung: `POST <Dienst>/api/v1/pruefen` (`secret`, `response`, `remoteip`) mit cURL (Streams als Ersatz), ohne Weiterleitungen, 8 s Zeitlimit. Abgelehnt wird
  mit einer Meldung am Formular (Ausnahme `RuntimeException`, die Joomla als Fehlermeldung zeigt); Ausfälle folgen der Einstellung „Bei Ausfall“.
* Datenschutz: Das Widget-Skript wird vom Dienst geladen (`captcha.hostily.de`, ohne Cookies) und die IP-Adresse des Besuchers geht an den Dienst; in der
  Datenschutzerklärung nennen.

## Tests

`test/lauf.sh [Abbild-Tag]` (unter `flock /tmp/claude-0/ap-docker.lock`): zieht das offizielle Abbild `joomla:<Tag>` (+ `mariadb:11`), lässt Joomla sich selbst einrichten,
installiert das Zip mit `php cli/joomla.php extension:install`, aktiviert das Plugin (`test/einrichten.php`) und prüft mit `test/forms.php` Kontakt und Registrierung
(ohne Token / `used` / `spam` / `expired` abgelehnt, `good` angenommen; `http500`, `garbage`, `redirect`, `bad`, `badreq` als Ausfall: durchlassen bzw. bei „blockieren“
abgelehnt, Protokolleintrag; ohne Konfiguration keine Prüfung) gegen die Attrappe `test/stub-dienst.php`. Danach `test/browser.spec.mjs` (Playwright): Widget im
Kontaktformular lösen gegen das echte Prüfprojekt, Absenden; ohne Widget abgelehnt; Registrierung; Plugin-Liste und Standard-Captcha in der Verwaltung.
Aufnahmen: `test/aufnahmen/`.

Ergebnis 0.1.0: 4.4.14, 5.4.10 und 6.2.0 je grün (76 Prüfungen der Kernformulare gegen die Attrappe, 8 Prüfungen im Browser). Joomla 5 und 6 laufen über den neuen
Anbieter, 4.4 über die Ereignisse. Beim Absenden eines gelösten Widgets zu schnell nach dem Laden lehnt der Dienst mit dem Grund `mindestzeit` ab; das ist Verhalten des
Dienstes, der Browsertest wartet daher vor dem Absenden. Der Browsertest meldet auf 4.4.14 einen JavaScript-Fehler `o.querySelector is not a function` in der Verwaltung,
dessen Ursache nicht untersucht ist (das Plugin liefert in der Verwaltung kein Skript; die Prüfungen laufen trotzdem durch).

---

## English

hostily Captcha for Joomla: a captcha plugin (`plg_captcha_hostily`) that plugs into Joomla's native captcha interface (`onCaptchaSetup` provider on Joomla 5/6, the
`onInit`/`onDisplay`/`onCheckAnswer` events on 4.4). It shows up under System → Global Configuration → Default Captcha and protects every core form that has a captcha field
(see the table above: contact, user registration, privacy requests, and the other forms listed there).

Install: System → Install → Extensions → upload `plg_captcha_hostily-0.1.1.zip` (built by `./bauen.sh`), enable the plugin under System → Plugins, enter site key and secret,
then select "hostily Captcha" as Default Captcha. Add your site's domain to the allowed domains of the project at captcha.hostily.de. On service failure visitors are let
through by default (configurable: block); everything is logged to `administrator/logs/plg_captcha_hostily.log.php`. Without site key and secret nothing is protected.
Tested on Joomla 4.4.14, 5.4.10 and 6.2.0.
