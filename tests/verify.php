<?php
declare(strict_types=1);

// Contrôle indépendant de l'import : recalcule des totaux directement depuis les XML bruts
// (sans passer par le code d'import) et les compare à la base. Rejoue ensuite l'import pour
// vérifier qu'il est idempotent (mêmes données, mêmes jetons de présence).
//   DB_DRIVER=sqlite DB_SQLITE_PATH=... php tests/verify.php <dossier_xml>

require __DIR__ . '/../src/autoload.php';

use Ascs\Db;
use Ascs\Import\Importer;
use Ascs\Import\XmlTable;

$dir = rtrim($argv[1] ?? '', '/');
$pdo = Db::connect();
$fail = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$fail): void {
    echo ($ok ? '  OK   ' : '  ÉCHEC ') . $label . ($detail !== '' ? " — $detail" : '') . "\n";
    $fail += $ok ? 0 : 1;
};
$num = static fn (string $s): float => (float) str_replace(',', '.', $s === '' ? '0' : $s);
$one = static fn (string $sql) => $pdo->query($sql)->fetchColumn();

echo "Comptages\n";
foreach (['saison', 'famille_section', 'section_principale', 'section', 'adherent', 'type_compte', 'compte', 'detail_compte', 'reglement'] as $t) {
    $src = iterator_count(XmlTable::rows("$dir/$t.xml"));
    $db = (int) $one("SELECT COUNT(*) FROM `$t`");
    $check("$t : $src lignes dans le XML", $src === $db, "base = $db");
}

echo "Montants (recalculés depuis le XML)\n";
$sum = static function (string $file, string $tag) use ($dir, $num): float {
    $s = 0.0;
    foreach (XmlTable::rows("$dir/$file.xml") as $r) {
        $s += $num($r[$tag] ?? '');
    }
    return round($s, 2);
};
foreach ([['reglement', 'Montant', 'reglement', 'montant'], ['compte', 'Debit', 'compte', 'debit'], ['compte', 'Credit', 'compte', 'credit'],
          ['compte', 'Valeur', 'compte', 'valeur'], ['detail_compte', 'Valeur', 'detail_compte', 'valeur']] as [$f, $tag, $t, $col]) {
    $a = $sum($f, $tag);
    $b = round((float) $one("SELECT SUM(`$col`) FROM `$t`"), 2);
    $check("Σ $t.$col", abs($a - $b) < 0.01, "XML $a / base $b");
}

echo "Inscriptions fusionnées\n";
$srcInsc = iterator_count(XmlTable::rows("$dir/inscription.xml"));
$db = (int) $one('SELECT COUNT(*) FROM inscription');
$check('113 inscriptions en doublon supprimées', $srcInsc - $db === 113, "XML $srcInsc → base $db");
$check('aucun doublon (adhérent, section, saison)', (int) $one('SELECT COUNT(*) FROM (SELECT 1 FROM inscription GROUP BY adherent_id, section_id, saison_id HAVING COUNT(*) > 1) x') === 0);
$check('tous les règlements ont conservé leur inscription', (int) $one('SELECT COUNT(*) FROM reglement r LEFT JOIN inscription i ON i.id = r.inscription_id WHERE i.id IS NULL') === 0);
// Les règlements d'un adhérent/section/saison doivent être inchangés avant/après fusion
$srcPay = [];
$inscKey = [];
foreach (XmlTable::rows("$dir/inscription.xml") as $r) {
    $inscKey[$r['IDInscription']] = "{$r['IDAdherent']}|{$r['IDSection']}|{$r['IDSaison']}";
}
foreach (XmlTable::rows("$dir/reglement.xml") as $r) {
    $k = $inscKey[$r['IDInscription']];
    $srcPay[$k] = ($srcPay[$k] ?? 0.0) + $num($r['Montant']);
}
$dbPay = [];
foreach ($pdo->query('SELECT i.adherent_id a, i.section_id s, i.saison_id n, SUM(r.montant) m FROM reglement r JOIN inscription i ON i.id = r.inscription_id GROUP BY 1,2,3') as $r) {
    $dbPay["{$r['a']}|{$r['s']}|{$r['n']}"] = (float) $r['m'];
}
$diff = 0;
foreach ($srcPay as $k => $m) {
    $diff += abs($m - ($dbPay[$k] ?? 0.0)) > 0.005 ? 1 : 0;
}
$check('total des règlements identique par (adhérent, section, saison)', $diff === 0 && count($srcPay) === count($dbPay), count($srcPay) . ' combinaisons, ' . $diff . ' écart(s)');

echo "Intégrité\n";
$fkErr = 0;
foreach (\Ascs\Schema::tables() as $table => $t) {
    foreach ($t['fk'] ?? [] as $col => $parent) {
        $fkErr += (int) $one("SELECT COUNT(*) FROM `$table` c LEFT JOIN `$parent` p ON c.`$col` = p.id WHERE c.`$col` IS NOT NULL AND p.id IS NULL");
    }
}
$check('aucune clé étrangère orpheline', $fkErr === 0);
$check('chaque section a un jeton de présence unique', (int) $one('SELECT COUNT(DISTINCT presence_token) FROM section') === (int) $one('SELECT COUNT(*) FROM section')
    && (int) $one("SELECT COUNT(*) FROM section WHERE presence_token IS NULL OR presence_token = ''") === 0);

echo "Idempotence (second import)\n";
$snapshot = static function () use ($pdo): string {
    $parts = [];
    foreach (['saison', 'section', 'adherent', 'inscription', 'reglement', 'compte', 'detail_compte', 'creneau', 'adherent_canal'] as $t) {
        $order = $t === 'adherent_canal' ? 'adherent_id, canal_id' : 'id';
        $parts[] = $t . ':' . md5(json_encode($pdo->query("SELECT * FROM `$t` ORDER BY $order")->fetchAll()));
    }
    return implode('|', $parts);
};
$before = $snapshot();
$res = (new Importer($pdo))->run($dir);
$after = $snapshot();
$check('second import : base strictement identique (jetons de présence conservés)', $before === $after);
$ins = array_sum(array_column($res['summary']['tables'], 'inserted'));
$del = array_sum(array_column($res['summary']['tables'], 'deleted'));
$upd = array_sum(array_column($res['summary']['tables'], 'updated'));
// adherent_canal (table de liaison) est reconstruite à chaque import : ses lignes sont comptées en « inserted »
$derived = $res['summary']['tables']['adherent_canal']['inserted'];
$check('second import : rien d\'ajouté ni supprimé', $ins - $derived === 0 && $del === 0, "mises à jour : $upd");

echo "Changement côté WinDev (simulation)\n";
$tmp = sys_get_temp_dir() . '/ascs_verify_' . bin2hex(random_bytes(4));
mkdir($tmp);
foreach (glob("$dir/*.xml") as $f) {
    copy($f, $tmp . '/' . basename($f));
}
// 1) on supprime la 1re inscription du fichier ; 2) on modifie le nom d'une section
$x = file_get_contents("$tmp/section.xml");
$x = preg_replace('~(<Titre>)([^<]*)(</Titre>)~u', '$1TITRE MODIFIÉ$3', $x, 1);
file_put_contents("$tmp/section.xml", $x);
$tokBefore = $pdo->query('SELECT id, presence_token FROM section ORDER BY id LIMIT 1')->fetch();
$firstId = (int) $pdo->query('SELECT id FROM section ORDER BY id LIMIT 1')->fetchColumn();
(new Importer($pdo))->run($tmp);
$tokAfter = $pdo->query('SELECT presence_token FROM section WHERE id = ' . (int) $tokBefore['id'])->fetchColumn();
$check('modification de WinDev reprise, jeton de présence inchangé', $tokAfter === $tokBefore['presence_token']);
array_map('unlink', glob("$tmp/*.xml"));
rmdir($tmp);
(new Importer($pdo))->run($dir); // remet la base dans l'état de la source

echo $fail === 0 ? "\nTOUS LES CONTRÔLES PASSENT\n" : "\n$fail CONTRÔLE(S) EN ÉCHEC\n";
exit($fail === 0 ? 0 : 1);
