<?php
declare(strict_types=1);
require __DIR__.'/lib.php';
setcookie('fb_session', '', ['expires'=>time()-3600,'path'=>'/','httponly'=>true,'samesite'=>'Lax','secure'=>is_https()]);
header('Location: /');
exit;
