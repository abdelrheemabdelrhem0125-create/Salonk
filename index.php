<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

if (current_user()) {
    redirect(role_home(current_user()['role']));
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $identifier = trim((string)($_POST['identifier'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($identifier === '' || $password === '') {
        $error = 'أدخل رقم الهاتف/البريد وكلمة المرور';
    } else {
        $user = login_by_identifier($identifier, $password);

        if ($user) {
            login_user($user);
            redirect(role_home($user['role']));
        }

        $error = 'بيانات الدخول غير صحيحة';
    }
}

page_start('تسجيل الدخول');
?>

<div class="login-page">
    <div class="auth-wrap">
        <div class="auth-card">

            <div class="login-brand">
                <img class="auth-logo" src="/hero.png" alt="منصة صالونك">

                <h1>منصة صالونك</h1>

                <p>
                    إدارة الحجز والخدمات والصوالين من مكان واحد.
                </p>
            </div>

            <?php if ($error !== ''): ?>
                <div class="error">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <form method="post" action="" autocomplete="on">
                <input type="hidden" name="csrf_token"
                    value="<?= e(csrf_token()) ?>">

                <label for="identifier">
                    رقم الهاتف أو البريد الإلكتروني
                </label>

                <input id="identifier" type="text" name="identifier"
                    autocomplete="username" required maxlength="150"
                    value="<?= e($_POST['identifier'] ?? '') ?>">

                <label for="password">
                    كلمة المرور
                </label>

                <input id="password" type="password" name="password"
                    autocomplete="current-password" required>

                <button class="btn primary" type="submit">
                    تسجيل الدخول
                </button>
            </form>

            <div class="auth-links">
                <a href="/register.php">إنشاء حساب زبون</a>
                <a href="/forgot.php">طلب استعادة كلمة المرور</a>
                <a href="/home.php">الدخول كزائر</a>
            </div>

        </div>
    </div>

    <!-- أيقونات التواصل -->
    <footer class="login-footer">
        <div class="login-footer-icons">
            <a href="tel:0125377912" aria-label="اتصال">
                ☎
            </a>

            <a href="https://wa.me/249125782021"
                target="_blank" rel="noopener noreferrer"
                aria-label="واتساب">
                ◉
            </a>

            <a href="mailto:abdelrheemabdelrhem0125@gmail.com"
                aria-label="البريد الإلكتروني">
                ✉
            </a>

            <a href="https://www.facebook.com/bdalrhymbdalghfwrmhmdhmad"
                target="_blank" rel="noopener noreferrer"
                aria-label="فيسبوك">
                f
            </a>
        </div>

        <div class="login-footer-copy">
            © <?= date('Y') ?> منصة صالونك
        </div>
    </footer>
</div>

<?php page_end(); ?>