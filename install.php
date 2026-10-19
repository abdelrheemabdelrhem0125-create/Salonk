<?php
declare(strict_types=1);
$config = require __DIR__.'/config/config.php';
$msg='';$err='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    $host=trim((string)($_POST['host']??$config['db']['host']));
    $port=(int)($_POST['port']??3306);
    $dbUser=trim((string)($_POST['db_user']??'root'));
    $dbPass=(string)($_POST['db_pass']??'');
    $appUser=trim((string)($_POST['app_user']??'salon_user'));
    $appPass=(string)($_POST['app_pass']??'');
    $adminName=trim((string)($_POST['admin_name']??''));
    $adminPhone=trim((string)($_POST['admin_phone']??''));
    $adminPass=(string)($_POST['admin_password']??'');
    try{
        $server=new PDO("mysql:host={$host};port={$port};charset=utf8mb4",$dbUser,$dbPass,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]);
        $server->exec("CREATE DATABASE IF NOT EXISTS `salon-db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        if($appUser!=='' && strlen($appPass)>=12){
            $quotedUser=$server->quote($appUser);$quotedPass=$server->quote($appPass);
            foreach (['localhost','127.0.0.1'] as $dbHost) {
                $server->exec("CREATE USER IF NOT EXISTS {$quotedUser}@'{$dbHost}' IDENTIFIED BY {$quotedPass}");
                $server->exec("ALTER USER {$quotedUser}@'{$dbHost}' IDENTIFIED BY {$quotedPass}");
                $server->exec("GRANT SELECT,INSERT,UPDATE,DELETE ON `salon-db`.* TO {$quotedUser}@'{$dbHost}'");
            }
            $server->exec("FLUSH PRIVILEGES");
        }
        $schema=file_get_contents(__DIR__.'/database/schema.sql');
        $server->exec($schema);
        $finalUser=$appUser!==''?$appUser:$dbUser;
        $finalPass=$appUser!==''?$appPass:$dbPass;
        $local="<?php\nreturn ".var_export(['db'=>['host'=>$host,'port'=>(string)$port,'name'=>'salon-db','user'=>$finalUser,'pass'=>$finalPass,'charset'=>'utf8mb4']],true).";\n";
        file_put_contents(__DIR__.'/config/local.php',$local,LOCK_EX);
        if($adminName===''||$adminPhone===''||strlen($adminPass)<8) throw new RuntimeException('بيانات المدير غير صحيحة');
        $pdo=new PDO("mysql:host={$host};port={$port};dbname=salon-db;charset=utf8mb4",$finalUser,$finalPass,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false
        ]);
        $hash=password_hash($adminPass,PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO users(name,phone,password,role) VALUES(?,?,?,'super_admin')")->execute([$adminName,$adminPhone,$hash]);
        $msg='تم تثبيت قاعدة البيانات وإنشاء المدير. احذف install.php الآن.';
    }catch(Throwable $e){$err='فشل التثبيت: '.htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8');}
}
?>
<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>تثبيت منصة صالونك</title><link rel="stylesheet" href="/assets/css/app.css"></head><body>
<div class="auth-wrap"><div class="auth-card"><h1>تثبيت منصة صالونك</h1><p>استخدم حساب MySQL إداري لإنشاء قاعدة البيانات والمستخدم الخاص بالتطبيق.</p>
<?php if($msg):?><div class="success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<?php if($err):?><div class="error"><?=htmlspecialchars($err)?></div><?php endif;?>
<form method="post">
<label>خادم MySQL</label><input name="host" value="127.0.0.1" required>
<label>المنفذ</label><input name="port" value="3306" required>
<label>مستخدم MySQL الإداري</label><input name="db_user" value="root" required>
<label>كلمة مرور MySQL</label><input type="password" name="db_pass">
<hr>
<label>مستخدم التطبيق</label><input name="app_user" value="salon_user">
<label>كلمة مرور التطبيق (12 حرفاً على الأقل)</label><input type="password" name="app_pass" minlength="12">
<hr>
<label>اسم أول مدير</label><input name="admin_name" required>
<label>هاتف المدير</label><input name="admin_phone" required>
<label>كلمة مرور المدير (8 أحرف على الأقل)</label><input type="password" name="admin_password" minlength="8" required>
<button class="btn primary">تثبيت</button></form></div></div></body></html>