<?php

if (is_file(__DIR__ . '/env.php')) {
    require_once __DIR__ . '/env.php';
}

if (!defined('APP_NAME')) {
    define('APP_NAME', 'THƯ VIỆN KHOA CNTT - VLU');
}
if (!defined('APP_SUBTITLE')) {
    define('APP_SUBTITLE', 'Website Quản lý Mượn - Trả Sách Thư viện');
}
if (!defined('APP_CODE')) {
    define('APP_CODE', '71ITSE30503');
}

if (!defined('DB_HOST')) {
    define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
}
if (!defined('DB_PORT')) {
    define('DB_PORT', getenv('DB_PORT') ?: '3306');
}
if (!defined('DB_NAME')) {
    define('DB_NAME', getenv('DB_NAME') ?: 'do_an_thu_vien');
}
if (!defined('DB_USER')) {
    define('DB_USER', getenv('DB_USER') ?: 'root');
}
if (!defined('DB_PASS')) {
    define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
}

if (!defined('DEFAULT_BORROW_DAYS')) {
    define('DEFAULT_BORROW_DAYS', 14);
}
if (!defined('MAX_BOOKS_PER_LOAN')) {
    define('MAX_BOOKS_PER_LOAN', 3);
}
if (!defined('MAX_ACTIVE_LOANS_PER_READER')) {
    define('MAX_ACTIVE_LOANS_PER_READER', 5);
}
if (!defined('FINE_PER_DAY_VND')) {
    define('FINE_PER_DAY_VND', 5000);
}
if (!defined('DAMAGED_BOOK_FINE_VND')) {
    define('DAMAGED_BOOK_FINE_VND', 50000);
}
if (!defined('LOST_BOOK_FINE_VND')) {
    define('LOST_BOOK_FINE_VND', 200000);
}
