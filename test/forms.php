<?php
// Prueft im Joomla-Container die Kernformulare gegen die Attrappe des Dienstes (test/stub-dienst.php):
// Kontakt, Registrierung, Passwort vergessen, Benutzername vergessen, Anmeldung (Seite) -- je mit/ohne Token.
//   php forms.php <Basis-URL der Seite> <URL der Attrappe, vom Container aus>
// Arbeitsverzeichnis = Joomla-Wurzel (fuer einrichten.php und die Protokolldatei).
$base = rtrim($argv[1] ?? 'http://localhost', '/');
$stub = rtrim($argv[2] ?? 'http://172.17.0.1:18172', '/');
$fail = 0;
$jar  = tempnam(sys_get_temp_dir(), 'hcjar');

function ok(string $t)  { echo "ok     $t\n"; }
function bad(string $t) { global $fail; $fail = 1; echo "FEHLER $t\n"; }
function check(bool $c, string $t) { $c ? ok($t) : bad($t); }

function http(string $method, string $url, array $fields = []): array
{
    global $jar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 60, CURLOPT_USERAGENT => 'hc-forms-test']);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return [$code, $body];
}

function stubCount(): int { global $stub; return (int) @file_get_contents($stub . '/_count'); }
function stubReset() { global $stub; @file_get_contents($stub . '/_reset'); }
function setze(string ...$kv) { shell_exec('php ' . __DIR__ . '/einrichten.php setze ' . implode(' ', array_map('escapeshellarg', $kv))); }

/** Alle versteckten Felder und die Aktion des Formulars mit der angegebenen id (oder des ersten mit hostily-captcha/jform). */
function formular(string $html, string $id): array
{
    if (!preg_match('#<form[^>]*id="' . preg_quote($id, '#') . '"[^>]*>(.*?)</form>#s', $html, $m)) {
        return [null, [], ''];
    }
    preg_match('#<form[^>]*id="' . preg_quote($id, '#') . '"[^>]*>#', $m[0], $tag);
    preg_match('#action="([^"]*)"#', $tag[0], $a);
    $hidden = [];
    preg_match_all('#<input[^>]*type="hidden"[^>]*>#', $m[1], $inputs);
    foreach ($inputs[0] as $i) {
        if (preg_match('#name="([^"]*)"#', $i, $n) && preg_match('#value="([^"]*)"#', $i, $v)) {
            $hidden[html_entity_decode($n[1])] = html_entity_decode($v[1]);
        }
    }

    return [html_entity_decode($a[1] ?? ''), $hidden, $m[1]];
}

function url(string $base, string $action): string { return str_starts_with($action, 'http') ? $action : $base . '/' . ltrim($action, '/'); }

/** Ergebnis einer Einsendung: 'abgelehnt' (Meldung des Plugins), 'durch' (keine Meldung des Plugins), plus Text. */
function senden(string $base, string $seite, string $formId, array $felder, ?string $token): array
{
    [$c, $html] = http('GET', url($base, $seite));
    [$action, $hidden, $inner] = formular($html, $formId);
    if ($action === null) {
        return ['kein-formular', $html, 'HTTP ' . $c];
    }
    $felder = array_merge($hidden, $felder);
    if ($token !== null) {
        $felder['hostily-captcha-token'] = $token;
    }
    [$c2, $res] = http('POST', url($base, $action), $felder);
    $text = html_entity_decode(strip_tags(preg_replace('#<(script|style)[^>]*>.*?</\1>#s', '', $res)));
    $text = preg_replace('/\s+/', ' ', $text);
    $hit  = preg_match('/rejected by the spam protection|not a robot|spam protection is currently unavailable|vom Spamschutz abgelehnt|kein Roboter|Spamschutz ist derzeit/i', $text);

    return [$hit ? 'abgelehnt' : 'durch', $text, 'HTTP ' . $c2];
}

$formulare = [
    'Kontakt' => ['/index.php?option=com_contact&view=contact&id=1', 'contact-form', function () {
        return ['jform[contact_name]' => 'Max Muster', 'jform[contact_email]' => 'max@example.org', 'jform[contact_subject]' => 'Betreff Test',
            'jform[contact_message]' => 'Eine Nachricht zum Testen des Kontaktformulars.'];
    }],
    'Registrierung' => ['/index.php?option=com_users&view=registration', 'member-registration', function () {
        $u = substr(md5(uniqid('', true)), 0, 8);
        return ['jform[name]' => 'Max Muster', 'jform[username]' => 'hc' . $u, 'jform[password1]' => 'Passw0rt-Test-12345',
            'jform[password2]' => 'Passw0rt-Test-12345', 'jform[email1]' => 'hc' . $u . '@example.org', 'jform[email2]' => 'hc' . $u . '@example.org'];
    }],
    'Passwort vergessen' => ['/index.php?option=com_users&view=reset', 'user-registration', function () {
        return ['jform[email]' => 'niemand@example.org'];
    }],
    'Benutzername vergessen' => ['/index.php?option=com_users&view=remind', 'user-registration', function () {
        return ['jform[email]' => 'niemand@example.org'];
    }],
];

// ---- Vorbereitung: Plugin gegen die Attrappe, Standard-Captcha hostily
setze('site_key=testkey', 'secret=testsecret', 'service_url=' . $stub, 'mode=sichtbar', 'language=auto', 'on_error=pass');
[$c, $html] = http('GET', $base . '/index.php?option=com_contact&view=contact&id=1');
check($c === 200 && str_contains($html, 'contact-form'), "Kontaktseite erreichbar (HTTP $c)");
check(str_contains($html, '<hostily-captcha') && str_contains($html, 'data-site-key="testkey"'), 'Kontakt: Widget-Element mit Site-Key im Formular');
check(str_contains($html, $stub . '/widget/hostily-captcha.js'), 'Kontakt: Widget-Skript des Dienstes eingebunden');
check(str_contains($html, 'data-api="' . $stub . '/api/"') && str_contains($html, 'data-modus="sichtbar"') && str_contains($html, 'data-sprache="en"'), 'Kontakt: data-api, data-modus, data-sprache (Seitensprache en-GB)');
check(!str_contains($html, 'testsecret'), 'Kontakt: Secret steht nicht im HTML');
check(preg_match('/<hostily-captcha[^>]*aria-labelledby="([^"]+)"/', $html, $lm) === 1 && preg_match('/<label[^>]*id="' . preg_quote($lm[1], '/') . '"/', $html) === 1, 'Kontakt: Widget per aria-labelledby mit der Beschriftung verknuepft');
// Alle fuenf Werte speichern/laden und im HTML; Altwert (leer/unbekannt) bleibt sichtbar
foreach (['sichtbar', 'unsichtbar', 'halten', 'schieben'] as $m) {
    setze('mode=' . $m);
    [, $hm] = http('GET', $base . '/index.php?option=com_contact&view=contact&id=1');
    check(str_contains($hm, 'data-modus="' . $m . '"'), "Modus $m: data-modus=\"$m\" im HTML");
}
setze('mode=portal');
[, $hm] = http('GET', $base . '/index.php?option=com_contact&view=contact&id=1');
check(str_contains($hm, '<hostily-captcha') && !str_contains($hm, 'data-modus'), 'Modus portal (Wie im Kundenportal): kein data-modus');
setze('mode=');
[, $hm] = http('GET', $base . '/index.php?option=com_contact&view=contact&id=1');
check(str_contains($hm, 'data-modus="sichtbar"'), 'Altwert leer: wie bisher sichtbar');
setze('mode=halten', 'hold_time=2000');
[, $hm] = http('GET', $base . '/index.php?option=com_contact&view=contact&id=1');
check(str_contains($hm, 'data-haltezeit="2000"'), 'halten + Haltezeit 2000 im HTML');
setze('hold_time=99999');
[, $hm] = http('GET', $base . '/index.php?option=com_contact&view=contact&id=1');
check(str_contains($hm, 'data-haltezeit="3000"'), 'Haltezeit auf 3000 begrenzt');
setze('mode=sichtbar', 'hold_time=1500');
setze('mode=unsichtbar', 'language=de');
[, $h2] = http('GET', $base . '/index.php?option=com_contact&view=contact&id=1');
check(str_contains($h2, 'data-modus="unsichtbar"') && str_contains($h2, 'data-sprache="de"'), 'Einstellungen Modus unsichtbar / Sprache de wirken');
setze('mode=sichtbar', 'language=auto');

foreach ($formulare as $name => [$seite, $id, $mk]) {
    echo "--- $name ---\n";
    stubReset();
    [$e, $t, $h] = senden($base, $seite, $id, $mk(), null);
    check($e === 'abgelehnt', "$name ohne Token abgelehnt ($h)");
    if ($e !== 'abgelehnt') { echo "   Antwort: " . substr($t, 0, 300) . "\n"; }
    check(preg_match('/not a robot/i', $t) === 1, "  Meldung (Token fehlt, englisch)");
    [$e, $t, $h] = senden($base, $seite, $id, $mk(), 'good');
    check($e === 'durch', "$name mit gutem Token angenommen ($h)");
    if ($e !== 'durch') { echo "   Antwort: " . substr($t, 0, 300) . "\n"; }
    [$e, $t] = senden($base, $seite, $id, $mk(), 'used');
    check($e === 'abgelehnt', "$name mit verbrauchtem Token abgelehnt");
    [$e, $t] = senden($base, $seite, $id, $mk(), 'spam');
    check($e === 'abgelehnt' && preg_match('/rejected by the spam protection/i', $t) === 1, "$name mit Spam-Token abgelehnt (Meldung)");
    [$e, $t] = senden($base, $seite, $id, $mk(), 'expired');
    check($e === 'abgelehnt', "$name mit abgelaufenem Token abgelehnt");

    // Ausfall des Dienstes: durchlassen (Standard) bzw. blockieren
    foreach (['http500', 'garbage', 'redirect', 'bad', 'badreq'] as $s) {
        setze("secret=$s", 'on_error=pass');
        [$e] = senden($base, $seite, $id, $mk(), 'good');
        check($e === 'durch', "$name: Dienst '$s', Einstellung durchlassen -> angenommen");
        setze("secret=$s", 'on_error=block');
        [$e, $t] = senden($base, $seite, $id, $mk(), 'good');
        check($e === 'abgelehnt' && preg_match('/currently unavailable/i', $t) === 1, "$name: Dienst '$s', Einstellung blockieren -> abgelehnt (Meldung)");
    }
    setze('secret=testsecret', 'on_error=pass');
}

echo "--- Anmeldung (Kern ohne Captcha-Feld) ---\n";
[, $h] = http('GET', $base . '/index.php?option=com_users&view=login');
check(!str_contains($h, '<hostily-captcha'), 'Anmeldeformular hat kein Captcha-Feld (Kern), kein Widget');

echo "--- Ohne Konfiguration ---\n";
setze('site_key=', 'secret=');
stubReset();
[, $h] = http('GET', $base . '/index.php?option=com_contact&view=contact&id=1');
check(!str_contains($h, '<hostily-captcha') && !str_contains($h, 'hostily-captcha.js'), 'ohne Site-Key/Secret: kein Widget, kein Skript');
[$e] = senden($base, $formulare['Kontakt'][0], 'contact-form', $formulare['Kontakt'][2](), null);
check($e === 'durch' && stubCount() === 0, 'ohne Konfiguration: Einsendung ohne Token angenommen, Dienst nicht gefragt');
setze('site_key=testkey', 'secret=testsecret');

echo "--- Protokoll ---\n";
$log = glob('{administrator/logs,logs}/plg_captcha_hostily.log.php', GLOB_BRACE);
$txt = $log ? file_get_contents($log[0]) : '';
check($txt !== '' && (str_contains($txt, 'service unreachable') || str_contains($txt, 'unexpected answer from service')), 'Ausfall (HTTP 500/Unsinn) steht im Protokoll');
check(str_contains($txt, 'service rejected the configuration'), 'falsches Secret steht im Protokoll');
check(!preg_match('/testsecret|http500|garbage/', $txt), 'das Secret steht nicht im Protokoll');

echo $fail ? "ERGEBNIS: FEHLER\n" : "ERGEBNIS: alles gruen\n";
exit($fail);
