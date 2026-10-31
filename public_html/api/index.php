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
    migrate($pdo);
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
function isOpenNow(array $s): bool {
    if (empty($s['hoursConfirmed']) || empty($s['hours'])) return true;
    $now = new DateTime('now', new DateTimeZone(TZ));
    $day = (int) $now->format('w'); $min = (int) $now->format('G') * 60 + (int) $now->format('i'); $y = ($day + 6) % 7;
    foreach ($s['hours'] as $h) {
        [$oh, $om] = array_map('intval', explode(':', $h['open'])); [$ch, $cm] = array_map('intval', explode(':', $h['close']));
        $o = $oh * 60 + $om; $c = $ch * 60 + $cm;
        if ($c > $o) { if ($h['day'] === $day && $min >= $o && $min < $c) return true; }
        else if (($h['day'] === $day && $min >= $o) || ($h['day'] === $y && $min < $c)) return true;
    }
    return false;
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
    if (!isOpenNow($s)) fail(409, 'Estamos fechados agora. Confira o horário no site.');
    $mode = ($in['mode'] ?? '') === 'delivery' ? 'delivery' : (($in['mode'] ?? '') === 'pickup' ? 'pickup' : fail(422, 'Escolha entrega ou retirada.'));
    if ($mode === 'delivery' && empty($s['deliveryEnabled'])) fail(409, 'A entrega está pausada agora. Escolha retirada.');
    if ($mode === 'pickup' && empty($s['pickupEnabled'])) fail(409, 'A retirada está pausada agora. Escolha entrega.');
    $c = (array) ($in['customer'] ?? []); $name = str($c['name'] ?? '', 80); $phone = str($c['phone'] ?? '', 30);
    if ($name === '') fail(422, 'Informe seu nome.');
    $digits = preg_replace('/\D/', '', $phone); if (strlen($digits) < 10 || strlen($digits) > 13) fail(422, 'Informe um telefone com DDD.');
    $address = $mode === 'delivery' ? str($in['address'] ?? '', 200) : ''; $nb = $mode === 'delivery' ? str($in['neighborhood'] ?? '', 100) : '';
    if ($mode === 'delivery' && (mb_strlen($address) < 3 || $nb === '')) fail(422, 'Informe endereço e bairro.');
    $byId = []; foreach ($s['products'] as $p) $byId[$p['id']] = $p;
    $lines = []; $sub = 0; $raw = array_slice((array) ($in['lines'] ?? []), 0, 51);
    if (!$raw) fail(422, 'A sacola está vazia.'); if (count($raw) > 50) fail(422, 'Itens demais em um pedido.');
    foreach ($raw as $l) {
        $p = $byId[(string) ($l['id'] ?? '')] ?? null;
        if (!$p || !$p['available']) fail(409, 'Um item da sacola não está mais disponível. Revise a sacola.');
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
    $fee = $mode === 'pickup' ? 0 : null;
    if ($mode === 'delivery') {
        $rule = null; foreach ($s['deliveryRules'] as $r) if (mb_strtolower($r['neighborhood']) === mb_strtolower($nb)) $rule = $r;
        if (!empty($s['restrictDelivery']) && (!$rule || !$rule['available'])) fail(409, "Ainda não entregamos no bairro $nb. Fale com a gente no WhatsApp.");
        if ($rule && $rule['available']) $fee = $rule['fee'];
    }
    return ['mode' => $mode, 'customer' => ['name' => $name, 'phone' => $phone], 'address' => $address, 'neighborhood' => $nb,
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
            'hours' => $s['hours'], 'hoursConfirmed' => $s['hoursConfirmed'], 'open' => isOpenNow($s)]);
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
        $b = body(); limit('quote', 120, 3600);
        $q = compute($b, settings()); $exp = time() + 1800;
        out(['subtotal' => $q['subtotal'], 'fee' => $q['fee'], 'total' => $q['fee'] === null ? null : $q['subtotal'] + $q['fee'], 'signature' => quoteSig($q, $exp)]);
    }
    if ($path === 'orders' && $method === 'POST') {
        $b = body();
        $key = str($b['key'] ?? '', 64); if ($key === '') fail(422, 'Pedido sem identificador.');
        $st = db()->prepare('SELECT id, data FROM orders WHERE idem=?'); $st->execute([$key]);
        if ($r = $st->fetch()) { $o = json_decode($r['data'], true); out(['id' => $o['id'], 'number' => $o['number'], 'token' => orderToken($o['id']), 'created' => $o['created']]); }
        limit('order', 15, 3600);
        $q = compute($b, settings());
        $sig = (string) ($b['quote'] ?? ''); $exp = (int) explode('.', $sig)[0];
        if ($exp < time() || !hash_equals(quoteSig($q, $exp), $sig)) fail(409, 'Os valores do pedido mudaram. Volte e revise o pedido.');
        $id = bin2hex(random_bytes(12)); $ms = nowMs();
        $o = ['id' => $id, 'number' => '', 'created' => iso($ms), 'status' => 'received', 'payment' => 'pending', 'fee' => $q['fee'],
            'fee_approved' => $q['fee'] !== null, 'cancelReason' => '', 'version' => 1,
            'snapshot' => ['customer' => $q['customer'], 'mode' => $q['mode'], 'address' => $q['address'], 'neighborhood' => $q['neighborhood'], 'notes' => $q['notes'], 'lines' => $q['lines'], 'subtotal' => $q['subtotal']]];
        $pdo = db(); $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO orders (id, created_ts, idem, version, data) VALUES (?, ?, ?, 1, ?)')->execute([$id, $ms, $key, '{}']);
        $o['number'] = '#' . $pdo->lastInsertId(); saveOrder($o); $pdo->commit();
        out(['id' => $id, 'number' => $o['number'], 'token' => orderToken($id), 'created' => $o['created']], 201);
    }
    if ($path === 'orders' && $method === 'GET') {
        requireAdmin();
        $since = isset($_GET['since']) ? (int) $_GET['since'] : 0;
        $st = db()->prepare('SELECT data, version FROM orders WHERE created_ts >= ? ORDER BY num DESC LIMIT 3000'); $st->execute([$since]);
        out(['orders' => array_map(function ($r) { $o = json_decode($r['data'], true); $o['version'] = (int) $r['version']; return $o; }, $st->fetchAll())]);
    }
    if (preg_match('#^orders/([0-9a-f]{24})$#', $path, $m)) {
        $id = $m[1];
        if ($method === 'GET') { if (!canSee($id, $_GET['token'] ?? null)) fail(404, 'Pedido não encontrado.'); out(loadOrder($id)); }
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
                if ($val !== 'cancelled' && !$o['fee_approved']) { $pdo->rollBack(); fail(409, 'O cliente ainda não aceitou a taxa de entrega.'); }
                $o['status'] = $val; if ($val === 'cancelled') $o['cancelReason'] = str($b['reason'] ?? '', 140);
            } elseif ($act === 'payment') {
                $next = ['pending' => ['approved', 'rejected', 'expired'], 'approved' => ['refunded'], 'rejected' => ['pending'], 'expired' => ['pending'], 'refunded' => []][$o['payment']];
                if (!in_array($val, $next, true) || ($val === 'approved' && $o['status'] === 'cancelled')) { $pdo->rollBack(); fail(409, 'Mudança de pagamento não permitida.'); }
                $o['payment'] = $val;
            } elseif ($act === 'fee') {
                if (!$del || $o['status'] !== 'received' || $o['payment'] !== 'pending') { $pdo->rollBack(); fail(409, 'Não dá para mudar o frete agora.'); }
                $o['fee'] = cents($val, 100000); $o['fee_approved'] = false;
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
