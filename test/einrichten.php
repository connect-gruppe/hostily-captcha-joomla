<?php
// Hilfsskript im Joomla-Container: richtet die Pruefumgebung ein oder aendert Plugin-Einstellungen.
//   php einrichten.php basis               Plugin aktivieren, Kontakt anlegen, Registrierung erlauben, Standard-Captcha = hostily
//   php einrichten.php setze k=v [k=v ...] Plugin-Parameter setzen (site_key, secret, service_url, mode, language, on_error)
//   php einrichten.php zeige               Plugin-Parameter ohne secret ausgeben
// Liest die Datenbankdaten aus configuration.php (Joomla-Wurzel = aktuelles Verzeichnis).
define('_JEXEC', 1);
require 'configuration.php';
$c   = new JConfig();
$pdo = new PDO('mysql:host=' . $c->host . ';dbname=' . $c->db, $c->user, $c->password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$p   = $c->dbprefix;

function plugin(PDO $pdo, string $p): array
{
    $r = $pdo->query("SELECT extension_id, params FROM {$p}extensions WHERE type='plugin' AND folder='captcha' AND element='hostily'")->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        fwrite(STDERR, "Plugin nicht installiert\n");
        exit(1);
    }

    return [(int) $r['extension_id'], json_decode($r['params'] ?: '{}', true) ?: []];
}

$cmd = $argv[1] ?? '';
if ($cmd === 'basis') {
    [$id, $params] = plugin($pdo, $p);
    $pdo->exec("UPDATE {$p}extensions SET enabled=1 WHERE extension_id=$id");
    // Registrierung erlauben, Aktivierung: keine
    $r  = $pdo->query("SELECT extension_id, params FROM {$p}extensions WHERE element='com_users' AND type='component'")->fetch(PDO::FETCH_ASSOC);
    $cp = json_decode($r['params'] ?: '{}', true) ?: [];
    $cp['allowUserRegistration'] = '1';
    $cp['useractivation']        = '0';
    $cp['sendpassword']          = '0';
    unset($cp['captcha']);
    $pdo->prepare("UPDATE {$p}extensions SET params=? WHERE extension_id=?")->execute([json_encode($cp), $r['extension_id']]);
    // Kontakt ohne Mailversand-Probleme: Kontakt anlegen
    $cat = (int) $pdo->query("SELECT id FROM {$p}categories WHERE extension='com_contact' ORDER BY id LIMIT 1")->fetchColumn();
    if (!$pdo->query("SELECT COUNT(*) FROM {$p}contact_details")->fetchColumn()) {
        $cols = [];
        foreach ($pdo->query("SHOW COLUMNS FROM {$p}contact_details")->fetchAll(PDO::FETCH_ASSOC) as $col) {
            $cols[$col['Field']] = $col;
        }
        $row = ['name' => 'Testkontakt', 'alias' => 'testkontakt', 'published' => 1, 'access' => 1, 'language' => '*', 'catid' => $cat,
            'email_to' => 'kontakt@example.org', 'params' => '{}', 'metadata' => '{}', 'con_position' => '', 'address' => '', 'suburb' => '',
            'state' => '', 'country' => '', 'postcode' => '', 'telephone' => '', 'fax' => '', 'misc' => '', 'image' => '', 'mobile' => '',
            'webpage' => '', 'sortname1' => '', 'sortname2' => '', 'sortname3' => '', 'metakey' => '', 'metadesc' => '', 'version' => 1,
            'ordering' => 1, 'created' => date('Y-m-d H:i:s'), 'created_by' => 0, 'created_by_alias' => '', 'modified' => date('Y-m-d H:i:s'),
            'modified_by' => 0, 'featured' => 0, 'hits' => 0, 'user_id' => 0, 'checked_out' => null, 'checked_out_time' => null, 'publish_up' => null,
            'publish_down' => null, 'access' => 1, 'webpage' => ''];
        $row = array_intersect_key($row, $cols);
        $pdo->prepare('INSERT INTO ' . $p . 'contact_details (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
    }
    // Standard-Captcha und Mailversand ueber /bin/true (kein echter Versand) in configuration.php
    $f = file_get_contents('configuration.php');
    $f = preg_replace("/public \\\$captcha = '[^']*';/", "public \$captcha = 'hostily';", $f);
    $f = preg_replace("/public \\\$mailer = '[^']*';/", "public \$mailer = 'sendmail';", $f);
    $f = preg_replace("/public \\\$sendmail = '[^']*';/", "public \$sendmail = '/bin/true';", $f);
    file_put_contents('configuration.php', $f);
    echo "basis ok\n";
} elseif ($cmd === 'setze') {
    [$id, $params] = plugin($pdo, $p);
    foreach (array_slice($argv, 2) as $kv) {
        [$k, $v] = explode('=', $kv, 2);
        $params[$k] = $v;
    }
    $pdo->prepare("UPDATE {$p}extensions SET params=? WHERE extension_id=?")->execute([json_encode($params), $id]);
} elseif ($cmd === 'zeige') {
    [$id, $params] = plugin($pdo, $p);
    if (isset($params['secret'])) {
        $params['secret'] = $params['secret'] === '' ? '' : '***';
    }
    echo json_encode($params), "\n";
} else {
    fwrite(STDERR, "Aufruf: einrichten.php basis|setze k=v...|zeige\n");
    exit(2);
}
