<?php

require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    header('Location: ' . baseUrl('index.php'));
    exit;
}

$pdo = getDB();
$errorMsg = '';

$fullName = '';
$email = '';
$phone = '';
$department = 'Khoa Công nghệ Thông tin';

function generateNextMemberCode($pdo)
{
    $stmt = $pdo->query("SELECT member_code FROM users WHERE member_code LIKE 'SV2026%' ORDER BY member_code DESC LIMIT 1");
    $lastCode = (string)$stmt->fetchColumn();
    if (preg_match('/^SV2026(\d+)$/', $lastCode, $m)) {
        $nextNum = (int)$m[1] + 1;
    } else {
        $nextNum = 1;
    }
    return 'SV2026' . str_pad((string)$nextNum, 2, '0', STR_PAD_LEFT);
}

$previewCode = generateNextMemberCode($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $errorMsg = 'Phiên làm việc không hợp lệ. Vui lòng thử lại.';
    } else {
        $fullName   = trim((string)($_POST['full_name'] ?? ''));
        $email      = trim((string)($_POST['email'] ?? ''));
        $phone      = trim((string)($_POST['phone'] ?? ''));
        $department = trim((string)($_POST['department'] ?? ''));
        $password   = (string)($_POST['password'] ?? '');
        $confirm    = (string)($_POST['password_confirm'] ?? '');

        if ($fullName === '' || $email === '' || $password === '') {
            $errorMsg = 'Vui lòng điền đầy đủ Họ tên, Email và Mật khẩu.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errorMsg = 'Địa chỉ Email không đúng định dạng.';
        } elseif (mb_strlen($password) < 6) {
            $errorMsg = 'Mật khẩu phải có ít nhất 6 ký tự.';
        } elseif ($password !== $confirm) {
            $errorMsg = 'Mật khẩu nhập lại không khớp.';
        } else {

            $chk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = :email");
            $chk->execute(['email' => $email]);
            if ((int)$chk->fetchColumn() > 0) {
                $errorMsg = 'Địa chỉ Email này đã được dùng để đăng ký thẻ trước đó.';
            } else {
                $memberCode = generateNextMemberCode($pdo);
                $hash = password_hash($password, PASSWORD_DEFAULT);

                $ins = $pdo->prepare("
                    INSERT INTO users (member_code, full_name, email, phone, department, password_hash, role, status)
                    VALUES (:code, :name, :email, :phone, :dept, :hash, 'reader', 'active')
                ");
                $ins->execute([
                    'code'  => $memberCode,
                    'name'  => $fullName,
                    'email' => $email,
                    'phone' => $phone !== '' ? $phone : null,
                    'dept'  => $department !== '' ? $department : 'Khoa Công nghệ Thông tin',
                    'hash'  => $hash,
                ]);

                $newUserId = (int)$pdo->lastInsertId();

                session_regenerate_id(true);
                $_SESSION['user'] = [
                    'id'          => $newUserId,
                    'member_code' => $memberCode,
                    'full_name'   => $fullName,
                    'email'       => $email,
                    'department'  => $department,
                    'role'        => 'reader',
                    'status'      => 'active',
                ];

                setFlash('success', 'Đăng ký thành công! Mã thẻ thư viện của bạn là ' . $memberCode . '.');
                header('Location: ' . baseUrl('index.php'));
                exit;
            }
        }
    }
}

$pageTitle = 'Đăng ký Thẻ Thư viện';
$activeNav = 'register';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="auth-grid">

        <section class="panel" aria-label="Form đăng ký thẻ độc giả">
            <div class="panel-header">
                <h1 class="panel-title">
                    <?= icon('user', 18) ?>
                    <span>ĐĂNG KÝ MỞ THẺ ĐỘC GIẢ MỚI</span>
                </h1>
                <span class="mono-code" style="font-size: var(--text-xs); color: var(--accent-stamp); font-weight: 700;">
                    MÃ THẺ TỰ ĐỘNG: <?= e($previewCode) ?>
                </span>
            </div>

            <div class="panel-body">
                <?php if ($errorMsg !== ''): ?>
                    <div class="flash-banner flash-error" style="margin-bottom: var(--space-4);" role="alert">
                        <?= icon('alert', 18) ?>
                        <span><?= e($errorMsg) ?></span>
                    </div>
                <?php endif; ?>

                <form action="<?= e(baseUrl('register.php')) ?>" method="post" novalidate>
                    <?= csrfField() ?>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label for="full_name" class="form-label">Họ và tên sinh viên *</label>
                            <input
                                type="text"
                                id="full_name"
                                name="full_name"
                                value="<?= e($fullName) ?>"
                                class="form-control"
                                placeholder="VD: Nguyễn Minh Khang"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="department" class="form-label">Khoa / Ngành học *</label>
                            <select id="department" name="department" class="form-select">
                                <?php
                                $depts = [
                                    'Khoa Công nghệ Thông tin',
                                    'Khoa Kỹ thuật Phần mềm',
                                    'Khoa Hệ thống Thông tin',
                                    'Khoa Khoa học Máy tính',
                                    'Khoa Mạng Máy tính & Truyền thông',
                                    'Khoa Toán - Tin Ứng dụng',
                                ];
                                foreach ($depts as $d):
                                ?>
                                    <option value="<?= e($d) ?>" <?= $department === $d ? 'selected' : '' ?>><?= e($d) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label for="email" class="form-label">Địa chỉ Email *</label>
                            <input
                                type="email"
                                id="email"
                                name="email"
                                value="<?= e($email) ?>"
                                class="form-control"
                                placeholder="sinhvien@vanlanguni.vn"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="phone" class="form-label">Số điện thoại</label>
                            <input
                                type="tel"
                                id="phone"
                                name="phone"
                                value="<?= e($phone) ?>"
                                class="form-control"
                                placeholder="090xxxxxxx"
                            >
                        </div>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label for="password" class="form-label">Mật khẩu (Tối thiểu 6 ký tự) *</label>
                            <input
                                type="password"
                                id="password"
                                name="password"
                                class="form-control"
                                placeholder="Nhập mật khẩu..."
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="password_confirm" class="form-label">Nhập lại mật khẩu *</label>
                            <input
                                type="password"
                                id="password_confirm"
                                name="password_confirm"
                                class="form-control"
                                placeholder="Nhập lại mật khẩu..."
                                required
                            >
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block" style="margin-top: var(--space-2);">
                        <?= icon('check', 18) ?>
                        <span>Đăng ký thẻ &amp; Đăng nhập ngay (<?= e($previewCode) ?>)</span>
                    </button>
                </form>

                <div style="margin-top: var(--space-6); padding-top: var(--space-4); border-top: 1px solid var(--border-rule); display: flex; align-items: center; justify-content: space-between;">
                    <span style="font-size: var(--text-sm); color: var(--text-muted);">Đã có tài khoản thư viện?</span>
                    <a href="<?= e(baseUrl('login.php')) ?>" class="btn btn-ghost btn-sm">
                        <?= icon('arrow-left', 14) ?>
                        <span>Quay lại trang Đăng nhập</span>
                    </a>
                </div>
            </div>
        </section>

        <aside class="panel" aria-label="Nội quy thẻ thư viện">
            <div class="panel-header">
                <h2 class="panel-title">
                    <?= icon('book', 18) ?>
                    <span>HƯỚNG DẪN &amp; NỘI QUY MƯỢN TRẢ SÁCH</span>
                </h2>
                <span class="mono-code" style="font-size: var(--text-xs); color: var(--text-muted);">NỘI QUY</span>
            </div>

            <div class="panel-body">
                <div class="demo-credential-card">
                    <div>
                        <div class="mono-code" style="font-size: var(--text-xs); color: var(--accent-stamp); font-weight: 700;">01 . CẤP MÃ THẺ SINH VIÊN TỰ ĐỘNG</div>
                        <p style="font-size: var(--text-sm); margin-top: 4px; color: var(--text-secondary);">
                            Mỗi tài khoản đăng ký mới được cấp tự động một mã thẻ <strong class="mono-code">SV2026XX</strong> dùng để đăng ký online và theo dõi lịch sử mượn trả sách.
                        </p>
                    </div>
                </div>

                <div class="demo-credential-card">
                    <div>
                        <div class="mono-code" style="font-size: var(--text-xs); color: var(--accent-stamp); font-weight: 700;">02 . SỐ LƯỢNG SÁCH ĐƯỢC MƯỢN</div>
                        <p style="font-size: var(--text-sm); margin-top: 4px; color: var(--text-secondary);">
                            Sinh viên được mượn tối đa <strong class="mono-num"><?= MAX_ACTIVE_LOANS_PER_READER ?> cuốn sách</strong> cùng lúc, thời hạn mượn mặc định là <strong class="mono-num"><?= DEFAULT_BORROW_DAYS ?> ngày</strong> cho mỗi phiếu mượn.
                        </p>
                    </div>
                </div>

                <div class="demo-credential-card" style="margin-bottom: 0;">
                    <div>
                        <div class="mono-code" style="font-size: var(--text-xs); color: var(--accent-stamp); font-weight: 700;">03 . QUY ĐỊNH TRẢ SÁCH &amp; TIỀN PHẠT</div>
                        <p style="font-size: var(--text-sm); margin-top: 4px; color: var(--text-secondary);">
                            Vui lòng giữ gìn sách cẩn thận và trả sách đúng hạn. Nếu trả sách trễ hạn, hệ thống sẽ tính phí phạt <strong class="mono-num"><?= formatVND(FINE_PER_DAY_VND) ?> / ngày</strong>.
                        </p>
                    </div>
                </div>
            </div>
        </aside>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
