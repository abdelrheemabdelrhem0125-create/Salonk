<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

if (current_user()) {
    redirect(role_home(current_user()['role']));
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $name = trim((string)($_POST['name'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($name === '' || $phone === '' || strlen($password) < 8) {
        $error = 'أكمل البيانات وكلمة المرور لا تقل عن 8 أحرف';
    } elseif (
        $email !== '' &&
        !filter_var($email, FILTER_VALIDATE_EMAIL)
    ) {
        $error = 'البريد الإلكتروني غير صحيح';
    } else {
        try {
            $passwordHash = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            if ($passwordHash === false) {
                throw new RuntimeException(
                    'تعذر تشفير كلمة المرور'
                );
            }

            $query = db()->prepare(
                "INSERT INTO users (
                    name,
                    phone,
                    email,
                    password,
                    role
                ) VALUES (
                    ?,
                    ?,
                    ?,
                    ?,
                    'customer'
                )"
            );

            $query->execute([
                $name,
                $phone,
                $email !== '' ? $email : null,
                $passwordHash
            ]);

            $success = 'تم إنشاء الحساب. يمكنك تسجيل الدخول الآن.';

            $_POST = [];

        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $error = 'رقم الهاتف أو البريد مستخدم مسبقاً';
            } else {
                $error = 'تعذر إنشاء الحساب';
            }

        } catch (Throwable $e) {
            $error = 'تعذر إنشاء الحساب';
        }
    }
}

page_start('إنشاء حساب');
?>

<div class="auth-wrap">
    <div class="auth-card">

        <img
            class="auth-logo"
            src="/hero.png"
            alt="منصة صالونك"
        >

        <h1>حساب زبون</h1>

        <?php if ($error !== ''): ?>
            <div class="error">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="success">
                <?= e($success) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="" autocomplete="on">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e(csrf_token()) ?>"
            >

            <label for="name">الاسم</label>

            <input
                id="name"
                type="text"
                name="name"
                required
                maxlength="100"
                autocomplete="name"
                value="<?= e($_POST['name'] ?? '') ?>"
            >

            <label for="phone">الهاتف</label>

            <input
                id="phone"
                type="tel"
                name="phone"
                required
                maxlength="30"
                autocomplete="tel"
                value="<?= e($_POST['phone'] ?? '') ?>"
            >

            <label for="email">البريد (اختياري)</label>

            <input
                id="email"
                type="email"
                name="email"
                maxlength="150"
                autocomplete="email"
                value="<?= e($_POST['email'] ?? '') ?>"
            >

            <label for="password">كلمة المرور</label>

            <input
                id="password"
                type="password"
                name="password"
                minlength="8"
                maxlength="255"
                required
                autocomplete="new-password"
            >

            <button
                class="btn success-btn"
                type="submit"
            >
                إنشاء الحساب
            </button>

        </form>

        <div class="auth-links">
            <a href="/index.php">لدي حساب</a>
        </div>

    </div>
</div>

<?php page_end(); ?>