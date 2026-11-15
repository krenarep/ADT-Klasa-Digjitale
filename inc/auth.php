<?php
declare(strict_types=1);

const MAX_FAILS = 6;          // tentativa të gabuara
const LOCK_MINUTES = 5;       // bllokimi

function current_user(): ?array {
    static $u = false;
    if ($u === false) {
        $u = null;
        if (!empty($_SESSION['uid'])) {
            $u = q1('SELECT u.*, s.name AS school_name FROM users u LEFT JOIN schools s ON s.id = u.school_id WHERE u.id = ? AND u.active = 1', [$_SESSION['uid']]);
            if (!$u) {
                $_SESSION = [];
            }
        }
    }
    return $u;
}

function uid(): int {
    return (int)($_SESSION['uid'] ?? 0);
}

function require_login(): array {
    $u = current_user();
    if (!$u) {
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
            json_out(['ok' => false, 'error' => 'auth'], 401);
        }
        redirect('login.php');
    }
    return $u;
}

function require_role(string ...$roles): array {
    $u = require_login();
    if (!in_array($u['role'], $roles, true)) {
        http_response_code(403);
        layout_start('Nuk keni qasje');
        echo '<div class="card"><h2>🚫 Nuk keni qasje në këtë faqe</h2><p><a class="btn" href="' . e(url('index.php')) . '">Kthehu në fillim</a></p></div>';
        layout_end();
        exit;
    }
    return $u;
}

function home_for(string $role): string {
    return match ($role) {
        'admin' => 'admin/index.php',
        'teacher' => 'teacher/index.php',
        default => 'student/index.php',
    };
}

function is_locked(string $ident): bool {
    $since = date('Y-m-d H:i:s', time() - LOCK_MINUTES * 60);
    $fails = (int)qv('SELECT COUNT(*) FROM login_attempts WHERE ident = ? AND success = 0 AND created_at > ?', [$ident, $since]);
    $ipFails = (int)qv('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND created_at > ?', [client_ip(), $since]);
    return $fails >= MAX_FAILS || $ipFails >= MAX_FAILS * 5;
}

function log_attempt(string $ident, bool $ok): void {
    q('INSERT INTO login_attempts (ident, ip, success) VALUES (?,?,?)', [$ident, client_ip(), $ok ? 1 : 0]);
    if ($ok) {
        q('DELETE FROM login_attempts WHERE ident = ? AND success = 0', [$ident]);
    }
}

function do_login(array $user): void {
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$user['id'];
    $_SESSION['role'] = $user['role'];
    q('UPDATE users SET last_login = NOW() WHERE id = ?', [$user['id']]);
    if ($user['role'] !== 'student' && function_exists('audit')) {
        try { q('INSERT INTO audit_log (user_id, user_name, role, action, ip) VALUES (?,?,?,?,?)', [$user['id'], $user['full_name'], $user['role'], 'login', client_ip()]); } catch (Throwable) {}
    }
}

/** @return array|string user ose mesazh gabimi */
function login_password(string $username, string $password): array|string {
    $ident = 'u:' . mb_strtolower($username);
    if (is_locked($ident)) {
        return 'Shumë tentativa të gabuara. Provoni përsëri pas ' . LOCK_MINUTES . ' minutash.';
    }
    $u = q1('SELECT * FROM users WHERE username = ?', [$username]);
    if (!$u || !password_verify($password, $u['password_hash'])) {
        log_attempt($ident, false);
        return 'Emri i përdoruesit ose fjalëkalimi është i gabuar.';
    }
    if (!(int)$u['active']) {
        return 'Llogaria juaj është çaktivizuar. Kontaktoni mësuesin ose administratorin.';
    }
    log_attempt($ident, true);
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    }
    do_login($u);
    return $u;
}

function login_picture(string $classCode, int $studentId, string $seq): array|string {
    $ident = 'p:' . $studentId;
    if (is_locked($ident)) {
        return 'Shumë tentativa. Prit pak minuta dhe provo përsëri, ose kërko ndihmë nga mësuesi.';
    }
    $u = q1('SELECT u.* FROM users u
             JOIN class_students cs ON cs.student_id = u.id
             JOIN classes c ON c.id = cs.class_id
             WHERE c.class_code = ? AND c.picture_login = 1 AND u.id = ? AND u.role = \'student\'', [strtoupper($classCode), $studentId]);
    if (!$u || !$u['picture_hash'] || !password_verify($seq, $u['picture_hash'])) {
        log_attempt($ident, false);
        return 'Fotot nuk janë të sakta. Provo përsëri!';
    }
    if (!(int)$u['active']) {
        return 'Llogaria jote nuk është aktive. Fol me mësuesin.';
    }
    log_attempt($ident, true);
    do_login($u);
    return $u;
}

function logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/* ---------- Kufizimet e qasjes ---------- */
function teacher_owns_class(int $teacherId, int $classId): bool {
    return (bool)qv('SELECT 1 FROM classes WHERE id = ? AND teacher_id = ?', [$classId, $teacherId]);
}
function teacher_has_student(int $teacherId, int $studentId): bool {
    return (bool)qv('SELECT 1 FROM class_students cs JOIN classes c ON c.id = cs.class_id WHERE c.teacher_id = ? AND cs.student_id = ?', [$teacherId, $studentId]);
}
function teacher_can_use_book(array $teacher, int $bookId): bool {
    return (bool)qv('SELECT 1 FROM books WHERE id = ? AND (is_global = 1 OR uploaded_by = ? OR school_id = ?)', [$bookId, $teacher['id'], $teacher['school_id']]);
}
function student_has_book(int $studentId, int $bookId): bool {
    return (bool)qv('SELECT 1 FROM class_books cb JOIN class_students cs ON cs.class_id = cb.class_id WHERE cs.student_id = ? AND cb.book_id = ?', [$studentId, $bookId]);
}
