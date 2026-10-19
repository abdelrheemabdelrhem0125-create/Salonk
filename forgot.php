<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$msg = 'إذا كان الحساب موجوداً فسيتم تسجيل طلب الاستعادة للمراجعة من مدير النظام.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $identifier = trim((string)($_POST['identifier'] ?? ''));

    if ($identifier !== '') {
        $query = db()->prepare(
            "SELECT id
             FROM users
             WHERE deleted_at IS NULL
               AND (phone = ? OR email = ?)
             LIMIT 1"
        );

        $query->execute([
            $identifier,
            $identifier
        ]);

        $userId = $query->fetchColumn();

        if ($userId) {
            $insert = db()->prepare(
                "INSERT INTO password_reset_requests (user_id)
                 VALUES (?)"
            );

            $insert->execute([
                (int)$userId
            ]);
        }
    }
}

page_start('استعادة كلمة المرور');
?>

<div class="auth-wrap">
    <div class="auth-card">

        <h1>استعادة كلمة المرور</h1>

        <p>
            <?= e($msg) ?>
        </p>

        <form method="post" action="">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e(csrf_token()) ?>"
            >

            <label for="identifier">
                رقم الهاتف أو البريد الإلكتروني
            </label>

            <input
                id="identifier"
                type="text"
                name="identifier"
                required
                maxlength="150"
                autocomplete="username"
            >

            <button
                class="btn primary"
                type="submit"
            >
                إرسال الطلب
            </button>

        </form>

        <div class="auth-links">
            <a href="/index.php">
                العودة للدخول
            </a>
        </div>

    </div>
</div>

<?php page_end(); ?>