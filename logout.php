<?php

require_once __DIR__ . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

unset($_SESSION['user']);
session_regenerate_id(true);

setFlash('info', 'Bạn đã đăng xuất khỏi hệ thống thư viện.');
header('Location: ' . baseUrl('login.php'));
exit;
