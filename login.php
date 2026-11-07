<?php

require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    $u = currentUser();
    header('Location: ' . baseUrl($u['role'] === 'librarian' ? 'librarian/dashboard.php' : 'index.php'));
    exit;
}

$pdo = getDB();
$errorMsg = '';
$identifierVal = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $errorMsg = 'Phiên làm việc không hợp lệ. Vui lòng tải lại trang và thử lại.';
    } else {
        $identifierVal = trim((string)($_POST['identifier'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($identifierVal === '' || $password === '') {
            $errorMsg = 'Vui lòng nhập đầy đủ Mã thẻ thư viện (hoặc Email) và Mật khẩu.';
        } else {

            $stmt = $pdo->prepare("
                SELECT *
                FROM users
                WHERE member_code = :code OR email = :email
                LIMIT 1
            ");
            $stmt->execute([
                'code'  => $identifierVal,
                'email' => $identifierVal,
            ]);
            $account = $stmt->fetch();

            if (!$account || !password_verify($password, $account['password_hash'])) {
                $errorMsg = 'Mã thẻ thư viện, Email hoặc Mật khẩu không đúng.';
            } elseif ($account['status'] === 'locked') {
                $errorMsg = 'Thẻ thư viện [' . $account['member_code'] . '] hiện đang bị khóa mượn trả. Vui lòng liên hệ Thủ thư để mở khóa.';
            } else {
                session_regenerate_id(true);
                $_SESSION['user'] = [
                    'id'          => (int)$account['id'],
                    'member_code' => $account['member_code'],
                    'full_name'   => $account['full_name'],
                    'email'       => $account['email'],
                    'department'  => $account['department'],
                    'role'        => $account['role'],
                    'status'      => $account['status'],
                ];

                setFlash('success', 'Xin chào ' . $account['full_name'] . ' (' . $account['member_code'] . '). Đăng nhập thành công!');
                $target = $account['role'] === 'librarian' ? 'librarian/dashboard.php' : 'index.php';
                header('Location: ' . baseUrl($target));
                exit;
            }
        }
    }
}

$pageTitle = 'Đăng nhập Thư viện';
$activeNav = 'login';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="auth-grid">

        <section class="panel" aria-label="Form đăng nhập">
            <div class="panel-header">
                <h1 class="panel-title">
                    <?= icon('user', 18) ?>
                    <span>ĐĂNG NHẬP HỆ THỐNG THƯ VIỆN</span>
                </h1>
                <span class="mono-code" style="font-size: var(--text-xs); color: var(--text-muted);">VLU LIB</span>
            </div>

            <div class="panel-body">
                <?php if ($errorMsg !== ''): ?>
                    <div class="flash-banner flash-error" style="margin-bottom: var(--space-4);" role="alert">
                        <?= icon('alert', 18) ?>
                        <span><?= e($errorMsg) ?></span>
                    </div>
                <?php endif; ?>

                <form action="<?= e(baseUrl('login.php')) ?>" method="post" novalidate>
                    <?= csrfField() ?>

                    <div class="form-group">
                        <label for="identifier" class="form-label">
                            <span>Mã thẻ thư viện hoặc Email</span>
                            <span class="mono-code">BẮT BUỘC</span>
                        </label>
                        <input
                            type="text"
                            id="identifier"
                            name="identifier"
                            value="<?= e($identifierVal) ?>"
                            class="form-control"
                            placeholder="VD: SV202601, TT001 hoặc email@thuvien.edu.vn"
                            required
                            autofocus
                        >
                    </div>

                    <div class="form-group">
                        <label for="password" class="form-label">
                            <span>Mật khẩu</span>
                            <span class="mono-code">BẮT BUỘC</span>
                        </label>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-control"
                            placeholder="Nhập mật khẩu của bạn..."
                            required
                        >
                    </div>

                    <button type="submit" class="btn btn-primary btn-block" style="margin-top: var(--space-2);">
                        <?= icon('arrow-right', 18) ?>
                        <span>Đăng nhập</span>
                    </button>
                </form>

                <div style="margin-top: var(--space-6); padding-top: var(--space-4); border-top: 1px solid var(--border-rule); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: var(--space-2);">
                    <span style="font-size: var(--text-sm); color: var(--text-muted);">Chưa có thẻ thư viện?</span>
                    <a href="<?= e(baseUrl('register.php')) ?>" class="btn btn-secondary btn-sm">
                        <?= icon('plus', 14) ?>
                        <span>Đăng ký thẻ độc giả mới</span>
                    </a>
                </div>
            </div>
        </section>

        <aside class="panel" aria-label="Tài khoản Demo">
            <div class="panel-header">
                <h2 class="panel-title">
                    <?= icon('archive', 18) ?>
                    <span>TÀI KHOẢN DEMO</span>
                </h2>
                <span class="status-badge badge-borrowed">Tài khoản mẫu để chạy thử chức năng Thủ thư và Sinh viên</span>
            </div>

            <div class="panel-body">
                <p style="font-size: var(--text-sm); color: var(--text-secondary); margin-bottom: var(--space-4);">
                    Tài khoản mẫu để chạy thử chức năng Thủ thư và Sinh viên. Bấm nút <strong>Điền nhanh</strong> để tự điền vào form đăng nhập bên trái (Mật khẩu chung: <code class="mono-code">123456</code>).
                </p>

                <div class="demo-credential-card">
                    <div>
                        <div style="display: flex; align-items: center; gap: var(--space-2); margin-bottom: 4px;">
                            <span class="status-badge badge-borrowed">Vai trò: Thủ thư</span>
                            <strong class="mono-code">TT001</strong>
                        </div>
                        <div style="font-size: var(--text-sm); font-weight: 700;">Nguyễn Hoàng Minh</div>
                        <div style="font-size: var(--text-xs); color: var(--text-muted);" class="mono-code">
                            Email: thuthu@thuvien.edu.vn . Mật khẩu: 123456
                        </div>
                        <div style="font-size: var(--text-xs); color: var(--text-muted); margin-top: 2px;">
                            Chức năng: Duyệt phiếu mượn, trả sách, tính tiền phạt, quản lý sách, thể loại và độc giả.
                        </div>
                    </div>
                    <button
                        type="button"
                        class="btn btn-secondary btn-sm"
                        data-fill-user="TT001"
                        data-fill-pass="123456"
                    >
                        <span>Điền nhanh TT001</span>
                    </button>
                </div>

                <div class="demo-credential-card">
                    <div>
                        <div style="display: flex; align-items: center; gap: var(--space-2); margin-bottom: 4px;">
                            <span class="status-badge badge-active">Vai trò: Sinh viên</span>
                            <strong class="mono-code">SV202601</strong>
                        </div>
                        <div style="font-size: var(--text-sm); font-weight: 700;">Dương Minh Thiện</div>
                        <div style="font-size: var(--text-xs); color: var(--text-muted);" class="mono-code">
                            Email: thien20071005@gmail.com . Mật khẩu: 123456
                        </div>
                        <div style="font-size: var(--text-xs); color: var(--text-muted); margin-top: 2px;">
                            Trạng thái: Đang có sách mượn và phiếu đăng ký online chờ duyệt.
                        </div>
                    </div>
                    <button
                        type="button"
                        class="btn btn-secondary btn-sm"
                        data-fill-user="SV202601"
                        data-fill-pass="123456"
                    >
                        <span>Điền nhanh SV202601</span>
                    </button>
                </div>

                <div class="demo-credential-card">
                    <div>
                        <div style="display: flex; align-items: center; gap: var(--space-2); margin-bottom: 4px;">
                            <span class="status-badge badge-pending">Sinh viên có sách quá hạn</span>
                            <strong class="mono-code">SV202602</strong>
                        </div>
                        <div style="font-size: var(--text-sm); font-weight: 700;">Trần Quốc Bảo</div>
                        <div style="font-size: var(--text-xs); color: var(--text-muted);" class="mono-code">
                            Email: baotq.sv@thuvien.edu.vn . Mật khẩu: 123456
                        </div>
                        <div style="font-size: var(--text-xs); color: var(--text-muted); margin-top: 2px;">
                            Trạng thái: Dùng để thử chức năng báo quá hạn và tính tiền phạt mượn trả.
                        </div>
                    </div>
                    <button
                        type="button"
                        class="btn btn-secondary btn-sm"
                        data-fill-user="SV202602"
                        data-fill-pass="123456"
                    >
                        <span>Điền nhanh SV202602</span>
                    </button>
                </div>

                <div class="demo-credential-card" style="margin-bottom: 0;">
                    <div>
                        <div style="display: flex; align-items: center; gap: var(--space-2); margin-bottom: 4px;">
                            <span class="status-badge badge-locked">Thẻ bị khóa (Thử chặn đăng nhập)</span>
                            <strong class="mono-code">SV202604</strong>
                        </div>
                        <div style="font-size: var(--text-sm); font-weight: 700;">Võ Đình Khôi</div>
                        <div style="font-size: var(--text-xs); color: var(--text-muted);" class="mono-code">
                            Email: khoivd.sv@thuvien.edu.vn . Mật khẩu: 123456
                        </div>
                    </div>
                    <button
                        type="button"
                        class="btn btn-ghost btn-sm"
                        data-fill-user="SV202604"
                        data-fill-pass="123456"
                    >
                        <span>Thử thẻ khóa</span>
                    </button>
                </div>
            </div>
        </aside>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
