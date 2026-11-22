<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| ZEM MEDYA · FREE / ADMIN PANEL  (free.php)
|--------------------------------------------------------------------------
| Tek dosya, bölümlü yapı:
|   1. Ayarlar ve sabitler        5. Servis fonksiyonları (premium, yayın)
|   2. Oturum ve veritabanı        6. POST işlemleri (action handler'lar)
|   3. Genel yardımcılar           7. Görünüm yardımcıları, CSS, layout
|   4. Güvenlik / şifreleme        8. Sayfalar (admin, panel, genel) + router
|--------------------------------------------------------------------------
*/

/* ======================================================================
   1. AYARLAR VE SABİTLER
   (config.php içinde aynı isimle tanımlarsan oradaki değer geçerli olur)
   ====================================================================== */
$zemDefaults = [
    'ZEM_SELF'          => 'free.php',
    'ZEM_ADMIN_HOST'    => 'admin.zemmedya.com',
    'ZEM_SITE_KEY'      => 'zemmedya',
    'ZEM_RTMP_URL'      => 'rtmp://rtmp.zemmedya.com/zem',
    'ZEM_SRT_URL'       => 'srt://zemmedya.com:10080',
    'ZEM_HLS_BASE'      => 'https://zemmedya.com/free/hls/zem/',
    'ZEM_SRS_HLS_BASE'  => 'https://zemmedya.com/srs-hls/live/',
    'ZEM_HLS_DIR'       => '/var/www/mutluapk-free/free/hls/zem/',
    'ZEM_LIVE_URL'      => 'https://canli.zemmedya.com/',
    'ZEM_SUPPORT_URL'   => 'https://t.me/zemmedya',
    'ZEM_PRICE_TEXT'    => '₺150',
    'ZEM_KEY_FILE'      => '/etc/zem-free/key-encryption.key',
    'ZEM_USERS_PER_PAGE'=> 20,
    'ZEM_TZ'            => 'Europe/Istanbul',
    'ZEM_SRS_HLS_DIR'   => '/var/www/mutluapk-free/srs-hls/live/',
    'ZEM_HLSJS_URL'     => 'https://cdn.jsdelivr.net/npm/hls.js@1.5.17/dist/hls.min.js',
    'ZEM_VERIFY_TTL'    => 60,
];

$requestHost = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

/* ======================================================================
   2. OTURUM VE VERİTABANI
   ====================================================================== */
$isAdminHost = ($requestHost === ($zemDefaults['ZEM_ADMIN_HOST']));
session_name($isAdminHost ? 'ZEMADMIN' : 'ZEMFREE');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $isHttps || !in_array($requestHost, ['localhost', '127.0.0.1'], true),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require_once '/etc/zem-free/config.php';

foreach ($zemDefaults as $constName => $constValue) {
    if (!defined($constName)) define($constName, $constValue);
}

date_default_timezone_set((string)ZEM_TZ);

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Cache-Control: private, no-store');

$db = new PDO('sqlite:' . ZEM_FREE_DB);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('PRAGMA busy_timeout=5000');

/* ======================================================================
   3. GENEL YARDIMCILAR
   ====================================================================== */
function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function go(string $url = ''): never
{
    header('Location: ' . ($url !== '' ? $url : ZEM_SELF));
    exit;
}

function url(array $params = []): string
{
    return ZEM_SELF . ($params ? '?' . http_build_query($params) : '');
}

function admin_url(string $section = 'overview', array $extra = []): string
{
    return url(array_merge(['admin' => 1, 'section' => $section], $extra));
}

function client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

function csrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf()) . '">';
}

function verify_csrf(): void
{
    if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('Güvenlik doğrulaması başarısız. Sayfayı yenileyip tekrar dene.');
    }
}

function flash(string $message, string $type = 'ok'): void
{
    $_SESSION['flash'] = [$message, $type];
}

function fmt_date(?int $ts, bool $time = false): string
{
    if (!$ts) return '—';
    return date($time ? 'd.m.Y H:i' : 'd.m.Y', $ts);
}

function time_ago(int $ts): string
{
    $d = max(0, time() - $ts);
    if ($d < 60)    return 'az önce';
    if ($d < 3600)  return intdiv($d, 60) . ' dk önce';
    if ($d < 86400) return intdiv($d, 3600) . ' sa önce';
    return intdiv($d, 86400) . ' gün önce';
}

function duration_text(int $seconds): string
{
    $seconds = max(0, $seconds);
    $hours = intdiv($seconds, 3600);
    $mins  = intdiv($seconds % 3600, 60);
    if ($hours > 0) return $hours . ' sa ' . $mins . ' dk';
    return max(1, $mins) . ' dk';
}

function valid_stream_name(string $name): bool
{
    return (bool)preg_match('/^[a-zA-Z0-9_\-]{3,64}$/', $name);
}

/* ======================================================================
   4. GÜVENLİK: ANAHTAR ÜRETİMİ, ŞİFRELEME, İSTEK SINIRLAMA
   ====================================================================== */
function make_key(): string
{
    $digits = '';
    for ($i = 0; $i < 10; $i++) {
        $digits .= (string)random_int(0, 9);
    }
    return 'Zemmedya-' . $digits;
}

function stream_encryption_key(): string
{
    $key = @file_get_contents(ZEM_KEY_FILE);
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('Yayın anahtarı şifreleme anahtarı okunamadı.');
    }
    return $key;
}

function encrypt_stream_key(string $plain): string
{
    $iv  = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', stream_encryption_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false || strlen($tag) !== 16) {
        throw new RuntimeException('Yayın anahtarı şifrelenemedi.');
    }
    return base64_encode($iv . $tag . $cipher);
}

function decrypt_stream_key(?string $stored): ?string
{
    if (!$stored) return null;
    $raw = base64_decode($stored, true);
    if ($raw === false || strlen($raw) < 29) return null;
    $plain = openssl_decrypt(
        substr($raw, 28),
        'aes-256-gcm',
        stream_encryption_key(),
        OPENSSL_RAW_DATA,
        substr($raw, 0, 12),
        substr($raw, 12, 16)
    );
    return $plain === false ? null : $plain;
}

/** Ek tablolar (yoksa oluşturulur). Yetki yoksa site çalışmaya devam eder. */
function ensure_schema(PDO $db): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $db->exec('CREATE TABLE IF NOT EXISTS zem_auth_attempts(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            kind TEXT NOT NULL,
            ip TEXT NOT NULL,
            identity TEXT,
            created_at INTEGER NOT NULL
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_zem_auth_attempts ON zem_auth_attempts(kind,ip,created_at)');
        $db->exec('CREATE TABLE IF NOT EXISTS zem_announcements(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            body TEXT NOT NULL,
            level TEXT NOT NULL DEFAULT \'info\',
            active INTEGER NOT NULL DEFAULT 1,
            admin_id INTEGER,
            created_at INTEGER NOT NULL,
            expires_at INTEGER
        )');
        $ok = true;
    } catch (Throwable $e) {
        error_log('ZEM schema: ' . $e->getMessage());
        $ok = false;
    }
    return $ok;
}

function throttle_blocked(PDO $db, string $kind, int $max = 8, int $window = 900): bool
{
    if (!ensure_schema($db)) return false;
    try {
        $q = $db->prepare('SELECT COUNT(*) FROM zem_auth_attempts WHERE kind=? AND ip=? AND created_at>=?');
        $q->execute([$kind, client_ip(), time() - $window]);
        return (int)$q->fetchColumn() >= $max;
    } catch (Throwable $e) {
        return false;
    }
}

function throttle_hit(PDO $db, string $kind, string $identity = ''): void
{
    if (!ensure_schema($db)) return;
    try {
        $db->prepare('INSERT INTO zem_auth_attempts(kind,ip,identity,created_at) VALUES(?,?,?,?)')
           ->execute([$kind, client_ip(), mb_substr($identity, 0, 120), time()]);
        if (random_int(1, 40) === 1) {
            $db->prepare('DELETE FROM zem_auth_attempts WHERE created_at<?')->execute([time() - 86400]);
        }
    } catch (Throwable $e) {
        error_log('ZEM throttle: ' . $e->getMessage());
    }
}

function throttle_clear(PDO $db, string $kind): void
{
    if (!ensure_schema($db)) return;
    try {
        $db->prepare('DELETE FROM zem_auth_attempts WHERE kind=? AND ip=?')->execute([$kind, client_ip()]);
    } catch (Throwable $e) {
    }
}

/* ======================================================================
   5. SERVİS FONKSİYONLARI
   ====================================================================== */
function audit(PDO $db, string $action, ?int $uid = null, string $details = ''): void
{
    $q = $db->prepare('INSERT INTO audit_logs(admin_id,user_id,action,details,ip,created_at) VALUES(?,?,?,?,?,?)');
    $q->execute([$_SESSION['admin_id'] ?? null, $uid, $action, $details, client_ip(), time()]);
}

function current_user(PDO $db): ?array
{
    if (empty($_SESSION['user_id'])) return null;
    $q = $db->prepare('SELECT * FROM users WHERE id=?');
    $q->execute([(int)$_SESSION['user_id']]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

function current_admin(PDO $db): ?array
{
    if (empty($_SESSION['admin_id'])) return null;
    $q = $db->prepare('SELECT * FROM admins WHERE id=? AND site=? AND active=1');
    $q->execute([(int)$_SESSION['admin_id'], ZEM_SITE_KEY]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

function user_is_banned(array $u): bool
{
    return ($u['status'] ?? '') === 'banned'
        && (empty($u['banned_until']) || (int)$u['banned_until'] > time());
}

/** Süresi dolmuş yasağı otomatik kaldırır; güncel kullanıcı satırını döndürür. */
function lift_expired_ban(PDO $db, array $u): array
{
    if (($u['status'] ?? '') === 'banned' && !user_is_banned($u)) {
        $db->prepare("UPDATE users SET status='active',banned_until=NULL,ban_reason=NULL WHERE id=?")->execute([$u['id']]);
        $u['status'] = 'active';
        $u['banned_until'] = null;
        $u['ban_reason'] = null;
    }
    return $u;
}

function new_stream_key(PDO $db, int $id): string
{
    $key = make_key();
    $q = $db->prepare('SELECT username FROM users WHERE id=? LIMIT 1');
    $q->execute([$id]);
    $username = (string)$q->fetchColumn();
    if ($username === '') {
        throw new RuntimeException('Kullanıcı adı bulunamadı.');
    }
    $q = $db->prepare('UPDATE users SET zem_stream_key_hash=?,zem_stream_key_enc=?,zem_stream_name=? WHERE id=?');
    $q->execute([hash('sha256', $key), encrypt_stream_key($key), $username, $id]);
    return $key;
}

function new_srt_stream_key(PDO $db, int $id): string
{
    $key = make_key();
    $q = $db->prepare('UPDATE users SET zem_srt_key_hash=?,zem_srt_key_enc=? WHERE id=?');
    $q->execute([hash('sha256', $key), encrypt_stream_key($key), $id]);
    return $key;
}

function has_premium(PDO $db, int $uid): bool
{
    return premium_data($db, $uid) !== null;
}

function premium_data(PDO $db, int $uid): ?array
{
    $now = time();
    $q = $db->prepare("SELECT starts_at,ends_at FROM premium_subscriptions WHERE user_id=? AND status='active' AND starts_at<=? AND ends_at>=? LIMIT 1");
    $q->execute([$uid, $now, $now]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Tüm aktif premium üyelikleri tek sorguda getirir: [user_id => ['starts_at','ends_at']] */
function premium_map(PDO $db): array
{
    $now = time();
    $q = $db->prepare("SELECT user_id,starts_at,ends_at FROM premium_subscriptions WHERE status='active' AND starts_at<=? AND ends_at>=?");
    $q->execute([$now, $now]);
    $map = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $map[(int)$r['user_id']] = $r;
    }
    return $map;
}

function premium_days_left(?array $prem): int
{
    return $prem ? max(0, (int)ceil(((int)$prem['ends_at'] - time()) / 86400)) : 0;
}

function premium_streams(PDO $db, int $uid): array
{
    $out = [];
    $q = $db->prepare("
        SELECT id,stream_name,key_enc,srt_key_enc,slot
        FROM premium_streams
        WHERE user_id=? AND site=? AND enabled=1
        ORDER BY slot ASC, stream_name ASC
    ");
    $q->execute([$uid, ZEM_SITE_KEY]);

    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $plain  = decrypt_stream_key($s['key_enc'] ?? null);
        $srtKey = decrypt_stream_key($s['srt_key_enc'] ?? null);

        if ($srtKey === null) {
            $srtKey = make_key();
            $db->prepare("UPDATE premium_streams SET srt_key_hash=?,srt_key_enc=?,updated_at=? WHERE id=? AND user_id=? AND site=?")
               ->execute([hash('sha256', $srtKey), encrypt_stream_key($srtKey), time(), (int)$s['id'], $uid, ZEM_SITE_KEY]);
        }

        $name = (string)$s['stream_name'];
        $out[] = [
            'id'            => (int)$s['id'],
            'slot'          => (int)($s['slot'] ?? 0),
            'name'          => $name,
            'key'           => $plain !== null ? $name . '?key=' . $plain : null,
            'hls'           => ZEM_HLS_BASE . $name . '.m3u8',
            'srt_key'       => $srtKey,
            'srt_stream_id' => '#!::r=live/' . $name . ',m=publish,key=' . $srtKey,
            'srt_hls'       => ZEM_SRS_HLS_BASE . $name . '.m3u8',
        ];
    }
    return $out;
}

function premium_slot_row(PDO $db, int $uid, int $slot): ?array
{
    $q = $db->prepare("SELECT id,stream_name FROM premium_streams WHERE user_id=? AND site=? AND slot=? LIMIT 1");
    $q->execute([$uid, ZEM_SITE_KEY, $slot]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

function stream_name_taken(PDO $db, string $name, int $uid, int $slot): bool
{
    $q = $db->prepare("SELECT 1 FROM premium_streams WHERE stream_name=? COLLATE NOCASE AND site=? AND NOT (user_id=? AND slot=?) LIMIT 1");
    $q->execute([$name, ZEM_SITE_KEY, $uid, $slot]);
    if ($q->fetchColumn()) return true;

    $q = $db->prepare('SELECT 1 FROM users WHERE zem_stream_name=? COLLATE NOCASE AND id<>? LIMIT 1');
    $q->execute([$name, $uid]);
    return (bool)$q->fetchColumn();
}

function zem_public_stream_id(PDO $db, int $uid, string $streamName): string
{
    $q = $db->prepare('SELECT public_id FROM zem_public_streams WHERE user_id=? AND stream_name=? LIMIT 1');
    $q->execute([$uid, $streamName]);
    $id = $q->fetchColumn();
    if ($id) return (string)$id;

    $id = bin2hex(random_bytes(12));
    $db->prepare('INSERT OR IGNORE INTO zem_public_streams(user_id,stream_name,public_id,created_at) VALUES(?,?,?,?)')
       ->execute([$uid, $streamName, $id, time()]);

    $q = $db->prepare('SELECT public_id FROM zem_public_streams WHERE user_id=? AND stream_name=? LIMIT 1');
    $q->execute([$uid, $streamName]);
    return (string)$q->fetchColumn();
}

function grant_premium(PDO $db, int $uid, int $days, int $adminId): void
{
    $now = time();
    $q = $db->prepare('SELECT starts_at,ends_at FROM premium_subscriptions WHERE user_id=? LIMIT 1');
    $q->execute([$uid]);
    $old = $q->fetch(PDO::FETCH_ASSOC);
    $activeOld = $old && (int)$old['ends_at'] >= $now;
    $starts = $activeOld ? (int)$old['starts_at'] : $now;
    $base   = $activeOld ? max($now, (int)$old['ends_at']) : $now;
    $ends   = $base + ($days * 86400);

    $db->prepare("INSERT INTO premium_subscriptions(user_id,starts_at,ends_at,status,approved_by,created_at,updated_at) VALUES(?,?,?,'active',?,?,?)
        ON CONFLICT(user_id) DO UPDATE SET starts_at=excluded.starts_at,ends_at=excluded.ends_at,status='active',approved_by=excluded.approved_by,updated_at=excluded.updated_at")
       ->execute([$uid, $starts, $ends, $adminId, $now, $now]);
    $db->prepare('UPDATE users SET is_premium=1 WHERE id=?')->execute([$uid]);

    $uq = $db->prepare('SELECT zem_stream_name FROM users WHERE id=?');
    $uq->execute([$uid]);
    $baseName = (string)($uq->fetchColumn() ?: ('u' . $uid));

    for ($slot = 1; $slot <= 5; $slot++) {
        $existing = premium_slot_row($db, $uid, $slot);
        if ($existing) {
            $db->prepare('UPDATE premium_streams SET enabled=1,updated_at=? WHERE id=? AND site=?')
               ->execute([$now, (int)$existing['id'], ZEM_SITE_KEY]);
        } else {
            $key = make_key();
            $db->prepare("INSERT INTO premium_streams(user_id,slot,stream_name,key_hash,key_enc,enabled,created_at,updated_at,site) VALUES(?,?,?,?,?,1,?,?,?)")
               ->execute([$uid, $slot, $baseName . '__s' . $slot, hash('sha256', $key), encrypt_stream_key($key), $now, $now, ZEM_SITE_KEY]);
        }
    }
}

function revoke_premium(PDO $db, int $uid): void
{
    $now = time();
    $db->prepare("UPDATE premium_subscriptions SET status='expired',updated_at=? WHERE user_id=?")->execute([$now, $uid]);
    $db->prepare('UPDATE premium_streams SET enabled=0,updated_at=? WHERE user_id=? AND site=?')->execute([$now, $uid, ZEM_SITE_KEY]);
    $db->prepare('UPDATE users SET is_premium=0 WHERE id=?')->execute([$uid]);
}

/** Bir kullanıcının şu an canlı (son 35 sn içinde sinyal veren) oturumları. */
function live_session_names(PDO $db, int $uid): array
{
    try {
        $q = $db->prepare("SELECT stream_name FROM stream_sessions WHERE user_id=? AND site=? AND active=1 AND last_seen>=?");
        $q->execute([$uid, ZEM_SITE_KEY, time() - 35]);
        return array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        return [];
    }
}

function recent_sessions(PDO $db, int $uid, int $limit = 8): array
{
    try {
        $q = $db->prepare("SELECT stream_name,started_at,last_seen,active FROM stream_sessions WHERE user_id=? AND site=? ORDER BY started_at DESC LIMIT " . (int)$limit);
        $q->execute([$uid, ZEM_SITE_KEY]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/* ======================================================================
   6. POST İŞLEMLERİ (action handler'lar)
   ====================================================================== */
function require_user(PDO $db, bool $mustBeActive = true): array
{
    $u = current_user($db);
    if (!$u) {
        flash('Oturum bulunamadı. Lütfen giriş yap.', 'err');
        go(url(['page' => 'login']));
    }
    $u = lift_expired_ban($db, $u);
    if ($mustBeActive && ($u['status'] ?? '') !== 'active') {
        flash('Aktif kullanıcı hesabı gerekli.', 'err');
        go();
    }
    return $u;
}

function require_premium_user(PDO $db): array
{
    $u = require_user($db, true);
    if (!has_premium($db, (int)$u['id'])) {
        flash('Bu özellik için premium üyelik gerekir.', 'err');
        go();
    }
    return $u;
}

function require_admin(PDO $db): array
{
    $a = current_admin($db);
    if (!$a) {
        flash('Yönetici oturumu bulunamadı.', 'err');
        go(url(['page' => 'login']));
    }
    return $a;
}

function post_slot(): int
{
    $slot = (int)($_POST['slot'] ?? 0);
    return ($slot >= 1 && $slot <= 5) ? $slot : 0;
}

/* ---------- Kimlik doğrulama ---------- */
function act_login(PDO $db): void
{
    $back = url(['page' => 'login']);
    if (throttle_blocked($db, 'login', 8, 900)) {
        flash('Çok fazla hatalı giriş denemesi. 15 dakika sonra tekrar dene.', 'err');
        go($back);
    }

    $identity = trim((string)($_POST['identity'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    $q = $db->prepare('SELECT * FROM admins WHERE username=? COLLATE NOCASE AND site=? AND active=1 LIMIT 1');
    $q->execute([$identity, ZEM_SITE_KEY]);
    $a = $q->fetch(PDO::FETCH_ASSOC);
    if ($a && password_verify($password, $a['password_hash'])) {
        throttle_clear($db, 'login');
        session_regenerate_id(true);
        unset($_SESSION['user_id']);
        $_SESSION['admin_id'] = (int)$a['id'];
        go(url(['admin' => 1]));
    }

    $q = $db->prepare('SELECT * FROM users WHERE email=? COLLATE NOCASE OR username=? COLLATE NOCASE LIMIT 1');
    $q->execute([$identity, $identity]);
    $u = $q->fetch(PDO::FETCH_ASSOC);
    if ($u && password_verify($password, $u['password_hash'])) {
        if (user_is_banned($u)) {
            flash('Hesabın yasaklanmış. Yöneticiyle iletişime geç.', 'err');
            go($back);
        }
        $u = lift_expired_ban($db, $u);
        throttle_clear($db, 'login');
        session_regenerate_id(true);
        unset($_SESSION['admin_id']);
        $_SESSION['user_id'] = (int)$u['id'];
        $db->prepare('UPDATE users SET last_login=?,last_ip=? WHERE id=?')->execute([time(), client_ip(), $u['id']]);
        go();
    }

    throttle_hit($db, 'login', strtolower($identity));
    flash('Giriş bilgileri hatalı.', 'err');
    go($back);
}

function act_forgot(PDO $db): void
{
    if (throttle_blocked($db, 'forgot', 5, 900)) {
        flash('Çok fazla deneme yapıldı. 15 dakika sonra tekrar dene.', 'err');
        go(url(['page' => 'forgot']));
    }

    $email  = strtolower(trim((string)($_POST['email'] ?? '')));
    $answer = mb_strtolower(trim((string)($_POST['secret_answer'] ?? '')), 'UTF-8');
    $q = $db->prepare('SELECT * FROM users WHERE email=? COLLATE NOCASE LIMIT 1');
    $q->execute([$email]);
    $u = $q->fetch(PDO::FETCH_ASSOC);

    if ($u && !empty($u['secret_answer_hash']) && password_verify($answer, $u['secret_answer_hash'])) {
        throttle_clear($db, 'forgot');
        $_SESSION['reset_user_id']     = (int)$u['id'];
        $_SESSION['reset_verified_at'] = time();
        go(url(['page' => 'reset']));
    }

    throttle_hit($db, 'forgot', $email);
    flash('E-posta veya gizli soru cevabı doğru değil.', 'err');
    go(url(['page' => 'forgot']));
}

function act_reset_password(PDO $db): void
{
    $id       = (int)($_SESSION['reset_user_id'] ?? 0);
    $verified = (int)($_SESSION['reset_verified_at'] ?? 0);
    $new      = (string)($_POST['new_password'] ?? '');

    if (!$id || !$verified || time() - $verified > 600 || strlen($new) < 10) {
        unset($_SESSION['reset_user_id'], $_SESSION['reset_verified_at']);
        flash('Doğrulama süresi doldu veya şifre yeterince uzun değil.', 'err');
        go(url(['page' => 'forgot']));
    }
    $db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($new, PASSWORD_DEFAULT), $id]);
    unset($_SESSION['reset_user_id'], $_SESSION['reset_verified_at']);
    audit($db, 'user_password_reset', $id, 'Gizli soru ile sıfırlandı');
    flash('Şifren yenilendi. Yeni şifrenle giriş yapabilirsin.');
    go(url(['page' => 'login']));
}

function act_logout(PDO $db): void
{
    $_SESSION = [];
    session_regenerate_id(true);
    flash('Oturum kapatıldı.');
    go();
}

function act_change_password(PDO $db): void
{
    $u = require_user($db, false);
    $current = (string)($_POST['current_password'] ?? '');
    $new     = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    $back    = url() . '#guvenlik';

    if (!password_verify($current, (string)$u['password_hash'])) {
        flash('Mevcut şifren doğru değil.', 'err');
        go($back);
    }
    if (strlen($new) < 10) {
        flash('Yeni şifre en az 10 karakter olmalı.', 'err');
        go($back);
    }
    if ($new !== $confirm) {
        flash('Yeni şifre ve tekrarı aynı değil.', 'err');
        go($back);
    }
    if (hash_equals($current, $new)) {
        flash('Yeni şifre mevcut şifreyle aynı olamaz.', 'err');
        go($back);
    }
    $db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($new, PASSWORD_DEFAULT), (int)$u['id']]);
    session_regenerate_id(true);
    audit($db, 'user_password_changed', (int)$u['id'], 'Panelden değiştirildi');
    flash('Şifren güncellendi.');
    go($back);
}

function act_admin_password(PDO $db): void
{
    $admin = current_admin($db);
    $new   = (string)($_POST['new_password'] ?? '');
    if (!$admin || strlen($new) < 12) {
        flash('Yeni yönetici şifresi en az 12 karakter olmalı.', 'err');
        go(url(['admin' => 1]));
    }
    $db->prepare('UPDATE admins SET password_hash=?,force_password_change=0 WHERE id=? AND site=?')
       ->execute([password_hash($new, PASSWORD_DEFAULT), $admin['id'], ZEM_SITE_KEY]);
    audit($db, 'admin_password_changed');
    flash('Yönetici şifresi güncellendi.');
    go(url(['admin' => 1]));
}

/* ---------- Kullanıcı yayın işlemleri ---------- */
function act_toggle_public_stream(PDO $db): void
{
    $u = require_user($db);
    $enabled = (int)(($_POST['enabled'] ?? '0') === '1');
    $db->prepare("INSERT INTO zem_public_settings(user_id,enabled,updated_at) VALUES(?,?,?)
        ON CONFLICT(user_id) DO UPDATE SET enabled=excluded.enabled,updated_at=excluded.updated_at")
       ->execute([(int)$u['id'], $enabled, time()]);

    flash($enabled
        ? 'Yayın görünürlüğü açıldı. RTMP bağlantın etkilenmedi.'
        : 'Yayın gizlendi. RTMP bağlantın devam ediyor.');
    go(url() . '#zem-public-live');
}

function act_new_key(PDO $db): void
{
    $u = require_user($db);
    $_SESSION['new_key'] = new_stream_key($db, (int)$u['id']);
    audit($db, 'user_rtmp_key_renewed', (int)$u['id']);
    flash('Yeni yayın anahtarın oluşturuldu. Eski anahtar geçersiz.');
    go(url() . '#yayin');
}

function act_new_srt_key(PDO $db): void
{
    $u = require_user($db);
    $_SESSION['new_srt_key'] = new_srt_stream_key($db, (int)$u['id']);
    audit($db, 'user_srt_key_renewed', (int)$u['id']);
    flash('SRT yayın anahtarın yenilendi. Eski SRT anahtarı geçersiz.');
    go(url() . '#yayin');
}

function act_rename_premium_stream(PDO $db): void
{
    $u    = require_premium_user($db);
    $uid  = (int)$u['id'];
    $slot = post_slot();
    $name = trim((string)($_POST['stream_name'] ?? ''));
    $back = url() . '#premium';

    if (!$slot) {
        flash('Geçersiz slot.', 'err');
        go($back);
    }
    if (!valid_stream_name($name)) {
        flash('Yayın adı 3-64 karakter olmalı ve sadece harf, rakam, _ ve - içermeli.', 'err');
        go($back);
    }
    if (stream_name_taken($db, $name, $uid, $slot)) {
        flash('Bu yayın adı başka bir yayında veya kullanıcıda kullanılıyor.', 'err');
        go($back);
    }
    $row = premium_slot_row($db, $uid, $slot);
    if (!$row) {
        flash('Bu slot için kayıt bulunamadı.', 'err');
        go($back);
    }
    $db->prepare('UPDATE premium_streams SET stream_name=?,updated_at=? WHERE id=? AND site=?')
       ->execute([$name, time(), (int)$row['id'], ZEM_SITE_KEY]);
    audit($db, 'premium_stream_renamed', $uid, 'Slot ' . $slot . ' → ' . $name);
    flash('Yayın adı güncellendi.');
    go($back);
}

function act_renew_premium_key(PDO $db): void
{
    $u    = require_premium_user($db);
    $uid  = (int)$u['id'];
    $slot = post_slot();
    $back = url() . '#premium';
    $row  = $slot ? premium_slot_row($db, $uid, $slot) : null;
    if (!$row) {
        flash('Slot bulunamadı.', 'err');
        go($back);
    }
    $key = make_key();
    $db->prepare('UPDATE premium_streams SET key_hash=?,key_enc=?,updated_at=? WHERE id=? AND site=?')
       ->execute([hash('sha256', $key), encrypt_stream_key($key), time(), (int)$row['id'], ZEM_SITE_KEY]);
    audit($db, 'premium_key_renewed', $uid, 'Slot ' . $slot);
    flash('Bu yayın anahtarı yenilendi.');
    go($back);
}

function act_renew_premium_srt_key(PDO $db): void
{
    $u    = require_premium_user($db);
    $uid  = (int)$u['id'];
    $slot = post_slot();
    $back = url() . '#premium';
    $row  = $slot ? premium_slot_row($db, $uid, $slot) : null;
    if (!$row) {
        flash('Slot bulunamadı.', 'err');
        go($back);
    }
    $key = make_key();
    $db->prepare('UPDATE premium_streams SET srt_key_hash=?,srt_key_enc=?,updated_at=? WHERE id=? AND site=?')
       ->execute([hash('sha256', $key), encrypt_stream_key($key), time(), (int)$row['id'], ZEM_SITE_KEY]);
    audit($db, 'premium_srt_key_renewed', $uid, 'Slot ' . $slot);
    flash('Premium slotun SRT anahtarı yenilendi.');
    go($back);
}

/* ---------- Yönetici işlemleri ---------- */
function act_admin_report_action(PDO $db): void
{
    $admin = require_admin($db);
    $back  = admin_url('reports');
    $rid   = (int)($_POST['report_id'] ?? 0);
    $op    = (string)($_POST['operation'] ?? '');

    $q = $db->prepare('SELECT * FROM zem_stream_reports WHERE id=? LIMIT 1');
    $q->execute([$rid]);
    $report = $q->fetch(PDO::FETCH_ASSOC);
    if (!$report) {
        flash('Bildirim bulunamadı.', 'err');
        go($back);
    }

    $setStatus = function (string $status) use ($db, $admin, $rid): void {
        $db->prepare('UPDATE zem_stream_reports SET status=?,reviewed_at=?,reviewed_by=? WHERE id=?')
           ->execute([$status, time(), (int)$admin['id'], $rid]);
    };

    if ($op === 'review') {
        $setStatus('reviewing');
        audit($db, 'stream_report_reviewed', null, 'Bildirim #' . $rid);
        flash('Bildirim incelemeye alındı.');
    } elseif ($op === 'close') {
        $setStatus('closed');
        audit($db, 'stream_report_closed', null, 'Bildirim #' . $rid);
        flash('Bildirim kapatıldı.');
    } elseif ($op === 'block') {
        $db->beginTransaction();
        try {
            $db->prepare("INSERT INTO zem_public_stream_blocks(public_id,stream_name,admin_id,reason,blocked_at) VALUES(?,?,?,?,?)
                ON CONFLICT(public_id) DO UPDATE SET stream_name=excluded.stream_name,admin_id=excluded.admin_id,reason=excluded.reason,blocked_at=excluded.blocked_at")
               ->execute([$report['public_id'], $report['stream_name'], (int)$admin['id'], 'Bildirim #' . $rid . ' · ' . $report['reason'], time()]);
            $setStatus('blocked');
            audit($db, 'public_stream_blocked', null, 'Yayın: ' . $report['stream_name'] . ' · Bildirim #' . $rid);
            $db->commit();
            flash('Yalnızca bildirilen public yayın engellendi. RTMP ve HLS akışı silinmedi.');
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('ZEM report block: ' . $e->getMessage());
            flash('Yayın engellenemedi. İşlem geri alındı.', 'err');
        }
    } elseif ($op === 'unblock') {
        $db->prepare('DELETE FROM zem_public_stream_blocks WHERE public_id=?')->execute([$report['public_id']]);
        $setStatus('reviewing');
        audit($db, 'public_stream_unblocked', null, 'Yayın: ' . $report['stream_name']);
        flash('Yayının public engeli kaldırıldı.');
    } else {
        flash('Geçersiz bildirim işlemi.', 'err');
    }
    go($back);
}

function act_admin_user_action(PDO $db): void
{
    $admin = require_admin($db);
    $uid   = (int)($_POST['user_id'] ?? 0);
    $back  = (string)($_POST['return'] ?? '');
    // yalnızca kendi admin sayfalarımıza dönüşe izin ver
    if (strpos($back, ZEM_SELF . '?admin=1') !== 0) {
        $back = admin_url('users');
    }

    $q = $db->prepare('SELECT * FROM users WHERE id=?');
    $q->execute([$uid]);
    $target = $q->fetch(PDO::FETCH_ASSOC);
    if (!$target) {
        flash('Kullanıcı bulunamadı.', 'err');
        go($back);
    }

    $op = (string)($_POST['operation'] ?? '');
    $transaction = function (callable $work, string $okMsg, string $errMsg, string $logTag) use ($db): void {
        try {
            $db->beginTransaction();
            $work();
            $db->commit();
            flash($okMsg);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('ZEM ' . $logTag . ': ' . $e->getMessage());
            flash($errMsg, 'err');
        }
    };

    switch ($op) {
        case 'delete_user':
            $transaction(function () use ($db, $uid) {
                $db->prepare("DELETE FROM stream_sessions WHERE user_id=? AND site=?")->execute([$uid, ZEM_SITE_KEY]);
                $db->prepare("DELETE FROM premium_streams WHERE user_id=? AND site=?")->execute([$uid, ZEM_SITE_KEY]);
                $db->prepare('UPDATE users SET zem_stream_key_hash=NULL,zem_stream_key_enc=NULL,zem_stream_name=NULL WHERE id=?')->execute([$uid]);
            }, $target['username'] . ' hesabı ve ilişkili veritabanı kayıtları silindi.', 'Kullanıcı silinemedi. İşlem geri alındı.', 'kullanıcı silme');
            break;

        case 'ban':
            $hours  = (int)($_POST['hours'] ?? 0);
            $until  = $hours > 0 ? time() + min($hours, 8760) * 3600 : null;
            $reason = trim((string)($_POST['reason'] ?? '')) ?: 'Yönetici kararı';
            $db->prepare("UPDATE users SET status='banned',banned_until=?,ban_reason=? WHERE id=?")->execute([$until, $reason, $uid]);
            audit($db, 'user_banned', $uid, $reason);
            flash('Kullanıcı yasaklandı.');
            break;

        case 'unban':
            $db->prepare("UPDATE users SET status='active',banned_until=NULL,ban_reason=NULL WHERE id=?")->execute([$uid]);
            audit($db, 'user_unbanned', $uid, 'Yasak kaldırıldı');
            flash('Kullanıcının yasağı kaldırıldı.');
            break;

        case 'revoke':
            $db->prepare('UPDATE users SET zem_stream_key_hash=NULL,zem_stream_key_enc=NULL WHERE id=?')->execute([$uid]);
            audit($db, 'stream_key_revoked', $uid, 'Anahtar iptal edildi');
            flash('Yayın anahtarı iptal edildi.');
            break;

        case 'grant_premium':
            $days = max(1, min(365, (int)($_POST['days'] ?? 30)));
            $transaction(function () use ($db, $uid, $days, $admin) {
                grant_premium($db, $uid, $days, (int)$admin['id']);
                audit($db, 'premium_granted', $uid, $days . ' gün · 5 yayın');
            }, $target['username'] . ' hesabına ' . $days . ' gün premium verildi. 5 yayın slotu hazır.', 'Premium işlemi başarısız.', 'premium verme');
            break;

        case 'revoke_premium':
            $transaction(function () use ($db, $uid) {
                revoke_premium($db, $uid);
                audit($db, 'premium_revoked', $uid);
            }, 'Premium üyelik kaldırıldı.', 'Premium kaldırılamadı.', 'premium kaldırma');
            break;

        case 'rename_premium_slot':
            $slot = post_slot();
            $name = trim((string)($_POST['stream_name'] ?? ''));
            if (!$slot || !valid_stream_name($name)) {
                flash('Geçersiz slot veya yayın adı.', 'err');
            } elseif (stream_name_taken($db, $name, $uid, $slot)) {
                flash('Bu yayın adı başka bir yayında veya kullanıcıda kullanılıyor.', 'err');
            } else {
                $db->prepare('UPDATE premium_streams SET stream_name=?,updated_at=? WHERE user_id=? AND site=? AND slot=?')
                   ->execute([$name, time(), $uid, ZEM_SITE_KEY, $slot]);
                audit($db, 'premium_slot_renamed', $uid, 'Slot ' . $slot . ' → ' . $name);
                flash('Slot yayın adı güncellendi.');
            }
            break;

        case 'renew_premium_slot':
            $slot = post_slot();
            $row  = $slot ? premium_slot_row($db, $uid, $slot) : null;
            if (!$row) {
                flash('Slot bulunamadı.', 'err');
            } else {
                $key = make_key();
                $db->prepare('UPDATE premium_streams SET key_hash=?,key_enc=?,updated_at=? WHERE id=? AND site=?')
                   ->execute([hash('sha256', $key), encrypt_stream_key($key), time(), (int)$row['id'], ZEM_SITE_KEY]);
                audit($db, 'premium_slot_renewed', $uid, 'Slot ' . $slot);
                flash('Slot anahtarı yenilendi.');
            }
            break;

        default:
            flash('Geçersiz işlem.', 'err');
    }
    go($back);
}

/** CSV dışa aktarım (yalnızca yönetici, GET ?admin=1&export=users). */
function act_export_users(PDO $db, array $admin): never
{
    $now  = time();
    $prem = premium_map($db);
    $rows = $db->query('SELECT id,username,email,phone,status,created_at,last_login FROM users ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    audit($db, 'users_exported', null, count($rows) . ' kayıt');

    $safe = static function ($v): string {
        $v = (string)$v;
        if ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) $v = "'" . $v;
        return $v;
    };

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="zem-kullanicilar-' . date('Ymd-His', $now) . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ID', 'Kullanıcı adı', 'E-posta', 'Telefon', 'Durum', 'Premium bitiş', 'Kayıt tarihi', 'Son giriş'], ';');
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        fputcsv($out, [
            $id,
            $safe($r['username']),
            $safe($r['email']),
            $safe($r['phone']),
            $safe($r['status']),
            isset($prem[$id]) ? date('d.m.Y', (int)$prem[$id]['ends_at']) : '',
            date('d.m.Y H:i', (int)$r['created_at']),
            !empty($r['last_login']) ? date('d.m.Y H:i', (int)$r['last_login']) : '',
        ], ';');
    }
    fclose($out);
    exit;
}

/* ---------- İşlem tablosu ve çalıştırma ---------- */
$zemActions = [
    'register'               => 'act_register',
    'verify_send'            => 'act_verify_send',
    'verify_resend'          => 'act_verify_resend',
    'verify_confirm'         => 'act_verify_confirm',
    'verify_reset'           => 'act_verify_reset',
    'verify_cancel'          => 'act_verify_cancel',
    'login'                  => 'act_login',
    'forgot'                 => 'act_forgot',
    'reset_password'         => 'act_reset_password',
    'logout'                 => 'act_logout',
    'change_password'        => 'act_change_password',
    'admin_password'         => 'act_admin_password',
    'toggle_public_stream'   => 'act_toggle_public_stream',
    'new_key'                => 'act_new_key',
    'new_srt_key'            => 'act_new_srt_key',
    'rename_premium_stream'  => 'act_rename_premium_stream',
    'renew_premium_key'      => 'act_renew_premium_key',
    'renew_premium_srt_key'  => 'act_renew_premium_srt_key',
    'admin_report_action'    => 'act_admin_report_action',
    'admin_user_action'      => 'act_admin_user_action',
    'admin_announce_create'  => 'act_admin_announce_create',
    'admin_announce_action'  => 'act_admin_announce_action',
];

$page   = (string)($_GET['page'] ?? 'home');
$action = (string)($_POST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (isset($zemActions[$action])) {
        $zemActions[$action]($db);
    }
    go();
}

/* ======================================================================
   6b. KAYIT + TELEFON DOĞRULAMA (2 adım)
   Adım 1: bilgiler (oturumda bekler)  ·  Adım 2: telefon + 60 sn'lik kod
   ====================================================================== */
function zem_countries(): array
{
    // [ISO, bayrak, ad, çevirme kodu]
    return [
        ['TR', '🇹🇷', 'Türkiye', '+90'],
        ['US', '🇺🇸', 'ABD', '+1'],
        ['GB', '🇬🇧', 'Birleşik Krallık', '+44'],
        ['DE', '🇩🇪', 'Almanya', '+49'],
        ['AZ', '🇦🇿', 'Azerbaycan', '+994'],
        ['CY', '🇨🇾', 'Kıbrıs', '+357'],
        ['NL', '🇳🇱', 'Hollanda', '+31'],
        ['FR', '🇫🇷', 'Fransa', '+33'],
        ['BE', '🇧🇪', 'Belçika', '+32'],
        ['AT', '🇦🇹', 'Avusturya', '+43'],
        ['CH', '🇨🇭', 'İsviçre', '+41'],
        ['SE', '🇸🇪', 'İsveç', '+46'],
        ['IT', '🇮🇹', 'İtalya', '+39'],
        ['ES', '🇪🇸', 'İspanya', '+34'],
        ['GR', '🇬🇷', 'Yunanistan', '+30'],
        ['BG', '🇧🇬', 'Bulgaristan', '+359'],
        ['RU', '🇷🇺', 'Rusya', '+7'],
        ['KZ', '🇰🇿', 'Kazakistan', '+7'],
        ['UA', '🇺🇦', 'Ukrayna', '+380'],
        ['SA', '🇸🇦', 'Suudi Arabistan', '+966'],
        ['AE', '🇦🇪', 'BAE', '+971'],
        ['IQ', '🇮🇶', 'Irak', '+964'],
        ['EG', '🇪🇬', 'Mısır', '+20'],
        ['CA', '🇨🇦', 'Kanada', '+1'],
        ['AU', '🇦🇺', 'Avustralya', '+61'],
    ];
}

function zem_country(string $iso): ?array
{
    foreach (zem_countries() as $c) {
        if ($c[0] === $iso) return $c;
    }
    return null;
}

/** Adım 1'de doğrulanan, henüz veritabanına yazılmamış kayıt bilgisi. */
function reg_pending(): ?array
{
    $p = $_SESSION['reg_pending'] ?? null;
    if (!is_array($p)) return null;
    if (time() - (int)($p['created'] ?? 0) > 1800) {
        unset($_SESSION['reg_pending'], $_SESSION['reg_verify']);
        return null;
    }
    return $p;
}

function reg_verify(): ?array
{
    $v = $_SESSION['reg_verify'] ?? null;
    return is_array($v) ? $v : null;
}

function reg_mask_phone(string $phone): string
{
    $len = strlen($phone);
    if ($len <= 6) return $phone;
    return substr($phone, 0, 4) . str_repeat('•', max(2, $len - 7)) . substr($phone, -3);
}

/**
 * Kodu SMS ağ geçidiyle göndermeyi dener. config.php içinde
 * zem_sms_gateway(string $phone, string $message): bool tanımlarsan gerçek SMS gider
 * ve kod ekranda GÖSTERİLMEZ. Tanımlı değilse kod ekranda gösterilir.
 */
function zem_deliver_code(string $phone, string $code): bool
{
    if (!function_exists('zem_sms_gateway')) return false;
    try {
        return (bool)zem_sms_gateway($phone, 'Zemmedya Yayıncılık ile kayıt doğrulama kodunuz: ' . $code);
    } catch (Throwable $e) {
        error_log('ZEM SMS: ' . $e->getMessage());
        return false;
    }
}

function reg_issue_code(array $v): array
{
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $v['code']      = $code;
    $v['code_hash'] = hash('sha256', $code);
    $v['expires']   = time() + (int)ZEM_VERIFY_TTL;
    $v['tries']     = 0;
    $v['issued']    = ($v['issued'] ?? 0) + 1;
    $v['delivered'] = zem_deliver_code((string)$v['phone'], $code);
    if ($v['delivered']) $v['code'] = '';
    return $v;
}

function phone_taken(PDO $db, string $phone): bool
{
    $plain = ltrim($phone, '+');
    $q = $db->prepare('SELECT 1 FROM users WHERE phone=? OR phone=? LIMIT 1');
    $q->execute(['+' . $plain, $plain]);
    return (bool)$q->fetchColumn();
}

function act_register(PDO $db): void
{
    $back = url(['page' => 'register']);
    if (throttle_blocked($db, 'register', 8, 3600)) {
        flash('Çok fazla kayıt denemesi yapıldı. Bir süre sonra tekrar dene.', 'err');
        go($back);
    }
    throttle_hit($db, 'register');

    $email    = strtolower(trim((string)($_POST['email'] ?? '')));
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $password2 = (string)($_POST['password2'] ?? '');
    $question = trim((string)($_POST['secret_question'] ?? ''));
    $answer   = mb_strtolower(trim((string)($_POST['secret_answer'] ?? '')), 'UTF-8');
    $policy   = ($_POST['policy'] ?? '') === '1';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)
        || !preg_match('/^[a-zA-Z0-9_]{3,24}$/', $username)
        || strlen($password) < 10
        || mb_strlen($question) < 5
        || mb_strlen($answer) < 3
        || !$policy) {
        flash('Bilgileri kontrol et. Tüm alanlar ve yayın kuralları onayı zorunludur.', 'err');
        go($back);
    }
    if (!hash_equals($password, $password2)) {
        flash('Şifre ve şifre tekrarı aynı değil.', 'err');
        go($back);
    }

    $q = $db->prepare('SELECT email,username FROM users WHERE email=? COLLATE NOCASE OR username=? COLLATE NOCASE LIMIT 1');
    $q->execute([$email, $username]);
    $dup = $q->fetch(PDO::FETCH_ASSOC);
    if ($dup) {
        flash(strtolower((string)$dup['email']) === $email
            ? 'Bu e-posta adresi zaten kayıtlı.'
            : 'Bu kullanıcı adı zaten alınmış.', 'err');
        go($back);
    }

    $_SESSION['reg_pending'] = [
        'email'    => $email,
        'username' => $username,
        'pw_hash'  => password_hash($password, PASSWORD_DEFAULT),
        'question' => $question,
        'ans_hash' => password_hash($answer, PASSWORD_DEFAULT),
        'created'  => time(),
    ];
    unset($_SESSION['reg_verify']);
    go(url(['page' => 'verify']));
}

function act_verify_send(PDO $db): void
{
    $back = url(['page' => 'verify']);
    if (!reg_pending()) {
        flash('Kayıt oturumun sona erdi. Lütfen formu yeniden doldur.', 'err');
        go(url(['page' => 'register']));
    }
    if (reg_verify()) go($back); // numara zaten kilitli

    if (throttle_blocked($db, 'verify', 8, 900)) {
        flash('Çok fazla kod isteği yapıldı. 15 dakika sonra tekrar dene.', 'err');
        go($back);
    }

    $country = zem_country((string)($_POST['country'] ?? ''));
    $national = preg_replace('/\D/', '', (string)($_POST['phone'] ?? ''));
    $national = ltrim((string)$national, '0');
    if (!$country || strlen($national) < 6 || strlen($national) > 12
        || ($country[0] === 'TR' && !preg_match('/^5\d{9}$/', $national))) {
        flash($country && $country[0] === 'TR'
            ? 'Türkiye numarası 5 ile başlayan 10 haneli olmalı. Örn: 5xx xxx xx xx'
            : 'Geçerli bir telefon numarası gir.', 'err');
        go($back);
    }

    $phone = $country[3] . $national;
    if (phone_taken($db, $phone)) {
        flash('Bu telefon numarası başka bir hesapta kayıtlı.', 'err');
        go($back);
    }

    throttle_hit($db, 'verify', $phone);
    $_SESSION['reg_verify'] = reg_issue_code([
        'iso'      => $country[0],
        'national' => $national,
        'phone'    => $phone,
    ]);
    go($back);
}

function act_verify_resend(PDO $db): void
{
    $back = url(['page' => 'verify']);
    $v = reg_verify();
    if (!reg_pending() || !$v) go($back);

    if (time() < (int)$v['expires']) {
        flash('Mevcut kodun süresi henüz dolmadı.', 'err');
        go($back);
    }
    if (throttle_blocked($db, 'verify', 8, 900)) {
        flash('Çok fazla kod isteği yapıldı. 15 dakika sonra tekrar dene.', 'err');
        go($back);
    }
    throttle_hit($db, 'verify', (string)$v['phone']);
    $_SESSION['reg_verify'] = reg_issue_code($v);
    flash('Yeni doğrulama kodu oluşturuldu.');
    go($back);
}

function act_verify_reset(PDO $db): void
{
    unset($_SESSION['reg_verify']);
    go(url(['page' => 'verify']));
}

function act_verify_cancel(PDO $db): void
{
    unset($_SESSION['reg_pending'], $_SESSION['reg_verify']);
    go(url(['page' => 'register']));
}

function act_verify_confirm(PDO $db): void
{
    $back = url(['page' => 'verify']);
    $p = reg_pending();
    $v = reg_verify();
    if (!$p || !$v) {
        flash('Kayıt oturumun sona erdi. Lütfen formu yeniden doldur.', 'err');
        go(url(['page' => 'register']));
    }

    if (time() > (int)$v['expires']) {
        flash('Kodun süresi doldu. Yeni kod iste.', 'err');
        go($back);
    }

    $code = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));
    if (!hash_equals((string)$v['code_hash'], hash('sha256', (string)$code))) {
        $_SESSION['reg_verify']['tries'] = (int)$v['tries'] + 1;
        if ((int)$_SESSION['reg_verify']['tries'] >= 5) {
            $_SESSION['reg_verify']['expires'] = 0; // kod iptal, yeni kod gerekir
            flash('Çok fazla hatalı deneme. Güvenlik için kod iptal edildi, yeni kod iste.', 'err');
        } else {
            flash('Doğrulama kodu hatalı. Kalan hak: ' . (5 - (int)$_SESSION['reg_verify']['tries']), 'err');
        }
        go($back);
    }

    if (phone_taken($db, (string)$v['phone'])) {
        unset($_SESSION['reg_verify']);
        flash('Bu telefon numarası başka bir hesapta kayıtlı.', 'err');
        go($back);
    }

    try {
        $q = $db->prepare('INSERT INTO users(email,username,password_hash,phone,secret_question,secret_answer_hash,created_at) VALUES(?,?,?,?,?,?,?)');
        $q->execute([$p['email'], $p['username'], $p['pw_hash'], $v['phone'], $p['question'], $p['ans_hash'], time()]);
        $id  = (int)$db->lastInsertId();
        $key = new_stream_key($db, $id);
        unset($_SESSION['reg_pending'], $_SESSION['reg_verify']);
        session_regenerate_id(true);
        $_SESSION['user_id'] = $id;
        $_SESSION['new_key'] = $key;
        audit($db, 'user_registered', $id, 'Telefon doğrulandı: ' . reg_mask_phone((string)$v['phone']));
        flash('Telefonun doğrulandı, hesabın oluşturuldu. Yayın anahtarını güvenli yere kaydet.');
        go();
    } catch (PDOException $e) {
        unset($_SESSION['reg_pending'], $_SESSION['reg_verify']);
        flash('E-posta, kullanıcı adı veya telefon numarası zaten kayıtlı olabilir.', 'err');
        go(url(['page' => 'register']));
    }
}

/* ======================================================================
   7a. TASARIM: CSS (mobil öncelikli)
   ====================================================================== */
function zem_css(): string
{
    return <<<'CSS'
:root{
  --bg:#04060e;--panel:#080c18;--panel2:#0d1327;
  --line:rgba(110,180,255,.14);--line2:rgba(110,180,255,.28);
  --text:#eef5ff;--muted:#8da0bf;--muted2:#5d6f8d;
  --cyan:#4de6ff;--cyan2:#8beeff;--violet:#a888ff;--gold:#f0c97a;
  --green:#4ade80;--red:#fb7185;--amber:#fbbf24;
  --r:18px;--rs:12px;--rl:28px;
  --font:'Inter',system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;
  --display:'Space Grotesk','Inter',sans-serif;
  --safe-b:env(safe-area-inset-bottom,0px);--safe-t:env(safe-area-inset-top,0px);
  color-scheme:dark;
}
*{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth;scroll-padding-top:84px;-webkit-text-size-adjust:100%;-webkit-tap-highlight-color:transparent}
body{font-family:var(--font);background:var(--bg);color:var(--text);min-height:100vh;min-height:100dvh;line-height:1.6;overflow-x:hidden;-webkit-font-smoothing:antialiased;font-size:15px}
body::before{content:"";position:fixed;inset:0;z-index:-2;pointer-events:none;background:
  radial-gradient(ellipse 700px 500px at 5% -8%,rgba(77,230,255,.12),transparent 60%),
  radial-gradient(ellipse 600px 480px at 100% 10%,rgba(168,136,255,.11),transparent 60%),var(--bg)}
a{color:var(--cyan);text-decoration:none;transition:color .2s}
a:hover{color:var(--cyan2)}
img{max-width:100%;display:block}
button{font-family:inherit;color:inherit}
h1,h2,h3{line-height:1.18;font-family:var(--display);letter-spacing:-.01em}
h1{font-size:clamp(28px,6vw,44px)}h2{font-size:clamp(22px,4.6vw,34px)}h3{font-size:clamp(16px,3.4vw,19px)}
em{font-style:normal;background:linear-gradient(90deg,var(--cyan),var(--violet));-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
::selection{background:rgba(77,230,255,.32);color:#fff}
::-webkit-scrollbar{width:9px;height:9px}::-webkit-scrollbar-track{background:#04060e}
::-webkit-scrollbar-thumb{background:linear-gradient(#4de6ff44,#a888ff44);border-radius:9px;border:2px solid #04060e}
code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
:focus-visible{outline:2px solid var(--cyan);outline-offset:2px;border-radius:6px}
[hidden]{display:none!important}

.wrap{width:min(1240px,calc(100% - 24px));margin:0 auto;padding:0 0 48px}
.small{font-size:12.5px;color:var(--muted);line-height:1.6}
.muted{color:var(--muted)}
.center{text-align:center}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.between{justify-content:space-between}
.stack>*+*{margin-top:12px}
.hide-mobile{display:none}

/* ---------- Butonlar ---------- */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:46px;padding:0 18px;border-radius:var(--rs);
  border:1px solid var(--line2);background:rgba(77,230,255,.06);color:var(--text);font-size:14px;font-weight:700;cursor:pointer;
  transition:transform .15s,background .2s,border-color .2s,box-shadow .2s;text-align:center;line-height:1.2;white-space:nowrap}
.btn:hover{border-color:var(--cyan);background:rgba(77,230,255,.14);color:#fff}
.btn:active{transform:scale(.98)}
.btn[disabled]{opacity:.55;pointer-events:none}
.btn.primary{background:linear-gradient(135deg,var(--cyan),#6aa8ff 55%,var(--violet));border-color:transparent;color:#04101c}
.btn.primary:hover{box-shadow:0 8px 30px rgba(77,230,255,.3);color:#04101c}
.btn.ghost{background:transparent}
.btn.danger{background:rgba(251,113,133,.1);border-color:rgba(251,113,133,.4);color:#ffc2cb}
.btn.danger:hover{background:rgba(251,113,133,.22);border-color:var(--red);color:#fff}
.btn.good{background:rgba(74,222,128,.1);border-color:rgba(74,222,128,.4);color:#bbf7d0}
.btn.good:hover{background:rgba(74,222,128,.22);border-color:var(--green);color:#fff}
.btn.sm{min-height:38px;padding:0 13px;font-size:12.5px;border-radius:10px}
.btn.block{width:100%;min-height:52px}

/* ---------- Form ---------- */
label{display:block;font-size:13px;font-weight:600;color:var(--muted);margin:14px 0 6px}
input,select,textarea{width:100%;min-height:48px;padding:0 14px;border-radius:var(--rs);border:1px solid var(--line2);background:rgba(8,12,24,.9);color:var(--text);font:inherit;font-size:16px;transition:border-color .2s,box-shadow .2s}
textarea{padding:12px 14px;min-height:88px;resize:vertical}
input::placeholder,textarea::placeholder{color:var(--muted2)}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--cyan);box-shadow:0 0 0 3px rgba(77,230,255,.15)}
input[type=checkbox]{width:20px;min-height:20px;height:20px;accent-color:var(--cyan);flex:0 0 20px}
.check{display:flex;gap:10px;align-items:flex-start;font-weight:400;color:var(--text);font-size:13.5px;margin:16px 0}
.pw{position:relative}.pw input{padding-right:78px}
.pw button{position:absolute;right:6px;top:6px;min-height:36px;padding:0 12px;border-radius:9px;border:1px solid var(--line);background:rgba(77,230,255,.07);font-size:12px;font-weight:700;cursor:pointer}
.meter{height:5px;border-radius:5px;background:rgba(255,255,255,.07);margin-top:8px;overflow:hidden}
.meter i{display:block;height:100%;width:0;border-radius:5px;background:var(--red);transition:width .25s,background .25s}
.inline-form{display:flex;gap:8px;flex-wrap:wrap}.inline-form input,.inline-form select{flex:1 1 150px;min-width:0}

/* ---------- Kartlar / rozet / toast ---------- */
.card{border:1px solid var(--line);border-radius:var(--r);background:linear-gradient(180deg,rgba(13,19,39,.85),rgba(8,12,24,.85));padding:20px;box-shadow:0 18px 50px rgba(0,0,0,.35)}
.form-card{max-width:480px;margin:28px auto}
.form-card h1{font-size:26px;margin-bottom:6px}.form-card>p{color:var(--muted);font-size:14px;margin-bottom:6px}
.form-card hr{border:0;border-top:1px solid var(--line);margin:20px 0}
.badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:99px;font-size:10.5px;font-weight:800;letter-spacing:.5px;border:1px solid var(--line2);background:rgba(77,230,255,.08);color:var(--cyan2);white-space:nowrap}
.badge.premium{border-color:rgba(240,201,122,.5);background:rgba(240,201,122,.1);color:var(--gold)}
.badge.banned{border-color:rgba(251,113,133,.5);background:rgba(251,113,133,.1);color:#fecdd3}
.badge.live{border-color:rgba(74,222,128,.5);background:rgba(74,222,128,.1);color:#bbf7d0}
.badge.live::before{content:"";width:7px;height:7px;border-radius:50%;background:var(--green);animation:pulse 1.6s infinite}
.badge.warn{border-color:rgba(251,191,36,.5);background:rgba(251,191,36,.1);color:#fde68a}
@keyframes pulse{0%{box-shadow:0 0 0 0 rgba(74,222,128,.6)}100%{box-shadow:0 0 0 9px rgba(74,222,128,0)}}
.toasts{position:fixed;left:12px;right:12px;top:calc(var(--safe-t) + 12px);z-index:2000;display:grid;gap:8px;pointer-events:none;max-width:460px;margin:0 auto}
.toast{pointer-events:auto;padding:13px 16px;border-radius:14px;border:1px solid rgba(74,222,128,.4);background:rgba(8,24,18,.96);color:#d1fae5;font-size:14px;font-weight:600;box-shadow:0 14px 40px rgba(0,0,0,.5);animation:toastIn .35s ease both;backdrop-filter:blur(12px)}
.toast.err{border-color:rgba(251,113,133,.5);background:rgba(36,10,16,.96);color:#fecdd3}
.toast.out{opacity:0;transform:translateY(-10px);transition:.3s}
@keyframes toastIn{from{opacity:0;transform:translateY(-14px)}to{opacity:1;transform:none}}

/* ---------- Üst menü + çekmece ---------- */
.nav{position:sticky;top:calc(var(--safe-t) + 8px);z-index:900;display:flex;align-items:center;justify-content:space-between;gap:10px;min-height:62px;margin-top:10px;padding:8px 12px;
  border:1px solid var(--line);border-radius:var(--r);background:rgba(6,9,18,.78);backdrop-filter:blur(22px) saturate(1.5);-webkit-backdrop-filter:blur(22px) saturate(1.5);box-shadow:0 16px 50px rgba(0,0,0,.5)}
.nav-left,.nav-right{display:flex;align-items:center;gap:10px;min-width:0}
.menu-btn{display:grid;place-content:center;gap:5px;width:44px;height:44px;flex:0 0 44px;border:1px solid var(--line2);border-radius:12px;background:rgba(77,230,255,.05);cursor:pointer}
.menu-btn span{display:block;width:18px;height:2px;border-radius:2px;background:var(--cyan)}
.brand{display:flex;flex-direction:column;line-height:1;min-width:0}
.brand strong{font-family:var(--display);font-size:19px;font-weight:700;letter-spacing:1.4px;white-space:nowrap;background:linear-gradient(90deg,#fff 35%,var(--cyan));-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
.brand small{font-size:8px;font-weight:800;letter-spacing:2.6px;color:var(--cyan);margin-top:4px;opacity:.9}
.nav-links{display:none;gap:4px}
.nav-links a{padding:8px 12px;border-radius:10px;font-size:13.5px;font-weight:600;color:var(--muted)}
.nav-links a:hover{color:#fff;background:rgba(77,230,255,.08)}
.nav-cta{min-height:42px;padding:0 16px}
.backdrop{position:fixed;inset:0;z-index:1000;background:rgba(2,4,10,.7);backdrop-filter:blur(3px);opacity:0;pointer-events:none;transition:opacity .25s}
.drawer{position:fixed;top:0;left:0;bottom:0;z-index:1001;width:min(320px,86vw);padding:calc(var(--safe-t) + 16px) 16px calc(var(--safe-b) + 16px);overflow-y:auto;
  border-right:1px solid var(--line2);background:linear-gradient(180deg,#0a1124,#060912);transform:translateX(-102%);transition:transform .3s cubic-bezier(.2,.8,.2,1)}
body.drawer-open{overflow:hidden}
body.drawer-open .backdrop{opacity:1;pointer-events:auto}
body.drawer-open .drawer{transform:none}
.drawer-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
.drawer-close{width:42px;height:42px;border-radius:12px;border:1px solid var(--line2);background:transparent;font-size:24px;cursor:pointer}
.drawer-label{margin:18px 8px 6px;font-size:10.5px;font-weight:800;letter-spacing:2px;color:var(--muted2)}
.drawer a{display:flex;align-items:center;gap:12px;min-height:48px;padding:0 12px;border-radius:12px;color:var(--text);font-weight:600;font-size:14.5px}
.drawer a span{display:grid;place-items:center;width:32px;height:32px;border-radius:9px;background:rgba(77,230,255,.08);color:var(--cyan);font-size:14px}
.drawer a:hover{background:rgba(77,230,255,.08)}
.drawer-foot{margin-top:22px;font-size:11px;color:var(--muted2);text-align:center}

/* ---------- Genel sayfa bölümleri ---------- */
.section{padding:44px 0 8px}
.section-head{text-align:center;max-width:720px;margin:0 auto 26px}
.kicker{display:inline-block;font-size:10.5px;font-weight:800;letter-spacing:2.4px;color:var(--cyan);margin-bottom:10px}
.section-head p{color:var(--muted);margin-top:12px;font-size:14.5px}
.hero{padding:30px 0 10px}
.hero-inner{display:grid;gap:28px;align-items:center}
.eyebrow{display:inline-flex;align-items:center;gap:9px;padding:6px 14px;border:1px solid var(--line2);border-radius:99px;font-size:10.5px;font-weight:800;letter-spacing:1.6px;color:var(--cyan2);background:rgba(77,230,255,.06);margin-bottom:18px}
.eyebrow i{width:8px;height:8px;border-radius:50%;background:var(--green);animation:pulse 1.6s infinite}
.hero h1{font-size:clamp(34px,9vw,60px);margin-bottom:16px}
.lead{color:var(--muted);font-size:16px;max-width:560px}
.hero-actions{display:grid;gap:10px;margin:24px 0 18px}
.hero-actions .btn{min-height:52px;font-size:15px}
.points{display:flex;flex-wrap:wrap;gap:8px}
.points span{padding:6px 12px;border-radius:99px;border:1px solid var(--line);font-size:12px;color:var(--muted);background:rgba(255,255,255,.02)}
.hero-visual{position:relative;min-height:260px;border:1px solid var(--line);border-radius:var(--rl);overflow:hidden;display:grid;place-items:center;
  background:radial-gradient(circle at 50% 40%,rgba(77,230,255,.16),transparent 60%),radial-gradient(circle at 80% 90%,rgba(168,136,255,.18),transparent 55%),var(--panel)}
.ring{position:absolute;border-radius:50%;border:1px dashed var(--line2);animation:spin 40s linear infinite}
.ring.r1{width:210px;height:210px}.ring.r2{width:300px;height:300px;animation-duration:70s;animation-direction:reverse}
@keyframes spin{to{transform:rotate(360deg)}}
.core{position:relative;display:grid;place-items:center;width:128px;height:128px;border-radius:32px;border:1px solid var(--line2);background:linear-gradient(145deg,rgba(77,230,255,.16),rgba(168,136,255,.16));box-shadow:0 0 60px rgba(77,230,255,.25)}
.core b{font-family:var(--display);font-size:46px;font-weight:700}.core b span{color:var(--cyan)}
.core small{position:absolute;bottom:12px;font-size:8px;letter-spacing:2px;color:var(--muted)}
.signal{position:absolute;display:flex;align-items:center;gap:10px;padding:8px 12px;border-radius:12px;border:1px solid var(--line2);background:rgba(6,9,18,.85);backdrop-filter:blur(10px);font-size:11px}
.signal small{display:block;font-size:8.5px;letter-spacing:1.4px;color:var(--muted)}.signal b{font-size:12px}
.signal.top{top:14px;left:14px}.signal.bottom{bottom:44px;right:14px}
.wave{position:absolute;left:0;right:0;bottom:0;height:34px;display:flex;align-items:flex-end;gap:3px;padding:0 14px;opacity:.7}
.wave span{flex:1;border-radius:3px 3px 0 0;background:linear-gradient(var(--cyan),var(--violet));animation:bar 1.4s ease-in-out infinite}
.wave span:nth-child(3n){animation-delay:-.4s}.wave span:nth-child(3n+1){animation-delay:-.9s}.wave span:nth-child(5n){animation-delay:-.2s}
@keyframes bar{0%,100%{height:18%}50%{height:96%}}

.live-head{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap;margin-bottom:18px}
.live-grid,.features,.flow,.steps{display:grid;gap:12px}
.live-card{display:block;padding:16px;border:1px solid var(--line);border-radius:var(--r);background:var(--panel);color:var(--text);transition:transform .2s,border-color .2s}
.live-card:hover{transform:translateY(-3px);border-color:var(--cyan);color:var(--text)}
.live-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
.live-tag{display:inline-flex;align-items:center;gap:6px;font-size:10.5px;font-weight:800;letter-spacing:1px;color:#fecdd3}
.live-tag i{width:8px;height:8px;border-radius:50%;background:var(--red);animation:pulse 1.4s infinite}
.live-play{display:grid;place-items:center;width:34px;height:34px;border-radius:50%;background:rgba(77,230,255,.12);color:var(--cyan);font-size:12px}
.live-name{font-weight:700;font-size:16px;overflow-wrap:anywhere}
.live-owner,.live-viewers{font-size:12.5px;color:var(--muted);margin-top:4px}
.empty{padding:30px 16px;text-align:center;border:1px dashed var(--line2);border-radius:var(--r);color:var(--muted);background:rgba(255,255,255,.015)}
.empty .ico{font-size:34px;margin-bottom:6px}.empty b{display:block;color:var(--text);margin-bottom:4px}

.feature,.flow-item,.step{padding:20px;border:1px solid var(--line);border-radius:var(--r);background:linear-gradient(180deg,rgba(13,19,39,.7),rgba(8,12,24,.7))}
.feature .ico,.flow-item .ico,.step .ico{display:grid;place-items:center;width:42px;height:42px;border-radius:12px;margin-bottom:12px;background:linear-gradient(135deg,rgba(77,230,255,.16),rgba(168,136,255,.16));color:var(--cyan);font-size:18px}
.tag{font-size:10px;font-weight:800;letter-spacing:1.8px;color:var(--muted2);margin-bottom:6px}
.feature p,.flow-item p,.step p{color:var(--muted);font-size:13.5px;margin-top:6px}
.flow-caption{margin-top:18px;text-align:center;font-size:12.5px;color:var(--muted);letter-spacing:.4px}
.trust{display:flex;flex-wrap:wrap;justify-content:center;gap:8px;margin:34px 0 0}
.trust span{padding:7px 14px;border:1px solid var(--line);border-radius:99px;font-size:10.5px;font-weight:800;letter-spacing:1.6px;color:var(--muted)}
.price{position:relative;max-width:460px;margin:0 auto;padding:26px 22px;border:1px solid rgba(240,201,122,.35);border-radius:var(--rl);background:linear-gradient(180deg,rgba(240,201,122,.07),rgba(8,12,24,.9));box-shadow:0 0 70px rgba(240,201,122,.08)}
.ribbon{display:inline-block;padding:4px 12px;border-radius:99px;background:rgba(240,201,122,.14);color:var(--gold);font-size:10px;font-weight:800;letter-spacing:1.6px}
.price-name{margin-top:12px;font-family:var(--display);font-weight:700;font-size:18px}
.price-tag{margin:8px 0;display:flex;align-items:baseline;gap:8px}.price-tag strong{font-family:var(--display);font-size:46px;color:var(--gold)}.price-tag small{color:var(--muted)}
.price ul{list-style:none;margin:16px 0}.price li{padding:9px 0 9px 28px;position:relative;border-bottom:1px solid var(--line);font-size:14px}
.price li::before{content:"✓";position:absolute;left:4px;color:var(--green);font-weight:800}
.policy-notice{padding:16px;border:1px solid rgba(240,201,122,.3);border-radius:var(--r);background:rgba(240,201,122,.05);margin-bottom:14px;font-size:13.5px;color:#e8dfc6}
.policy-notice strong{display:block;color:var(--gold);margin-bottom:4px}
.doc,.faq details{border:1px solid var(--line);border-radius:var(--r);background:var(--panel);margin-bottom:10px;overflow:hidden}
.doc summary,.faq summary{list-style:none;cursor:pointer;display:flex;align-items:center;gap:12px;padding:14px 16px;min-height:56px}
.doc summary::-webkit-details-marker,.faq summary::-webkit-details-marker{display:none}
.doc-num{display:grid;place-items:center;width:34px;height:34px;flex:0 0 34px;border-radius:10px;background:rgba(77,230,255,.09);color:var(--cyan);font-size:12px;font-weight:800}
.doc-title{flex:1;min-width:0}.doc-title b{display:block;font-size:14.5px}.doc-title small{color:var(--muted);font-size:12px}
.doc-plus{color:var(--cyan);font-size:20px;transition:transform .25s}
.doc[open] .doc-plus,.faq details[open] summary::after{transform:rotate(45deg)}
.doc-body{padding:2px 18px 18px;color:var(--muted);font-size:13.5px}
.doc-body h3{font-size:14px;color:var(--text);margin:14px 0 4px}
.doc-warn{margin-top:14px;padding:12px;border-radius:12px;background:rgba(251,191,36,.08);border:1px solid rgba(251,191,36,.3);color:#fde68a;font-size:12.5px}
.policy-updated{margin-top:12px;font-size:12px;color:var(--muted2);text-align:center}
.faq summary{font-weight:600;font-size:14.5px;justify-content:space-between}
.faq summary::after{content:"＋";color:var(--cyan);font-size:18px;transition:transform .25s}
.faq p{padding:0 16px 16px;color:var(--muted);font-size:14px}
.cta{margin:44px 0 0;padding:34px 20px;text-align:center;border:1px solid var(--line2);border-radius:var(--rl);background:radial-gradient(circle at 50% 0,rgba(77,230,255,.14),transparent 60%),var(--panel)}
.cta p{color:var(--muted);margin:10px 0 20px}.cta .row{justify-content:center}
.footer{margin-top:44px;padding:28px 18px 18px;border:1px solid var(--line);border-radius:var(--rl);background:rgba(6,9,18,.7)}
.footer-main{display:grid;gap:22px}
.footer-brand strong{font-family:var(--display);font-size:22px}.footer-brand strong em{margin-left:2px}
.footer-brand p{color:var(--muted);font-size:13px;margin:6px 0}.footer-brand small{color:var(--muted2);font-size:11px;letter-spacing:1px}
.footer-col{display:grid;gap:2px;align-content:start}.footer-col b{font-size:10.5px;letter-spacing:2px;color:var(--muted2);margin-bottom:6px}
.footer-col a{color:var(--muted);font-size:13.5px;padding:5px 0}.footer-col a:hover{color:var(--cyan)}
.footer-bottom{display:flex;flex-direction:column;gap:4px;margin-top:20px;padding-top:14px;border-top:1px solid var(--line);font-size:11.5px;color:var(--muted2)}
.to-top{position:fixed;right:14px;bottom:calc(var(--safe-b) + 18px);z-index:800;width:44px;height:44px;border-radius:50%;border:1px solid var(--line2);background:rgba(6,9,18,.9);color:var(--cyan);font-size:18px;cursor:pointer;opacity:0;pointer-events:none;transition:.25s}
.to-top.show{opacity:1;pointer-events:auto}
html.js .reveal{opacity:0;transform:translateY(18px);transition:opacity .6s ease,transform .6s ease}
html.js .reveal.in{opacity:1;transform:none}

/* ---------- Kullanıcı paneli ---------- */
.appbar{position:sticky;top:0;z-index:900;display:flex;align-items:center;justify-content:space-between;gap:10px;padding:calc(var(--safe-t) + 10px) 14px 10px;border-bottom:1px solid var(--line);background:rgba(4,6,14,.85);backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px)}
.dash{display:block;padding:14px 0 calc(var(--safe-b) + 90px)}
.side{display:none}
.dash-top{display:flex;flex-direction:column;gap:12px;margin-bottom:14px}
.kicker-sm{font-size:10.5px;font-weight:800;letter-spacing:2px;color:var(--cyan)}
.dash-title{font-size:26px;margin:4px 0}
.pill{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:99px;font-size:12px;font-weight:800;border:1px solid var(--line2);background:rgba(77,230,255,.08)}
.pill.gold{border-color:rgba(240,201,122,.5);background:rgba(240,201,122,.1);color:var(--gold)}
.welcome{padding:18px;border-radius:var(--r);border:1px solid var(--line2);background:linear-gradient(135deg,rgba(77,230,255,.1),rgba(168,136,255,.1));margin-bottom:14px}
.welcome .kicker-sm{margin-bottom:4px}.welcome h2{font-size:21px}.welcome p{color:var(--muted);font-size:13.5px;margin-top:4px}
.notice{display:flex;gap:12px;align-items:flex-start;padding:14px 16px;border-radius:var(--r);margin-bottom:14px;border:1px solid rgba(251,191,36,.4);background:rgba(251,191,36,.07);color:#fde68a;font-size:13.5px}
.notice.new{border-color:rgba(74,222,128,.4);background:rgba(74,222,128,.07);color:#bbf7d0}
.notice b{display:block;margin-bottom:2px}
.stat-row{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:14px}
.mini-stat{padding:14px;border:1px solid var(--line);border-radius:var(--r);background:var(--panel)}
.mini-stat span{display:block;font-size:10px;letter-spacing:1.4px;font-weight:800;color:var(--muted2)}
.mini-stat strong{display:block;font-family:var(--display);font-size:22px;margin-top:2px}
.dash-grid{display:grid;gap:14px}
.dcard{padding:18px;border:1px solid var(--line);border-radius:var(--r);background:linear-gradient(180deg,rgba(13,19,39,.85),rgba(8,12,24,.85));min-width:0}
.heading{display:flex;align-items:center;gap:12px;margin-bottom:14px}
.heading .ico{display:grid;place-items:center;width:42px;height:42px;flex:0 0 42px;border-radius:12px;background:linear-gradient(135deg,rgba(77,230,255,.18),rgba(168,136,255,.18));color:var(--cyan);font-size:18px}
.heading p{font-size:12.5px;color:var(--muted)}
.tabbar{display:grid;grid-auto-flow:column;grid-auto-columns:1fr;gap:4px;padding:4px;margin-bottom:14px;border:1px solid var(--line);border-radius:14px;background:rgba(0,0,0,.25)}
.tabbar button{min-height:42px;border:0;border-radius:10px;background:transparent;color:var(--muted);font-weight:700;font-size:13.5px;cursor:pointer;transition:.2s}
.tabbar button.active{background:linear-gradient(135deg,rgba(77,230,255,.22),rgba(168,136,255,.22));color:#fff;box-shadow:inset 0 0 0 1px var(--line2)}
.field{margin-bottom:12px}
.field-label{display:block;margin-bottom:6px;font-size:10.5px;font-weight:800;letter-spacing:1.3px;color:var(--muted2);text-transform:uppercase}
.copy-row{display:flex;gap:8px;flex-wrap:wrap;align-items:stretch}
.val{flex:1 1 100%;min-width:0;padding:11px 13px;border-radius:12px;border:1px solid var(--line);background:rgba(0,0,0,.35);color:var(--cyan2);font-size:13px;word-break:break-all;line-height:1.5;overflow-wrap:anywhere}
.val.is-secret{letter-spacing:1px}
.copy-row .btn{flex:1 1 auto}
.help{font-size:12.5px;color:var(--muted);margin:10px 0}
.card-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.card-actions form,.card-actions .btn{flex:1 1 200px}.card-actions form .btn{width:100%}
.note{margin-top:12px;padding:11px 13px;border-radius:12px;background:rgba(77,230,255,.05);border:1px solid var(--line);font-size:12.5px;color:var(--muted)}
.acc-row{display:flex;justify-content:space-between;gap:12px;padding:11px 0;border-bottom:1px solid var(--line);font-size:14px}
.acc-row:last-child{border-bottom:0}.acc-row span{color:var(--muted)}.acc-row b{text-align:right;overflow-wrap:anywhere;min-width:0}
.ok-text{color:var(--green)}
.slot{padding:16px;margin-bottom:14px;border:1px solid var(--line);border-radius:var(--r);background:rgba(0,0,0,.22)}
.slot-head{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:12px}
.slot-name{font-weight:700;overflow-wrap:anywhere}
.viewers{margin:8px 0 12px;padding:10px 13px;border:1px solid var(--line2);border-radius:12px;font-size:13px;color:var(--muted)}
.viewers strong{color:var(--text)}
.guide details{border-top:1px solid var(--line);padding:2px 0}.guide summary{cursor:pointer;min-height:46px;display:flex;align-items:center;font-weight:600;font-size:14px;list-style:none}
.guide summary::-webkit-details-marker{display:none}.guide summary::after{content:"＋";margin-left:auto;color:var(--cyan)}
.guide details[open] summary::after{content:"－"}
.guide ul{margin:0 0 12px 18px;color:var(--muted);font-size:13.5px}.guide li{margin:4px 0}
.history{display:grid;gap:8px}
.history div{display:flex;justify-content:space-between;gap:10px;padding:10px 12px;border:1px solid var(--line);border-radius:12px;font-size:13px;background:rgba(0,0,0,.2)}
.history small{color:var(--muted)}
.bottom-nav{position:fixed;left:0;right:0;bottom:0;z-index:950;display:flex;justify-content:space-around;padding:6px 6px calc(var(--safe-b) + 6px);border-top:1px solid var(--line2);background:rgba(6,9,18,.94);backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px)}
.bottom-nav a{flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;padding:6px 2px;border-radius:12px;color:var(--muted);font-size:10.5px;font-weight:700}
.bottom-nav a i{font-style:normal;font-size:18px;line-height:1}
.bottom-nav a.active{color:var(--cyan);background:rgba(77,230,255,.09)}

/* ---------- Yönetici paneli ---------- */
.adm{display:block;padding:12px 0 40px}
.adm-side{position:fixed;top:0;left:0;bottom:0;z-index:1001;width:min(300px,86vw);padding:calc(var(--safe-t) + 16px) 14px 20px;overflow-y:auto;border-right:1px solid var(--line2);background:linear-gradient(180deg,#0a1124,#060912);transform:translateX(-102%);transition:transform .3s cubic-bezier(.2,.8,.2,1)}
.adm-side.open{transform:none}
.adm-overlay{position:fixed;inset:0;z-index:1000;background:rgba(2,4,10,.7);opacity:0;pointer-events:none;transition:opacity .25s}
.adm-overlay.show{opacity:1;pointer-events:auto}
.adm-brand b{font-family:var(--display);font-size:20px;letter-spacing:1.5px;display:block}.adm-brand small{font-size:9px;letter-spacing:2px;color:var(--cyan)}
.adm-label{margin:20px 8px 6px;font-size:10px;font-weight:800;letter-spacing:2px;color:var(--muted2)}
.adm-nav{display:grid;gap:3px}
.adm-nav a{display:flex;align-items:center;justify-content:space-between;gap:8px;min-height:46px;padding:0 12px;border-radius:12px;color:var(--text);font-weight:600;font-size:14px}
.adm-nav a:hover{background:rgba(77,230,255,.07)}
.adm-nav a.active{background:linear-gradient(135deg,rgba(77,230,255,.18),rgba(168,136,255,.14));box-shadow:inset 0 0 0 1px var(--line2)}
.adm-me{margin-top:22px;padding:13px;border:1px solid var(--line);border-radius:14px}
.adm-top{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:14px}
.adm-top .left{display:flex;align-items:center;gap:12px;min-width:0}
.adm-title{font-family:var(--display);font-size:20px;font-weight:700}.adm-sub{font-size:11.5px;color:var(--muted)}
.stats{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:14px}
.stat{padding:14px;border:1px solid var(--line);border-radius:var(--r);background:var(--panel);min-width:0}
.stat span{display:block;font-size:9.5px;font-weight:800;letter-spacing:1.3px;color:var(--muted2)}
.stat strong{display:block;font-family:var(--display);font-size:26px;line-height:1.2;margin:2px 0}
.stat em{font-size:11.5px;color:var(--muted);-webkit-text-fill-color:var(--muted);background:none}
.panel{padding:16px;border:1px solid var(--line);border-radius:var(--r);background:linear-gradient(180deg,rgba(13,19,39,.85),rgba(8,12,24,.85));margin-bottom:14px}
.panel-head{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap;margin-bottom:14px}
.panel-head h2{font-size:18px}.panel-head p{font-size:12.5px;color:var(--muted);margin-top:2px}
.chips{display:flex;gap:6px;overflow-x:auto;padding-bottom:6px;margin-bottom:10px;scrollbar-width:none}
.chips::-webkit-scrollbar{display:none}
.chip{flex:0 0 auto;display:inline-flex;align-items:center;gap:6px;min-height:38px;padding:0 14px;border-radius:99px;border:1px solid var(--line);color:var(--muted);font-size:13px;font-weight:600;background:rgba(255,255,255,.02)}
.chip.active{color:#fff;border-color:var(--cyan);background:rgba(77,230,255,.12)}
.chip b{font-size:11px;color:var(--cyan)}
.search{display:flex;gap:8px;margin-bottom:12px}.search input{flex:1;min-width:0}
.rowline{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:12px 0;border-bottom:1px solid var(--line)}
.rowline:last-of-type{border-bottom:0}
.rowline b{display:block;font-size:14px;overflow-wrap:anywhere}.rowline small{color:var(--muted);font-size:12px;line-height:1.5}
.chart{display:flex;align-items:flex-end;justify-content:space-between;gap:8px;height:170px;padding-top:8px}
.chart-day{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;gap:6px;height:100%}
.chart-day b{font-size:12px}.chart-day small{font-size:10.5px;color:var(--muted)}
.chart-bar{width:100%;max-width:38px;border-radius:8px 8px 3px 3px;background:linear-gradient(var(--cyan),var(--violet));min-height:4px}
.ucard{border:1px solid var(--line);border-radius:var(--r);background:rgba(0,0,0,.2);margin-bottom:10px;overflow:hidden}
.ucard summary{list-style:none;cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px;min-height:60px}
.ucard summary::-webkit-details-marker{display:none}
.u-name{font-weight:700;display:flex;gap:8px;flex-wrap:wrap;align-items:center;overflow-wrap:anywhere}
.u-meta{font-size:12px;color:var(--muted)}
.u-body{padding:4px 14px 16px;border-top:1px solid var(--line)}
.info-grid{display:grid;grid-template-columns:1fr;gap:8px;margin:12px 0}
.info-box{padding:10px 12px;border:1px solid var(--line);border-radius:12px;background:rgba(0,0,0,.2);min-width:0}
.info-box label{margin:0 0 2px;font-size:9.5px;letter-spacing:1.3px;font-weight:800;color:var(--muted2)}
.info-box strong{font-size:13.5px;overflow-wrap:anywhere}
.box{margin:10px 0;padding:12px;border:1px solid var(--line);border-radius:14px;background:rgba(0,0,0,.2)}
.slots-grid{display:grid;gap:10px;margin-top:8px}
.mini-slot{padding:12px;border:1px solid var(--line);border-radius:12px}
.mini-slot form{margin-top:8px}
.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.actions form{display:flex;gap:8px;flex-wrap:wrap;flex:1 1 240px}
.actions form select,.actions form input{flex:1 1 120px;min-width:0}
.log{display:flex;gap:12px;padding:12px 0;border-bottom:1px solid var(--line)}
.log i{width:9px;height:9px;margin-top:7px;flex:0 0 9px;border-radius:50%;background:var(--cyan);box-shadow:0 0 12px var(--cyan)}
.log b{font-size:13.5px}.log p{font-size:13px;color:var(--muted);overflow-wrap:anywhere}.log small{font-size:11.5px;color:var(--muted2)}
.report{padding:16px 0;border-bottom:1px solid var(--line)}
.report p{margin:6px 0;overflow-wrap:anywhere;white-space:pre-wrap;font-size:13.5px}
.pager{display:flex;justify-content:center;align-items:center;gap:8px;margin-top:14px;flex-wrap:wrap}
.pager span{font-size:13px;color:var(--muted)}
.admin-empty{padding:26px 12px;text-align:center;color:var(--muted);font-size:14px}
.warn-list .rowline b{color:var(--amber)}


/* ---------- Duyurular ---------- */
.announce{display:flex;gap:12px;align-items:flex-start;padding:13px 16px;margin:12px 0;border-radius:var(--r);border:1px solid rgba(77,230,255,.35);background:linear-gradient(135deg,rgba(77,230,255,.1),rgba(168,136,255,.08));animation:toastIn .5s ease both}
.announce.warn{border-color:rgba(251,191,36,.45);background:rgba(251,191,36,.08)}
.announce.success{border-color:rgba(74,222,128,.45);background:rgba(74,222,128,.08)}
.announce .ico{font-size:20px;line-height:1.3}.announce b{display:block;font-size:14px}.announce p{font-size:13px;color:var(--muted);margin-top:2px}

/* ---------- Kayıt / telefon doğrulama ---------- */
.steps-bar{display:flex;align-items:center;gap:8px;margin:2px 0 18px}
.steps-bar span{flex:1;height:5px;border-radius:5px;background:rgba(255,255,255,.08)}
.steps-bar span.on{background:linear-gradient(90deg,var(--cyan),var(--violet))}
.steps-label{font-size:11px;letter-spacing:1.6px;font-weight:800;color:var(--cyan);margin-bottom:6px}
.phone-row{display:grid;grid-template-columns:minmax(120px,42%) 1fr;gap:8px}
.phone-row select,.phone-row input{min-width:0}
.locked{position:relative}
.locked input,.locked select{opacity:.7;background:rgba(77,230,255,.05);border-style:dashed;cursor:not-allowed}
.lock-tag{display:inline-flex;align-items:center;gap:6px;margin:8px 0 0;font-size:12px;color:var(--green);font-weight:700}
.code-card{margin:18px 0;padding:18px;border-radius:var(--r);border:1px solid rgba(77,230,255,.35);background:linear-gradient(145deg,rgba(77,230,255,.1),rgba(168,136,255,.1));text-align:center}
.code-card p{font-size:13.5px;color:var(--muted)}
.code-num{display:block;margin:10px 0 4px;font-family:var(--display);font-size:clamp(34px,10vw,46px);font-weight:700;letter-spacing:.28em;padding-left:.28em;color:#fff;text-shadow:0 0 30px rgba(77,230,255,.5)}
.cd-bar{height:6px;border-radius:6px;background:rgba(255,255,255,.08);margin:12px 0 6px;overflow:hidden}
.cd-bar i{display:block;height:100%;width:100%;border-radius:6px;background:linear-gradient(90deg,var(--green),var(--cyan));transition:width .25s linear}
.cd-text{font-size:12.5px;color:var(--muted)}.cd-text b{color:var(--text)}
.code-card.expired{border-color:rgba(251,113,133,.45);background:rgba(251,113,133,.07)}
.code-card.expired .cd-bar i{background:var(--red)}
.otp{text-align:center;letter-spacing:.5em;font-size:22px;font-family:var(--display);padding-left:.5em}

/* ---------- Ana sayfa süslemeleri ---------- */
.hero{position:relative;border-radius:var(--rl);padding:24px 0 10px;--mx:50%;--my:30%}
.hero::before{content:"";position:absolute;inset:-20px -10px;z-index:-1;border-radius:var(--rl);background:radial-gradient(420px circle at var(--mx) var(--my),rgba(77,230,255,.12),transparent 70%);pointer-events:none}
.rot{display:inline-block;transition:opacity .28s,transform .28s;min-width:2ch}
.stats-strip{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-top:26px}
.sstat{padding:16px 12px;text-align:center;border:1px solid var(--line);border-radius:var(--r);background:linear-gradient(160deg,rgba(13,19,39,.8),rgba(8,12,24,.8))}
.sstat strong{display:block;font-family:var(--display);font-size:clamp(24px,6vw,34px);background:linear-gradient(90deg,var(--cyan),var(--violet));-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
.sstat span{font-size:11px;letter-spacing:1.4px;font-weight:700;color:var(--muted)}
.marquee{overflow:hidden;margin:30px -12px 0;padding:12px 0;border-block:1px solid var(--line);-webkit-mask-image:linear-gradient(90deg,transparent,#000 10%,#000 90%,transparent);mask-image:linear-gradient(90deg,transparent,#000 10%,#000 90%,transparent)}
.marquee-track{display:flex;gap:34px;width:max-content;animation:marquee 32s linear infinite}
.marquee span{font-family:var(--display);font-weight:600;font-size:14px;letter-spacing:2px;color:var(--muted);white-space:nowrap}
.marquee span::before{content:"◆";margin-right:34px;color:var(--cyan);font-size:9px;vertical-align:middle}
@keyframes marquee{to{transform:translateX(-50%)}}
.feature{position:relative;overflow:hidden;transition:transform .25s,border-color .25s;--mx:50%;--my:50%}
.feature::after{content:"";position:absolute;inset:0;pointer-events:none;opacity:0;transition:opacity .25s;background:radial-gradient(220px circle at var(--mx) var(--my),rgba(77,230,255,.14),transparent 70%)}
.feature:hover{transform:translateY(-4px);border-color:var(--line2)}.feature:hover::after{opacity:1}
.compare-wrap{overflow-x:auto;border:1px solid var(--line);border-radius:var(--r);background:var(--panel)}
.compare{width:100%;min-width:520px;border-collapse:collapse;font-size:14px}
.compare th,.compare td{padding:13px 14px;text-align:center;border-bottom:1px solid var(--line)}
.compare th:first-child,.compare td:first-child{text-align:left;color:var(--muted)}
.compare thead th{font-family:var(--display);font-size:15px;background:rgba(255,255,255,.02)}
.compare th.pro{color:var(--gold)}
.compare tr:last-child td{border-bottom:0}
.compare .yes{color:var(--green);font-weight:800}.compare .no{color:var(--muted2)}
.m-cta{position:fixed;left:10px;right:10px;bottom:calc(var(--safe-b) + 10px);z-index:850;display:flex;gap:8px;padding:8px;border-radius:18px;border:1px solid var(--line2);background:rgba(6,9,18,.92);backdrop-filter:blur(16px);transform:translateY(140%);transition:transform .35s cubic-bezier(.2,.8,.2,1)}
.m-cta.show{transform:none}.m-cta .btn{flex:1}
.mode-public .to-top{bottom:calc(var(--safe-b) + 84px)}

/* ---------- Geniş ekran ---------- */
@media (min-width:640px){
  .stats-strip{grid-template-columns:repeat(4,1fr)}
  .marquee{margin-inline:0}
  body{font-size:15.5px}
  .wrap{width:min(1240px,calc(100% - 40px))}
  .hero-actions{display:flex}
  .live-grid,.features{grid-template-columns:repeat(2,1fr)}
  .flow{grid-template-columns:repeat(2,1fr)}
  .footer-main{grid-template-columns:repeat(2,1fr)}
  .stats{grid-template-columns:repeat(4,1fr)}
  .info-grid{grid-template-columns:repeat(2,1fr)}
  .stat-row{grid-template-columns:repeat(4,1fr)}
  .slots-grid{grid-template-columns:repeat(2,1fr)}
  .footer-bottom{flex-direction:row;justify-content:space-between}
  .hide-mobile{display:inline-flex}
  .val{flex:1 1 0}
  .copy-row .btn{flex:0 0 auto}
}
@media (min-width:960px){
  .features .feature:nth-child(1),.features .feature:nth-child(6),.features .feature:nth-child(9){grid-column:span 2}
  .m-cta{display:none}
  .mode-public .to-top{bottom:calc(var(--safe-b) + 18px)}
  body::after{content:"";position:fixed;inset:0;z-index:-1;pointer-events:none;opacity:.35;background-image:linear-gradient(rgba(110,180,255,.045) 1px,transparent 1px),linear-gradient(90deg,rgba(110,180,255,.045) 1px,transparent 1px);background-size:70px 70px;-webkit-mask-image:radial-gradient(ellipse at center,#000 15%,transparent 75%);mask-image:radial-gradient(ellipse at center,#000 15%,transparent 75%)}
  .hero-inner{grid-template-columns:1.1fr .9fr;gap:44px}
  .hero-visual{min-height:420px}
  .ring.r1{width:280px;height:280px}.ring.r2{width:400px;height:400px}
  .core{width:160px;height:160px}
  .live-grid,.features{grid-template-columns:repeat(3,1fr)}
  .flow{grid-template-columns:repeat(4,1fr)}
  .steps{grid-template-columns:repeat(3,1fr)}
  .footer-main{grid-template-columns:1.6fr 1fr 1fr 1fr}
  .section{padding-top:70px}
  .nav{padding:10px 18px;min-height:70px}
  .nav-links{display:flex}
  .dash{display:grid;grid-template-columns:260px minmax(0,1fr);gap:20px;padding-bottom:40px;align-items:start}
  .side{display:block;position:sticky;top:20px;padding:18px;border:1px solid var(--line);border-radius:var(--r);background:var(--panel)}
  .side .logo b{font-family:var(--display);font-size:20px;letter-spacing:1.4px;display:block}.side .logo span{font-size:9px;letter-spacing:2px;color:var(--cyan)}
  .side-cap{margin:20px 4px 6px;font-size:10px;font-weight:800;letter-spacing:2px;color:var(--muted2)}
  .side nav{display:grid;gap:3px}
  .side nav a{display:flex;align-items:center;gap:10px;min-height:44px;padding:0 12px;border-radius:12px;color:var(--text);font-weight:600;font-size:14px}
  .side nav a:hover,.side nav a.active{background:rgba(77,230,255,.1);box-shadow:inset 0 0 0 1px var(--line)}
  .side-bottom{margin-top:20px;padding:12px;border-radius:12px;border:1px solid var(--line);background:rgba(0,0,0,.2)}
  .side-bottom small{display:block;font-size:10px;letter-spacing:1.6px;color:var(--muted2)}
  .bottom-nav{display:none}
  .dash-top{flex-direction:row;justify-content:space-between;align-items:flex-end}
  .dash-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
  .dcard.wide{grid-column:1/-1}
  .adm{display:grid;grid-template-columns:270px minmax(0,1fr);gap:20px;align-items:start}
  .adm-side{position:sticky;top:16px;transform:none;width:auto;z-index:1;border:1px solid var(--line);border-radius:var(--r);background:var(--panel);max-height:calc(100vh - 32px)}
  .adm-overlay{display:none}
  .adm-top .menu-btn{display:none}
  .info-grid{grid-template-columns:repeat(4,1fr)}
  .slots-grid{grid-template-columns:repeat(3,1fr)}
}
@media (min-width:1200px){
  .toasts{left:auto;right:24px;margin:0}
}
@media (prefers-reduced-motion:reduce){
  *,*::before,*::after{animation-duration:.01ms!important;animation-iteration-count:1!important;transition-duration:.01ms!important;scroll-behavior:auto!important}
  html.js .reveal{opacity:1;transform:none}
}
CSS;
}

/* ======================================================================
   7b. TASARIM: JavaScript (tek parça, olay delegasyonu)
   ====================================================================== */
function zem_js(): string
{
    return <<<'JS'
(function(){
'use strict';
var doc=document,body=doc.body;
function $(s,r){return (r||doc).querySelector(s)}
function $$(s,r){return Array.prototype.slice.call((r||doc).querySelectorAll(s))}

/* Kopyalama */
function fallbackCopy(v,done){
  var a=doc.createElement('textarea');a.value=v;a.style.cssText='position:fixed;opacity:0';
  doc.body.appendChild(a);a.select();
  try{if(doc.execCommand('copy'))done()}catch(e){}
  a.remove();
}
function copyText(v,btn){
  var done=function(){
    var old=btn.getAttribute('data-label')||btn.textContent;
    btn.setAttribute('data-label',old);btn.textContent='Kopyalandı ✓';
    clearTimeout(btn._t);btn._t=setTimeout(function(){btn.textContent=old},1500);
  };
  if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(v).then(done,function(){fallbackCopy(v,done)})}
  else{fallbackCopy(v,done)}
}

/* Tıklama olayları */
doc.addEventListener('click',function(e){
  var t=e.target;
  var dr=t.closest('[data-drawer]');
  if(dr){body.classList.toggle('drawer-open',dr.getAttribute('data-drawer')==='open');return}
  if(t.closest('.drawer a')){body.classList.remove('drawer-open')}

  var adm=t.closest('[data-adm]');
  if(adm){
    var open=adm.getAttribute('data-adm')==='open';
    var side=$('#admSide'),ov=$('#admOverlay');
    if(side)side.classList.toggle('open',open);
    if(ov)ov.classList.toggle('show',open);
    return;
  }

  var cp=t.closest('[data-copy]');
  if(cp){copyText(cp.getAttribute('data-copy')||'',cp);return}

  var sec=t.closest('[data-toggle-secret]');
  if(sec){
    var code=$('.val.is-secret',sec.parentNode);
    if(code){
      var shown=code.getAttribute('data-shown')==='1';
      code.textContent=shown?code.getAttribute('data-mask'):code.getAttribute('data-full');
      code.setAttribute('data-shown',shown?'0':'1');
      sec.textContent=shown?'Göster':'Gizle';
    }
    return;
  }

  var tab=t.closest('[data-tab]');
  if(tab){
    var set=tab.closest('[data-tabset]');
    if(set){
      var key=tab.getAttribute('data-tab');
      $$(':scope > .tabbar [data-tab]',set).forEach(function(b){
        var on=b.getAttribute('data-tab')===key;
        b.classList.toggle('active',on);b.setAttribute('aria-pressed',on?'true':'false');
      });
      Array.prototype.forEach.call(set.children,function(p){
        if(p.hasAttribute('data-panel'))p.hidden=p.getAttribute('data-panel')!==key;
      });
    }
    return;
  }

  var pw=t.closest('[data-pw-toggle]');
  if(pw){
    var inp=$('input',pw.parentNode);
    if(inp){var show=inp.type==='password';inp.type=show?'text':'password';pw.textContent=show?'Gizle':'Göster'}
    return;
  }

  var toast=t.closest('.toast');
  if(toast){toast.remove();return}

  if(t.closest('.to-top')){window.scrollTo({top:0,behavior:'smooth'})}
});

doc.addEventListener('keydown',function(e){
  if(e.key==='Escape'){
    body.classList.remove('drawer-open');
    var side=$('#admSide'),ov=$('#admOverlay');
    if(side)side.classList.remove('open');
    if(ov)ov.classList.remove('show');
  }
});

/* Onay penceresi + çift gönderimi engelle */
doc.addEventListener('submit',function(e){
  var f=e.target;
  var msg=f.getAttribute&&f.getAttribute('data-confirm');
  if(msg&&!window.confirm(msg)){e.preventDefault();return}
  var b=$('button[type=submit]',f);
  if(b){setTimeout(function(){b.setAttribute('disabled','disabled')},0)}
});
window.addEventListener('pageshow',function(){
  $$('button[type=submit][disabled]').forEach(function(b){b.removeAttribute('disabled')});
});

/* Toast'lar otomatik kapanır */
$$('.toast').forEach(function(t){
  setTimeout(function(){t.classList.add('out');setTimeout(function(){t.remove()},350)},5500);
});

/* Şifre gücü göstergesi */
$$('input[data-strength]').forEach(function(inp){
  var wrap=inp.closest('.pw')||inp.parentNode;
  var meter=wrap.nextElementSibling;
  var bar=meter&&meter.classList.contains('meter')?$('i',meter):null;
  if(!bar)return;
  inp.addEventListener('input',function(){
    var v=inp.value,s=0;
    if(v.length>=10)s++;if(v.length>=14)s++;
    if(/[a-z]/.test(v)&&/[A-Z]/.test(v))s++;
    if(/\d/.test(v))s++;
    if(/[^A-Za-z0-9]/.test(v))s++;
    var pct=Math.min(100,s*20);
    bar.style.width=pct+'%';
    bar.style.background=s<=2?'#fb7185':(s<=3?'#fbbf24':'#4ade80');
  });
});

/* Görününce belirme */
var reveals=$$('.reveal');
if('IntersectionObserver' in window&&reveals.length){
  var io=new IntersectionObserver(function(es){
    es.forEach(function(en){if(en.isIntersecting){en.target.classList.add('in');io.unobserve(en.target)}});
  },{rootMargin:'0px 0px -8% 0px',threshold:.06});
  reveals.forEach(function(r){io.observe(r)});
}else{reveals.forEach(function(r){r.classList.add('in')})}

/* Yukarı çık düğmesi */
var top=$('.to-top');
if(top){
  var onScroll=function(){top.classList.toggle('show',window.scrollY>700)};
  window.addEventListener('scroll',onScroll,{passive:true});onScroll();
}

/* Panelde aktif bölümü vurgula */
var spyLinks=$$('[data-spy] a[href^="#"]');
if(spyLinks.length&&'IntersectionObserver' in window){
  var map={};
  spyLinks.forEach(function(a){var id=a.getAttribute('href').slice(1);var el=doc.getElementById(id);if(el){(map[id]=map[id]||{el:el,links:[]}).links.push(a)}});
  var spy=new IntersectionObserver(function(es){
    es.forEach(function(en){
      if(!en.isIntersecting)return;
      spyLinks.forEach(function(a){a.classList.remove('active')});
      var m=map[en.target.id];if(m)m.links.forEach(function(a){a.classList.add('active')});
    });
  },{rootMargin:'-35% 0px -55% 0px'});
  Object.keys(map).forEach(function(k){spy.observe(map[k].el)});
}


/* Sayaç animasyonu (görününce) */
function countUp(el){
  var to=+el.getAttribute('data-count')||0;if(to<=0)return;
  var t0=null,dur=1000;
  function step(t){
    if(t0===null)t0=t;
    var p=Math.min(1,(t-t0)/dur),e=1-Math.pow(1-p,3);
    el.textContent=Math.round(to*e).toLocaleString('tr-TR');
    if(p<1)requestAnimationFrame(step);
  }
  requestAnimationFrame(step);
}
var counters=$$('[data-count]');
if('IntersectionObserver' in window){
  var cio=new IntersectionObserver(function(es){es.forEach(function(en){if(en.isIntersecting){countUp(en.target);cio.unobserve(en.target)}})},{threshold:.3});
  counters.forEach(function(c){cio.observe(c)});
}else{counters.forEach(countUp)}

/* Dönen vurgu kelimesi */
$$('[data-rotate]').forEach(function(el){
  var words;try{words=JSON.parse(el.getAttribute('data-rotate'))}catch(e){return}
  if(!words||words.length<2)return;
  var i=0;
  setInterval(function(){
    el.style.opacity='0';el.style.transform='translateY(8px)';
    setTimeout(function(){i=(i+1)%words.length;el.textContent=words[i];el.style.opacity='1';el.style.transform='none'},280);
  },2600);
});

/* Fare ışığı (hero + özellik kartları) */
var glowTargets=$$('.hero,.feature,.price');
glowTargets.forEach(function(el){
  el.addEventListener('pointermove',function(e){
    var r=el.getBoundingClientRect();
    el.style.setProperty('--mx',(e.clientX-r.left)+'px');
    el.style.setProperty('--my',(e.clientY-r.top)+'px');
  },{passive:true});
});

/* Telefon doğrulama kodu geri sayımı */
$$('[data-countdown]').forEach(function(box){
  var left=parseInt(box.getAttribute('data-countdown'),10)||0,ttl=parseInt(box.getAttribute('data-ttl'),10)||60;
  var num=$('[data-cd-num]',box),bar=$('[data-cd-bar]',box);
  var end=Date.now()+left*1000,t;
  function paint(){
    var ms=end-Date.now(),r=Math.max(0,Math.ceil(ms/1000));
    if(num)num.textContent=r;
    if(bar)bar.style.width=Math.max(0,Math.min(100,ms/(ttl*1000)*100))+'%';
    if(r<=0){
      clearInterval(t);box.classList.add('expired');
      $$('[data-expire-show]').forEach(function(x){x.hidden=false});
      $$('[data-expire-hide]').forEach(function(x){x.hidden=true});
      $$('[data-expire-disable]').forEach(function(x){x.disabled=true});
    }
  }
  t=setInterval(paint,250);paint();
});

/* Sabit mobil CTA çubuğu */
var mcta=$('.m-cta');
if(mcta){
  var onS=function(){mcta.classList.toggle('show',window.scrollY>520)};
  window.addEventListener('scroll',onS,{passive:true});onS();
}

/* İzleyici sayaçları */
function poll(attr,endpoint){
  var nodes=$$('['+attr+']');
  if(!nodes.length)return;
  function tick(){
    if(doc.hidden)return;
    fetch(endpoint+'?_='+Date.now(),{credentials:'same-origin',cache:'no-store'})
      .then(function(r){return r.ok?r.json():null})
      .then(function(d){
        if(!d||!d.ok||!d.counts)return;
        nodes.forEach(function(n){
          var c=Number(d.counts[n.getAttribute(attr)]||0);
          n.textContent=String(isFinite(c)?c:0);
        });
      }).catch(function(){});
  }
  tick();setInterval(tick,10000);
}
poll('data-zem-viewers','/viewer-count.php');
poll('data-zem-public-viewers','/viewer-public-count.php');
})();
JS;
}

/* ======================================================================
   7c. GÖRÜNÜM YARDIMCILARI
   ====================================================================== */
function badge(string $text, string $class = ''): string
{
    return '<span class="badge' . ($class !== '' ? ' ' . h($class) : '') . '">' . h($text) . '</span>';
}

/** Gizli (CSRF'li) POST formu üretir. */
function post_form(string $action, array $fields, string $label, string $btnClass = 'btn', ?string $confirm = null, string $formClass = ''): string
{
    $html  = '<form method="post"' . ($formClass !== '' ? ' class="' . h($formClass) . '"' : '');
    $html .= $confirm !== null ? ' data-confirm="' . h($confirm) . '"' : '';
    $html .= '>' . csrf_field() . '<input type="hidden" name="action" value="' . h($action) . '">';
    foreach ($fields as $name => $value) {
        $html .= '<input type="hidden" name="' . h((string)$name) . '" value="' . h($value) . '">';
    }
    return $html . '<button class="' . h($btnClass) . '" type="submit">' . h($label) . '</button></form>';
}

/** Etiketli, kopyalanabilir (isteğe bağlı gizlenebilir) değer satırı. */
function copy_field(string $label, string $value, bool $secret = false): string
{
    $mask = '••••••••••••';
    $html  = '<div class="field"><span class="field-label">' . h($label) . '</span><div class="copy-row">';
    if ($secret) {
        $html .= '<code class="val is-secret" data-full="' . h($value) . '" data-mask="' . h($mask) . '">' . h($mask) . '</code>';
        $html .= '<button type="button" class="btn sm" data-toggle-secret>Göster</button>';
    } else {
        $html .= '<code class="val">' . h($value) . '</code>';
    }
    $html .= '<button type="button" class="btn sm primary" data-copy="' . h($value) . '">Kopyala</button>';
    return $html . '</div></div>';
}

function empty_field(string $label, string $message): string
{
    return '<div class="field"><span class="field-label">' . h($label) . '</span><div class="copy-row"><code class="val">' . h($message) . '</code></div></div>';
}

function protocol_tabs(string $firstLabel = 'RTMP', string $secondLabel = 'SRT'): string
{
    return '<div class="tabbar" role="tablist">'
        . '<button type="button" class="active" data-tab="rtmp" aria-pressed="true">' . h($firstLabel) . '</button>'
        . '<button type="button" data-tab="srt" aria-pressed="false">' . h($secondLabel) . '</button>'
        . '</div>';
}

function password_input(string $name, string $autocomplete, bool $strength = false, int $min = 10): string
{
    $html  = '<div class="pw"><input type="password" name="' . h($name) . '" minlength="' . $min . '" autocomplete="' . h($autocomplete) . '" required' . ($strength ? ' data-strength' : '') . '>';
    $html .= '<button type="button" data-pw-toggle>Göster</button></div>';
    if ($strength) $html .= '<div class="meter"><i></i></div>';
    return $html;
}

/* ======================================================================
   7d. SAYFA İSKELETİ (layout)
   ====================================================================== */
function layout_start(string $title, string $mode = 'public', string $appRight = '', string $extraCss = ''): void
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#04060e">
<meta name="color-scheme" content="dark">
<meta name="robots" content="<?= $mode === 'public' ? 'index,follow' : 'noindex,nofollow' ?>">
<title><?= h($title) ?> | ZEM Medya Yayıncılık</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<style><?= zem_css() ?></style>
<?php if ($extraCss !== ''): ?><style><?= $extraCss ?></style><?php endif; ?>
</head>
<body class="mode-<?= h($mode) ?>">
<div class="toasts" id="toasts" aria-live="polite">
<?php if ($f): ?>
  <div class="toast<?= $f[1] === 'err' ? ' err' : '' ?>" role="status"><?= h($f[0]) ?></div>
<?php endif; ?>
</div>
<?php if ($mode === 'app'): ?>
<header class="appbar">
  <a class="brand" href="<?= h(ZEM_SELF) ?>"><strong>ZEM MEDYA</strong><small>YAYINCILIK · 2021–2026</small></a>
  <div class="row"><?= $appRight ?></div>
</header>
<main class="wrap">
<?php else: ?>
<main class="wrap">
<header class="nav">
  <div class="nav-left">
    <button type="button" class="menu-btn" aria-label="Menüyü aç" data-drawer="open"><span></span><span></span><span></span></button>
    <a class="brand" href="<?= h(ZEM_SELF) ?>"><strong>ZEM MEDYA</strong><small>YAYINCILIK · 2021–2026</small></a>
  </div>
  <nav class="nav-links" aria-label="Ana bağlantılar">
    <a href="<?= h(ZEM_SELF) ?>#zem-canli-tv">Canlı</a>
    <a href="<?= h(ZEM_SELF) ?>#yayin-mimarisi">Mimari</a>
    <a href="<?= h(ZEM_SELF) ?>#pricing">Premium</a>
    <a href="<?= h(ZEM_SELF) ?>#sss">SSS</a>
  </nav>
  <div class="nav-right">
    <a class="btn ghost sm hide-mobile" href="<?= h(url(['page' => 'login'])) ?>">Giriş yap</a>
    <a class="btn primary sm nav-cta" href="<?= h(url(['page' => 'register'])) ?>">Ücretsiz başla ↗</a>
  </div>
</header>
<div class="backdrop" data-drawer="close"></div>
<aside class="drawer" aria-label="Ana menü">
  <div class="drawer-head">
    <div class="brand"><strong>ZEM MEDYA</strong><small>ANA MENÜ</small></div>
    <button type="button" class="drawer-close" aria-label="Menüyü kapat" data-drawer="close">×</button>
  </div>
  <div class="drawer-label">PLATFORM</div>
  <a href="<?= h(ZEM_SELF) ?>"><span>⌂</span>Ana Sayfa</a>
  <a href="<?= h(ZEM_SELF) ?>#zem-canli-tv"><span>◉</span>Canlı Yayınlar</a>
  <a href="<?= h(ZEM_SELF) ?>#pricing"><span>✦</span>Premium</a>
  <a href="<?= h(ZEM_SELF) ?>#sss"><span>?</span>Sık Sorulanlar</a>
  <a href="<?= h(url(['page' => 'login'])) ?>"><span>↪</span>Giriş Yap</a>
  <a href="<?= h(url(['page' => 'register'])) ?>"><span>＋</span>Ücretsiz Kayıt Ol</a>
  <div class="drawer-label">YASAL BİLGİLER</div>
  <a href="<?= h(ZEM_SELF) ?>#zem-policies"><span>§</span>Politikalar</a>
  <a href="<?= h(ZEM_SELF) ?>#zem-policy-terms"><span>▤</span>Kullanım Şartları</a>
  <a href="<?= h(ZEM_SELF) ?>#zem-policy-privacy"><span>◈</span>Gizlilik Politikası</a>
  <a href="<?= h(ZEM_SELF) ?>#zem-policy-kvkk"><span>⌑</span>KVKK Aydınlatma</a>
  <a href="<?= h(ZEM_SELF) ?>#zem-policy-cookies"><span>◌</span>Çerez Politikası</a>
  <a href="<?= h(ZEM_SELF) ?>#zem-policy-copyright"><span>©</span>Telif Hakkı</a>
  <div class="drawer-foot">ZEM MEDYA · 2021–2026</div>
</aside>
<?php endif; ?>
<?php
}

function layout_end(string $mode = 'public'): void
{
    if ($mode === 'public'):
?>
<footer class="footer">
  <div class="footer-main">
    <div class="footer-brand">
      <strong>ZEM<em>MEDYA</em></strong>
      <p>Türkiye'nin bağımsız dijital yayıncılık platformu.</p>
      <small>OBS · RTMP · SRT · HLS · M3U8</small>
    </div>
    <div class="footer-col">
      <b>PLATFORM</b>
      <a href="<?= h(ZEM_SELF) ?>">Ana sayfa</a>
      <a href="<?= h(ZEM_SELF) ?>#yayin-mimarisi">Yayın mimarisi</a>
      <a href="<?= h(ZEM_SELF) ?>#pricing">Premium üyelik</a>
      <a href="<?= h(ZEM_LIVE_URL) ?>">Canlı yayınlar</a>
    </div>
    <div class="footer-col">
      <b>YASAL BİLGİLER</b>
      <a href="<?= h(ZEM_SELF) ?>#zem-policy-terms">Kullanım şartları</a>
      <a href="<?= h(ZEM_SELF) ?>#zem-policy-privacy">Gizlilik politikası</a>
      <a href="<?= h(ZEM_SELF) ?>#zem-policy-kvkk">KVKK aydınlatma</a>
      <a href="<?= h(ZEM_SELF) ?>#zem-policy-cookies">Çerez politikası</a>
      <a href="<?= h(ZEM_SELF) ?>#zem-policy-copyright">Telif hakkı</a>
    </div>
    <div class="footer-col">
      <b>İLETİŞİM</b>
      <a href="<?= h(ZEM_SUPPORT_URL) ?>" target="_blank" rel="noopener">Telegram destek</a>
      <a href="<?= h(url(['page' => 'login'])) ?>">Hesabına giriş yap</a>
      <a href="<?= h(url(['page' => 'register'])) ?>">Ücretsiz kayıt ol</a>
    </div>
  </div>
  <div class="footer-bottom">
    <span>© 2021–<?= date('Y') ?> ZEM Medya Yayıncılık</span>
    <span>Yasalara uygun ve izinli yayıncılık.</span>
  </div>
</footer>
<?php else: ?>
<p class="small center" style="margin-top:24px">ZEM MEDYA YAYINCILIK · 2021–<?= date('Y') ?> · Güvenli yayıncılık</p>
<?php endif; ?>
</main>
<button type="button" class="to-top" aria-label="Yukarı çık">↑</button>
<script><?= zem_js() ?></script>
</body>
</html>
<?php
}

/* ======================================================================
   8a. YÖNETİCİ PANELİ
   ====================================================================== */
function aform_open(int $uid, string $op, string $return, ?string $confirm = null): string
{
    return '<form method="post"' . ($confirm !== null ? ' data-confirm="' . h($confirm) . '"' : '') . '>'
        . csrf_field()
        . '<input type="hidden" name="action" value="admin_user_action">'
        . '<input type="hidden" name="user_id" value="' . $uid . '">'
        . '<input type="hidden" name="operation" value="' . h($op) . '">'
        . '<input type="hidden" name="return" value="' . h($return) . '">';
}

function admin_user_card(array $u, ?array $prem, ?array $session, string $return, PDO $db): void
{
    $uid         = (int)$u['id'];
    $isBanned    = user_is_banned($u);
    $baseName    = !empty($u['zem_stream_name']) ? (string)$u['zem_stream_name'] : ('u' . $uid);
    $plainKey    = decrypt_stream_key($u['zem_stream_key_enc'] ?? null);
    $obsKey      = $plainKey ? $baseName . '?key=' . $plainKey : '';
    $m3u         = ZEM_HLS_BASE . rawurlencode($baseName) . '.m3u8';
    $slots       = $prem ? premium_streams($db, $uid) : [];
    $isLiveNow   = $session && (int)($session['last_seen'] ?? 0) >= time() - 35;
?>
<article class="ucard">
  <details>
    <summary>
      <div>
        <div class="u-name"><?= h($u['username']) ?>
          <?php if ($prem): ?><?= badge('PREMIUM · ' . fmt_date((int)$prem['ends_at']), 'premium') ?><?php endif; ?>
          <?php if ($isLiveNow): ?><?= badge('CANLI', 'live') ?><?php endif; ?>
        </div>
        <div class="u-meta">#<?= $uid ?> · Kayıt: <?= fmt_date((int)$u['created_at'], true) ?></div>
      </div>
      <?= $isBanned ? badge('YASAKLI', 'banned') : badge('AKTİF') ?>
    </summary>

    <div class="u-body">
      <div class="info-grid">
        <div class="info-box"><label>E-POSTA</label><strong><?= h($u['email']) ?></strong></div>
        <div class="info-box"><label>TELEFON</label><strong><?= h($u['phone']) ?></strong></div>
        <div class="info-box"><label>YAYIN ADI</label><strong><?= h($baseName) ?></strong></div>
        <div class="info-box"><label>PREMIUM</label><strong><?= $prem ? 'Bitiş ' . fmt_date((int)$prem['ends_at']) : 'Yok' ?></strong></div>
        <div class="info-box"><label>SON GİRİŞ</label><strong><?= !empty($u['last_login']) ? fmt_date((int)$u['last_login'], true) : '—' ?></strong></div>
        <div class="info-box"><label>SON IP</label><strong><?= h((string)($u['last_ip'] ?? '—')) ?></strong></div>
        <?php if ($isBanned): ?>
        <div class="info-box"><label>YASAK NEDENİ</label><strong><?= h((string)($u['ban_reason'] ?? '—')) ?></strong></div>
        <div class="info-box"><label>YASAK BİTİŞ</label><strong><?= !empty($u['banned_until']) ? fmt_date((int)$u['banned_until'], true) : 'Süresiz' ?></strong></div>
        <?php endif; ?>
      </div>

      <div class="box">
        <?= copy_field('OBS · RTMP sunucusu', ZEM_RTMP_URL) ?>
        <?= $obsKey !== '' ? copy_field('OBS · Ana yayın anahtarı (' . $baseName . ')', $obsKey, true) : empty_field('OBS · Ana yayın anahtarı', 'Anahtar bulunmuyor veya çözülemedi.') ?>
        <?= copy_field('İzleme · HLS M3U8 (ana yayın)', $m3u) ?>
        <div class="row"><button type="button" class="btn sm primary" data-preview="<?= h($baseName) ?>">▶ Canlı önizle</button></div>
      </div>

      <?php if ($prem): ?>
      <div class="box">
        <span class="field-label">PREMIUM · 5 YAYIN SLOTU</span>
        <div class="slots-grid">
        <?php for ($slot = 1; $slot <= 5; $slot++):
            $found = null;
            foreach ($slots as $ps) { if ($ps['slot'] === $slot) { $found = $ps; break; } }
            $slotName = $found ? $found['name'] : $baseName . '__s' . $slot;
        ?>
          <div class="mini-slot">
            <div class="slot-head"><span class="badge">SLOT <?= $slot ?></span><b class="small"><?= h($slotName) ?></b></div>
            <?php if ($found && $found['key']): ?>
              <?= copy_field('RTMP anahtarı', (string)$found['key'], true) ?>
              <?= copy_field('HLS', (string)$found['hls']) ?>
              <button type="button" class="btn sm" data-preview="<?= h($slotName) ?>">▶ Önizle</button>
            <?php else: ?>
              <p class="small">Anahtar hazır değil.</p>
            <?php endif; ?>
            <?= aform_open($uid, 'rename_premium_slot', $return) ?>
              <input type="hidden" name="slot" value="<?= $slot ?>">
              <div class="inline-form">
                <input name="stream_name" value="<?= h($slotName) ?>" placeholder="Yeni ad" pattern="[a-zA-Z0-9_\-]{3,64}" required>
                <button class="btn sm" type="submit">Adı değiştir</button>
              </div>
            </form>
            <?= aform_open($uid, 'renew_premium_slot', $return, 'Slot anahtarı yenilensin mi?') ?>
              <input type="hidden" name="slot" value="<?= $slot ?>">
              <button class="btn sm danger block" type="submit" style="min-height:38px;margin-top:8px">Anahtarı yenile</button>
            </form>
          </div>
        <?php endfor; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($session): ?>
        <p class="note">Oturum IP: <?= h((string)($session['client_ip'] ?? '-')) ?> · Başlangıç: <?= fmt_date((int)$session['started_at'], true) ?> · Yayın: <?= h((string)($session['stream_name'] ?? '-')) ?></p>
      <?php endif; ?>

      <div class="actions">
        <?= aform_open($uid, 'grant_premium', $return, 'Premium verilsin mi? 5 slot aktif olur.') ?>
          <select name="days" aria-label="Premium süresi">
            <option value="7">7 gün</option><option value="30" selected>30 gün</option><option value="90">90 gün</option><option value="180">180 gün</option><option value="365">365 gün</option>
          </select>
          <button class="btn primary" type="submit">Premium ver / uzat</button>
        </form>
        <?php if ($prem): ?>
          <?= aform_open($uid, 'revoke_premium', $return, 'Bu kullanıcının Premium üyeliği ve 5 yayın slotu kapatılsın mı?') ?>
            <button class="btn danger" type="submit">Premium kaldır</button>
          </form>
        <?php endif; ?>
      </div>

      <div class="actions">
        <?php if ($isBanned): ?>
          <?= aform_open($uid, 'unban', $return) ?><button class="btn good" type="submit">Yasağı kaldır</button></form>
        <?php else: ?>
          <?= aform_open($uid, 'ban', $return, 'Kullanıcı yasaklansın mı?') ?>
            <select name="hours" aria-label="Yasak süresi"><option value="0">Süresiz</option><option value="1">1 saat</option><option value="24">24 saat</option><option value="168">7 gün</option><option value="720">30 gün</option></select>
            <input name="reason" placeholder="Yasak nedeni" required>
            <button class="btn danger" type="submit">Yasakla</button>
          </form>
        <?php endif; ?>
      </div>

      <div class="actions">
        <?= aform_open($uid, 'revoke', $return, 'Ana yayın anahtarı iptal edilsin mi?') ?><button class="btn danger" type="submit">Anahtarı iptal et</button></form>
        <?= aform_open($uid, 'delete_user', $return, 'DİKKAT: Bu kullanıcının premium kayıtları, yayın anahtarları, oturumları ve ilişkili kayıtları kalıcı olarak silinecek. Emin misiniz?') ?><button class="btn danger" type="submit">Kullanıcıyı sil</button></form>
      </div>
    </div>
  </details>
</article>
<?php
}


/* ======================================================================
   8a-1. YÖNETİCİ SERVİSLERİ: İstatistik, HLS sağlık analizi, duyurular
   ====================================================================== */
function active_announcements(PDO $db): array
{
    if (!ensure_schema($db)) return [];
    try {
        $q = $db->prepare('SELECT id,title,body,level,created_at FROM zem_announcements WHERE active=1 AND (expires_at IS NULL OR expires_at>?) ORDER BY id DESC LIMIT 3');
        $q->execute([time()]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function render_announcements(array $items): string
{
    $html = '';
    foreach ($items as $a) {
        $lvl = in_array($a['level'], ['info', 'warn', 'success'], true) ? $a['level'] : 'info';
        $ico = ['info' => '📢', 'warn' => '⚠️', 'success' => '🎉'][$lvl];
        $html .= '<div class="announce ' . $lvl . '"><span class="ico">' . $ico . '</span><div><b>' . h($a['title']) . '</b><p>' . h($a['body']) . '</p></div></div>';
    }
    return $html;
}

function act_admin_announce_create(PDO $db): void
{
    $admin = require_admin($db);
    $back  = admin_url('announcements');
    if (!ensure_schema($db)) {
        flash('Duyuru tablosu oluşturulamadı (veritabanı yetkisi).', 'err');
        go($back);
    }
    $title = trim((string)($_POST['title'] ?? ''));
    $body  = trim((string)($_POST['body'] ?? ''));
    $level = (string)($_POST['level'] ?? 'info');
    $hours = (int)($_POST['hours'] ?? 0);
    if (mb_strlen($title) < 3 || mb_strlen($title) > 80 || mb_strlen($body) < 3 || mb_strlen($body) > 400) {
        flash('Başlık 3-80, mesaj 3-400 karakter olmalı.', 'err');
        go($back);
    }
    if (!in_array($level, ['info', 'warn', 'success'], true)) $level = 'info';
    $expires = $hours > 0 ? time() + min($hours, 8760) * 3600 : null;
    $db->prepare('INSERT INTO zem_announcements(title,body,level,active,admin_id,created_at,expires_at) VALUES(?,?,?,1,?,?,?)')
       ->execute([$title, $body, $level, (int)$admin['id'], time(), $expires]);
    audit($db, 'announcement_created', null, $title);
    flash('Duyuru yayınlandı. Ana sayfada ve kullanıcı panelinde görünür.');
    go($back);
}

function act_admin_announce_action(PDO $db): void
{
    $admin = require_admin($db);
    $back  = admin_url('announcements');
    if (!ensure_schema($db)) go($back);
    $id = (int)($_POST['id'] ?? 0);
    $op = (string)($_POST['operation'] ?? '');
    if ($op === 'delete') {
        $db->prepare('DELETE FROM zem_announcements WHERE id=?')->execute([$id]);
        audit($db, 'announcement_deleted', null, '#' . $id);
        flash('Duyuru silindi.');
    } elseif ($op === 'toggle') {
        $db->prepare('UPDATE zem_announcements SET active=1-active WHERE id=?')->execute([$id]);
        audit($db, 'announcement_toggled', null, '#' . $id);
        flash('Duyuru durumu değişti.');
    }
    go($back);
}

/* ---------- Doğru istatistikler (tek yerde) ---------- */
function admin_stats(PDO $db): array
{
    $now   = time();
    $site  = ZEM_SITE_KEY;
    $today = strtotime('today');
    $st    = ['now' => $now];

    $one = static function (PDO $db, string $sql, array $p = []): int {
        $q = $db->prepare($sql);
        $q->execute($p);
        return (int)$q->fetchColumn();
    };

    $st['total']      = $one($db, 'SELECT COUNT(*) FROM users');
    $st['today']      = $one($db, 'SELECT COUNT(*) FROM users WHERE created_at>=?', [$today]);
    $st['d7']         = $one($db, 'SELECT COUNT(*) FROM users WHERE created_at>=?', [strtotime('-6 days', $today)]);
    $st['d30']        = $one($db, 'SELECT COUNT(*) FROM users WHERE created_at>=?', [strtotime('-29 days', $today)]);
    $st['banned']     = $one($db, "SELECT COUNT(*) FROM users WHERE status='banned' AND (banned_until IS NULL OR banned_until>?)", [$now]);
    $st['premium']    = $one($db, "SELECT COUNT(DISTINCT user_id) FROM premium_subscriptions WHERE status='active' AND starts_at<=? AND ends_at>=?", [$now, $now]);
    $st['expiring7']  = $one($db, "SELECT COUNT(DISTINCT user_id) FROM premium_subscriptions WHERE status='active' AND ends_at>=? AND ends_at<=?", [$now, $now + 7 * 86400]);
    $st['active_u7']  = $one($db, 'SELECT COUNT(*) FROM users WHERE last_login>=?', [$now - 7 * 86400]);

    // Canlı = son 35 sn içinde sinyal veren oturumlar; takılı = aktif görünüp sinyali kesilenler
    $st['live']       = $one($db, 'SELECT COUNT(DISTINCT stream_name) FROM stream_sessions WHERE active=1 AND site=? AND last_seen>=?', [$site, $now - 35]);
    $st['stale']      = $one($db, 'SELECT COUNT(*) FROM stream_sessions WHERE active=1 AND site=? AND (last_seen IS NULL OR last_seen<?)', [$site, $now - 35]);
    $st['live_users'] = $one($db, 'SELECT COUNT(DISTINCT user_id) FROM stream_sessions WHERE active=1 AND site=? AND last_seen>=?', [$site, $now - 35]);

    $st['reports_open'] = 0;
    $st['blocked']      = 0;
    try {
        $st['reports_open'] = $one($db, "SELECT COUNT(*) FROM zem_stream_reports WHERE status NOT IN ('closed','blocked')");
        $st['blocked']      = $one($db, 'SELECT COUNT(*) FROM zem_public_stream_blocks');
    } catch (Throwable $e) {
    }
    $st['public_on'] = 0;
    try {
        $st['public_on'] = $one($db, 'SELECT COUNT(*) FROM zem_public_settings WHERE enabled=1');
    } catch (Throwable $e) {
    }
    $st['free'] = max(0, $st['total'] - $st['premium'] - $st['banned']);
    return $st;
}

/** Son $days gün için günlük kayıt sayıları (yerel saat dilimine göre). */
function stats_signups(PDO $db, int $days): array
{
    $start = strtotime('-' . ($days - 1) . ' days', strtotime('today'));
    $q = $db->prepare('SELECT created_at FROM users WHERE created_at>=?');
    $q->execute([$start]);
    $buckets = [];
    for ($i = 0; $i < $days; $i++) {
        $buckets[date('Y-m-d', strtotime('+' . $i . ' days', $start))] = 0;
    }
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $ts) {
        $k = date('Y-m-d', (int)$ts);
        if (isset($buckets[$k])) $buckets[$k]++;
    }
    $out = [];
    foreach ($buckets as $day => $c) $out[] = ['label' => date('d.m', strtotime($day)), 'value' => $c];
    return $out;
}

/** Günlük yayın saati (7 gün) + en çok yayın yapanlar. */
function stats_broadcast(PDO $db, int $days = 7): array
{
    $now   = time();
    $start = strtotime('-' . ($days - 1) . ' days', strtotime('today'));
    $days_ = [];
    for ($i = 0; $i < $days; $i++) $days_[date('Y-m-d', strtotime('+' . $i . ' days', $start))] = 0;
    $top = [];
    try {
        $q = $db->prepare('SELECT s.user_id,s.started_at,s.last_seen,s.active,u.username FROM stream_sessions s JOIN users u ON u.id=s.user_id WHERE s.site=? AND s.started_at>=? ORDER BY s.started_at DESC LIMIT 5000');
        $q->execute([ZEM_SITE_KEY, $start]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $s = (int)$r['started_at'];
            $e = (int)($r['last_seen'] ?? 0);
            if ($e < $s) $e = ((int)$r['active'] === 1) ? $now : $s;
            $dur = min($e - $s, 86400);
            if ($dur <= 0) continue;
            $k = date('Y-m-d', $s);
            if (isset($days_[$k])) $days_[$k] += $dur;
            $uid = (int)$r['user_id'];
            if (!isset($top[$uid])) $top[$uid] = ['username' => $r['username'], 'sec' => 0, 'n' => 0];
            $top[$uid]['sec'] += $dur;
            $top[$uid]['n']++;
        }
    } catch (Throwable $e) {
    }
    $series = [];
    foreach ($days_ as $day => $sec) $series[] = ['label' => date('d.m', strtotime($day)), 'value' => round($sec / 3600, 1)];
    uasort($top, static fn($a, $b) => $b['sec'] <=> $a['sec']);
    return ['series' => $series, 'top' => array_slice(array_values($top), 0, 5)];
}

function server_info(): array
{
    $info = ['php' => PHP_VERSION, 'load' => null, 'disk_free' => null, 'disk_total' => null, 'db' => null];
    if (function_exists('sys_getloadavg')) {
        $l = @sys_getloadavg();
        if ($l) $info['load'] = round((float)$l[0], 2) . ' · ' . round((float)$l[1], 2) . ' · ' . round((float)$l[2], 2);
    }
    $dir = is_dir(ZEM_HLS_DIR) ? ZEM_HLS_DIR : (is_dir(ZEM_SRS_HLS_DIR) ? ZEM_SRS_HLS_DIR : '/');
    $free = @disk_free_space($dir);
    $tot  = @disk_total_space($dir);
    if ($free !== false && $tot) {
        $info['disk_free'] = $free;
        $info['disk_total'] = $tot;
    }
    $f = defined('ZEM_FREE_DB') ? @filesize(ZEM_FREE_DB) : false;
    if ($f !== false) $info['db'] = $f;
    return $info;
}

function fmt_bytes(?float $b): string
{
    if ($b === null) return '—';
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($b >= 1024 && $i < 4) {
        $b /= 1024;
        $i++;
    }
    return round($b, $i > 1 ? 1 : 0) . ' ' . $u[$i];
}

/* ---------- SVG grafikler ---------- */
function svg_bars(array $series, string $unit = '', string $id = 'g'): string
{
    $n = count($series);
    if (!$n) return '';
    $w = 640; $h = 200; $padB = 26; $padT = 22;
    $max = 1;
    foreach ($series as $s) $max = max($max, (float)$s['value']);
    $slot = $w / $n;
    $bw = min(34, $slot * .62);
    $svg = '<svg class="chart-svg" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" role="img">'
         . '<defs><linearGradient id="' . h($id) . '" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#4de6ff"/><stop offset="1" stop-color="#a888ff"/></linearGradient></defs>';
    for ($g = 0; $g <= 3; $g++) {
        $y = $padT + ($h - $padT - $padB) * $g / 3;
        $svg .= '<line x1="0" x2="' . $w . '" y1="' . round($y, 1) . '" y2="' . round($y, 1) . '" stroke="rgba(110,180,255,.1)"/>';
    }
    foreach ($series as $i => $s) {
        $bh = max(3, ($h - $padT - $padB) * ((float)$s['value'] / $max));
        $x  = $slot * $i + ($slot - $bw) / 2;
        $y  = $h - $padB - $bh;
        $svg .= '<rect x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . round($bw, 1) . '" height="' . round($bh, 1) . '" rx="5" fill="url(#' . h($id) . ')"><title>' . h($s['label'] . ': ' . $s['value'] . $unit) . '</title></rect>';
        if ($n <= 14 || $i % 2 === 0) {
            $svg .= '<text x="' . round($x + $bw / 2, 1) . '" y="' . ($h - 8) . '" text-anchor="middle" fill="#8da0bf" font-size="11">' . h($s['label']) . '</text>';
        }
        if ($s['value'] > 0 && $n <= 14) {
            $svg .= '<text x="' . round($x + $bw / 2, 1) . '" y="' . round(max(12, $y - 6), 1) . '" text-anchor="middle" fill="#eef5ff" font-size="11" font-weight="700">' . h((string)$s['value']) . '</text>';
        }
    }
    return $svg . '</svg>';
}

function svg_donut(array $parts, string $center = ''): string
{
    $total = 0;
    foreach ($parts as $p) $total += $p['value'];
    $r = 52; $c = 2 * M_PI * $r;
    $svg = '<svg viewBox="0 0 140 140" class="donut" role="img"><circle cx="70" cy="70" r="' . $r . '" fill="none" stroke="rgba(255,255,255,.06)" stroke-width="16"/>';
    $offset = 0;
    if ($total > 0) {
        foreach ($parts as $p) {
            $len = $c * $p['value'] / $total;
            if ($len <= 0) continue;
            $svg .= '<circle cx="70" cy="70" r="' . $r . '" fill="none" stroke="' . h($p['color']) . '" stroke-width="16" stroke-dasharray="' . round($len, 2) . ' ' . round($c - $len, 2) . '" stroke-dashoffset="' . round(-$offset, 2) . '" transform="rotate(-90 70 70)"><title>' . h($p['label'] . ': ' . $p['value']) . '</title></circle>';
            $offset += $len;
        }
    }
    return $svg . '<text x="70" y="68" text-anchor="middle" fill="#eef5ff" font-size="22" font-weight="700" font-family="Space Grotesk,sans-serif">' . h($center) . '</text><text x="70" y="86" text-anchor="middle" fill="#8da0bf" font-size="9" letter-spacing="1.5">TOPLAM</text></svg>';
}

/* ---------- HLS sağlık analizi (sunucudaki .m3u8 ve segment dosyaları) ---------- */
function hls_dirs(): array
{
    return ['srs' => rtrim((string)ZEM_SRS_HLS_DIR, '/') . '/', 'legacy' => rtrim((string)ZEM_HLS_DIR, '/') . '/'];
}

function valid_hls_name(string $n): bool
{
    return (bool)preg_match('/^[a-zA-Z0-9_\-]{1,64}$/', $n);
}

function hls_locate(string $name): ?array
{
    if (!valid_hls_name($name)) return null;
    $best = null;
    foreach (hls_dirs() as $key => $dir) {
        $path = $dir . $name . '.m3u8';
        if (@is_file($path)) {
            $m = (int)@filemtime($path);
            if (!$best || $m > $best['mtime']) $best = ['src' => $key, 'dir' => $dir, 'path' => $path, 'mtime' => $m];
        }
    }
    return $best;
}

function hls_analyze(string $name): array
{
    $res = [
        'name' => $name, 'found' => false, 'status' => 'bad', 'src' => null,
        'playlist_age' => null, 'target' => null, 'segments' => [], 'issues' => [],
        'avg_dur' => null, 'bitrate' => null, 'last_seg_age' => null, 'missing' => 0,
    ];
    $loc = hls_locate($name);
    if (!$loc) {
        $res['issues'][] = ['bad', 'HLS çıktısı bulunamadı (playlist yok)'];
        return $res;
    }
    $res['found'] = true;
    $res['src']   = $loc['src'];
    $now = time();
    $res['playlist_age'] = max(0, $now - $loc['mtime']);

    $txt = (string)@file_get_contents($loc['path'], false, null, 0, 262144);
    $target = 0; $seq = 0; $segs = []; $pendingDur = null;
    foreach (preg_split('/\r?\n/', $txt) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (strpos($line, '#EXT-X-TARGETDURATION:') === 0) $target = (int)substr($line, 22);
        elseif (strpos($line, '#EXT-X-MEDIA-SEQUENCE:') === 0) $seq = (int)substr($line, 22);
        elseif (strpos($line, '#EXTINF:') === 0) $pendingDur = (float)substr($line, 8);
        elseif ($line[0] !== '#' && $pendingDur !== null) {
            $segs[] = ['uri' => $line, 'dur' => $pendingDur];
            $pendingDur = null;
        }
    }
    $res['target'] = $target ?: null;
    $segs = array_slice($segs, -12);
    $sizes = []; $durs = []; $prevM = null; $gapBad = 0; $tiny = 0;
    foreach ($segs as $i => $sg) {
        $base = basename(parse_url($sg['uri'], PHP_URL_PATH) ?: $sg['uri']);
        $file = $loc['dir'] . $base;
        $ok   = @is_file($file);
        $size = $ok ? (int)@filesize($file) : 0;
        $mt   = $ok ? (int)@filemtime($file) : 0;
        $state = 'ok';
        if (!$ok || $size <= 0) { $state = 'bad'; $res['missing']++; }
        $gap = ($prevM && $mt) ? $mt - $prevM : null;
        if ($ok && $gap !== null && $target && $gap > max(4, $target * 2.5)) { $state = $state === 'bad' ? 'bad' : 'warn'; $gapBad++; }
        if ($target && $sg['dur'] > $target * 1.5) $state = $state === 'bad' ? 'bad' : 'warn';
        if ($ok) $prevM = $mt;
        $res['segments'][] = [
            'n' => $base, 'dur' => round($sg['dur'], 2), 'size' => $size,
            'age' => $mt ? max(0, $now - $mt) : null, 'state' => $state,
        ];
        if ($size > 0) { $sizes[] = $size; $durs[] = $sg['dur']; }
    }
    if ($durs) {
        $res['avg_dur'] = round(array_sum($durs) / count($durs), 2);
        $res['bitrate'] = (int)round((array_sum($sizes) * 8) / max(1, array_sum($durs)));
        sort($sizes);
        $median = $sizes[intdiv(count($sizes), 2)];
        foreach ($res['segments'] as &$rs) {
            if ($rs['size'] > 0 && $median > 0 && $rs['size'] < $median * 0.2 && $rs['state'] === 'ok') { $rs['state'] = 'warn'; $tiny++; }
        }
        unset($rs);
    }
    $last = end($res['segments']);
    $res['last_seg_age'] = $last ? $last['age'] : null;

    $t = max(2, $target ?: 4);
    if ($res['playlist_age'] > max(12, $t * 3)) {
        $res['issues'][] = ['bad', 'Playlist ' . $res['playlist_age'] . ' sn’dir güncellenmiyor — yayın donmuş veya kopmuş olabilir'];
    } elseif ($res['playlist_age'] > max(6, $t * 2)) {
        $res['issues'][] = ['warn', 'Playlist gecikiyor (' . $res['playlist_age'] . ' sn) — kasma riski'];
    }
    if (count($res['segments']) < 2) $res['issues'][] = ['warn', 'Segment sayısı çok az (yayın yeni başlamış veya takılmış)'];
    if ($res['missing'] > 0) $res['issues'][] = ['bad', $res['missing'] . ' segment diskte eksik/boş — oynatıcıda kopma görülebilir'];
    if ($gapBad > 0) $res['issues'][] = ['warn', $gapBad . ' segment aralığı normalden uzun — kaynaktan veri akışı aksıyor'];
    if ($tiny > 0) $res['issues'][] = ['warn', $tiny . ' segment çok küçük — görüntü donmuş/ses-only olabilir'];
    if ($res['avg_dur'] && $target && $res['avg_dur'] > $target * 1.3) $res['issues'][] = ['warn', 'Ortalama segment süresi hedefin üstünde (anahtar kare aralığı yüksek)'];

    $status = 'ok';
    foreach ($res['issues'] as $iss) {
        if ($iss[0] === 'bad') { $status = 'bad'; break; }
        $status = 'warn';
    }
    $res['status'] = $status;
    return $res;
}

function hls_urls(string $name, ?string $src): array
{
    $enc = rawurlencode($name);
    return [
        'proxy'  => $src ? url(['admin' => 1, 'ajax' => 'hls', 'src' => $src, 'name' => $name]) : null,
        'remote' => ZEM_SRS_HLS_BASE . $enc . '.m3u8',
        'legacy' => ZEM_HLS_BASE . $enc . '.m3u8',
    ];
}

function admin_live_snapshot(PDO $db): array
{
    $now = time();
    $q = $db->prepare('SELECT s.stream_name,s.user_id,s.client_ip,s.started_at,s.last_seen,u.username FROM stream_sessions s JOIN users u ON u.id=s.user_id WHERE s.active=1 AND s.site=? ORDER BY s.started_at DESC');
    $q->execute([ZEM_SITE_KEY]);
    $seen = [];
    $streams = [];
    $sum = ['ok' => 0, 'warn' => 0, 'bad' => 0];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $name = (string)$r['stream_name'];
        if (isset($seen[$name])) continue;
        $seen[$name] = true;
        $a = hls_analyze($name);
        $lastSeen = (int)($r['last_seen'] ?? 0);
        $fresh = $lastSeen >= $now - 35;
        if (!$fresh) {
            array_unshift($a['issues'], ['bad', 'Sunucudan ' . ($lastSeen ? time_ago($lastSeen) : 'hiç') . ' sinyal alınmadı (takılı oturum olabilir)']);
            $a['status'] = 'bad';
        }
        $sum[$a['status']]++;
        $streams[] = array_merge($a, [
            'user' => $r['username'], 'uid' => (int)$r['user_id'], 'ip' => (string)($r['client_ip'] ?? ''),
            'uptime' => $now - (int)$r['started_at'], 'fresh' => $fresh,
            'last_seen_ago' => $lastSeen ? $now - $lastSeen : null,
            'urls' => hls_urls($name, $a['src']),
        ]);
    }
    return ['ok' => true, 'ts' => $now, 'streams' => $streams, 'summary' => $sum + ['total' => count($streams)]];
}

function hls_content_type(string $file): string
{
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    return [
        'ts' => 'video/mp2t', 'm4s' => 'video/iso.segment', 'mp4' => 'video/mp4',
        'aac' => 'audio/aac', 'm3u8' => 'application/vnd.apple.mpegurl',
    ][$ext] ?? 'application/octet-stream';
}

/** ?admin=1&ajax=… uç noktaları (JSON + HLS proxy). Oturum kilidi hemen bırakılır. */
function admin_ajax(PDO $db, string $what): never
{
    session_write_close();
    $json = static function (array $data): never {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    };
    $fail = static function (int $code, string $msg) use ($json): never {
        http_response_code($code);
        $json(['ok' => false, 'error' => $msg]);
    };

    if ($what === 'health') {
        $json(admin_live_snapshot($db));
    }

    $name = (string)($_GET['name'] ?? '');
    if ($what === 'pinfo') {
        if (!valid_hls_name($name)) $fail(400, 'Geçersiz yayın adı');
        $a = hls_analyze($name);
        $json(['ok' => true, 'health' => $a, 'urls' => hls_urls($name, $a['src'])]);
    }

    $src  = (string)($_GET['src'] ?? '');
    $dirs = hls_dirs();
    if (!isset($dirs[$src]) || !valid_hls_name($name) && $what === 'hls') $fail(400, 'Geçersiz istek');

    if ($what === 'hls') {
        $path = $dirs[$src] . $name . '.m3u8';
        if (!@is_file($path)) $fail(404, 'Playlist bulunamadı');
        $txt = (string)@file_get_contents($path, false, null, 0, 262144);
        $rewrite = static function (string $uri) use ($src): string {
            $base = basename(parse_url($uri, PHP_URL_PATH) ?: $uri);
            return url(['admin' => 1, 'ajax' => 'seg', 'src' => $src, 'f' => $base]);
        };
        $out = [];
        foreach (preg_split('/\r?\n/', $txt) as $line) {
            $t = trim($line);
            if ($t !== '' && $t[0] !== '#') {
                $line = $rewrite($t);
            } elseif (strpos($t, 'URI="') !== false) {
                $line = (string)preg_replace_callback('/URI="([^"]+)"/', static fn($m) => 'URI="' . $rewrite($m[1]) . '"', $t);
            }
            $out[] = $line;
        }
        header('Content-Type: application/vnd.apple.mpegurl');
        header('Cache-Control: no-store');
        echo implode("\n", $out);
        exit;
    }

    if ($what === 'seg') {
        $f = basename((string)($_GET['f'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9._\-]{1,160}\.(ts|m4s|mp4|aac)$/', $f)) $fail(400, 'Geçersiz segment');
        $path = $dirs[$src] . $f;
        if (!@is_file($path)) $fail(404, 'Segment bulunamadı');
        header('Content-Type: ' . hls_content_type($f));
        header('Content-Length: ' . (int)@filesize($path));
        header('Cache-Control: no-store');
        @readfile($path);
        exit;
    }

    $fail(404, 'Bilinmeyen uç nokta');
}

/* ======================================================================
   8a-2. YÖNETİCİ SAYFALARI
   ====================================================================== */
function health_badge(string $status): string
{
    return ['ok' => badge('SAĞLIKLI', 'live'), 'warn' => badge('KASMA RİSKİ', 'warn'), 'bad' => badge('SORUNLU', 'banned')][$status] ?? badge($status);
}

function kpi(string $cls, string $ico, string $label, int $value, string $sub): string
{
    return '<div class="kpi ' . h($cls) . '"><div class="kpi-ico">' . h($ico) . '</div><span>' . h($label) . '</span>'
         . '<strong data-count="' . $value . '">' . number_format($value) . '</strong><em>' . h($sub) . '</em></div>';
}

function page_admin(PDO $db, array $admin): void
{
    if (isset($_GET['ajax'])) {
        admin_ajax($db, (string)$_GET['ajax']);
    }
    if (($_GET['export'] ?? '') === 'users') {
        act_export_users($db, $admin);
    }

    $logoutForm = post_form('logout', [], 'Çıkış', 'btn sm danger');

    if ((int)$admin['force_password_change'] === 1) {
        layout_start('Yönetici güvenliği', 'app', $logoutForm);
?>
<div class="card form-card">
  <h1>Yönetici güvenliği</h1>
  <p>İlk giriş için yönetici şifreni değiştir (en az 12 karakter).</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="admin_password">
    <label>Yeni şifre</label>
    <?= password_input('new_password', 'new-password', true, 12) ?>
    <br><button class="btn primary block" type="submit">Şifreyi değiştir</button>
  </form>
</div>
<?php
        layout_end('app');
        return;
    }

    $st = admin_stats($db);
    $section = (string)($_GET['section'] ?? 'overview');
    $titles = [
        'overview'      => ['Komuta merkezi', '◈'],
        'live'          => ['Canlı yayın merkezi', '▣'],
        'users'         => ['Kullanıcı yönetimi', '♙'],
        'reports'       => ['Yayın bildirimleri', '⚑'],
        'announcements' => ['Duyurular', '📢'],
        'security'      => ['Güvenlik kayıtları', '⌁'],
    ];
    if (!isset($titles[$section])) $section = 'overview';

    $right = '<span class="badge premium hide-mobile">YÖNETİCİ · ' . h($admin['username']) . '</span>' . $logoutForm;
    layout_start('Yönetici paneli', 'app', $right, zem_admin_css());
?>
<div class="adm-overlay" id="admOverlay" data-adm="close"></div>
<div class="adm">
  <aside class="adm-side" id="admSide">
    <div class="adm-brand"><b>ZEM MEDYA</b><small>FREE · CONTROL CENTER</small></div>
    <div class="adm-label">YÖNETİM</div>
    <nav class="adm-nav">
      <?php foreach ($titles as $key => [$label, $ico]):
          $count = '';
          if ($key === 'live' && $st['live'] > 0) $count = badge((string)$st['live'], 'live');
          if ($key === 'reports' && $st['reports_open'] > 0) $count = badge((string)$st['reports_open'], 'warn');
      ?>
        <a class="<?= $section === $key ? 'active' : '' ?>" href="<?= h(admin_url($key)) ?>"><span><?= h($ico) ?> <?= h($label) ?></span><?= $count ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="adm-label">SİSTEM</div>
    <nav class="adm-nav">
      <a href="<?= h(ZEM_SELF) ?>" target="_blank" rel="noopener"><span>↗ Kullanıcı sayfası</span></a>
      <a href="<?= h(ZEM_LIVE_URL) ?>" target="_blank" rel="noopener"><span>↗ Canlı dizin</span></a>
      <a href="<?= h(url(['admin' => 1, 'export' => 'users'])) ?>"><span>⇩ Kullanıcıları CSV indir</span></a>
    </nav>
    <div class="adm-me">
      <div class="small">Oturum açık</div>
      <b><?= h($admin['username']) ?></b>
      <button type="button" class="btn sm block" style="margin-top:10px" data-adm="close">Menüyü kapat</button>
    </div>
  </aside>

  <section class="adm-main">
    <div class="adm-top">
      <div class="left">
        <button type="button" class="menu-btn" data-adm="open" aria-label="Menüyü aç"><span></span><span></span><span></span></button>
        <div>
          <div class="adm-title"><?= h($titles[$section][0]) ?></div>
          <div class="adm-sub"><?= date('d.m.Y H:i') ?> · <?= $st['live'] ?> canlı yayın · <?= $st['total'] ?> kullanıcı</div>
        </div>
      </div>
      <form method="get" class="adm-search hide-mobile">
        <input type="hidden" name="admin" value="1"><input type="hidden" name="section" value="users">
        <input type="search" name="q" placeholder="Kullanıcı ara…" aria-label="Kullanıcı ara">
      </form>
    </div>
<?php
    if ($section === 'overview') {
        admin_section_overview($db, $st);
    } elseif ($section === 'live') {
        admin_section_live($db, $st);
    } elseif ($section === 'users') {
        admin_section_users($db);
    } elseif ($section === 'reports') {
        admin_section_reports($db);
    } elseif ($section === 'announcements') {
        admin_section_announcements($db);
    } else {
        admin_section_security($db);
    }
?>
  </section>
</div>
<?php admin_preview_modal(); ?>
<script><?= zem_admin_js() ?></script>
<?php
    layout_end('app');
}

function admin_preview_modal(): void
{
?>
<div class="pv" id="pv" hidden data-hlsjs="<?= h(ZEM_HLSJS_URL) ?>">
  <div class="pv-box" role="dialog" aria-modal="true" aria-label="Canlı önizleme">
    <div class="pv-head">
      <div><b id="pvTitle">Önizleme</b><small id="pvSub">—</small></div>
      <div class="row"><span id="pvVerdict" class="badge">…</span><button type="button" class="btn sm" id="pvClose">Kapat ✕</button></div>
    </div>
    <div class="pv-body">
      <div class="pv-video">
        <video id="pvVideo" controls muted autoplay playsinline></video>
        <div class="pv-overlay" id="pvOverlay">Yükleniyor…</div>
      </div>
      <div class="pv-side">
        <div class="pv-stats" id="pvStats"></div>
        <div class="pv-srcs" id="pvSrcs"></div>
      </div>
    </div>
    <div class="pv-sec"><b>Segment zaman çizelgesi</b><small>Her çubuk bir segment: yüklenme süresi / segment süresi. Yeşil akıcı, sarı yavaş, kırmızı hata.</small></div>
    <div class="seg-line" id="pvSeg"></div>
    <div class="pv-sec"><b>Sunucudaki son segmentler</b><small>Diskteki .ts dosyaları</small></div>
    <div class="seg-strip big" id="pvDisk"></div>
    <div class="pv-sec"><b>Olay günlüğü</b></div>
    <ul class="pv-log" id="pvLog"></ul>
  </div>
</div>
<?php
}

function admin_section_overview(PDO $db, array $st): void
{
    $signups = stats_signups($db, 14);
    $bc      = stats_broadcast($db, 7);
    $snap    = admin_live_snapshot($db);
    $srv     = server_info();
    $now     = time();

    $latest = $db->query('SELECT id,username,email,status,banned_until,created_at FROM users ORDER BY id DESC LIMIT 6')->fetchAll(PDO::FETCH_ASSOC);
    $premMap = premium_map($db);
    $expiring = [];
    try {
        $e = $db->prepare("SELECT p.user_id,p.ends_at,u.username FROM premium_subscriptions p JOIN users u ON u.id=p.user_id WHERE p.status='active' AND p.ends_at>=? AND p.ends_at<=? ORDER BY p.ends_at ASC LIMIT 8");
        $e->execute([$now, $now + 7 * 86400]);
        $expiring = $e->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $ex) {
    }
    $logs = $db->query('SELECT a.action,a.details,a.created_at,u.username FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.created_at DESC LIMIT 6')->fetchAll(PDO::FETCH_ASSOC);

    $donut = [
        ['label' => 'Ücretsiz', 'value' => $st['free'], 'color' => '#4de6ff'],
        ['label' => 'Premium', 'value' => $st['premium'], 'color' => '#f0c97a'],
        ['label' => 'Yasaklı', 'value' => $st['banned'], 'color' => '#fb7185'],
    ];
    $diskPct = ($srv['disk_total'] ?? 0) ? round((1 - $srv['disk_free'] / $srv['disk_total']) * 100) : null;
?>
<div class="hero-adm">
  <div>
    <div class="kicker-sm">HOŞ GELDİN</div>
    <h2>Platform bugün <em><?= $st['live'] ? $st['live'] . ' canlı yayınla' : 'sakin bir şekilde' ?></em> çalışıyor.</h2>
    <p class="small">Sağlık özeti: <b class="ok-text"><?= $snap['summary']['ok'] ?> sağlıklı</b> · <b style="color:var(--amber)"><?= $snap['summary']['warn'] ?> riskli</b> · <b style="color:var(--red)"><?= $snap['summary']['bad'] ?> sorunlu</b> yayın</p>
  </div>
  <div class="row">
    <a class="btn primary" href="<?= h(admin_url('live')) ?>">▣ Canlı merkezi aç</a>
    <a class="btn" href="<?= h(admin_url('users')) ?>">♙ Kullanıcılar</a>
  </div>
</div>

<div class="kpis">
  <?= kpi('k-cyan', '♙', 'TOPLAM KULLANICI', $st['total'], 'Bugün +' . $st['today'] . ' · 7 gün +' . $st['d7']) ?>
  <?= kpi('k-gold', '◆', 'PREMIUM AKTİF', $st['premium'], $st['expiring7'] . ' üyelik 7 gün içinde bitiyor') ?>
  <?= kpi('k-green', '▣', 'CANLI YAYIN', $st['live'], $st['live_users'] . ' yayıncı · 35 sn içinde sinyal') ?>
  <?= kpi('k-amber', '⏸', 'TAKILI OTURUM', $st['stale'], 'Aktif görünüp sinyali kesilen') ?>
  <?= kpi('k-violet', '◎', 'HERKESE AÇIK', $st['public_on'], 'Görünürlüğü açık yayıncı') ?>
  <?= kpi('k-red', '⛔', 'YASAKLI HESAP', $st['banned'], $st['blocked'] . ' engelli public yayın') ?>
  <?= kpi('k-amber', '⚑', 'AÇIK BİLDİRİM', $st['reports_open'], 'İnceleme bekleyen') ?>
  <?= kpi('k-cyan', '↺', 'SON 7 GÜN AKTİF', $st['active_u7'], '30 günde +' . $st['d30'] . ' yeni kayıt') ?>
</div>

<div class="grid2">
  <div class="panel glass">
    <div class="panel-head"><div><h2>Kayıt trendi</h2><p>Son 14 gün · yerel saat (<?= h((string)ZEM_TZ) ?>)</p></div><?= badge('+' . array_sum(array_column($signups, 'value')), 'premium') ?></div>
    <?= svg_bars($signups, ' kayıt', 'gSign') ?>
  </div>
  <div class="panel glass">
    <div class="panel-head"><div><h2>Hesap dağılımı</h2><p>Ücretsiz · premium · yasaklı</p></div></div>
    <div class="donut-wrap">
      <?= svg_donut($donut, (string)$st['total']) ?>
      <ul class="legend">
        <?php foreach ($donut as $d): ?>
          <li><i style="background:<?= h($d['color']) ?>"></i><span><?= h($d['label']) ?></span><b><?= number_format($d['value']) ?></b></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</div>

<div class="grid2">
  <div class="panel glass">
    <div class="panel-head"><div><h2>Yayın saatleri</h2><p>Son 7 gün · günlük toplam yayın süresi (saat)</p></div><?= badge(round(array_sum(array_column($bc['series'], 'value')), 1) . ' sa') ?></div>
    <?= svg_bars($bc['series'], ' sa', 'gBc') ?>
  </div>
  <div class="panel glass">
    <div class="panel-head"><div><h2>En çok yayın yapanlar</h2><p>Son 7 gün</p></div></div>
    <?php foreach ($bc['top'] as $i => $t): ?>
      <div class="rowline"><div><b>#<?= $i + 1 ?> · <?= h($t['username']) ?></b><small><?= (int)$t['n'] ?> oturum</small></div><?= badge(duration_text((int)$t['sec']), 'premium') ?></div>
    <?php endforeach; ?>
    <?php if (!$bc['top']): ?><div class="admin-empty">Son 7 günde kayıtlı yayın oturumu yok.</div><?php endif; ?>
  </div>
</div>

<div class="panel glass">
  <div class="panel-head"><div><h2>Şu an canlı</h2><p>Yayın sağlığı sunucudaki HLS dosyalarından hesaplanır</p></div><a class="btn sm" href="<?= h(admin_url('live')) ?>">Tümünü gör</a></div>
  <?php foreach (array_slice($snap['streams'], 0, 6) as $s): ?>
    <div class="rowline">
      <div><b><?= h($s['name']) ?></b><small><?= h($s['user']) ?> · <?= duration_text((int)$s['uptime']) ?> yayında</small>
        <div class="seg-strip mini"><?php foreach ($s['segments'] as $sg): ?><i class="sg sg-<?= h($sg['state']) ?>"></i><?php endforeach; ?></div></div>
      <div class="row"><?= health_badge($s['status']) ?><button type="button" class="btn sm primary" data-preview="<?= h($s['name']) ?>">▶</button></div>
    </div>
  <?php endforeach; ?>
  <?php if (!$snap['streams']): ?><div class="admin-empty">Şu anda aktif yayın yok.</div><?php endif; ?>
</div>

<div class="grid2">
  <div class="panel glass warn-list">
    <div class="panel-head"><div><h2>Bekleyen işler</h2><p>Dikkat gerektirenler</p></div></div>
    <?php if ($st['reports_open'] > 0): ?>
      <div class="rowline"><div><b><?= $st['reports_open'] ?> açık yayın bildirimi</b><small>İnceleme veya karar bekliyor.</small></div><a class="btn sm" href="<?= h(admin_url('reports')) ?>">Aç</a></div>
    <?php endif; ?>
    <?php if ($st['stale'] > 0): ?>
      <div class="rowline"><div><b><?= $st['stale'] ?> takılı oturum</b><small>Aktif görünüyor ama sinyal gelmiyor.</small></div><a class="btn sm" href="<?= h(admin_url('live')) ?>">İncele</a></div>
    <?php endif; ?>
    <?php foreach ($expiring as $ex): ?>
      <div class="rowline"><div><b><?= h($ex['username']) ?> · premium bitiyor</b><small><?= fmt_date((int)$ex['ends_at']) ?> · <?= premium_days_left(['ends_at' => $ex['ends_at']]) ?> gün kaldı</small></div>
        <a class="btn sm" href="<?= h(admin_url('users', ['q' => $ex['username']])) ?>">Kullanıcı</a></div>
    <?php endforeach; ?>
    <?php if (!$st['reports_open'] && !$st['stale'] && !$expiring): ?><div class="admin-empty">🎉 Bekleyen iş yok.</div><?php endif; ?>
  </div>
  <div class="panel glass">
    <div class="panel-head"><div><h2>Son kayıt olanlar</h2></div><a class="btn sm" href="<?= h(admin_url('users')) ?>">Tümü</a></div>
    <?php foreach ($latest as $u): ?>
      <div class="rowline">
        <div><b><?= h($u['username']) ?> <?= isset($premMap[(int)$u['id']]) ? badge('PREMIUM', 'premium') : '' ?></b><small><?= h($u['email']) ?> · <?= fmt_date((int)$u['created_at'], true) ?></small></div>
        <?= user_is_banned($u) ? badge('Yasaklı', 'banned') : badge('Aktif') ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="grid2">
  <div class="panel glass">
    <div class="panel-head"><div><h2>Sunucu durumu</h2><p>Bu sunucudan okunan anlık değerler</p></div></div>
    <div class="rowline"><div><b>Yük ortalaması</b><small>1 · 5 · 15 dk</small></div><span><?= h($srv['load'] ?? '—') ?></span></div>
    <div class="rowline"><div><b>HLS disk kullanımı</b><small><?= fmt_bytes($srv['disk_free'] !== null ? (float)$srv['disk_free'] : null) ?> boş / <?= fmt_bytes($srv['disk_total'] !== null ? (float)$srv['disk_total'] : null) ?></small></div><span><?= $diskPct !== null ? '%' . $diskPct : '—' ?></span></div>
    <?php if ($diskPct !== null): ?><div class="meter"><i style="width:<?= (int)$diskPct ?>%;background:<?= $diskPct > 90 ? 'var(--red)' : ($diskPct > 75 ? 'var(--amber)' : 'var(--green)') ?>"></i></div><?php endif; ?>
    <div class="rowline"><div><b>Veritabanı boyutu</b></div><span><?= fmt_bytes($srv['db'] !== null ? (float)$srv['db'] : null) ?></span></div>
    <div class="rowline"><div><b>PHP sürümü</b><small>Saat dilimi: <?= h((string)ZEM_TZ) ?></small></div><span><?= h($srv['php']) ?></span></div>
  </div>
  <div class="panel glass">
    <div class="panel-head"><div><h2>Son işlemler</h2></div><a class="btn sm" href="<?= h(admin_url('security')) ?>">Tümü</a></div>
    <?php foreach ($logs as $log): ?>
      <div class="log"><i></i><div><b><?= h($log['action']) ?></b><p><?= h((string)($log['details'] ?? '')) ?><?= !empty($log['username']) ? ' · ' . h($log['username']) : '' ?></p><small><?= fmt_date((int)$log['created_at'], true) ?></small></div></div>
    <?php endforeach; ?>
    <?php if (!$logs): ?><div class="admin-empty">Kayıt yok.</div><?php endif; ?>
  </div>
</div>
<?php
}

function admin_section_live(PDO $db, array $st): void
{
    $snap = admin_live_snapshot($db);
?>
<div class="kpis kpis-4">
  <?= kpi('k-green', '▣', 'CANLI YAYIN', $st['live'], 'Sinyal veren') ?>
  <?= kpi('k-green', '✓', 'SAĞLIKLI', $snap['summary']['ok'], 'Sorunsuz akış') ?>
  <?= kpi('k-amber', '!', 'KASMA RİSKİ', $snap['summary']['warn'], 'Uyarı veren') ?>
  <?= kpi('k-red', '✕', 'SORUNLU', $snap['summary']['bad'], 'Donma / kopma') ?>
</div>
<div class="panel glass">
  <div class="panel-head">
    <div><h2>Canlı yayın merkezi</h2><p>Bir yayına dokun → canlı önizleme, segment günlüğü ve kasma/donma analizi</p></div>
    <div class="row"><span class="badge" id="liveStamp">—</span><button type="button" class="btn sm" id="liveRefresh">↻ Yenile</button></div>
  </div>
  <div class="legend-row"><span><i class="sg sg-ok"></i> Sağlıklı segment</span><span><i class="sg sg-warn"></i> Uzun/küçük/gecikmeli</span><span><i class="sg sg-bad"></i> Eksik veya boş</span></div>
  <div id="liveGrid" class="live-center"></div>
  <p class="note">Analiz; sunucudaki playlist (.m3u8) yaşını, son 12 segmentin varlığını, boyutunu ve oluşma aralığını kontrol eder. Oynatıcıdaki ölçümler (tampon, düşen kare, takılma) yönetici tarayıcısından alınır. Sayfa 6 sn’de bir kendiliğinden yenilenir.</p>
</div>
<script type="application/json" id="liveData"><?= json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php
}

function admin_section_users(PDO $db): void
{
    $now      = time();
    $premMap  = premium_map($db);
    $search   = trim((string)($_GET['q'] ?? ''));
    $filter   = (string)($_GET['f'] ?? 'all');
    $order    = (string)($_GET['o'] ?? 'new');
    $pageNo   = max(1, (int)($_GET['p'] ?? 1));
    $perPage  = (int)ZEM_USERS_PER_PAGE;
    if (!in_array($filter, ['all', 'premium', 'banned', 'live'], true)) $filter = 'all';
    $orders = ['new' => ['En yeni', 'id DESC'], 'old' => ['En eski', 'id ASC'], 'login' => ['Son giriş', 'COALESCE(last_login,0) DESC'], 'name' => ['A → Z', 'username COLLATE NOCASE ASC']];
    if (!isset($orders[$order])) $order = 'new';

    $filterSql = [
        'premium' => "id IN (SELECT user_id FROM premium_subscriptions WHERE status='active' AND ends_at>=" . $now . ')',
        'banned'  => "status='banned' AND (banned_until IS NULL OR banned_until>" . $now . ')',
        'live'    => "id IN (SELECT user_id FROM stream_sessions WHERE active=1 AND site='" . ZEM_SITE_KEY . "' AND last_seen>=" . ($now - 35) . ')',
    ];
    $searchSql = '';
    $params = [];
    if ($search !== '') {
        $like = '%' . $search . '%';
        $searchSql = '(username LIKE ? OR email LIKE ? OR phone LIKE ? OR zem_stream_name LIKE ?)';
        $params = [$like, $like, $like, $like];
    }
    $countFor = static function (PDO $db, string $f) use ($filterSql, $searchSql, $params): int {
        $w = [];
        if ($searchSql !== '') $w[] = $searchSql;
        if ($f !== 'all') $w[] = $filterSql[$f];
        $q = $db->prepare('SELECT COUNT(*) FROM users' . ($w ? ' WHERE ' . implode(' AND ', $w) : ''));
        $q->execute($params);
        return (int)$q->fetchColumn();
    };
    $chipCounts = [];
    foreach (['all', 'premium', 'banned', 'live'] as $f) $chipCounts[$f] = $countFor($db, $f);

    $where = [];
    if ($searchSql !== '') $where[] = $searchSql;
    if ($filter !== 'all') $where[] = $filterSql[$filter];
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $count  = $chipCounts[$filter];
    $pages  = max(1, (int)ceil($count / $perPage));
    $pageNo = min($pageNo, $pages);
    $lq = $db->prepare('SELECT * FROM users' . $whereSql . ' ORDER BY ' . $orders[$order][1] . ' LIMIT ' . $perPage . ' OFFSET ' . (($pageNo - 1) * $perPage));
    $lq->execute($params);
    $users = $lq->fetchAll(PDO::FETCH_ASSOC);

    $sessionMap = [];
    $sq = $db->query("SELECT user_id,client_ip,started_at,last_seen,stream_name FROM stream_sessions WHERE active=1 AND site='" . ZEM_SITE_KEY . "' ORDER BY started_at DESC");
    foreach ($sq->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $sid = (int)$s['user_id'];
        if (!isset($sessionMap[$sid])) $sessionMap[$sid] = $s;
    }

    $baseQuery = ['q' => $search, 'f' => $filter, 'o' => $order];
    $return    = admin_url('users', array_merge($baseQuery, ['p' => $pageNo]));
    $chips     = ['all' => 'Tümü', 'premium' => 'Premium', 'banned' => 'Yasaklı', 'live' => 'Yayında'];
?>
<div class="panel glass">
  <div class="panel-head">
    <div><h2>Kullanıcı hesapları</h2><p>Premium, yasak, 5 slot yönetimi, anahtarlar ve canlı önizleme</p></div>
    <?= badge($count . ' sonuç') ?>
  </div>
  <form method="get" class="search">
    <input type="hidden" name="admin" value="1"><input type="hidden" name="section" value="users"><input type="hidden" name="f" value="<?= h($filter) ?>">
    <input type="search" name="q" placeholder="Kullanıcı adı, e-posta, telefon veya yayın adı" value="<?= h($search) ?>">
    <select name="o" aria-label="Sırala" style="max-width:140px">
      <?php foreach ($orders as $k => [$label]): ?><option value="<?= h($k) ?>"<?= $order === $k ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
    </select>
    <button class="btn primary" type="submit">Ara</button>
  </form>
  <div class="chips">
    <?php foreach ($chips as $key => $label): ?>
      <a class="chip<?= $filter === $key ? ' active' : '' ?>" href="<?= h(admin_url('users', ['q' => $search, 'f' => $key, 'o' => $order])) ?>"><?= h($label) ?> <b><?= $chipCounts[$key] ?></b></a>
    <?php endforeach; ?>
  </div>

  <?php foreach ($users as $u): ?>
    <?php admin_user_card($u, $premMap[(int)$u['id']] ?? null, $sessionMap[(int)$u['id']] ?? null, $return, $db); ?>
  <?php endforeach; ?>
  <?php if (!$users): ?><div class="admin-empty">Aramanla eşleşen kullanıcı bulunamadı.</div><?php endif; ?>

  <?php if ($pages > 1): ?>
  <div class="pager">
    <?php if ($pageNo > 1): ?><a class="btn sm" href="<?= h(admin_url('users', array_merge($baseQuery, ['p' => $pageNo - 1]))) ?>">← Önceki</a><?php endif; ?>
    <span>Sayfa <?= $pageNo ?> / <?= $pages ?></span>
    <?php if ($pageNo < $pages): ?><a class="btn sm" href="<?= h(admin_url('users', array_merge($baseQuery, ['p' => $pageNo + 1]))) ?>">Sonraki →</a><?php endif; ?>
  </div>
  <?php endif; ?>
  <p class="note">Not: Anahtar iptali yeni bağlantıları engeller. Halihazırda açık olan yayın ayrıca sonlandırılmadıkça devam edebilir.</p>
</div>
<?php
}

function admin_section_announcements(PDO $db): void
{
    $rows = [];
    $ready = ensure_schema($db);
    if ($ready) {
        $rows = $db->query('SELECT * FROM zem_announcements ORDER BY id DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
    }
?>
<div class="panel glass">
  <div class="panel-head"><div><h2>Yeni duyuru</h2><p>Ana sayfada ve tüm kullanıcı panellerinde görünür</p></div></div>
  <?php if (!$ready): ?>
    <div class="admin-empty">Duyuru tablosu oluşturulamadı. Veritabanı dosyasında yazma yetkisi olduğundan emin ol.</div>
  <?php else: ?>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="admin_announce_create">
    <label>Başlık</label><input name="title" maxlength="80" required placeholder="Örn. Planlı bakım">
    <label>Mesaj</label><textarea name="body" maxlength="400" required placeholder="Kullanıcılara gösterilecek kısa mesaj"></textarea>
    <div class="inline-form" style="margin-top:12px">
      <select name="level" aria-label="Tür"><option value="info">📢 Bilgi</option><option value="warn">⚠️ Uyarı</option><option value="success">🎉 Müjde</option></select>
      <select name="hours" aria-label="Süre"><option value="24">24 saat</option><option value="72">3 gün</option><option value="168" selected>7 gün</option><option value="720">30 gün</option><option value="0">Süresiz</option></select>
      <button class="btn primary" type="submit">Yayınla</button>
    </div>
  </form>
  <?php endif; ?>
</div>
<div class="panel glass">
  <div class="panel-head"><div><h2>Duyurular</h2></div><?= badge(count($rows) . ' kayıt') ?></div>
  <?php foreach ($rows as $a):
      $expired = !empty($a['expires_at']) && (int)$a['expires_at'] <= time();
      $live = (int)$a['active'] === 1 && !$expired;
  ?>
    <div class="report">
      <div class="row between"><b><?= h($a['title']) ?></b><?= $live ? badge('YAYINDA', 'live') : badge($expired ? 'SÜRESİ DOLDU' : 'KAPALI', 'warn') ?></div>
      <p><?= h($a['body']) ?></p>
      <small class="small"><?= fmt_date((int)$a['created_at'], true) ?> · <?= !empty($a['expires_at']) ? 'Bitiş: ' . fmt_date((int)$a['expires_at'], true) : 'Süresiz' ?></small>
      <div class="row" style="margin-top:10px">
        <?= post_form('admin_announce_action', ['id' => (int)$a['id'], 'operation' => 'toggle'], (int)$a['active'] === 1 ? 'Kapat' : 'Aç', 'btn sm') ?>
        <?= post_form('admin_announce_action', ['id' => (int)$a['id'], 'operation' => 'delete'], 'Sil', 'btn sm danger', 'Duyuru silinsin mi?') ?>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$rows): ?><div class="admin-empty">Henüz duyuru yok.</div><?php endif; ?>
</div>
<?php
}


/* ======================================================================
   8a-3. YÖNETİCİ: CSS + JS (yalnızca admin sayfasında yüklenir)
   ====================================================================== */
function zem_admin_css(): string
{
    return <<<'CSS'
.hero-adm{display:flex;flex-direction:column;gap:14px;justify-content:space-between;padding:22px;margin-bottom:14px;border-radius:var(--rl);border:1px solid var(--line2);
  background:radial-gradient(circle at 0 0,rgba(77,230,255,.18),transparent 55%),radial-gradient(circle at 100% 100%,rgba(168,136,255,.2),transparent 55%),var(--panel);position:relative;overflow:hidden}
.hero-adm h2{font-size:clamp(20px,4.4vw,28px);margin:4px 0 6px}
.kpis{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:14px}
.kpi{position:relative;padding:16px 14px 14px;border-radius:var(--r);border:1px solid var(--line);background:linear-gradient(160deg,rgba(13,19,39,.95),rgba(8,12,24,.95));overflow:hidden;min-width:0;transition:transform .2s,border-color .2s}
.kpi:hover{transform:translateY(-3px);border-color:var(--kc,var(--cyan))}
.kpi::after{content:"";position:absolute;right:-30px;top:-30px;width:110px;height:110px;border-radius:50%;background:radial-gradient(circle,var(--kc,var(--cyan)),transparent 70%);opacity:.16}
.kpi-ico{display:grid;place-items:center;width:34px;height:34px;border-radius:10px;margin-bottom:10px;background:rgba(255,255,255,.05);color:var(--kc,var(--cyan));font-size:16px}
.kpi span{display:block;font-size:9.5px;font-weight:800;letter-spacing:1.4px;color:var(--muted2)}
.kpi strong{display:block;font-family:var(--display);font-size:30px;line-height:1.15;margin:2px 0}
.kpi em{display:block;font-size:11.5px;color:var(--muted);-webkit-text-fill-color:var(--muted);background:none;line-height:1.4}
.k-cyan{--kc:#4de6ff}.k-gold{--kc:#f0c97a}.k-green{--kc:#4ade80}.k-amber{--kc:#fbbf24}.k-violet{--kc:#a888ff}.k-red{--kc:#fb7185}
.grid2{display:grid;gap:14px}
.glass{background:linear-gradient(160deg,rgba(15,22,44,.8),rgba(8,12,24,.85));backdrop-filter:blur(10px);box-shadow:0 20px 50px rgba(0,0,0,.28)}
.chart-svg{width:100%;height:200px;display:block}
.donut-wrap{display:flex;align-items:center;gap:20px;flex-wrap:wrap;justify-content:center}
.donut{width:160px;height:160px}
.legend{list-style:none;display:grid;gap:10px;flex:1;min-width:150px}
.legend li{display:flex;align-items:center;gap:10px;font-size:14px}.legend i{width:12px;height:12px;border-radius:4px}.legend b{margin-left:auto}
.adm-search{max-width:260px}.adm-search input{min-height:42px}
.adm-nav a span{display:inline-flex;gap:6px}
.legend-row{display:flex;gap:14px;flex-wrap:wrap;margin-bottom:12px;font-size:12px;color:var(--muted)}
.legend-row span{display:inline-flex;align-items:center;gap:6px}.legend-row .sg{width:12px;height:12px;display:inline-block}
.seg-strip{display:flex;align-items:flex-end;gap:3px;height:34px}
.seg-strip.mini{height:14px;margin-top:6px;max-width:220px}
.seg-strip.big{height:60px}
.sg{display:block;flex:1;min-width:5px;max-width:26px;height:100%;border-radius:4px;background:var(--green)}
.sg-ok{background:linear-gradient(var(--green),#16a34a)}
.sg-warn{background:linear-gradient(var(--amber),#d97706)}
.sg-bad{background:linear-gradient(var(--red),#be123c)}
.live-center{display:grid;gap:12px}
.lv{padding:16px;border-radius:var(--r);border:1px solid var(--line);background:rgba(0,0,0,.22);border-left:4px solid var(--green);transition:border-color .2s}
.lv.warn{border-left-color:var(--amber)}.lv.bad{border-left-color:var(--red)}
.lv-head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start;margin-bottom:10px}
.lv-head b{font-size:16px;overflow-wrap:anywhere}.lv-head small{display:block;color:var(--muted);font-size:12px}
.lv-metrics{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin:10px 0}
.lv-metrics div{padding:8px 10px;border-radius:10px;background:rgba(255,255,255,.03);border:1px solid var(--line);font-size:11px;color:var(--muted)}
.lv-metrics b{display:block;font-size:15px;color:var(--text);font-family:var(--display)}
.lv-issues{list-style:none;display:grid;gap:6px;margin:10px 0}
.lv-issues li{padding:8px 10px;border-radius:10px;font-size:12.5px;border:1px solid var(--line)}
.lv-issues li.warn{background:rgba(251,191,36,.07);border-color:rgba(251,191,36,.3);color:#fde68a}
.lv-issues li.bad{background:rgba(251,113,133,.07);border-color:rgba(251,113,133,.3);color:#fecdd3}
.lv-issues li.ok{background:rgba(74,222,128,.06);border-color:rgba(74,222,128,.25);color:#bbf7d0}
.lv-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.pv{position:fixed;inset:0;z-index:3000;display:grid;place-items:start center;padding:calc(var(--safe-t) + 10px) 8px 10px;background:rgba(2,4,10,.86);backdrop-filter:blur(6px);overflow-y:auto}
.pv[hidden]{display:none!important}
.pv-box{width:min(1100px,100%);padding:14px;border-radius:var(--rl);border:1px solid var(--line2);background:linear-gradient(180deg,#0b1226,#060912);box-shadow:0 40px 100px rgba(0,0,0,.7)}
.pv-head{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px}
.pv-head b{display:block;font-size:17px;font-family:var(--display);overflow-wrap:anywhere}.pv-head small{color:var(--muted);font-size:12px}
.pv-body{display:grid;gap:12px}
.pv-video{position:relative;border-radius:16px;overflow:hidden;background:#000;aspect-ratio:16/9;border:1px solid var(--line)}
.pv-video video{width:100%;height:100%;display:block;background:#000}
.pv-overlay{position:absolute;inset:0;display:grid;place-items:center;text-align:center;padding:16px;background:rgba(0,0,0,.55);color:var(--muted);font-size:14px;pointer-events:none}
.pv-overlay.hide{display:none}
.pv-stats{display:grid;grid-template-columns:repeat(2,1fr);gap:8px}
.pv-stats div{padding:9px 10px;border-radius:10px;border:1px solid var(--line);background:rgba(255,255,255,.03);font-size:11px;color:var(--muted)}
.pv-stats b{display:block;font-size:15px;color:var(--text);font-family:var(--display)}
.pv-stats .warn b{color:var(--amber)}.pv-stats .bad b{color:var(--red)}
.pv-srcs{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}
.pv-srcs .btn.on{border-color:var(--cyan);background:rgba(77,230,255,.18)}
.pv-sec{display:flex;justify-content:space-between;gap:10px;align-items:baseline;flex-wrap:wrap;margin:16px 0 8px;font-size:13px}
.pv-sec small{color:var(--muted);font-size:11.5px}
.seg-line{display:flex;align-items:flex-end;gap:3px;height:64px;padding:6px;border-radius:12px;border:1px solid var(--line);background:rgba(0,0,0,.25);overflow:hidden}
.seg-line .sg{max-width:18px}
.pv-log{list-style:none;max-height:180px;overflow-y:auto;border:1px solid var(--line);border-radius:12px;background:rgba(0,0,0,.25);font-size:12px;font-family:ui-monospace,Menlo,monospace}
.pv-log li{padding:6px 10px;border-bottom:1px solid rgba(110,180,255,.08);color:var(--muted)}
.pv-log li.warn{color:#fde68a}.pv-log li.bad{color:#fecdd3}.pv-log li.ok{color:#bbf7d0}
@media (min-width:640px){
  .kpis{grid-template-columns:repeat(4,1fr)}.kpis-4{grid-template-columns:repeat(4,1fr)}
  .hero-adm{flex-direction:row;align-items:center}
  .lv-metrics{grid-template-columns:repeat(4,1fr)}
  .pv-stats{grid-template-columns:repeat(3,1fr)}
}
@media (min-width:960px){
  .grid2{grid-template-columns:repeat(2,minmax(0,1fr))}
  .live-center{grid-template-columns:repeat(2,minmax(0,1fr))}
  .pv{place-items:center}
  .pv-body{grid-template-columns:1.6fr 1fr}
}
CSS;
}

function zem_admin_js(): string
{
    return <<<'JS'
(function(){
'use strict';
var D=document,BASE=location.pathname;
function $(s,r){return (r||D).querySelector(s)}
function $$(s,r){return [].slice.call((r||D).querySelectorAll(s))}
function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]})}
function fmtDur(sec){sec=Math.max(0,Math.round(sec));var h=Math.floor(sec/3600),m=Math.floor(sec%3600/60);return h?h+' sa '+m+' dk':(m?m+' dk':sec+' sn')}
function fmtBps(b){if(!b)return '—';return b>=1e6?(b/1e6).toFixed(1)+' Mbps':Math.round(b/1e3)+' kbps'}
function fmtB(b){if(!b)return '0 B';var u=['B','KB','MB','GB'],i=0;while(b>=1024&&i<3){b/=1024;i++}return (i?b.toFixed(1):b)+' '+u[i]}

/* ===== Canlı yayın merkezi ===== */
var grid=$('#liveGrid');
function strip(segs,cls){
  if(!segs||!segs.length)return '<div class="small">Segment bulunamadı</div>';
  var max=1;segs.forEach(function(s){if(s.size>max)max=s.size});
  return '<div class="seg-strip '+(cls||'')+'">'+segs.map(function(s){
    var h=s.size?Math.max(14,Math.round(s.size/max*100)):100;
    var tip=s.n+' · '+s.dur+' sn · '+fmtB(s.size)+(s.age!=null?' · '+s.age+' sn önce':'');
    return '<i class="sg sg-'+esc(s.state)+'" style="height:'+h+'%" title="'+esc(tip)+'"></i>';
  }).join('')+'</div>';
}
var HB={ok:['SAĞLIKLI','live'],warn:['KASMA RİSKİ','warn'],bad:['SORUNLU','banned']};
function card(s){
  var hb=HB[s.status]||HB.bad;
  var issues=(s.issues&&s.issues.length)?s.issues.map(function(i){return '<li class="'+esc(i[0])+'">'+esc(i[1])+'</li>'}).join(''):'<li class="ok">Sorun tespit edilmedi — akış düzenli.</li>';
  return '<article class="lv '+esc(s.status)+'">'
   +'<div class="lv-head"><div><b>'+esc(s.name)+'</b><small>'+esc(s.user)+' · '+esc(s.ip||'IP yok')+' · '+fmtDur(s.uptime)+' yayında</small></div><span class="badge '+hb[1]+'">'+hb[0]+'</span></div>'
   +'<div class="lv-metrics">'
   +'<div>Playlist yaşı<b>'+(s.playlist_age==null?'—':s.playlist_age+' sn')+'</b></div>'
   +'<div>Son segment<b>'+(s.last_seg_age==null?'—':s.last_seg_age+' sn önce')+'</b></div>'
   +'<div>Ort. segment<b>'+(s.avg_dur?s.avg_dur+' sn':'—')+'</b></div>'
   +'<div>Bitrate<b>'+fmtBps(s.bitrate)+'</b></div></div>'
   +strip(s.segments)
   +'<ul class="lv-issues">'+issues+'</ul>'
   +'<div class="lv-actions"><button type="button" class="btn primary" data-preview="'+esc(s.name)+'">▶ Canlı önizle</button>'
   +'<a class="btn" href="'+BASE+'?admin=1&section=users&q='+encodeURIComponent(s.user)+'">Kullanıcı</a></div></article>';
}
function render(data){
  if(!grid||!data||!data.streams)return;
  grid.innerHTML=data.streams.length?data.streams.map(card).join(''):'<div class="admin-empty">Şu anda aktif yayın yok.</div>';
  var st=$('#liveStamp');if(st)st.textContent='Güncelleme: '+new Date(data.ts*1000).toLocaleTimeString('tr-TR');
}
if(grid){
  try{render(JSON.parse($('#liveData').textContent))}catch(e){}
  var refresh=function(){
    fetch(BASE+'?admin=1&ajax=health',{credentials:'same-origin',cache:'no-store'})
      .then(function(r){return r.ok?r.json():null}).then(function(d){if(d&&d.ok)render(d)}).catch(function(){});
  };
  setInterval(function(){if(!D.hidden)refresh()},6000);
  var rb=$('#liveRefresh');if(rb)rb.addEventListener('click',refresh);
}

/* ===== Önizleme oynatıcı ===== */
var pv=$('#pv');
if(!pv)return;
var E={video:$('#pvVideo'),title:$('#pvTitle'),sub:$('#pvSub'),verdict:$('#pvVerdict'),over:$('#pvOverlay'),stats:$('#pvStats'),srcs:$('#pvSrcs'),seg:$('#pvSeg'),disk:$('#pvDisk'),log:$('#pvLog')};
var P=null,hlsLoading=null;

function loadHls(){
  if(window.Hls)return Promise.resolve();
  if(hlsLoading)return hlsLoading;
  hlsLoading=new Promise(function(res,rej){
    var s=D.createElement('script');s.src=pv.getAttribute('data-hlsjs');s.onload=res;s.onerror=function(){hlsLoading=null;rej(new Error('hls.js yüklenemedi'))};
    D.head.appendChild(s);
  });
  return hlsLoading;
}
function log(msg,lvl){
  var li=D.createElement('li');li.className=lvl||'';
  li.textContent=new Date().toLocaleTimeString('tr-TR')+' · '+msg;
  E.log.insertBefore(li,E.log.firstChild);
  while(E.log.children.length>60)E.log.removeChild(E.log.lastChild);
}
function newState(){return {hls:null,timer:null,started:false,fatal:false,stalls:0,stallStart:0,lastStall:0,frozen:0,lastT:-1,segs:[],ms:[],retry:0,info:null,url:''}}
function renderSegs(){
  E.seg.innerHTML=P.segs.length?P.segs.map(function(s){
    var h=Math.max(14,Math.min(100,Math.round(s.ratio*100)));
    return '<i class="sg sg-'+s.state+'" style="height:'+h+'%" title="'+esc('#'+s.sn+' · yükleme '+s.ms+' ms / segment '+s.dur+' sn'+(s.size?' · '+fmtB(s.size):''))+'"></i>';
  }).join(''):'<span class="small" style="padding:6px">Henüz segment yüklenmedi…</span>';
}
function addSeg(o){P.segs.push(o);if(P.segs.length>48)P.segs.shift();if(o.ms)P.ms.push(o.ms);if(P.ms.length>20)P.ms.shift();renderSegs()}

function stopPlayer(){
  if(!P)return;
  clearInterval(P.timer);
  if(P.hls){try{P.hls.destroy()}catch(e){}}
  E.video.removeAttribute('src');try{E.video.load()}catch(e){}
  P=null;
}
function setVerdict(txt,cls){E.verdict.textContent=txt;E.verdict.className='badge '+(cls||'')}

function tick(){
  var v=E.video;if(!P||!v)return;
  var cur=v.currentTime,playing=!v.paused&&!v.ended&&P.started;
  if(playing){if(Math.abs(cur-P.lastT)<0.05)P.frozen++;else P.frozen=0}
  P.lastT=cur;
  var buf=0;
  try{for(var i=0;i<v.buffered.length;i++){if(cur>=v.buffered.start(i)-0.1&&cur<=v.buffered.end(i)+0.3){buf=v.buffered.end(i)-cur;break}}}catch(e){}
  var q=v.getVideoPlaybackQuality?v.getVideoPlaybackQuality():null;
  var dropped=q?q.droppedVideoFrames:0,total=q?q.totalVideoFrames:0;
  var lvl=P.hls&&P.hls.levels&&P.hls.levels[P.hls.currentLevel];
  var lat=P.hls&&P.hls.latency?P.hls.latency:null;
  var avgMs=P.ms.length?Math.round(P.ms.reduce(function(a,b){return a+b},0)/P.ms.length):0;
  var recentStall=P.lastStall&&(Date.now()-P.lastStall)<20000;
  var v1,c1;
  if(P.fatal){v1='KOPUK';c1='banned'}
  else if(!P.started){v1='BAĞLANIYOR';c1=''}
  else if(P.frozen>=4){v1='DONMUŞ';c1='banned'}
  else if(recentStall){v1='KASIYOR';c1='warn'}
  else if(buf<0.4){v1='TAMPON DÜŞÜK';c1='warn'}
  else{v1='AKICI';c1='live'}
  setVerdict(v1,c1);
  function c(label,val,cls){return '<div class="'+(cls||'')+'">'+label+'<b>'+val+'</b></div>'}
  E.stats.innerHTML=
    c('Takılma',P.stalls,P.stalls>2?'bad':(P.stalls?'warn':''))
   +c('Donma (sn)',P.frozen,P.frozen>=4?'bad':'')
   +c('Düşen kare',dropped+(total?' / '+total:''),total&&dropped/total>0.05?'warn':'')
   +c('Tampon',buf.toFixed(1)+' sn',buf<0.4?'warn':'')
   +c('Gecikme',lat?lat.toFixed(1)+' sn':'—','')
   +c('Bitrate',lvl?fmtBps(lvl.bitrate):'—','')
   +c('Çözünürlük',v.videoWidth?v.videoWidth+'×'+v.videoHeight:'—','')
   +c('Ort. yükleme',avgMs?avgMs+' ms':'—','')
   +c('Segment',P.segs.length,'');
}

function startPlayer(url){
  stopPlayer();P=newState();P.url=url;var my=P;
  E.over.textContent='Yükleniyor…';E.over.classList.remove('hide');
  renderSegs();
  log('Kaynak: '+url);
  var v=E.video;
  loadHls().then(function(){
    if(P!==my)return;
    if(window.Hls&&Hls.isSupported()){
      var h=new Hls({liveSyncDurationCount:3,maxBufferLength:30,manifestLoadingMaxRetry:2,levelLoadingMaxRetry:2,fragLoadingMaxRetry:3});
      P.hls=h;
      h.on(Hls.Events.MANIFEST_PARSED,function(){log('Playlist okundu, oynatma başlıyor','ok');v.play().catch(function(){})});
      h.on(Hls.Events.LEVEL_SWITCHED,function(e,d){log('Kalite seviyesi: '+d.level)});
      h.on(Hls.Events.FRAG_LOADED,function(e,d){
        var f=d.frag||{},st=f.stats||{},ld=st.loading||{};
        var ms=Math.max(1,Math.round((ld.end||0)-(ld.start||0)));
        var dur=f.duration||0,ratio=dur?ms/(dur*1000):0;
        var state=ratio<0.6?'ok':(ratio<1?'warn':'bad');
        addSeg({sn:f.sn,ms:ms,dur:dur?dur.toFixed(1):'?',ratio:Math.max(0.15,ratio),state:state,size:st.total||0});
        if(state!=='ok')log('Segment #'+f.sn+' geç yüklendi ('+ms+' ms / '+dur.toFixed(1)+' sn)',state);
      });
      h.on(Hls.Events.ERROR,function(e,d){
        var det=d.details||'';
        if(det==='bufferStalledError'||det==='bufferNudgeOnStall'){P.stalls++;P.lastStall=Date.now();log('Tampon tükendi (takılma)','warn');return}
        if(/fragLoad|levelLoad|manifestLoad/.test(det)){
          addSeg({sn:(d.frag&&d.frag.sn)||'?',ms:0,dur:'?',ratio:1,state:'bad',size:0});
          log('Hata: '+det+(d.response&&d.response.code?' ('+d.response.code+')':''),d.fatal?'bad':'warn');
        }else{log('Olay: '+det,d.fatal?'bad':'warn')}
        if(d.fatal){
          if(d.type===Hls.ErrorTypes.NETWORK_ERROR&&P.retry<3){P.retry++;log('Ağ hatası, yeniden deneniyor ('+P.retry+'/3)','warn');h.startLoad()}
          else if(d.type===Hls.ErrorTypes.MEDIA_ERROR&&P.retry<3){P.retry++;log('Medya hatası, toparlanıyor','warn');h.recoverMediaError()}
          else{P.fatal=true;E.over.textContent='Yayın alınamıyor: '+det;E.over.classList.remove('hide');log('Kalıcı hata: '+det,'bad')}
        }
      });
      h.loadSource(url);h.attachMedia(v);
    }else if(v.canPlayType('application/vnd.apple.mpegurl')){
      v.src=url;v.play().catch(function(){});log('Yerel HLS (Safari) kullanılıyor');
    }else{E.over.textContent='Bu tarayıcı HLS oynatmayı desteklemiyor.'}
  }).catch(function(err){E.over.textContent=err.message;log(err.message,'bad')});
  P.timer=setInterval(tick,1000);
}

['waiting','stalled'].forEach(function(ev){E.video.addEventListener(ev,function(){
  if(!P||!P.started||E.video.seeking)return;
  if(!P.stallStart){P.stallStart=Date.now();P.stalls++;P.lastStall=Date.now();log('Oynatma durdu, veri bekleniyor','warn')}
})});
E.video.addEventListener('playing',function(){
  if(!P)return;
  if(!P.started){P.started=true;E.over.classList.add('hide');log('Oynatma başladı','ok')}
  if(P.stallStart){log('Oynatma devam etti ('+((Date.now()-P.stallStart)/1000).toFixed(1)+' sn kesinti)','warn');P.stallStart=0}
});

function srcButtons(urls,active){
  var list=[];
  if(urls.proxy)list.push(['Yerel (proxy)',urls.proxy]);
  list.push(['SRS HLS',urls.remote]);list.push(['Legacy HLS',urls.legacy]);
  E.srcs.innerHTML=list.map(function(it,i){return '<button type="button" class="btn sm'+(it[1]===active?' on':'')+'" data-src="'+esc(it[1])+'">'+esc(it[0])+'</button>'}).join('');
}
function openPv(name){
  pv.hidden=false;D.body.style.overflow='hidden';
  E.title.textContent=name;E.sub.textContent='Sağlık analizi alınıyor…';
  E.log.innerHTML='';E.stats.innerHTML='';E.seg.innerHTML='';E.disk.innerHTML='';E.srcs.innerHTML='';
  setVerdict('…','');
  fetch(BASE+'?admin=1&ajax=pinfo&name='+encodeURIComponent(name),{credentials:'same-origin',cache:'no-store'})
    .then(function(r){return r.json()}).then(function(d){
      if(!d.ok)throw new Error(d.error||'Hata');
      var h=d.health;
      E.sub.textContent=(h.found?'Sunucuda HLS bulundu ('+h.src+')':'Sunucuda HLS dosyası bulunamadı')+' · hedef segment '+(h.target||'?')+' sn';
      E.disk.innerHTML=strip(h.segments,'big');
      (h.issues||[]).forEach(function(i){log(i[1],i[0])});
      var first=d.urls.proxy||d.urls.remote;
      srcButtons(d.urls,first);
      startPlayer(first);
    }).catch(function(e){E.sub.textContent='Bilgi alınamadı';log(e.message,'bad')});
}
function closePv(){stopPlayer();pv.hidden=true;D.body.style.overflow=''}

D.addEventListener('click',function(e){
  var b=e.target.closest('[data-preview]');
  if(b){openPv(b.getAttribute('data-preview'));return}
  var s=e.target.closest('[data-src]');
  if(s&&pv.contains(s)){
    $$('[data-src]',E.srcs).forEach(function(x){x.classList.toggle('on',x===s)});
    startPlayer(s.getAttribute('data-src'));return;
  }
  if(e.target.id==='pvClose'||e.target===pv)closePv();
});
D.addEventListener('keydown',function(e){if(e.key==='Escape'&&!pv.hidden)closePv()});
})();
JS;
}
function admin_section_security(PDO $db): void
{
    $search = trim((string)($_GET['lq'] ?? ''));
    $limit  = ((int)($_GET['lim'] ?? 0) === 300) ? 300 : 100;
    $sql    = 'SELECT a.*,u.username FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id';
    $params = [];
    if ($search !== '') {
        $sql .= ' WHERE a.action LIKE ? OR a.details LIKE ? OR u.username LIKE ? OR a.ip LIKE ?';
        $like = '%' . $search . '%';
        $params = [$like, $like, $like, $like];
    }
    $sql .= ' ORDER BY a.created_at DESC LIMIT ' . $limit;
    $q = $db->prepare($sql);
    $q->execute($params);
    $logs = $q->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="panel">
  <div class="panel-head"><div><h2>Güvenlik ve işlem kayıtları</h2><p>Son <?= $limit ?> işlem</p></div><?= badge(count($logs) . ' kayıt') ?></div>
  <form method="get" class="search">
    <input type="hidden" name="admin" value="1"><input type="hidden" name="section" value="security">
    <input type="search" name="lq" placeholder="İşlem, kullanıcı, IP veya detay ara" value="<?= h($search) ?>">
    <button class="btn primary" type="submit">Ara</button>
  </form>
  <?php foreach ($logs as $log): ?>
    <div class="log"><i></i><div>
      <b><?= h($log['action']) ?></b>
      <p><?= h((string)($log['details'] ?? '')) ?><?= !empty($log['username']) ? ' · Kullanıcı: ' . h($log['username']) : '' ?></p>
      <small><?= date('d.m.Y H:i:s', (int)$log['created_at']) ?> · IP: <?= h((string)($log['ip'] ?? '-')) ?></small>
    </div></div>
  <?php endforeach; ?>
  <?php if (!$logs): ?><div class="admin-empty">Kayıt bulunamadı.</div><?php endif; ?>
  <?php if ($limit === 100 && count($logs) >= 100): ?>
    <div class="pager"><a class="btn sm" href="<?= h(admin_url('security', ['lq' => $search, 'lim' => 300])) ?>">Daha fazla göster (300)</a></div>
  <?php endif; ?>
</div>
<?php
}

function admin_section_reports(PDO $db): void
{
    $statusFilter = (string)($_GET['rs'] ?? '');
    $counts = [];
    foreach ($db->query('SELECT status,COUNT(*) AS c FROM zem_stream_reports GROUP BY status')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $counts[(string)$r['status']] = (int)$r['c'];
    }
    if ($statusFilter !== '' && !isset($counts[$statusFilter])) $statusFilter = '';

    if ($statusFilter !== '') {
        $q = $db->prepare('SELECT * FROM zem_stream_reports WHERE status=? ORDER BY created_at DESC LIMIT 200');
        $q->execute([$statusFilter]);
    } else {
        $q = $db->query('SELECT * FROM zem_stream_reports ORDER BY created_at DESC LIMIT 200');
    }
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    $blocked = array_fill_keys($db->query('SELECT public_id FROM zem_public_stream_blocks')->fetchAll(PDO::FETCH_COLUMN), true);
    $ops = ['review' => ['İncelemeye al', 'btn sm'], 'close' => ['Kapat', 'btn sm'], 'block' => ['Yalnızca bu yayını engelle', 'btn sm danger']];
?>
<div class="panel">
  <div class="panel-head"><div><h2>Yayın bildirimleri</h2><p>Bildirim yapan kişiler anonimdir. IP adresleri yalnızca bu ekranda görünür.</p></div><?= badge(count($rows) . ' kayıt') ?></div>
  <div class="chips">
    <a class="chip<?= $statusFilter === '' ? ' active' : '' ?>" href="<?= h(admin_url('reports')) ?>">Tümü <b><?= array_sum($counts) ?></b></a>
    <?php foreach ($counts as $st => $c): ?>
      <a class="chip<?= $statusFilter === $st ? ' active' : '' ?>" href="<?= h(admin_url('reports', ['rs' => $st])) ?>"><?= h($st) ?> <b><?= $c ?></b></a>
    <?php endforeach; ?>
  </div>

  <?php foreach ($rows as $r): ?>
    <article class="report">
      <div class="row between"><b><?= h($r['stream_name']) ?> · #<?= (int)$r['id'] ?></b><?= badge((string)$r['status']) ?></div>
      <p style="color:#cbd5e1"><?= h($r['reason']) ?></p>
      <p><?= h($r['details'] ?: 'Açıklama girilmemiş.') ?></p>
      <small class="small">IP: <b><?= h($r['reporter_ip']) ?></b> · Tarih: <?= date('d.m.Y H:i:s', (int)$r['created_at']) ?></small>
      <div class="row" style="margin:10px 0">
        <a class="btn sm ghost" target="_blank" rel="noopener" href="<?= h($r['player_url']) ?>">Oynatıcıyı aç</a>
        <a class="btn sm ghost" target="_blank" rel="noopener" href="<?= h($r['hls_url']) ?>">HLS adresi</a>
      </div>
      <div class="row">
        <?php foreach ($ops as $op => [$label, $class]): ?>
          <?= post_form('admin_report_action', ['report_id' => (int)$r['id'], 'operation' => $op], $label, $class,
              $op === 'block' ? 'Bu public yayın erişime kapatılacak. RTMP ve HLS dosyaları silinmeyecek. Devam edilsin mi?' : null) ?>
        <?php endforeach; ?>
        <?php if (isset($blocked[$r['public_id']])): ?>
          <?= post_form('admin_report_action', ['report_id' => (int)$r['id'], 'operation' => 'unblock'], 'Engeli kaldır', 'btn sm primary', 'Bu yayının public engeli kaldırılsın mı?') ?>
        <?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
  <?php if (!$rows): ?><div class="admin-empty">Bildirim bulunmuyor.</div><?php endif; ?>
</div>
<?php
}

/* ======================================================================
   8b. KULLANICI PANELİ
   ====================================================================== */
function page_dashboard(PDO $db, array $user): void
{
    $uid = (int)$user['id'];

    /* Yasak kontrolü (süresi dolmuşsa otomatik kaldırılır) */
    if (user_is_banned($user)) {
        unset($_SESSION['user_id']);
        flash('Hesabın yönetici tarafından yasaklanmış.', 'err');
        go(url(['page' => 'login']));
    }
    $user = lift_expired_ban($db, $user);
    unset($_SESSION['new_key'], $_SESSION['new_srt_key']);

    $streamName  = !empty($user['zem_stream_name']) ? (string)$user['zem_stream_name'] : ('u' . $uid);
    $savedKey    = decrypt_stream_key($user['zem_stream_key_enc'] ?? null);
    $savedSrt    = decrypt_stream_key($user['zem_srt_key_enc'] ?? null);
    if ($savedSrt === null) {
        $savedSrt = new_srt_stream_key($db, $uid);
    }
    $obsKey      = $savedKey !== null ? $streamName . '?key=' . $savedKey : null;
    $srtStreamId = '#!::r=live/' . $streamName . ',m=publish,key=' . $savedSrt;
    $rtmpHls     = ZEM_SRS_HLS_BASE . $streamName . '.m3u8';
    $srtHls      = ZEM_SRS_HLS_BASE . $streamName . '.m3u8';

    $prem        = premium_data($db, $uid);
    $premStreams = $prem ? premium_streams($db, $uid) : [];
    $daysLeft    = premium_days_left($prem);

    $pq = $db->prepare('SELECT enabled FROM zem_public_settings WHERE user_id=?');
    $pq->execute([$uid]);
    $publicEnabled = (int)($pq->fetchColumn() ?: 0);

    $publicNames = [$streamName];
    foreach ($premStreams as $ps) {
        if (!empty($ps['name'])) $publicNames[] = (string)$ps['name'];
    }
    $publicEntries = [];
    foreach (array_values(array_unique($publicNames)) as $pn) {
        $publicEntries[] = ['name' => $pn, 'id' => zem_public_stream_id($db, $uid, $pn)];
    }

    $liveNames = live_session_names($db, $uid);
    $history   = recent_sessions($db, $uid, 8);
    $isLive    = count($liveNames) > 0;

    $appRight = ($prem ? '<span class="pill gold">◆ PREMIUM · ' . $daysLeft . ' gün</span>' : '<span class="pill">● FREE</span>')
              . post_form('logout', [], 'Çıkış', 'btn sm');

    layout_start('Kullanıcı paneli', 'app', $appRight);
?>
<div class="dash">
  <aside class="side" data-spy>
    <div class="logo"><b>ZEM MEDYA</b><span>BROADCAST PLATFORM</span></div>
    <div class="side-cap">YAYINCI MENÜSÜ</div>
    <nav>
      <a class="active" href="#genel"><span>⌂</span>Genel bakış</a>
      <a href="#yayin"><span>◉</span>Yayın ayarları</a>
      <a href="#zem-public-live"><span>◎</span>Herkese açık yayın</a>
      <a href="#izleme"><span>▶</span>İzleme adresim</a>
      <?php if ($prem): ?><a href="#premium"><span>◆</span>Premium yayınlar</a><?php endif; ?>
      <a href="#rehber"><span>?</span>OBS rehberi</a>
      <a href="#hesap"><span>♙</span>Hesabım</a>
      <a href="#guvenlik"><span>⌑</span>Güvenlik</a>
    </nav>
    <div class="side-bottom">
      <small>HESAP TÜRÜ</small>
      <b><?= $prem ? 'Premium · ' . $daysLeft . ' gün' : 'Ücretsiz yayıncı' ?></b>
    </div>
  </aside>

  <section class="dash-main" id="genel">
    <div class="dash-top">
      <div>
        <div class="kicker-sm">YAYINCI KONTROL MERKEZİ</div>
        <h1 class="dash-title">Kontrol panelin</h1>
        <p class="small">Yayın bağlantılarını ve hesap bilgilerini buradan yönet.</p>
      </div>
      <?= $isLive ? badge('YAYINDASIN', 'live') : badge('ÇEVRİMDIŞI') ?>
    </div>

    <?= render_announcements(active_announcements($db)) ?>

    <div class="welcome">
      <div class="kicker-sm">ZEM MEDYA · YAYINCI ALANI</div>
      <h2>Hoş geldin, <?= h($user['username']) ?>.</h2>
      <p><?= $prem ? 'Premium üyeliğin aktif. 5 ayrı yayın anahtarını kullanabilirsin.' : 'Ücretsiz yayıncı hesabınla tek yayın anahtarı kullanabilirsin.' ?></p>
    </div>

    <?php if ($prem && $daysLeft <= 5): ?>
      <div class="notice"><span>⏳</span><div><b>Premium üyeliğin <?= $daysLeft <= 0 ? 'bugün' : $daysLeft . ' gün içinde' ?> bitiyor</b>Yayın slotlarını kaybetmemek için yenilemek üzere <a href="<?= h(ZEM_SUPPORT_URL) ?>" target="_blank" rel="noopener">Telegram'dan</a> iletişime geç.</div></div>
    <?php endif; ?>
    <?php if ($savedKey === null): ?>
      <div class="notice"><span>⚠</span><div><b>Ana yayın anahtarın yok</b>Yönetici tarafından iptal edilmiş olabilir. Aşağıdan yeni bir RTMP anahtarı oluşturabilirsin.</div></div>
    <?php endif; ?>

    <div class="stat-row">
      <div class="mini-stat"><span>DURUM</span><strong><?= $isLive ? '● Canlı' : '○ Kapalı' ?></strong></div>
      <div class="mini-stat"><span>GÖRÜNÜRLÜK</span><strong><?= $publicEnabled ? 'Herkese açık' : 'Gizli' ?></strong></div>
      <div class="mini-stat"><span>HESAP</span><strong><?= $prem ? 'Premium' : 'Ücretsiz' ?></strong></div>
      <div class="mini-stat"><span>YAYIN SLOTU</span><strong><?= $prem ? '5' : '1' ?></strong></div>
    </div>

    <div class="dash-grid">

      <!-- Ana yayın bağlantısı -->
      <article class="dcard" id="yayin">
        <div class="heading"><div class="ico">◉</div><div><h3>Ana OBS yayın bağlantısı</h3><p>Protokolü seç, bilgileri OBS'ye gir</p></div></div>
        <div data-tabset>
          <?= protocol_tabs() ?>
          <div data-panel="rtmp">
            <?= copy_field('RTMP sunucu adresi', ZEM_RTMP_URL) ?>
            <?= $obsKey ? copy_field('RTMP yayın anahtarı · ' . $streamName, $obsKey, true) : empty_field('RTMP yayın anahtarı', 'Henüz anahtar oluşturulmadı.') ?>
            <div class="card-actions"><?= post_form('new_key', [], '↻ RTMP anahtarını yenile', 'btn primary', 'RTMP anahtarın yenilensin mi? Eski anahtar geçersiz olacak.') ?></div>
          </div>
          <div data-panel="srt" hidden>
            <?= copy_field('SRT sunucu adresi', ZEM_SRT_URL) ?>
            <?= copy_field('SRT Stream ID', $srtStreamId, true) ?>
            <p class="help">OBS SRT ayarlarında sunucu adresini URL alanına, Stream ID bilgisini Stream ID alanına gir. Ayrı bir parola girmen gerekmez.</p>
            <div class="card-actions"><?= post_form('new_srt_key', [], '↻ SRT anahtarını yenile', 'btn danger', 'SRT anahtarın yenilensin mi? Eski SRT bağlantın geçersiz olacak.') ?></div>
          </div>
        </div>
        <p class="help">RTMP ve SRT anahtarları birbirinden bağımsızdır; birinin yenilenmesi diğerini etkilemez.</p>
      </article>

      <!-- Herkese açık yayın -->
      <article class="dcard" id="zem-public-live">
        <div class="heading"><div class="ico">◎</div><div><h3>Herkese açık canlı yayın</h3><p>Canlı dizinde ve oynatıcıda görünsün mü?</p></div></div>
        <div class="acc-row"><span>Yayın görünürlüğü</span><b class="<?= $publicEnabled ? 'ok-text' : '' ?>"><?= $publicEnabled ? '● GÖRÜNÜR' : '○ GİZLİ' ?></b></div>
        <p class="help">Gizle seçeneği OBS yayınını durdurmaz; yalnızca canlı dizinden ve herkese açık oynatıcıdan kaldırır.</p>
        <div class="card-actions">
          <?= post_form('toggle_public_stream', ['enabled' => $publicEnabled ? '0' : '1'], $publicEnabled ? 'Yayını gizle' : 'Yayını herkese göster', $publicEnabled ? 'btn danger' : 'btn primary') ?>
          <a class="btn" href="<?= h(ZEM_LIVE_URL) ?>" target="_blank" rel="noopener">Canlı dizini aç ↗</a>
        </div>
      </article>

      <!-- Paylaşım / iframe -->
      <article class="dcard wide" id="paylas">
        <div class="heading"><div class="ico">⌘</div><div><h3>Oynatıcı ve iframe kodları</h3><p>Yayınlarını sitene göm veya bağlantıyla paylaş</p></div></div>
        <?php foreach ($publicEntries as $entry):
            $playerUrl  = ZEM_LIVE_URL . 'player.php?id=' . $entry['id'];
            $iframeCode = '<iframe src="' . $playerUrl . '" width="100%" height="450" frameborder="0" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen loading="lazy"></iframe>';
        ?>
          <div class="slot">
            <div class="slot-head">
              <div class="slot-name"><?= h($entry['name']) ?></div>
              <?= in_array($entry['name'], $liveNames, true) ? badge('CANLI', 'live') : badge('Kapalı') ?>
            </div>
            <div class="viewers">Şu an izleyen: <strong data-zem-viewers="<?= h($entry['id']) ?>">0</strong> kişi</div>
            <?= copy_field('Oynatıcı bağlantısı', $playerUrl) ?>
            <?= copy_field('Iframe kodu', $iframeCode) ?>
          </div>
        <?php endforeach; ?>
      </article>

      <!-- HLS izleme adresi -->
      <article class="dcard wide" id="izleme">
        <div class="heading"><div class="ico">▶</div><div><h3>HLS izleme adresin</h3><p>Protokole göre oluşan M3U8 bağlantısı</p></div></div>
        <div data-tabset>
          <?= protocol_tabs('RTMP yayını', 'SRT yayını') ?>
          <div data-panel="rtmp"><?= copy_field('M3U8 · RTMP', $rtmpHls) ?></div>
          <div data-panel="srt" hidden><?= copy_field('M3U8 · SRT', $srtHls) ?></div>
        </div>
        <p class="note">HLS adresi, ilgili protokolden yayın gönderildiğinde ve HLS çıkışı oluştuğunda kullanılabilir.</p>
      </article>

      <?php if ($prem): ?>
      <!-- Premium slotlar -->
      <article class="dcard wide" id="premium">
        <div class="heading"><div class="ico">◆</div><div><h3>Premium · 5 yayın slotun</h3><p>Her slot için ayrı yayın adı, anahtar ve HLS adresi</p></div></div>
        <?php if ($premStreams): foreach ($premStreams as $ps): $slot = (int)$ps['slot']; ?>
          <div class="slot">
            <div class="slot-head">
              <div class="slot-name">Slot <?= $slot ?> · <?= h($ps['name']) ?></div>
              <?= in_array($ps['name'], $liveNames, true) ? badge('CANLI', 'live') : badge('PREMIUM', 'premium') ?>
            </div>

            <form class="inline-form" method="post" data-confirm="Bu slotun yayın adı güncellensin mi?" style="margin-bottom:14px">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="rename_premium_stream">
              <input type="hidden" name="slot" value="<?= $slot ?>">
              <input name="stream_name" value="<?= h($ps['name']) ?>" placeholder="yeni_ad" pattern="[a-zA-Z0-9_\-]{3,64}" aria-label="Yayın adı" required>
              <button class="btn good" type="submit">Yayın adını kaydet</button>
            </form>

            <div data-tabset>
              <?= protocol_tabs() ?>
              <div data-panel="rtmp">
                <?= copy_field('RTMP sunucu', ZEM_RTMP_URL) ?>
                <?= $ps['key'] !== null ? copy_field('RTMP anahtarı', (string)$ps['key'], true) : empty_field('RTMP anahtarı', 'Anahtar hazırlanıyor') ?>
                <?= copy_field('RTMP HLS M3U8', ZEM_SRS_HLS_BASE . $ps['name'] . '.m3u8') ?>
                <div class="card-actions"><?= post_form('renew_premium_key', ['slot' => $slot], '↻ RTMP anahtarını yenile', 'btn danger', 'Bu slotun RTMP anahtarı yenilensin mi?') ?></div>
              </div>
              <div data-panel="srt" hidden>
                <?= copy_field('SRT sunucu', ZEM_SRT_URL) ?>
                <?= copy_field('SRT Stream ID', (string)$ps['srt_stream_id'], true) ?>
                <?= copy_field('SRT HLS M3U8', (string)$ps['srt_hls']) ?>
                <p class="help">OBS SRT ayarlarında sunucu adresini URL alanına, Stream ID bilgisini Stream ID alanına gir.</p>
                <div class="card-actions"><?= post_form('renew_premium_srt_key', ['slot' => $slot], '↻ SRT anahtarını yenile', 'btn danger', 'Bu slotun SRT anahtarı yenilensin mi?') ?></div>
              </div>
            </div>
          </div>
        <?php endforeach; else: ?>
          <p class="help">Premium slotların hazırlanıyor. Kısa süre sonra burada listelenecek.</p>
        <?php endif; ?>
      </article>
      <?php else: ?>
      <article class="dcard wide" id="premium">
        <div class="heading"><div class="ico">◆</div><div><h3>Premium'a yükselt</h3><p>5 ayrı yayın + her yayın için ayrı isim</p></div></div>
        <p class="help">Premium üyelikle 5 ayrı OBS anahtarı alır ve her birinin yayın adını kendin belirlersin.</p>
        <div class="card-actions"><a class="btn primary" href="<?= h(ZEM_SELF) ?>#pricing">Premium paketleri gör →</a></div>
      </article>
      <?php endif; ?>

      <!-- Yayın geçmişi -->
      <article class="dcard" id="gecmis">
        <div class="heading"><div class="ico">⏱</div><div><h3>Son yayın oturumların</h3><p>Sunucuya kaydedilen son bağlantılar</p></div></div>
        <?php if ($history): ?>
          <div class="history">
          <?php foreach ($history as $hs):
              $started = (int)$hs['started_at'];
              $seen    = (int)($hs['last_seen'] ?? 0);
              $active  = (int)($hs['active'] ?? 0) === 1 && $seen >= time() - 35;
          ?>
            <div>
              <span><b><?= h($hs['stream_name']) ?></b><br><small><?= fmt_date($started, true) ?></small></span>
              <span><?= $active ? badge('CANLI', 'live') : '<small>' . ($seen > $started ? h(duration_text($seen - $started)) : '—') . '</small>' ?></span>
            </div>
          <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="help">Henüz kayıtlı bir yayın oturumun yok. İlk yayınını başlattığında burada görünecek.</p>
        <?php endif; ?>
      </article>

      <!-- OBS rehberi -->
      <article class="dcard" id="rehber">
        <div class="heading"><div class="ico">?</div><div><h3>OBS hızlı rehberi</h3><p>Önerilen başlangıç ayarları</p></div></div>
        <div class="guide">
          <details open><summary>1 · Bağlantıyı gir</summary>
            <ul><li>Ayarlar → Yayın → Hizmet: <b>Özel…</b></li><li>Sunucu: RTMP adresin, Yayın anahtarı: anahtar alanındaki metnin tamamı</li><li>SRT için URL ve Stream ID alanlarını kullan</li></ul></details>
          <details><summary>2 · Önerilen video</summary>
            <ul><li>Çözünürlük 1280×720 veya 1920×1080, 30 FPS</li><li>Bit hızı: 720p için 2500–4000 kbps, 1080p için 4500–6000 kbps</li><li>Anahtar kare aralığı: 2 saniye</li></ul></details>
          <details><summary>3 · Önerilen ses ve kodlayıcı</summary>
            <ul><li>Ses: AAC, 128–160 kbps, 44.1/48 kHz</li><li>Kodlayıcı: x264 veya donanım (NVENC / QuickSync)</li><li>CBR (sabit bit hızı) kullan</li></ul></details>
          <details><summary>4 · Sorun giderme</summary>
            <ul><li>Bağlanamıyorsan anahtarı yeniden kopyala; boşluk kalmasın</li><li>Kare atlıyorsa bit hızını düşür</li><li>Anahtarı yeniledikten sonra OBS'deki eskisini güncelle</li></ul></details>
        </div>
      </article>

      <!-- Hesap -->
      <article class="dcard" id="hesap">
        <div class="heading"><div class="ico">♙</div><div><h3>Hesap bilgilerim</h3><p>Yayıncı hesabına ait bilgiler</p></div></div>
        <div class="acc-row"><span>Kullanıcı adı</span><b><?= h($user['username']) ?></b></div>
        <div class="acc-row"><span>E-posta</span><b><?= h($user['email']) ?></b></div>
        <div class="acc-row"><span>Telefon</span><b><?= h($user['phone']) ?></b></div>
        <div class="acc-row"><span>Hesap durumu</span><b class="ok-text">● Aktif</b></div>
        <div class="acc-row"><span>Hesap türü</span><b><?= $prem ? 'Premium' : 'Ücretsiz' ?></b></div>
        <?php if ($prem): ?>
        <div class="acc-row"><span>Premium bitiş</span><b><?= fmt_date((int)$prem['ends_at'], true) ?></b></div>
        <?php endif; ?>
        <div class="acc-row"><span>Kayıt tarihi</span><b><?= fmt_date((int)$user['created_at']) ?></b></div>
      </article>

      <!-- Güvenlik -->
      <article class="dcard" id="guvenlik">
        <div class="heading"><div class="ico">⌑</div><div><h3>Şifreni değiştir</h3><p>Hesabını güvende tut</p></div></div>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="change_password">
          <label>Mevcut şifre</label>
          <?= password_input('current_password', 'current-password') ?>
          <label>Yeni şifre (en az 10 karakter)</label>
          <?= password_input('new_password', 'new-password', true) ?>
          <label>Yeni şifre (tekrar)</label>
          <?= password_input('confirm_password', 'new-password') ?>
          <div class="card-actions"><button class="btn primary" type="submit">Şifreyi güncelle</button></div>
        </form>
        <p class="help">Yayın anahtarlarını kimseyle paylaşma. Şüphe durumunda anahtarını hemen yenile.</p>
      </article>
    </div>
  </section>
</div>

<nav class="bottom-nav" data-spy aria-label="Panel menüsü">
  <a class="active" href="#genel"><i>⌂</i>Genel</a>
  <a href="#yayin"><i>◉</i>Yayın</a>
  <a href="#izleme"><i>▶</i>İzleme</a>
  <?php if ($prem): ?><a href="#premium"><i>◆</i>Premium</a><?php endif; ?>
  <a href="#hesap"><i>♙</i>Hesap</a>
</nav>
<?php
    layout_end('app');
}

/* ======================================================================
   8c. GENEL SAYFALAR (ana sayfa, giriş, kayıt, şifre sıfırlama)
   ====================================================================== */
function zem_policies(): array
{
    return [
        ['id' => 'terms', 'title' => 'Kullanım Şartları', 'sub' => 'Hizmetin kullanımı, hesaplar ve kullanıcı sorumlulukları', 'sections' => [
            ['1. Hizmetin kapsamı', 'ZEM Medya; kullanıcıların uyumlu yayın yazılımları üzerinden canlı yayın göndermesine, yayın akışlarının hazırlanmasına ve desteklenen oynatıcılar üzerinden izlenmesine yönelik teknik altyapı sunar. Sunulan özellikler hesap türüne ve aktif hizmet kapsamına göre değişebilir.'],
            ['2. Hesap güvenliği', 'Kullanıcı, hesap bilgilerinin ve kendisine tahsis edilen yayın anahtarlarının güvenliğinden sorumludur. Yayın anahtarları üçüncü kişilerle paylaşılmamalı; yetkisiz kullanım şüphesinde anahtar yenileme veya destek talebi için hizmet sağlayıcıyla iletişime geçilmelidir.'],
            ['3. Yasaklı kullanımlar', 'Hizmet; hukuka aykırı içerik, izinsiz ücretli veya şifreli yayın aktarımı, telif hakkı ihlali, dolandırıcılık, zararlı yazılım dağıtımı, başkalarının sistemlerine saldırı veya hizmetin güvenliğini bozma amacıyla kullanılamaz.'],
            ['4. Hizmetin sürekliliği', 'Bakım, güvenlik, teknik arıza, ağ kesintisi veya hizmet sağlayıcıdan kaynaklanan nedenlerle erişimde geçici kesintiler yaşanabilir. Kesintisiz veya belirli bir izleyici kapasitesinde hizmet garantisi, ayrıca yazılı olarak belirtilmedikçe verilmiş sayılmaz.'],
            ['5. Hesap kısıtlama ve sonlandırma', 'Kuralların ihlali veya hukuka aykırı kullanım şüphesinde yayın geçici olarak durdurulabilir, yayın anahtarı devre dışı bırakılabilir veya hesap incelemeye alınabilir. Uygulanacak işlem ihlalin niteliğine ve yürürlükteki mevzuata göre değerlendirilir.'],
            ['6. Değişiklikler', 'Hizmet özellikleri ve kullanım şartları gerektiğinde güncellenebilir. Güncel metin bu sayfada yayımlanır. Kullanıcıların değişiklikleri düzenli olarak kontrol etmesi önerilir.'],
        ]],
        ['id' => 'privacy', 'title' => 'Gizlilik Politikası', 'sub' => 'Hesap bilgileri, teknik kayıtlar ve bilgi güvenliği', 'sections' => [
            ['1. Gizlilik yaklaşımı', 'ZEM Medya, hizmetin sunulması ve güvenliğinin sağlanması için gerekli olabilecek bilgileri amaçla bağlantılı ve sınırlı şekilde işlemeyi hedefler. Gereksiz kişisel bilgi toplanmaması esastır.'],
            ['2. İşlenebilecek bilgi türleri', 'Hizmetin kullanımına bağlı olarak hesap ve iletişim bilgileri, hesap durumu, üyelik bilgileri, yayın adı ve yayın ayarları, oturum ve işlem kayıtları, IP adresi ve bağlantı güvenliğiyle ilgili teknik günlükler işlenebilir.'],
            ['3. Kullanım amaçları', 'Bilgiler; hesap oluşturma ve yönetme, yayın hizmetini sunma, teknik sorunları giderme, kötüye kullanımı önleme, güvenliği sağlama, destek taleplerini yanıtlama ve mevzuattan doğan yükümlülükleri yerine getirme amaçlarıyla kullanılabilir.'],
            ['4. Paylaşım', 'Kişisel bilgiler, hizmetin çalışması için gerekli altyapı ve teknik hizmet sağlayıcılarla sınırlı olarak veya hukuken yetkili makamların usulüne uygun talepleri doğrultusunda paylaşılabilir.'],
            ['5. Saklama ve güvenlik', 'Bilgiler, ilgili işleme amacı ve yasal yükümlülükler için gerekli süre boyunca saklanır. Yetkisiz erişim, kayıp veya değişikliğe karşı uygun teknik ve idari önlemlerin uygulanması hedeflenir. İnternet üzerinden hiçbir aktarım yöntemi mutlak güvenlik garantisi vermez.'],
            ['6. Başvuru', 'Gizlilik ve kişisel verilerle ilgili sorular için [[ZEM Medya iletişim kanalından]] başvuru yapılabilir.'],
        ]],
        ['id' => 'kvkk', 'title' => 'KVKK Aydınlatma Metni', 'sub' => '6698 sayılı Kanun kapsamındaki bilgilendirme', 'sections' => [
            ['Veri sorumlusu', 'ZEM Medya, hizmetin görünen marka ve platform adıdır. Veri sorumlusunun hukuki unvanı ve tebligat adresi, hizmeti işleten gerçek veya tüzel kişinin bilgileriyle ayrıca açıkça belirtilmelidir.'],
            ['İşlenen kişisel veriler', 'Hizmetin kullanımına göre kimlik veya hesap tanımlayıcıları, iletişim bilgileri, kullanıcı işlem kayıtları, IP ve oturum bilgileri, yayın ve üyelik tercihleri ile destek yazışmaları işlenebilir.'],
            ['İşleme amaçları ve hukuki sebepler', 'Kişisel veriler; hizmet sözleşmesinin kurulması veya ifası, hukuki yükümlülüklerin yerine getirilmesi, bir hakkın tesisi veya korunması ve ilgili şartların bulunması halinde veri sorumlusunun meşru menfaatleri kapsamında işlenebilir.'],
            ['Aktarım', 'Veriler, hizmetin yürütülmesi için gerekli teknik hizmet sağlayıcılara ve kanunen yetkili kurumlara, mevzuattaki şartlar çerçevesinde aktarılabilir.'],
            ['İlgili kişinin hakları', 'KVKK\'nın 11. maddesi kapsamındaki haklar arasında kişisel verilerin işlenip işlenmediğini öğrenme, bilgi talep etme, düzeltme veya silme şartları oluştuğunda bunları isteme, aktarıldığı üçüncü kişileri öğrenme ve kanunda sayılan diğer haklar bulunur.'],
            ['Başvuru yöntemi', 'Başvuruların hukuken geçerli biçimde alınabilmesi için veri sorumlusunun doğrulanmış kimliği, adresi ve başvuru yöntemi yayımlanmalıdır. [[ZEM Medya iletişim kanalı]] ilk iletişim noktası olarak kullanılabilir.'],
        ], 'warning' => 'Yayın öncesi tamamlanması gereken bilgi: Hizmeti işleten veri sorumlusunun tam hukuki unvanı, adresi ve resmi başvuru yöntemi bu metne eklenmelidir.'],
        ['id' => 'cookies', 'title' => 'Çerez Politikası', 'sub' => 'Oturum, tercih ve teknik çerezler', 'sections' => [
            ['Çerez nedir?', 'Çerezler, ziyaret edilen internet sitesi tarafından tarayıcıya kaydedilebilen küçük veri dosyalarıdır. Oturumun sürdürülmesi ve tercihlerin hatırlanması gibi işlevlerde kullanılabilir.'],
            ['Zorunlu çerezler', 'Giriş oturumunun sürdürülmesi, güvenlik kontrolleri ve talep edilen temel hizmetin çalışması için zorunlu teknik çerezler kullanılabilir.'],
            ['Tercih ve analiz çerezleri', 'Tercihleri hatırlama veya ziyaret istatistikleri oluşturma amaçlı çerezler kullanılıyorsa, bunların amaçları ve saklama süreleri ayrıca açıklanmalı; gerekli hallerde kullanımdan önce izin alınmalıdır.'],
            ['Kontrol', 'Kullanıcılar tarayıcı ayarlarından çerezleri görüntüleyebilir, silebilir veya engelleyebilir. Zorunlu çerezlerin engellenmesi giriş ve hesap işlevlerinin çalışmasını etkileyebilir.'],
        ], 'warning' => 'Not: Bu metin genel çerez bilgilendirmesidir. Sitede kullanılan gerçek çerez adları, sağlayıcıları ve saklama süreleri teknik envanterle doğrulanarak yayımlanmalıdır.'],
        ['id' => 'copyright', 'title' => 'Telif Hakkı ve İçerik Şikâyeti', 'sub' => 'Hak sahipleri için bildirim ve inceleme süreci', 'sections' => [
            ['Yayın hakkı', 'Kullanıcı, gönderdiği görüntü, ses, video, logo ve diğer materyaller için gerekli telif, bağlantılı hak, lisans ve izinlere sahip olduğunu veya bunları kullanmaya hukuken yetkili olduğunu kabul eder.'],
            ['Yasak içerikler', 'Ücretli veya şifreli televizyon yayınlarının, filmlerin, spor karşılaşmalarının, müziklerin ve üçüncü kişilere ait diğer korunan eserlerin izinsiz yeniden yayınlanması veya dağıtılması yasaktır.'],
            ['Şikâyette bulunma', 'Hak sahibi veya yetkili temsilcisi; ihlale konu yayın bağlantısını, ilgili içerik açıklamasını, hak sahipliğini veya temsil yetkisini gösteren bilgileri ve kendisine ulaşılabilecek iletişim bilgisini iletmelidir.'],
            ['İnceleme ve işlem', 'Bildirimler mevcut bilgiler çerçevesinde değerlendirilir. Gerekli görüldüğünde ek bilgi istenebilir, ilgili yayın geçici olarak erişime kapatılabilir veya hesap hakkında inceleme başlatılabilir.'],
            ['İletişim', 'Telif hakkı bildirimleri için [[ZEM Medya Telegram iletişim kanalı]] kullanılabilir. Bildirimde konuya "Telif hakkı bildirimi" yazılması ve ilgili bağlantının eklenmesi önerilir.'],
        ]],
        ['id' => 'broadcast', 'title' => 'Yayın ve Topluluk Kuralları', 'sub' => 'Yayıncıların uyması gereken içerik ilkeleri', 'sections' => [
            ['Yayıncı sorumluluğu', 'Yayıncı, gönderdiği içeriğin hukuka uygunluğundan ve yayınlama yetkisinden kendisi sorumludur. ZEM Medya\'ya üye olmak herhangi bir kanal, etkinlik veya eserin yayın hakkını sağlamaz.'],
            ['Güvenli kullanım', 'Başka kişilerin özel hayatını ihlal eden, kişisel verilerini izinsiz açıklayan, tehdit veya dolandırıcılık içeren ya da yürürlükteki hukuka aykırı içerikler yayınlanamaz.'],
            ['Teknik kötüye kullanım', 'Hizmetin çalışmasını engelleyen trafik, yetkisiz erişim girişimleri, güvenlik açıklarını kötüye kullanma ve yayın anahtarlarının izinsiz kullanımı yasaktır.'],
            ['İhlal bildirimi', 'Kurallara aykırı yayınlar incelenebilir. İhlalin niteliğine göre yayın durdurma, anahtar yenileme veya hesap kısıtlama işlemleri uygulanabilir.'],
        ]],
    ];
}

function zem_link_markup(string $text): string
{
    return (string)preg_replace_callback('/\[\[(.+?)\]\]/u', static function (array $m): string {
        return '<a href="' . h(ZEM_SUPPORT_URL) . '" target="_blank" rel="noopener">' . h($m[1]) . '</a>';
    }, $text);
}

function home_live_streams(PDO $db): array
{
    $out = [];
    try {
        $q = $db->prepare("
            SELECT ps.public_id, ps.stream_name, u.username, ss.last_seen
            FROM zem_public_streams ps
            INNER JOIN zem_public_settings zs ON zs.user_id = ps.user_id AND zs.enabled = 1
            INNER JOIN users u ON u.id = ps.user_id AND u.status = 'active'
            INNER JOIN stream_sessions ss
                ON ss.user_id = ps.user_id AND ss.stream_name = ps.stream_name
                AND ss.site = ? AND ss.active = 1 AND ss.last_seen >= ?
            ORDER BY ss.last_seen DESC
            LIMIT 30
        ");
        $q->execute([ZEM_SITE_KEY, time() - 35]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_file(ZEM_HLS_DIR . basename((string)$row['stream_name']) . '.m3u8')) continue;
            $out[] = $row;
        }
    } catch (Throwable $e) {
        error_log('ZEM home live: ' . $e->getMessage());
    }
    return $out;
}

function public_form_login(): void
{
?>
<div class="card form-card">
  <h1>Hesabına giriş yap</h1>
  <p>Kullanıcı ve yönetici hesapları aynı giriş alanını kullanır.</p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="login">
    <label for="identity">E-posta veya kullanıcı adı</label>
    <input id="identity" name="identity" autocomplete="username" autocapitalize="none" autocorrect="off" required>
    <label for="lpw">Şifre</label>
    <div class="pw"><input id="lpw" type="password" name="password" autocomplete="current-password" required><button type="button" data-pw-toggle>Göster</button></div>
    <br><button class="btn primary block" type="submit">Giriş yap</button>
  </form>
  <hr>
  <p class="center small"><a href="<?= h(url(['page' => 'forgot'])) ?>">Şifremi unuttum</a> · <a href="<?= h(url(['page' => 'register'])) ?>">Yeni hesap oluştur</a></p>
</div>
<?php
}

function public_form_register(): void
{
?>
<div class="card form-card">
  <div class="steps-label">ADIM 1 / 2 · HESAP BİLGİLERİ</div>
  <div class="steps-bar"><span class="on"></span><span></span></div>
  <h1>Ücretsiz yayıncı kaydı</h1>
  <p>Bilgilerini gir, ardından telefonunu doğrula.</p>
  <form method="post" autocomplete="on">
    <?= csrf_field() ?><input type="hidden" name="action" value="register">
    <label for="r-email">E-posta *</label>
    <input id="r-email" type="email" name="email" autocomplete="email" inputmode="email" autocapitalize="none" required>
    <label for="r-user">Kullanıcı adı * <span class="small">(harf, rakam, _ · 3-24)</span></label>
    <input id="r-user" name="username" minlength="3" maxlength="24" pattern="[A-Za-z0-9_]{3,24}" autocomplete="username" autocapitalize="none" required>
    <label>Şifre (en az 10 karakter) *</label>
    <?= password_input('password', 'new-password', true) ?>
    <label>Şifre tekrar *</label>
    <?= password_input('password2', 'new-password') ?>
    <label for="r-q">Gizli sorun *</label>
    <input id="r-q" name="secret_question" minlength="5" maxlength="180" placeholder="Örn. İlk okul öğretmeninin adı nedir?" required>
    <label for="r-a">Gizli sorunun cevabı *</label>
    <input id="r-a" name="secret_answer" minlength="3" maxlength="120" autocomplete="off" required>
    <p class="small" style="margin-top:8px">Şifre kurtarmada bu cevabı aynı şekilde girmen gerekecek. Cevap büyük/küçük harf duyarsız doğrulanır.</p>
    <label class="check"><input type="checkbox" name="policy" value="1" required><span>Yayın kurallarını okudum ve kabul ediyorum. Yalnızca yayınlama hakkına sahip olduğum içerikleri paylaşacağımı, telif ihlali ve kaçak yayın yapmayacağımı kabul ediyorum.</span></label>
    <button class="btn primary block" type="submit">Kayıt ol →</button>
  </form>
  <hr>
  <p class="center small">Zaten hesabın var mı? <a href="<?= h(url(['page' => 'login'])) ?>">Giriş yap</a></p>
</div>
<?php
}

function public_form_verify(): void
{
    $p   = reg_pending();
    $v   = reg_verify();
    $ttl = (int)ZEM_VERIFY_TTL;
?>
<div class="card form-card">
  <div class="steps-label">ADIM 2 / 2 · TELEFON DOĞRULAMA</div>
  <div class="steps-bar"><span class="on"></span><span class="on"></span></div>
  <h1>Telefonunu doğrula</h1>
  <p><b><?= h($p['username'] ?? '') ?></b> hesabını tamamlamak için telefon numaranı doğrula.</p>

  <?php if (!$v): ?>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="verify_send">
    <label for="v-phone">Telefon numaran</label>
    <div class="phone-row">
      <select name="country" aria-label="Ülke kodu">
        <?php foreach (zem_countries() as $c): ?>
          <option value="<?= h($c[0]) ?>"<?= $c[0] === 'TR' ? ' selected' : '' ?>><?= h($c[1] . ' ' . $c[3] . ' ' . $c[2]) ?></option>
        <?php endforeach; ?>
      </select>
      <input id="v-phone" type="tel" name="phone" inputmode="tel" autocomplete="tel-national" placeholder="5xx xxx xx xx" maxlength="16" required>
    </div>
    <p class="small" style="margin-top:8px">Numaranın başına 0 koyma. Doğrula’ya bastığında 6 haneli bir kod oluşturulur; kod <?= $ttl ?> saniye geçerlidir.</p>
    <br><button class="btn primary block" type="submit">Doğrula</button>
  </form>

  <?php else:
      $c = zem_country((string)$v['iso']) ?? ['TR', '🏳', '', '+90'];
      $remaining = max(0, (int)$v['expires'] - time());
      $expired = $remaining <= 0;
      $tries = (int)$v['tries'];
  ?>
  <label>Telefon numaran</label>
  <div class="phone-row locked">
    <select disabled aria-label="Ülke kodu"><option><?= h($c[1] . ' ' . $c[3] . ' ' . $c[2]) ?></option></select>
    <input type="tel" value="<?= h((string)$v['national']) ?>" readonly disabled aria-label="Telefon numarası">
  </div>
  <div class="lock-tag">🔒 Numara kilitlendi</div>

  <div class="code-card<?= $expired ? ' expired' : '' ?>" data-countdown="<?= $remaining ?>" data-ttl="<?= $ttl ?>">
    <?php if (!empty($v['delivered'])): ?>
      <p><b><?= h(reg_mask_phone((string)$v['phone'])) ?></b> numarasına SMS ile doğrulama kodu gönderildi.</p>
    <?php else: ?>
      <p data-expire-hide<?= $expired ? ' hidden' : '' ?>>Zemmedya Yayıncılık ile kayıt doğrulama kodunuz</p>
      <span class="code-num" data-expire-hide<?= $expired ? ' hidden' : '' ?>><?= h((string)$v['code']) ?></span>
      <p data-expire-show<?= $expired ? '' : ' hidden' ?>>⏱ Kodun süresi doldu. Yeni kod isteyebilirsin.</p>
    <?php endif; ?>
    <div class="cd-bar"><i data-cd-bar style="width:<?= $expired ? 0 : round($remaining / $ttl * 100) ?>%"></i></div>
    <div class="cd-text">Kalan süre: <b><span data-cd-num><?= $remaining ?></span> sn</b></div>
  </div>

  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="verify_confirm">
    <label for="v-code">Doğrulama kodu</label>
    <input id="v-code" class="otp" name="code" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code" placeholder="••••••" required<?= $expired ? ' disabled' : '' ?> data-expire-disable>
    <?php if ($tries > 0 && !$expired): ?><p class="small" style="margin-top:6px">Hatalı deneme: <?= $tries ?>/5</p><?php endif; ?>
    <br><button class="btn primary block" type="submit"<?= $expired ? ' disabled' : '' ?> data-expire-disable>Kaydı tamamla</button>
  </form>

  <form method="post" style="margin-top:10px" data-expire-show<?= $expired ? '' : ' hidden' ?>>
    <?= csrf_field() ?><input type="hidden" name="action" value="verify_resend">
    <button class="btn block" type="submit">↻ Yeni kod iste</button>
  </form>

  <form method="post" style="margin-top:6px">
    <?= csrf_field() ?><input type="hidden" name="action" value="verify_reset">
    <button class="btn ghost sm block" type="submit" style="margin-top:6px">Numarayı değiştir</button>
  </form>
  <?php endif; ?>

  <hr>
  <form method="post" class="center">
    <?= csrf_field() ?><input type="hidden" name="action" value="verify_cancel">
    <button class="btn ghost sm" type="submit">← Bilgileri düzenle</button>
  </form>
</div>
<?php
}

function public_form_forgot(): void
{
?>
<div class="card form-card">
  <h1>Şifremi unuttum</h1>
  <p>Kayıt sırasında belirlediğin gizli sorunun cevabıyla hesabını doğrula.</p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="forgot">
    <label for="f-email">Kayıtlı e-posta adresi</label>
    <input id="f-email" type="email" name="email" autocomplete="email" inputmode="email" autocapitalize="none" required>
    <label for="f-a">Gizli sorunun cevabı</label>
    <input id="f-a" name="secret_answer" autocomplete="off" required>
    <br><button class="btn primary block" type="submit">Hesabımı doğrula</button>
  </form>
  <p class="center small" style="margin-top:16px"><a href="<?= h(url(['page' => 'login'])) ?>">Giriş sayfasına dön</a></p>
</div>
<?php
}

function public_form_reset(): void
{
?>
<div class="card form-card">
  <h1>Yeni şifre belirle</h1>
  <p>Hesap doğrulandı. Yeni şifreni oluştur (10 dakika içinde).</p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="reset_password">
    <label>Yeni şifre (en az 10 karakter)</label>
    <?= password_input('new_password', 'new-password', true) ?>
    <br><button class="btn primary block" type="submit">Şifreyi yenile</button>
  </form>
</div>
<?php
}

function public_stats(PDO $db): array
{
    $now = time();
    $o = ['users' => 0, 'live' => 0, 'premium' => 0, 'public' => 0];
    try {
        $o['users'] = (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $q = $db->prepare('SELECT COUNT(DISTINCT stream_name) FROM stream_sessions WHERE active=1 AND site=? AND last_seen>=?');
        $q->execute([ZEM_SITE_KEY, $now - 35]);
        $o['live'] = (int)$q->fetchColumn();
        $q = $db->prepare("SELECT COUNT(DISTINCT user_id) FROM premium_subscriptions WHERE status='active' AND starts_at<=? AND ends_at>=?");
        $q->execute([$now, $now]);
        $o['premium'] = (int)$q->fetchColumn();
        $o['public'] = (int)$db->query('SELECT COUNT(*) FROM zem_public_settings WHERE enabled=1')->fetchColumn();
    } catch (Throwable $e) {
    }
    return $o;
}

function public_home(PDO $db): void
{
    $stats = public_stats($db);
    $liveStreams = home_live_streams($db);
    $features = [
        ['⌁', '01 · INGEST', 'RTMP yayın girişi', 'OBS Studio ve uyumlu yayın yazılımlarıyla kişisel yayın adresin ve anahtarın üzerinden bağlantı kur.'],
        ['⇄', '02 · PROTOCOL', 'SRT bağlantı desteği', 'Desteklenen yayın senaryolarında SRT protokolüyle düşük gecikmeli yayın aktarımı kullan.'],
        ['◉', '03 · CODEC', 'H.264 & H.265', 'Desteklenen video kodlamalarıyla OBS tarafında hazırlanan görüntüyü sunucuya gönder.'],
        ['♫', '04 · AUDIO', 'AAC ses', 'Uyumlu AAC ses akışıyla görüntü ve sesi birlikte izleyiciye ulaştır.'],
        ['▶', '05 · DELIVERY', 'HLS ve M3U8', 'Yayın akışın HLS oynatma listesi ve segmentleriyle uyumlu oynatıcılarda izlenebilir.'],
        ['▣', '06 · CONTROL', 'Yayıncı paneli', 'Kişisel yayın anahtarını, yayın adlarını ve izleme bağlantılarını hesabından yönet.'],
        ['◆', '07 · PREMIUM', '5 ayrı yayın slotu', 'Premium üyelikte beş ayrı yayın anahtarı, yayın adı ve HLS/M3U8 bağlantısı kullan.'],
        ['◎', '08 · DISCOVERY', 'Herkese açık yayınlar', 'Görünürlüğünü açtığın aktif yayınlar canlı yayın dizininde listelenebilir.'],
        ['⌕', '09 · EXPERIENCE', 'İzleyici deneyimi', 'Paylaşılabilir yayın sayfaları, web oynatımı ve yayın bazlı izleyici sayacı.'],
    ];
    $flow = [
        ['01 / CREATOR', '◉', 'Yayıncı ve OBS', 'Kamera, mikrofon veya yayın yazılımından görüntünü hazırla.'],
        ['02 / INGEST', '⌁', 'RTMP / SRT bağlantısı', 'Kişisel sunucu adresini ve yayın anahtarını OBS\'ye gir.'],
        ['03 / DELIVERY', '◇', 'ZEM yayın altyapısı', 'Gelen yayın HLS segmentleri ve oynatma listesi olarak hazırlanır.'],
        ['04 / VIEWER', '▶', 'HLS / M3U8', 'Uyumlu oynatıcı veya uygulama üzerinden yayınını izle.'],
    ];
    $steps = [
        ['01', '＋', 'Hesabını oluştur', 'Ücretsiz yayıncı hesabını aç veya premium için kaydol.'],
        ['02', '▤', 'OBS bağlantısını ayarla', 'RTMP sunucu adresini ve sana verilen yayın anahtarını OBS\'ye gir.'],
        ['03', '◉', 'Yayına hazırlan', 'HLS/M3U8 izleme adresini kullan veya premium ile 5 yayın aç.'],
    ];
    $faq = [
        ['ZEM Medya ücretsiz mi?', 'Ücretsiz yayıncı hesabı açabilirsin. Premium üyelik aylık ' . ZEM_PRICE_TEXT . '\'dir. Belirtilen fiyata KDV dahil değildir.'],
        ['Premium ne kazandırır?', '5 ayrı OBS anahtarı, her slot için ayrı yayın adı ve ayrı HLS adresi.'],
        ['5 yayın adını ayrı ayrı değiştirebilir miyim?', 'Evet. Premium panelindeki her slot için ayrı "Yayın adını kaydet" formu vardır. Değiştirdiğin ad sadece o slotu etkiler.'],
        ['OBS ile yayın yapabilir miyim?', 'Evet, RTMP veya SRT bağlantısı ve yayın anahtarı ile OBS üzerinden yayın gönderebilirsin.'],
        ['M3U8 bağlantım nerede olacak?', 'Kullanıcı panelindeki "İzleme adresim" bölümünde kişisel M3U8 adresin görünür. Premium\'da her slot için ayrı M3U8 vardır.'],
        ['Anahtarımı kaybedersem ne olur?', 'Panelinden anahtarını istediğin zaman yenileyebilirsin. Yenilenen anahtarın eskisi anında geçersiz olur.'],
    ];
?>
<?= render_announcements(active_announcements($db)) ?>
<section class="hero reveal">
  <div class="hero-inner">
    <div class="hero-copy">
      <div class="eyebrow"><i></i>ZEM MEDYA · DİJİTAL YAYINCILIK</div>
      <h1>Yayınını oluştur.<br><em><span class="rot" data-rotate='["Geleceğe","Dünyaya","Ekranlara","Herkese"]'>Geleceğe</span></em> aktar.</h1>
      <p class="lead">OBS üzerinden RTMP veya SRT bağlantısıyla yayınını gönder. ZEM Medya ile HLS/M3U8 izleme bağlantını oluştur, yayınını yönet ve izleyicilerinle paylaş. Premium ile 5 ayrı yayın slotu.</p>
      <div class="hero-actions">
        <a class="btn primary" href="<?= h(url(['page' => 'register'])) ?>">Ücretsiz yayıncı ol →</a>
        <a class="btn" href="<?= h(ZEM_SELF) ?>#pricing">Premium paketler</a>
      </div>
      <div class="points"><span>Ücretsiz hesap</span><span>OBS uyumlu RTMP / SRT</span><span>Kişisel yayın anahtarı</span><span>Premium 5 slot</span></div>
    </div>
    <div class="hero-visual" aria-hidden="true">
      <div class="ring r1"></div><div class="ring r2"></div>
      <div class="core"><b>Z<span>EM</span></b><small>GLOBAL BROADCAST</small></div>
      <div class="signal top"><div><small>TRANSMISSION</small><b>LIVE SIGNAL</b></div><span class="badge live"></span></div>
      <div class="signal bottom"><div><small>STREAM ENGINE</small><b>RTMP/SRT → HLS</b></div></div>
      <div class="wave"><?php for ($i = 0; $i < 24; $i++) echo '<span></span>'; ?></div>
    </div>
  </div>
</section>

<section class="reveal">
  <div class="stats-strip">
    <div class="sstat"><strong data-count="<?= (int)$stats['users'] ?>"><?= number_format($stats['users']) ?></strong><span>KAYITLI YAYINCI</span></div>
    <div class="sstat"><strong data-count="<?= (int)$stats['live'] ?>"><?= number_format($stats['live']) ?></strong><span>ŞU AN CANLI</span></div>
    <div class="sstat"><strong data-count="<?= (int)$stats['public'] ?>"><?= number_format($stats['public']) ?></strong><span>HERKESE AÇIK KANAL</span></div>
    <div class="sstat"><strong data-count="<?= (int)$stats['premium'] ?>"><?= number_format($stats['premium']) ?></strong><span>PREMIUM ÜYE</span></div>
  </div>
  <div class="marquee" aria-hidden="true">
    <div class="marquee-track">
      <?php for ($m = 0; $m < 2; $m++) foreach (['RTMP', 'SRT', 'HLS', 'M3U8', 'H.264', 'H.265', 'AAC', 'OBS STUDIO', 'ÇOKLU YAYIN', 'CANLI DİZİN', 'İFRAME OYNATICI'] as $w) echo '<span>' . h($w) . '</span>'; ?>
    </div>
  </div>
</section>

<section class="section reveal" id="zem-canli-tv">
  <div class="live-head">
    <div><span class="kicker">PUBLIC BROADCAST</span><h2>Şimdi <em>canlı yayında.</em></h2></div>
    <a class="btn sm" href="<?= h(ZEM_LIVE_URL) ?>">Tüm canlı yayınlar ↗</a>
  </div>
  <?php if ($liveStreams): ?>
    <div class="live-grid">
      <?php foreach ($liveStreams as $row): ?>
        <a class="live-card" href="<?= h(ZEM_LIVE_URL . 'player.php?id=' . $row['public_id']) ?>">
          <div class="live-top"><span class="live-tag"><i></i> CANLI</span><span class="live-play">▶</span></div>
          <div class="live-name"><?= h($row['stream_name']) ?></div>
          <div class="live-owner">Yayıncı: <?= h($row['username']) ?></div>
          <div class="live-viewers">◉ İzleyen: <b data-zem-public-viewers="<?= h($row['public_id']) ?>">0</b> kişi</div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="empty"><div class="ico">📡</div><b>Şu anda herkese açık canlı yayın yok.</b><p class="small">Yayıncılar görünürlüğü açıp yayına başladığında kanallar burada otomatik listelenir.</p></div>
  <?php endif; ?>
</section>

<section class="section reveal" id="zem-platform-overview">
  <div class="section-head">
    <span class="kicker">ZEM MEDYA · YAYIN TEKNOLOJİLERİ</span>
    <h2>Yayıncılığın tüm aşamaları. <em>Tek platformda.</em></h2>
    <p>OBS üzerinden yayınını gönder, ZEM Medya altyapısında HLS/M3U8 olarak hazırla ve izleyicilerinle paylaş.</p>
  </div>
  <div class="features">
    <?php foreach ($features as [$ico, $tag, $title, $text]): ?>
      <article class="feature"><div class="ico"><?= h($ico) ?></div><div class="tag"><?= h($tag) ?></div><h3><?= h($title) ?></h3><p><?= h($text) ?></p></article>
    <?php endforeach; ?>
  </div>
</section>

<section class="section reveal" id="yayin-mimarisi">
  <div class="section-head">
    <span class="kicker">FROM ENCODER TO VIEWER</span>
    <h2>Tek bağlantıdan <em>yayına.</em></h2>
    <p>Yayıncı tarafındaki OBS bağlantısından izleyicinin açtığı HLS/M3U8 adresine uzanan akış.</p>
  </div>
  <div class="flow">
    <?php foreach ($flow as [$num, $ico, $title, $text]): ?>
      <article class="flow-item"><div class="tag"><?= h($num) ?></div><div class="ico"><?= h($ico) ?></div><h3><?= h($title) ?></h3><p><?= h($text) ?></p></article>
    <?php endforeach; ?>
  </div>
  <div class="flow-caption">OBS → RTMP / SRT → ZEM MEDYA → HLS / M3U8 → İZLEYİCİ</div>
  <div class="trust"><span>YAYIN ÖZGÜRLÜĞÜ</span><span>TEKNİK ALTYAPI</span><span>TELİF HAKLARINA SAYGI</span><span>GÜVENLİ YAYINCILIK</span></div>
</section>

<section class="section reveal">
  <div class="section-head">
    <span class="kicker">BAŞLAMAK ÇOK KOLAY</span>
    <h2>Yayın akışı <em>nasıl çalışır?</em></h2>
  </div>
  <div class="steps">
    <?php foreach ($steps as [$num, $ico, $title, $text]): ?>
      <article class="step"><div class="tag"><?= h($num) ?></div><div class="ico"><?= h($ico) ?></div><h3><?= h($title) ?></h3><p><?= h($text) ?></p></article>
    <?php endforeach; ?>
  </div>
</section>

<section class="section reveal" id="karsilastirma">
  <div class="section-head">
    <span class="kicker">ÜCRETSİZ MI, PREMIUM MU?</span>
    <h2>İhtiyacına göre <em>doğru plan.</em></h2>
  </div>
  <div class="compare-wrap">
    <table class="compare">
      <thead><tr><th></th><th>Ücretsiz</th><th class="pro">Premium</th></tr></thead>
      <tbody>
        <tr><td>Yayın slotu</td><td>1</td><td><b>5</b></td></tr>
        <tr><td>RTMP ve SRT</td><td class="yes">✓</td><td class="yes">✓</td></tr>
        <tr><td>HLS / M3U8 adresi</td><td class="yes">✓</td><td class="yes">✓ (slot başına)</td></tr>
        <tr><td>Herkese açık dizin + iframe</td><td class="yes">✓</td><td class="yes">✓</td></tr>
        <tr><td>Slot yayın adını değiştirme</td><td class="no">—</td><td class="yes">✓</td></tr>
        <tr><td>Slot başına ayrı anahtar yenileme</td><td class="no">—</td><td class="yes">✓</td></tr>
        <tr><td>Fiyat</td><td><b>Ücretsiz</b></td><td><b><?= h(ZEM_PRICE_TEXT) ?> / 30 gün</b></td></tr>
      </tbody>
    </table>
  </div>
</section>

<section class="section reveal" id="pricing">
  <div class="section-head">
    <span class="kicker">ZEM MEDYA PREMIUM</span>
    <h2>Tek paket. <em>Özgür yayıncılık.</em></h2>
    <p>İhtiyacın olan yayın araçları tek aylık premium üyelikte.</p>
  </div>
  <article class="price">
    <span class="ribbon">AYLIK PREMIUM</span>
    <div class="price-name">ZEM MEDYA PREMIUM</div>
    <div class="price-tag"><strong><?= h(ZEM_PRICE_TEXT) ?></strong><small>/ 30 gün</small></div>
    <p class="small">Düzenli yayın yapan içerik üreticileri için premium yayın deneyimi.</p>
    <ul>
      <li>5 ayrı OBS yayın anahtarı</li>
      <li>Her yayın slotu için ayrı yayın adı</li>
      <li>Her slot için ayrı HLS / M3U8 adresi</li>
      <li>RTMP ve SRT desteği</li>
      <li>Premium yayıncı paneli</li>
      <li>30 gün premium erişim</li>
    </ul>
    <p class="small"><?= h(ZEM_PRICE_TEXT) ?> fiyatına KDV dahil değildir.</p>
    <a class="btn primary block" href="<?= h(ZEM_SUPPORT_URL) ?>" target="_blank" rel="noopener" style="margin-top:12px">Premium satın al →</a>
  </article>
  <p class="small center" style="margin-top:18px">Ödeme ve aktivasyon için Telegram üzerinden iletişime geç. Premium üyelikler yönetici onayıyla aktive edilir.</p>
</section>

<section class="section reveal" id="zem-policies">
  <div class="section-head">
    <span class="kicker">ZEM MEDYA · ŞEFFAFLIK VE GÜVEN</span>
    <h2>Kurallar, haklar ve <em>politikalar.</em></h2>
    <p>Platformu kullanmadan önce hizmet koşullarını, gizlilik esaslarını ve yayın sorumluluklarını inceleyebilirsin. Başlıklara dokunarak ilgili metni aç.</p>
  </div>
  <div class="policy-notice"><strong>Önemli bilgilendirme</strong>ZEM Medya bir yayın altyapısı hizmetidir. Platforma kayıt olmak veya yayın anahtarı edinmek, üçüncü kişilere ait içerikleri yayınlama lisansı vermez. Kullanıcı yalnızca gerekli hak ve izinlere sahip olduğu içerikleri göndermelidir.</div>
  <?php foreach (zem_policies() as $i => $doc): ?>
    <details class="doc" id="zem-policy-<?= h($doc['id']) ?>">
      <summary>
        <span class="doc-num"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
        <span class="doc-title"><b><?= h($doc['title']) ?></b><small><?= h($doc['sub']) ?></small></span>
        <span class="doc-plus">＋</span>
      </summary>
      <div class="doc-body">
        <?php foreach ($doc['sections'] as [$heading, $text]): ?>
          <h3><?= h($heading) ?></h3>
          <p><?= zem_link_markup(h($text)) ?></p>
        <?php endforeach; ?>
        <?php if (!empty($doc['warning'])): ?><div class="doc-warn"><?= h($doc['warning']) ?></div><?php endif; ?>
      </div>
    </details>
  <?php endforeach; ?>
  <p class="policy-updated">Politika metinleri genel bilgilendirme amacı taşır. Yayın öncesinde gerçek veri işleme envanteri, veri sorumlusu bilgileri ve kullanılan teknik servislerle uyumlu hale getirilmelidir.</p>
</section>

<section class="section reveal" id="sss">
  <div class="section-head"><span class="kicker">SIKÇA SORULAN SORULAR</span><h2>Merak edilen <em>konular.</em></h2></div>
  <div class="faq" style="max-width:820px;margin:0 auto">
    <?php foreach ($faq as [$question, $answer]): ?>
      <details><summary><?= h($question) ?></summary><p><?= h($answer) ?></p></details>
    <?php endforeach; ?>
  </div>
</section>

<section class="cta reveal">
  <h2>Kendi yayın yolculuğuna <em>bugün hazırlan.</em></h2>
  <p>Ücretsiz yayıncı hesabını oluştur veya premium paketlerle 5 ayrı yayın aç.</p>
  <div class="row">
    <a class="btn primary" href="<?= h(url(['page' => 'register'])) ?>">Ücretsiz kayıt ol →</a>
    <a class="btn" href="<?= h(ZEM_SELF) ?>#pricing">Premium paketler</a>
  </div>
</section>
<div class="m-cta"><a class="btn primary" href="<?= h(url(['page' => 'register'])) ?>">Ücretsiz başla →</a><a class="btn" href="<?= h(url(['page' => 'login'])) ?>">Giriş</a></div>
<?php
}

function page_public(PDO $db, string $page): void
{
    $resetOk = !empty($_SESSION['reset_user_id']) && time() - (int)($_SESSION['reset_verified_at'] ?? 0) <= 600;
    $titles  = ['login' => 'Giriş yap', 'register' => 'Kayıt ol', 'verify' => 'Telefon doğrulama', 'forgot' => 'Şifremi unuttum', 'reset' => 'Yeni şifre'];

    if ($page === 'verify' && !reg_pending()) {
        flash('Önce hesap bilgilerini doldurmalısın.', 'err');
        go(url(['page' => 'register']));
    }

    layout_start($titles[$page] ?? 'Ana sayfa', 'public');
    if ($page === 'login') {
        public_form_login();
    } elseif ($page === 'register') {
        public_form_register();
    } elseif ($page === 'verify') {
        public_form_verify();
    } elseif ($page === 'forgot') {
        public_form_forgot();
    } elseif ($page === 'reset' && $resetOk) {
        public_form_reset();
    } else {
        public_home($db);
    }
    layout_end('public');
}

/* ======================================================================
   8d. YÖNLENDİRİCİ (router)
   ====================================================================== */
$adminMode = isset($_GET['admin']) || $isAdminHost;
$admin     = current_admin($db);
$user      = current_user($db);

if ($adminMode) {
    if ($admin) {
        page_admin($db, $admin);
        exit;
    }
    // Yönetici oturumu yoksa yalnızca giriş/şifre sayfaları gösterilir (yönlendirme döngüsü olmaz)
    if (!in_array($page, ['login', 'register', 'verify', 'forgot', 'reset'], true)) {
        flash('Yönetici oturumu bulunamadı.', 'err');
        go(url(['page' => 'login']));
    }
    page_public($db, $page);
    exit;
}

if ($user) {
    page_dashboard($db, $user);
    exit;
}

page_public($db, $page);
