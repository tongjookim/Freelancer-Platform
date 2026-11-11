<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

logout_user();
header('Location: /admin/login.php');
exit;
