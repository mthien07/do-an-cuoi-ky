<?php

require_once __DIR__ . '/auth.php';

$pageTitle = isset($pageTitle) ? $pageTitle . ' - ' . APP_NAME : APP_NAME;
$activeNav = $activeNav ?? 'catalog';
$user = currentUser();
$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(baseUrl('assets/css/tokens.css')) ?>">
    <link rel="stylesheet" href="<?= e(baseUrl('assets/css/app.css')) ?>">
</head>
<body>
    <a href="#main-content" class="skip-link">Chuyển tới nội dung chính</a>

    <header class="site-header">
        <div class="header-top-strip">
            <div class="container strip-inner">
                <div class="strip-meta">
                    <span class="mono-code"><?= e(APP_CODE) ?></span>
                    <span class="strip-divider">/</span>
                    <span>ĐỒ ÁN HỌC PHẦN LẬP TRÌNH ỨNG DỤNG WEB - KHOA CNTT</span>
                </div>
                <div class="strip-meta">
                    <span>GIỜ MỞ CỬA: 07:30 ĐẾN 21:00 (THỨ 2 ĐẾN THỨ 7)</span>
                </div>
            </div>
        </div>

        <div class="container header-main">
            <a href="<?= e(baseUrl('index.php')) ?>" class="brand-identity">
                <span class="brand-call-box">VLU<br>LIB</span>
                <div class="brand-text">
                    <span class="brand-title"><?= e(APP_NAME) ?></span>
                    <span class="brand-subtitle"><?= e(APP_SUBTITLE) ?></span>
                </div>
            </a>

            <nav class="primary-nav" aria-label="Menu điều hướng">
                <a href="<?= e(baseUrl('index.php')) ?>" class="nav-item <?= $activeNav === 'catalog' ? 'is-active' : '' ?>">
                    <?= icon('search', 16) ?>
                    <span>Trang chủ (Tìm sách)</span>
                </a>

                <?php if ($user && $user['role'] === 'reader'): ?>
                    <a href="<?= e(baseUrl('my-loans.php')) ?>" class="nav-item <?= $activeNav === 'my-loans' ? 'is-active' : '' ?>">
                        <?= icon('receipt', 16) ?>
                        <span>Sách đang mượn</span>
                    </a>
                <?php endif; ?>

                <?php if ($user && $user['role'] === 'librarian'): ?>
                    <a href="<?= e(baseUrl('librarian/dashboard.php')) ?>" class="nav-item <?= $activeNav === 'dashboard' ? 'is-active' : '' ?>">
                        <?= icon('grid', 16) ?>
                        <span>Thống kê</span>
                    </a>
                    <a href="<?= e(baseUrl('librarian/loans.php')) ?>" class="nav-item <?= $activeNav === 'loans' ? 'is-active' : '' ?>">
                        <?= icon('receipt', 16) ?>
                        <span>Quản lý Mượn - Trả</span>
                    </a>
                    <a href="<?= e(baseUrl('librarian/books.php')) ?>" class="nav-item <?= $activeNav === 'books' ? 'is-active' : '' ?>">
                        <?= icon('book', 16) ?>
                        <span>Quản lý Sách</span>
                    </a>
                    <a href="<?= e(baseUrl('librarian/categories.php')) ?>" class="nav-item <?= $activeNav === 'categories' ? 'is-active' : '' ?>">
                        <?= icon('archive', 16) ?>
                        <span>Quản lý Thể loại</span>
                    </a>
                    <a href="<?= e(baseUrl('librarian/readers.php')) ?>" class="nav-item <?= $activeNav === 'readers' ? 'is-active' : '' ?>">
                        <?= icon('users', 16) ?>
                        <span>Quản lý Độc giả</span>
                    </a>
                <?php endif; ?>
            </nav>

            <div class="header-user-zone">
                <?php if ($user): ?>
                    <div class="member-badge">
                        <span class="member-code mono-code"><?= e($user['member_code']) ?></span>
                        <div class="member-info">
                            <span class="member-name"><?= e($user['full_name']) ?></span>
                            <span class="member-role"><?= $user['role'] === 'librarian' ? 'Thủ thư' : 'Sinh viên' ?></span>
                        </div>
                    </div>
                    <a href="<?= e(baseUrl('logout.php')) ?>" class="btn btn-ghost btn-sm" title="Đăng xuất">
                        <?= icon('logout', 16) ?>
                        <span>Thoát</span>
                    </a>
                <?php else: ?>
                    <a href="<?= e(baseUrl('login.php')) ?>" class="btn btn-secondary btn-sm">
                        <?= icon('user', 16) ?>
                        <span>Đăng nhập</span>
                    </a>
                    <a href="<?= e(baseUrl('register.php')) ?>" class="btn btn-primary btn-sm">
                        <span>Đăng ký thẻ</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main id="main-content" class="site-main">
        <?php if ($flash): ?>
            <div class="container flash-container">
                <div class="flash-banner flash-<?= e($flash['type']) ?>" role="alert" aria-live="polite">
                    <?= icon($flash['type'] === 'error' ? 'alert' : 'check', 18) ?>
                    <span><?= e($flash['message']) ?></span>
                </div>
            </div>
        <?php endif; ?>
