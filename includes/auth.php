<?php

require_once __DIR__ . '/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function currentUser()
{
    return $_SESSION['user'] ?? null;
}

function isLoggedIn()
{
    return !empty($_SESSION['user']);
}

function isLibrarian()
{
    return isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'librarian';
}

function isReader()
{
    return isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'reader';
}

function requireLogin()
{
    if (!isLoggedIn()) {
        setFlash('error', 'Vui lòng đăng nhập tài khoản để tiếp tục.');
        header('Location: ' . baseUrl('login.php'));
        exit;
    }
}

function requireLibrarian()
{
    requireLogin();
    if (!isLibrarian()) {
        setFlash('error', 'Trang này chỉ dành cho Thủ thư quản lý.');
        header('Location: ' . baseUrl('index.php'));
        exit;
    }
}
