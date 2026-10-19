<?php
declare(strict_types=1);

function current_user(): ?array
{
    static $loaded = false;
    static $user = null;
    if ($loaded) return $user;
    $loaded = true;

    if (empty($_SESSION['user_id'])) return null;

    $stmt = db()->prepare("SELECT id,name,phone,email,password,role,salon_id,is_available,rating,location,must_change_password,deleted_at FROM users WHERE id=? LIMIT 1");
    $stmt->execute([(int)$_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;

    if (!$user || $user['deleted_at'] !== null) {
        logout_user(false);
        return null;
    }
    return $user;
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['name'] = $user['name'];
    $_SESSION['salon_id'] = $user['salon_id'] !== null ? (int)$user['salon_id'] : null;
    unset($_SESSION['csrf_token']);
    csrf_token();
}

function logout_user(bool $redirect = true): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time()-42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    if ($redirect) redirect('/index.php');
}

function require_login(): array
{
    $u = current_user();
    if (!$u) redirect('/index.php');
    return $u;
}

function require_role(array $roles): array
{
    $u = require_login();
    if (!in_array($u['role'], $roles, true)) {
        http_response_code(403);
        exit('غير مصرح لك');
    }
    return $u;
}

function require_api_role(array $roles): array
{
    $u = current_user();
    if (!$u) respond(['ok'=>false,'error'=>'غير مسجل الدخول'],401);
    if (!in_array($u['role'], $roles, true)) respond(['ok'=>false,'error'=>'غير مصرح لك'],403);
    return $u;
}

function login_by_identifier(string $identifier, string $password): ?array
{
    $stmt = db()->prepare("SELECT * FROM users WHERE deleted_at IS NULL AND (phone=? OR email=?) LIMIT 5");
    $stmt->execute([$identifier, $identifier]);
    foreach ($stmt->fetchAll() as $user) {
        if (password_verify($password, $user['password'])) return $user;
    }
    return null;
}

function role_home(string $role): string
{
    return match ($role) {
        'super_admin', 'admin' => '/admin/index.php',
        'salon_admin' => '/salon/index.php',
        'barber' => '/barber/index.php',
        default => '/home.php',
    };
}
