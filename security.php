<?php
declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    if (!is_string($token) || $token === '' || empty($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], $token)) {
        respond(['ok' => false, 'error' => 'رمز الحماية غير صالح أو انتهت الجلسة'], 419);
    }
}

function e(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function input_string(string $key, int $max = 255, bool $required = true): ?string
{
    $value = trim((string)($_POST[$key] ?? ''));
    if ($value === '') {
        if ($required) respond(['ok'=>false,'error'=>"الحقل {$key} مطلوب"], 422);
        return null;
    }
    if (mb_strlen($value, 'UTF-8') > $max) {
        respond(['ok'=>false,'error'=>"الحقل {$key} طويل جداً"], 422);
    }
    return $value;
}

function request_data(): array
{
    $body = file_get_contents('php://input');
    $json = [];
    if (is_string($body) && trim($body) !== '') {
        $decoded = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $json = $decoded;
        }
    }
    return array_merge($_GET, $_POST, $json);
}

function positive_id(mixed $value, string $name = 'id'): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if ($id === false) respond(['ok'=>false,'error'=>"{$name} غير صحيح"], 422);
    return (int)$id;
}

function money(mixed $value, string $name = 'السعر'): string
{
    if (!is_numeric($value)) respond(['ok'=>false,'error'=>"{$name} غير صحيح"], 422);
    $n = (float)$value;
    if ($n < 0 || $n > 999999999) respond(['ok'=>false,'error'=>"{$name} غير صحيح"], 422);
    return number_format($n, 2, '.', '');
}

function valid_date(string $date): bool
{
    $d = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date;
}

function valid_time(string $time): bool
{
    $d = DateTimeImmutable::createFromFormat('H:i', $time);
    return $d !== false && $d->format('H:i') === $time;
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}
