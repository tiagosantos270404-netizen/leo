<?php
/* API do Léo Restaurante & Lancheria (PHP 8 + MySQL).
   Rotas usadas pela loja (index.html), pelo painel (painel.html) e pela página do pedido (pedido.html):
   GET  /api/session            GET  /api/catalog            POST /api/quote
   POST /api/orders             GET  /api/orders?admin=1     GET  /api/orders/{id}[?token=]
   PATCH /api/orders/{id}       GET|PUT /api/settings
   POST /api/admin/login|logout|setup|password
   Segredos e acesso ao banco ficam em config.php (fora do git). */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
date_default_timezone_set('UTC');

const TZ = 'America/Sao_Paulo';
const STAGES = ['received', 'preparing', 'ready', 'out_for_delivery', 'completed', 'cancelled'];

final class HttpError extends Exception {
    public function __construct(public int $status, string $msg) { parent::__construct($msg); }
}
function fail(int $status, string $msg): never { throw new HttpError($status, $msg); }
function out($data, int $status = 200): never { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

$CFG = @include __DIR__ . '/config.php';
if (!is_array($CFG)) out(['error' => 'Servidor ainda não configurado.'], 503);

function db(): PDO {
    static $pdo = null; global $CFG;
    if ($pdo) return $pdo;
    $d = $CFG['db'];
    $pdo = new PDO('mysql:host=' . ($d['host'] ?? '127.0.0.1') . ';dbname=' . $d['name'] . ';charset=utf8mb4', $d['user'], $d['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    migrate($pdo); unlockFees($pdo);
    return $pdo;
}
function migrate(PDO $pdo): void {
    $flag = __DIR__ . '/.migrated-v1';
    if (is_file($flag)) return;
    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (id TINYINT PRIMARY KEY, version INT NOT NULL, data MEDIUMTEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE IF NOT EXISTS orders (num INT AUTO_INCREMENT PRIMARY KEY, id CHAR(24) NOT NULL UNIQUE, created_ts BIGINT NOT NULL, idem VARCHAR(64) NULL UNIQUE, version INT NOT NULL, data MEDIUMTEXT NOT NULL, KEY (created_ts)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1001');
    $pdo->exec('CREATE TABLE IF NOT EXISTS admin (id TINYINT PRIMARY KEY, pass_hash VARCHAR(255) NULL, gen INT NOT NULL DEFAULT 1) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE IF NOT EXISTS hits (k VARCHAR(80) NOT NULL, ts INT NOT NULL, KEY (k, ts)) ENGINE=InnoDB');
    $pdo->exec('INSERT IGNORE INTO admin (id, pass_hash, gen) VALUES (1, NULL, 1)');
    if (!$pdo->query('SELECT 1 FROM settings WHERE id=1')->fetch()) {
        $seed = json_decode((string) file_get_contents(__DIR__ . '/seed.json'), true);
        $st = $pdo->prepare('INSERT INTO settings (id, version, data) VALUES (1, 1, ?)');
        $st->execute([json_encode($seed, JSON_UNESCAPED_UNICODE)]);
    }
    @file_put_contents($flag, date('c'));
}

/* frete não precisa mais de aceite do cliente: uma única vez, libera os pedidos que ficaram esperando o aceite
   (mantém o valor do frete; nenhum pedido é apagado) */
function unlockFees(PDO $pdo): void {
    $flag = __DIR__ . '/.migrated-fee-v1';
    if (is_file($flag)) return;
    $rows = $pdo->query('SELECT id FROM orders WHERE data LIKE \'%"fee_approved":false%\' OR data LIKE \'%"fee_approved": false%\'')->fetchAll();
    foreach ($rows as $r) {
        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT data FROM orders WHERE id=? FOR UPDATE'); $st->execute([$r['id']]);
        $o = json_decode((string) $st->fetchColumn(), true);
        if (is_array($o) && empty($o['fee_approved'])) { $o['fee_approved'] = true; $o['version'] = (int) $o['version'] + 1;
            $pdo->prepare('UPDATE orders SET data=?, version=? WHERE id=?')->execute([json_encode($o, JSON_UNESCAPED_UNICODE), $o['version'], $o['id']]); }
        $pdo->commit();
    }
    @file_put_contents($flag, date('c') . ' ' . count($rows));
}

/* ---------- utilidades ---------- */
function body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    if (strlen($raw) > 200000) fail(413, 'Pedido grande demais.');
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== 0) fail(415, 'Formato inválido.');
    $j = json_decode($raw, true);
    if (!is_array($j)) fail(400, 'Dados inválidos.');
    return $j;
}
function str($v, int $max): string { $s = trim(preg_replace('/\s+/u', ' ', (string) ($v ?? '')) ?? ''); return mb_substr($s, 0, $max); }
/* bairro sem acento, minúsculo, sem a palavra "bairro" e sem espaços sobrando: "Bairro  Três Vendas" = "tres vendas" */
function normBairro(string $s): string {
    $s = mb_strtolower(trim($s));
    $s = strtr($s, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
        'ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n']);
    $s = preg_replace('/^bairro\s+/u', '', $s) ?? $s;
    return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
}
function cents($v, int $max = 1000000): int { if (!is_numeric($v)) fail(422, 'Valor inválido.'); $n = (int) round((float) $v); if ($n < 0 || $n > $max) fail(422, 'Valor fora do limite.'); return $n; }
function hmac(string $msg): string { global $CFG; return hash_hmac('sha256', $msg, $CFG['secret']); }
function ip(): string { return substr($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '?', 0, 60); }
function limit(string $key, int $max, int $window): void {
    $pdo = db(); $now = time(); $k = substr($key . ':' . ip(), 0, 80);
    if (random_int(1, 50) === 1) $pdo->prepare('DELETE FROM hits WHERE ts < ?')->execute([$now - 86400]);
    $st = $pdo->prepare('SELECT COUNT(*) FROM hits WHERE k=? AND ts>?'); $st->execute([$k, $now - $window]);
    if ((int) $st->fetchColumn() >= $max) fail(429, 'Muitas tentativas. Aguarde alguns minutos.');
    $pdo->prepare('INSERT INTO hits (k, ts) VALUES (?, ?)')->execute([$k, $now]);
}
function iso(int $ms): string { return gmdate('Y-m-d\TH:i:s', intdiv($ms, 1000)) . '.' . str_pad((string) ($ms % 1000), 3, '0', STR_PAD_LEFT) . 'Z'; }
function nowMs(): int { return (int) floor(microtime(true) * 1000); }

/* ---------- configurações ---------- */
function settings(bool $lock = false): array {
    $row = db()->query('SELECT version, data FROM settings WHERE id=1' . ($lock ? ' FOR UPDATE' : ''))->fetch();
    $s = json_decode($row['data'], true); $s['version'] = (int) $row['version'];
    return $s;
}
/* ---------- horários (definidos em horarios.php, sempre pela hora do servidor) ---------- */
function schedule(): array { static $h = null; return $h ??= require __DIR__ . '/horarios.php'; }
function nowLocal(): DateTimeImmutable {
    global $CFG; $tz = new DateTimeZone(schedule()['fuso'] ?? TZ);
    // test_now só existe no config.php de testes; em produção vale a hora real do servidor
    return new DateTimeImmutable(isset($CFG['test_now']) ? (string) $CFG['test_now'] : 'now', $tz);
}
function hm(string $t): int { [$h, $m] = array_map('intval', explode(':', $t) + [0, 0]); return $h * 60 + $m; }
function inWindow(array $days, string $from, string $to, DateTimeImmutable $n): bool {
    $min = (int) $n->format('G') * 60 + (int) $n->format('i');
    return in_array((int) $n->format('w'), array_map('intval', $days), true) && $min >= hm($from) && $min < hm($to);
}
function isOpenNow(): bool { $h = schedule(); return inWindow($h['dias'], $h['abre'], $h['fecha'], nowLocal()); }
function isDeliveryOpenNow(): bool {
    $h = schedule(); $n = nowLocal();
    return inWindow($h['dias'], $h['abre'], $h['fecha'], $n) && inWindow($h['entrega_dias'] ?? $h['dias'], $h['entrega_inicio'], $h['entrega_fim'], $n);
}
/* almoço pode ser pedido desde a abertura do restaurante até o fim do almoço (antes das 11:30 vai agendado) */
function isLunchOpenNow(): bool {
    $h = schedule(); if (empty($h['almoco_inicio'])) return true; $n = nowLocal();
    return inWindow($h['dias'], $h['abre'], $h['fecha'], $n) && inWindow($h['almoco_dias'] ?? $h['dias'], $h['abre'], $h['almoco_fim'], $n);
}
function nowMin(): int { $n = nowLocal(); return (int) $n->format('G') * 60 + (int) $n->format('i'); }
/* antes do início do almoço: o pedido sai agendado e o cliente marca "Estou ciente" */
function isLunchEarly(): bool { $h = schedule(); return !empty($h['almoco_inicio']) && nowMin() < hm($h['almoco_inicio']); }
/* horários para receber/retirar o almoço: 11:30, 12:00 ... 14:30 */
function lunchSlots(): array {
    $h = schedule(); $from = hm($h['almoco_agenda_inicio'] ?? $h['almoco_inicio'] ?? '11:30'); $to = hm($h['almoco_agenda_fim'] ?? '14:30');
    $step = max(5, (int) ($h['almoco_agenda_passo'] ?? 30)); $out = [];
    for ($m = $from; $m <= $to; $m += $step) $out[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    return $out;
}
/* os que ainda não passaram agora */
function lunchSlotsAvailable(): array { $now = nowMin(); return array_values(array_filter(lunchSlots(), fn($t) => hm($t) >= $now)); }
/* [1,2,3,4,5,6] -> "segunda a sábado" (ou "Seg a Sáb" no formato curto) */
function diasTexto(array $d, bool $curto): string {
    $nomes = $curto ? ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'] : ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];
    $d = array_values(array_unique(array_map('intval', $d))); sort($d);
    if (count($d) === 7) return $curto ? 'Todos os dias' : 'todos os dias';
    if (count($d) >= 3 && $d[count($d) - 1] - $d[0] === count($d) - 1) return $nomes[$d[0]] . ' a ' . $nomes[$d[count($d) - 1]];
    $n = array_map(fn($i) => $nomes[$i], $d);
    return count($n) > 1 ? implode(', ', array_slice($n, 0, -1)) . ' e ' . end($n) : (string) ($n[0] ?? '');
}
function scheduleInfo(): array {
    $h = schedule(); $ed = $h['entrega_dias'] ?? $h['dias'];
    $sameDays = array_values(array_unique($ed)) == array_values(array_unique($h['dias']));
    $ad = $h['almoco_dias'] ?? $h['dias']; $lunchSameDays = array_values(array_unique($ad)) == array_values(array_unique($h['dias']));
    $lunch = empty($h['almoco_inicio']) ? '' : ' • Almoço ' . ($lunchSameDays ? '' : diasTexto($ad, true) . ' ') . 'das ' . $h['almoco_inicio'] . ' às ' . $h['almoco_fim'];
    return [
        'open' => isOpenNow(), 'deliveryOpen' => isDeliveryOpenNow(), 'serverTime' => nowLocal()->format('Y-m-d\TH:i:sP'),
        'text' => diasTexto($h['dias'], true) . ' • Aberto das ' . $h['abre'] . ' às ' . $h['fecha'] . ' • Entregas ' . ($sameDays ? '' : diasTexto($ed, true) . ' ') . 'das ' . $h['entrega_inicio'] . ' às ' . $h['entrega_fim'] . $lunch,
        'lunchOpen' => isLunchOpenNow(), 'lunchEarly' => isLunchEarly(), 'lunchSlots' => lunchSlotsAvailable(),
        'lunchFrom' => $h['almoco_agenda_inicio'] ?? ($h['almoco_inicio'] ?? ''),
        'lunchMsg' => empty($h['almoco_inicio']) ? '' : 'O almoço à la minuta sai de ' . diasTexto($ad, false) . ', das ' . $h['almoco_inicio'] . ' às ' . $h['almoco_fim'] . '.',
        'closedMsg' => 'Estamos fechados. Funcionamos de ' . diasTexto($h['dias'], false) . ', das ' . $h['abre'] . ' às ' . $h['fecha'] . '.',
        'deliveryMsg' => 'Entregas de ' . diasTexto($ed, false) . ', das ' . $h['entrega_inicio'] . ' às ' . $h['entrega_fim'],
    ];
}
function cleanSettings(array $in, array $old): array {
    $time = fn($t) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $t) ? (string) $t : fail(422, 'Horário inválido.');
    $out = [
        'acceptingOrders' => !empty($in['acceptingOrders']), 'pickupEnabled' => !empty($in['pickupEnabled']),
        'deliveryEnabled' => !empty($in['deliveryEnabled']), 'hoursConfirmed' => !empty($in['hoursConfirmed']),
        'restrictDelivery' => !empty($in['restrictDelivery']),
    ];
    $out['hours'] = [];
    foreach (array_slice((array) ($in['hours'] ?? []), 0, 40) as $h) {
        $d = (int) ($h['day'] ?? -1); if ($d < 0 || $d > 6) fail(422, 'Dia inválido.');
        $out['hours'][] = ['day' => $d, 'open' => $time($h['open'] ?? ''), 'close' => $time($h['close'] ?? '')];
    }
    $out['deliveryRules'] = [];
    foreach (array_slice((array) ($in['deliveryRules'] ?? []), 0, 200) as $r) {
        $n = str($r['neighborhood'] ?? '', 100); if ($n === '') fail(422, 'Bairro sem nome.');
        $out['deliveryRules'][] = ['neighborhood' => $n, 'fee' => ($r['fee'] ?? null) === null ? null : cents($r['fee'], 100000), 'available' => !empty($r['available'])];
    }
    $eta = (array) ($in['eta'] ?? []);
    $out['eta'] = ['pickup' => str($eta['pickup'] ?? '', 30), 'delivery' => str($eta['delivery'] ?? '', 30)];
    $px = $in['pix'] ?? null;
    $out['pix'] = is_array($px) && str($px['key'] ?? '', 77) !== '' ? [
        'type' => in_array($px['type'] ?? '', ['phone', 'doc', 'email', 'random'], true) ? $px['type'] : 'phone',
        'key' => str($px['key'], 77), 'name' => str($px['name'] ?? '', 25), 'city' => str($px['city'] ?? 'Barra do Ribeiro', 15),
    ] : null;
    /* motoboys (só o painel vê): nome, WhatsApp e se está ativo */
    $out['couriers'] = []; $cids = [];
    foreach (array_slice((array) ($in['couriers'] ?? []), 0, 30) as $c) {
        $cn = str($c['name'] ?? '', 60); if ($cn === '') fail(422, 'Motoboy sem nome.');
        $ph = preg_replace('/\D/', '', (string) ($c['phone'] ?? '')); $ph = preg_replace('/^55(?=\d{10,11}$)/', '', $ph);
        if (strlen($ph) < 10 || strlen($ph) > 11) fail(422, "WhatsApp do motoboy $cn: informe DDD + número.");
        $cid = str($c['id'] ?? '', 32); if ($cid === '' || isset($cids[$cid])) $cid = bin2hex(random_bytes(6)); $cids[$cid] = 1;
        $out['couriers'][] = ['id' => $cid, 'name' => $cn, 'phone' => $ph, 'active' => !empty($c['active'])];
    }
    $out['products'] = []; $ids = [];
    foreach (array_slice((array) ($in['products'] ?? []), 0, 500) as $p) {
        $id = str($p['id'] ?? '', 64); $name = str($p['name'] ?? '', 100);
        if ($id === '' || $name === '' || isset($ids[$id])) fail(422, 'Item do cardápio inválido ou repetido.');
        $ids[$id] = 1; $g = (int) ($p['group'] ?? -1); if ($g < 0 || $g > 7) fail(422, 'Categoria inválida.');
        $ex = [];
        foreach (array_slice((array) ($p['extras'] ?? []), 0, 30) as $x) {
            $xn = str($x['name'] ?? '', 100); if ($xn === '') fail(422, 'Adicional sem nome.');
            $ex[] = ['id' => str($x['id'] ?? bin2hex(random_bytes(6)), 64), 'name' => $xn, 'price' => cents($x['price'] ?? 0, 100000), 'available' => !empty($x['available'])];
        }
        $out['products'][] = ['id' => $id, 'name' => $name, 'description' => mb_substr(trim((string) ($p['description'] ?? '')), 0, 500), 'group' => $g,
            'price' => cents($p['price'] ?? 0), 'available' => !empty($p['available']), 'badge' => str($p['badge'] ?? '', 30), 'extras' => $ex];
    }
    return $out;
}

/* ---------- sessão do painel (cookie assinado) ---------- */
const COOKIE = 'leo_adm';
function adminRow(): array { return db()->query('SELECT pass_hash, gen FROM admin WHERE id=1')->fetch(); }
function isAdmin(): bool {
    $c = $_COOKIE[COOKIE] ?? ''; $p = explode('.', $c);
    if (count($p) !== 3) return false;
    [$gen, $exp, $sig] = $p;
    if (!hash_equals(hmac("adm.$gen.$exp"), $sig) || (int) $exp < time()) return false;
    $a = adminRow(); return $a['pass_hash'] !== null && (int) $a['gen'] === (int) $gen;
}
function setAdminCookie(int $gen): void {
    $exp = time() + 30 * 86400;
    setcookie(COOKIE, "$gen.$exp." . hmac("adm.$gen.$exp"), ['expires' => $exp, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax']);
}
function requireAdmin(): void { if (!isAdmin()) fail(401, 'Entre no painel para continuar.'); }
function sessionInfo(): array {
    $a = adminRow(); $adm = isAdmin();
    return ['admin' => $adm, 'passwordSet' => $a['pass_hash'] !== null, 'method' => $adm ? 'password' : null, 'user' => $adm ? ['name' => 'Léo'] : null];
}

/* ---------- pedidos ---------- */
function orderToken(string $id): string { return substr(hmac("order.$id"), 0, 32); }
function compute(array $in, array $s): array {
    if (empty($s['acceptingOrders'])) fail(409, 'O Léo não está recebendo pedidos pelo site agora. Fale pelo WhatsApp (51) 99620-0841.');
    if (!isOpenNow()) fail(409, scheduleInfo()['closedMsg']);
    /* tipo de pedido: delivery = entrega, pickup = retirar no balcão (para levar), dine_in = comer no restaurante */
    $mode = (string) ($in['mode'] ?? '');
    if (!in_array($mode, ['delivery', 'pickup', 'dine_in'], true)) fail(422, 'Escolha o tipo de pedido: entrega, para levar ou comer no restaurante.');
    if ($mode === 'delivery' && !isDeliveryOpenNow()) fail(409, scheduleInfo()['deliveryMsg']);
    if ($mode === 'delivery' && empty($s['deliveryEnabled'])) fail(409, 'A entrega está pausada agora. Escolha outra opção.');
    if ($mode !== 'delivery' && empty($s['pickupEnabled'])) fail(409, 'O atendimento no balcão está pausado agora. Escolha entrega.');
    $table = $mode === 'dine_in' ? str($in['table'] ?? '', 10) : '';
    $c = (array) ($in['customer'] ?? []); $name = str($c['name'] ?? '', 80); $phone = str($c['phone'] ?? '', 30);
    if ($name === '') fail(422, 'Informe seu nome.');
    $digits = preg_replace('/\D/', '', $phone); if (strlen($digits) < 10 || strlen($digits) > 13) fail(422, 'Informe um telefone com DDD.');
    /* endereço em campos: rua, número (ou S/N), complemento e referência; "address" continua sendo a linha pronta (rua, número - complemento) */
    $nb = $mode === 'delivery' ? str($in['neighborhood'] ?? '', 100) : ''; $addr = null; $address = '';
    if ($mode === 'delivery') {
        if (!is_array($in['addr'] ?? null)) fail(422, 'O site foi atualizado. Recarregue a página e confira o endereço.');
        $a = $in['addr'];
        $street = str($a['street'] ?? '', 150); $number = !empty($a['noNumber']) ? 'S/N' : str($a['number'] ?? '', 12);
        $addr = ['street' => $street, 'number' => $number, 'complement' => str($a['complement'] ?? '', 100), 'reference' => str($a['reference'] ?? '', 150)];
        if (mb_strlen($street) < 3) fail(422, 'Informe a rua ou avenida.');
        if ($number === '') fail(422, 'Informe o número da casa.');
        if ($nb === '') fail(422, 'Informe o bairro.');
        $address = $street . ', ' . $number . ($addr['complement'] !== '' ? ' - ' . $addr['complement'] : '');
    }
    $byId = []; foreach ($s['products'] as $p) $byId[$p['id']] = $p;
    $lines = []; $sub = 0; $hasLunch = false; $raw = array_slice((array) ($in['lines'] ?? []), 0, 51);
    if (!$raw) fail(422, 'A sacola está vazia.'); if (count($raw) > 50) fail(422, 'Itens demais em um pedido.');
    foreach ($raw as $l) {
        $p = $byId[(string) ($l['id'] ?? '')] ?? null;
        if (!$p || !$p['available']) fail(409, 'Um item da sacola não está mais disponível. Revise a sacola.');
        if ($p['group'] === 5 && !isLunchOpenNow()) fail(409, scheduleInfo()['lunchMsg'] . ' Tire a minuta da sacola para continuar.');
        if ($p['group'] === 5) $hasLunch = true;
        $qty = (int) ($l['qty'] ?? 0); if ($qty < 1 || $qty > 30) fail(422, 'Quantidade inválida.');
        $ex = []; $unit = $p['price'];
        foreach (array_unique(array_map('strval', (array) ($l['extras'] ?? []))) as $xid) {
            $x = null; foreach ($p['extras'] as $e) if ($e['id'] === $xid) $x = $e;
            if (!$x || !$x['available']) fail(409, 'Um adicional não está mais disponível. Revise a sacola.');
            $ex[] = ['id' => $x['id'], 'name' => $x['name'], 'price' => $x['price']]; $unit += $x['price'];
        }
        $lines[] = ['id' => $p['id'], 'name' => ($p['group'] === 5 ? 'Minuta ' : '') . $p['name'], 'qty' => $qty, 'total' => $unit * $qty, 'extras' => $ex, 'note' => str($l['note'] ?? '', 300)];
        $sub += $unit * $qty;
    }
    /* almoço: hora escolhida para receber/retirar ("asap" = o quanto antes); antes das 11:30 exige "Estou ciente" */
    $lunch = null;
    if ($hasLunch) {
        $t = (string) ($in['lunchTime'] ?? '');
        if ($t === '') fail(422, 'Escolha o horário do almoço.');
        $slots = lunchSlots(); $from = $slots[0] ?? '11:30'; $to = end($slots) ?: '14:30';
        if ($t !== 'asap') {
            if (!preg_match('/^\d{2}:\d{2}$/', $t) || hm($t) < hm($from) || hm($t) > hm($to)) fail(422, "O horário do almoço vai de $from até $to.");
            if (!in_array($t, $slots, true)) fail(422, 'Escolha um dos horários do almoço da lista.');
            if (hm($t) < nowMin()) fail(409, 'Esse horário do almoço já passou. Escolha outro.');
        }
        $aware = !empty($in['lunchAware']);
        if (isLunchEarly() && !$aware) fail(422, "Entregas e retiradas de almoço a partir das $from. Marque \"Estou ciente\" para continuar.");
        $lunch = ['time' => $t, 'aware' => $aware];
    }
    $fee = $mode === 'delivery' ? null : 0; // taxa de entrega só na entrega
    if ($mode === 'delivery') {
        $rule = null;
        foreach ($s['deliveryRules'] as $r) if (normBairro($r['neighborhood']) === normBairro($nb) && (!$rule || $r['available'])) $rule = $r;
        if (!empty($s['restrictDelivery']) && (!$rule || !$rule['available'])) fail(409, "Ainda não entregamos no bairro $nb. Fale com a gente no WhatsApp.");
        if ($rule && $rule['available']) { $fee = $rule['fee']; $nb = $rule['neighborhood']; }
    }
    /* forma de pagamento: pix | card | cash ('' em pedidos de versões antigas da loja) */
    $pay = in_array($in['pay'] ?? '', ['pix', 'card', 'cash'], true) ? $in['pay'] : '';
    if ($pay === 'pix' && $mode === 'delivery' && $fee === null) fail(409, 'Para outros bairros, pague na entrega.');
    return ['mode' => $mode, 'table' => $table, 'pay' => $pay, 'customer' => ['name' => $name, 'phone' => $phone], 'address' => $address, 'addr' => $addr, 'neighborhood' => $nb, 'lunch' => $lunch,
        'notes' => str($in['notes'] ?? '', 600), 'lines' => $lines, 'subtotal' => $sub, 'fee' => $fee];
}
function quoteSig(array $q, int $exp): string { return $exp . '.' . hmac('quote.' . $exp . '.' . json_encode($q, JSON_UNESCAPED_UNICODE)); }
function loadOrder(string $id, bool $lock = false): array {
    $st = db()->prepare('SELECT data, version FROM orders WHERE id=?' . ($lock ? ' FOR UPDATE' : '')); $st->execute([$id]);
    $r = $st->fetch(); if (!$r) fail(404, 'Pedido não encontrado.');
    $o = json_decode($r['data'], true); $o['version'] = (int) $r['version']; return $o;
}
function saveOrder(array $o): void {
    db()->prepare('UPDATE orders SET data=?, version=? WHERE id=?')->execute([json_encode($o, JSON_UNESCAPED_UNICODE), $o['version'], $o['id']]);
}
function canSee(string $id, ?string $token): bool { return isAdmin() || ($token !== null && hash_equals(orderToken($id), $token)); }

/* ---------- comprovantes de Pix (pedidos antigos) ----------
   O site não recebe mais comprovante: o cliente manda pelo WhatsApp. Os comprovantes já anexados continuam
   FORA da pasta pública e só a rota do painel, com login, entrega o arquivo. */
const PROOF_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
function proofDir(): string {
    $root = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    foreach ([$root !== '' ? dirname($root) . '/comprovantes' : '', __DIR__ . '/comprovantes'] as $d) if ($d !== '' && is_dir($d)) return $d;
    return '';
}
function proofPublic(array $o): array { if (isset($o['proof'])) unset($o['proof']['file']); return $o; } // cliente não precisa do nome interno

/* ---------- rotas ---------- */
try {
    $method = $_SERVER['REQUEST_METHOD'];
    $path = trim((string) preg_replace('#^.*?/api/#', '', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? ''), '/');

    if ($path === 'session' && $method === 'GET') out(sessionInfo());

    if ($path === 'admin/login' && $method === 'POST') {
        $b = body(); limit('login', 8, 900);
        $a = adminRow();
        if ($a['pass_hash'] === null || !password_verify((string) ($b['password'] ?? ''), $a['pass_hash'])) fail(401, 'Senha incorreta.');
        setAdminCookie((int) $a['gen']); out(sessionInfo());
    }
    if ($path === 'admin/logout' && $method === 'POST') { setcookie(COOKIE, '', ['expires' => 1, 'path' => '/']); out(['ok' => true]); }
    if ($path === 'admin/setup' && $method === 'POST') {
        $b = body(); limit('setup', 8, 900);
        if (adminRow()['pass_hash'] !== null) fail(409, 'A senha do painel já foi criada. Entre com ela.');
        if (!hash_equals((string) $CFG['setup_code'], trim((string) ($b['code'] ?? '')))) fail(401, 'Código de instalação incorreto.');
        $pw = (string) ($b['password'] ?? ''); if (strlen($pw) < 8 || strlen($pw) > 72) fail(422, 'A senha precisa ter de 8 a 72 caracteres.');
        db()->prepare('UPDATE admin SET pass_hash=?, gen=gen+1 WHERE id=1 AND pass_hash IS NULL')->execute([password_hash($pw, PASSWORD_DEFAULT)]);
        setAdminCookie((int) adminRow()['gen']); out(sessionInfo());
    }
    if ($path === 'admin/password' && $method === 'POST') {
        requireAdmin(); $b = body(); limit('password', 8, 900);
        $a = adminRow();
        if (!password_verify((string) ($b['current'] ?? ''), (string) $a['pass_hash'])) fail(401, 'A senha atual está errada.');
        $pw = (string) ($b['new'] ?? ''); if (strlen($pw) < 8 || strlen($pw) > 72) fail(422, 'A senha precisa ter de 8 a 72 caracteres.');
        db()->prepare('UPDATE admin SET pass_hash=?, gen=gen+1 WHERE id=1')->execute([password_hash($pw, PASSWORD_DEFAULT)]);
        setAdminCookie((int) adminRow()['gen']); out(sessionInfo());
    }

    if ($path === 'catalog' && $method === 'GET') {
        $s = settings();
        out(['products' => $s['products'], 'acceptingOrders' => $s['acceptingOrders'], 'pickupEnabled' => $s['pickupEnabled'], 'deliveryEnabled' => $s['deliveryEnabled'],
            'eta' => $s['eta'], 'pix' => $s['pix'], 'deliveryRules' => $s['deliveryRules'], 'restrictDelivery' => $s['restrictDelivery'],
            'schedule' => scheduleInfo()]);
    }
    /* situação de agora (aberto / entrega), consultada pela loja a cada minuto */
    if ($path === 'status' && $method === 'GET') {
        $s = settings();
        out(['acceptingOrders' => $s['acceptingOrders'], 'pickupEnabled' => $s['pickupEnabled'], 'deliveryEnabled' => $s['deliveryEnabled'], 'schedule' => scheduleInfo()]);
    }
    if ($path === 'settings' && $method === 'GET') { requireAdmin(); out(settings()); }
    if ($path === 'settings' && $method === 'PUT') {
        requireAdmin(); $b = body(); $pdo = db(); $pdo->beginTransaction();
        $old = settings(true);
        if ((int) ($b['version'] ?? 0) !== $old['version']) { $pdo->rollBack(); fail(409, 'As configurações foram alteradas em outro aparelho. Recarregue o painel.'); }
        $new = cleanSettings($b, $old); $v = $old['version'] + 1;
        $pdo->prepare('UPDATE settings SET version=?, data=? WHERE id=1')->execute([$v, json_encode($new, JSON_UNESCAPED_UNICODE)]);
        $pdo->commit(); $new['version'] = $v; out($new);
    }

    if ($path === 'quote' && $method === 'POST') {
        $b = body(); limit('quote', 600, 3600);
        $q = compute($b, settings()); $exp = time() + 1800;
        out(['subtotal' => $q['subtotal'], 'fee' => $q['fee'], 'total' => $q['fee'] === null ? null : $q['subtotal'] + $q['fee'], 'signature' => quoteSig($q, $exp)]);
    }
    if ($path === 'orders' && $method === 'POST') {
        /* página antiga da loja (aberta antes da atualização) ainda pode mandar formulário com arquivo:
           usa só o campo "payload" e ignora o arquivo; o comprovante agora vai pelo WhatsApp */
        if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') === 0) {
            $b = json_decode((string) ($_POST['payload'] ?? ''), true);
            if (!is_array($b)) fail(400, 'Dados inválidos.');
        } else $b = body();
        $key = str($b['key'] ?? '', 64); if ($key === '') fail(422, 'Pedido sem identificador.');
        $st = db()->prepare('SELECT id, data FROM orders WHERE idem=?'); $st->execute([$key]);
        if ($r = $st->fetch()) { $o = json_decode($r['data'], true); out(['id' => $o['id'], 'number' => $o['number'], 'token' => orderToken($o['id']), 'created' => $o['created']]); }
        limit('order', 60, 3600); // por conexão: operadoras de celular põem muitos clientes no mesmo IP
        $q = compute($b, settings());
        $sig = (string) ($b['quote'] ?? ''); $exp = (int) explode('.', $sig)[0];
        if ($exp < time() || !hash_equals(quoteSig($q, $exp), $sig)) fail(409, 'Os valores do pedido mudaram. Volte e revise o pedido.');
        // Pix: entra como "Aguardando conferência do Pix" (o cliente manda o comprovante pelo WhatsApp e o Léo confere)
        $pixCheck = $q['pay'] === 'pix';
        $origin = ($b['origin'] ?? '') === 'whatsapp' ? 'whatsapp' : 'site';
        $id = bin2hex(random_bytes(12)); $ms = nowMs();
        $o = ['id' => $id, 'number' => '', 'created' => iso($ms), 'status' => 'received', 'payment' => $pixCheck ? 'review' : 'pending', 'fee' => $q['fee'],
            'fee_approved' => true, 'cancelReason' => '', 'payMethod' => $q['pay'], 'origin' => $origin, 'version' => 1,
            'snapshot' => ['customer' => $q['customer'], 'mode' => $q['mode'], 'table' => $q['table'], 'address' => $q['address'], 'addr' => $q['addr'], 'neighborhood' => $q['neighborhood'], 'lunch' => $q['lunch'], 'notes' => $q['notes'], 'lines' => $q['lines'], 'subtotal' => $q['subtotal']]];
        $pdo = db(); $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO orders (id, created_ts, idem, version, data) VALUES (?, ?, ?, 1, ?)')->execute([$id, $ms, $key, '{}']);
        $num = (int) $pdo->lastInsertId(); $o['number'] = '#' . $num;
        saveOrder($o); $pdo->commit();
        out(['id' => $id, 'number' => $o['number'], 'token' => orderToken($id), 'created' => $o['created']], 201);
    }
    if ($path === 'orders' && $method === 'GET') {
        requireAdmin();
        $since = isset($_GET['since']) ? (int) $_GET['since'] : 0;
        $st = db()->prepare('SELECT data, version FROM orders WHERE created_ts >= ? ORDER BY num DESC LIMIT 3000'); $st->execute([$since]);
        out(['orders' => array_map(function ($r) { $o = json_decode($r['data'], true); $o['version'] = (int) $r['version']; return $o; }, $st->fetchAll())]);
    }
    /* comprovante do Pix: só o painel do Léo (com login) consegue abrir */
    if (preg_match('#^orders/([0-9a-f]{24})/proof$#', $path, $m) && $method === 'GET') {
        requireAdmin();
        $o = loadOrder($m[1]); $p = $o['proof'] ?? null;
        if (!$p || !preg_match('/^[a-z0-9_.-]+$/i', $p['file'])) fail(404, 'Este pedido não tem comprovante.');
        $file = proofDir() === '' ? '' : proofDir() . '/' . $p['file'];
        if (!is_file($file)) fail(404, 'O arquivo do comprovante não foi encontrado.');
        header('Content-Type: ' . (isset(PROOF_TYPES[$p['type']]) ? $p['type'] : 'application/octet-stream'));
        header('Content-Length: ' . filesize($file));
        header('Content-Disposition: inline; filename="' . $p['file'] . '"');
        header('Cache-Control: private, no-store');
        readfile($file); exit;
    }
    if (preg_match('#^orders/([0-9a-f]{24})$#', $path, $m)) {
        $id = $m[1];
        if ($method === 'GET') { if (!canSee($id, $_GET['token'] ?? null)) fail(404, 'Pedido não encontrado.'); out(isAdmin() ? loadOrder($id) : proofPublic(loadOrder($id))); }
        if ($method === 'PATCH') {
            $b = body(); $act = (string) ($b['action'] ?? ''); $val = $b['value'] ?? null;
            $admin = isAdmin();
            if (!$admin && !($act === 'accept_fee' && canSee($id, isset($b['token']) ? (string) $b['token'] : null))) fail($act === 'accept_fee' ? 404 : 401, $act === 'accept_fee' ? 'Pedido não encontrado.' : 'Entre no painel para continuar.');
            $pdo = db(); $pdo->beginTransaction(); $o = loadOrder($id, true);
            if ((int) ($b['version'] ?? 0) !== $o['version']) { $pdo->rollBack(); fail(409, 'Este pedido foi alterado em outro aparelho. Atualizamos os dados.'); }
            $del = $o['snapshot']['mode'] === 'delivery';
            if ($act === 'accept_fee') {
                if (!$del || $o['status'] !== 'received' || $o['fee'] === null || $o['fee_approved']) { $pdo->rollBack(); fail(409, 'Não há taxa para aceitar agora.'); }
                $o['fee_approved'] = true;
            } elseif ($act === 'status') {
                $next = ['received' => ['preparing', 'cancelled'], 'preparing' => ['ready', 'cancelled'], 'ready' => $del ? ['out_for_delivery', 'cancelled'] : ['completed', 'cancelled'],
                    'out_for_delivery' => ['completed', 'cancelled'], 'completed' => [], 'cancelled' => []][$o['status']];
                if (!in_array($val, $next, true)) { $pdo->rollBack(); fail(409, 'Mudança de andamento não permitida.'); }
                $o['status'] = $val; if ($val === 'cancelled') $o['cancelReason'] = str($b['reason'] ?? '', 140);
            } elseif ($act === 'payment') {
                $next = ['pending' => ['approved', 'rejected', 'expired'], 'review' => ['approved', 'rejected'], 'approved' => ['refunded'], 'rejected' => ['pending', 'approved'], 'expired' => ['pending'], 'refunded' => []][$o['payment']] ?? [];
                if (!in_array($val, $next, true) || ($val === 'approved' && $o['status'] === 'cancelled')) { $pdo->rollBack(); fail(409, 'Mudança de pagamento não permitida.'); }
                $wasReview = $o['payment'] === 'review'; $o['payment'] = $val;
                if ($val === 'rejected') $o['payReason'] = str($b['reason'] ?? '', 140);
                // "Pix não caiu": o Léo pode cancelar o pedido junto
                if ($val === 'rejected' && !empty($b['cancel']) && !in_array($o['status'], ['completed', 'cancelled'], true)) {
                    $o['status'] = 'cancelled'; $o['cancelReason'] = $o['payReason'] !== '' ? $o['payReason'] : 'Pix não identificado';
                }
            } elseif ($act === 'courier') {
                // motoboy chamado pelo painel: vale o último; os anteriores ficam no histórico do pedido
                if (!$del || in_array($o['status'], ['completed', 'cancelled'], true)) { $pdo->rollBack(); fail(409, 'Este pedido não está aguardando entrega.'); }
                $c = null; foreach ((settings()['couriers'] ?? []) as $x) if ($x['id'] === (string) $val && $x['active']) $c = $x;
                if (!$c) { $pdo->rollBack(); fail(422, 'Motoboy não encontrado ou desativado. Atualize a lista em Funcionamento.'); }
                if (!empty($o['courier'])) $o['courierLog'][] = $o['courier'];
                $o['courier'] = ['id' => $c['id'], 'name' => $c['name'], 'at' => iso(nowMs())];
            } elseif ($act === 'fee') {
                if (!$del || in_array($o['status'], ['completed', 'cancelled'], true) || $o['payment'] === 'approved') { $pdo->rollBack(); fail(409, 'Não dá para mudar o frete agora.'); }
                $o['fee'] = cents($val, 100000); $o['fee_approved'] = true;
            } else { $pdo->rollBack(); fail(422, 'Ação desconhecida.'); }
            $o['version']++; saveOrder($o); $pdo->commit(); out($o);
        }
    }
    fail(404, 'Rota não encontrada.');
} catch (HttpError $e) {
    if (db_in_tx()) db()->rollBack();
    out(['error' => $e->getMessage()], $e->status);
} catch (Throwable $e) {
    if (db_in_tx()) db()->rollBack();
    error_log('leo-api: ' . $e->getMessage());
    out(['error' => 'Erro no servidor. Tente de novo em instantes.'], 500);
}
function db_in_tx(): bool { try { return db()->inTransaction(); } catch (Throwable $e) { return false; } }
