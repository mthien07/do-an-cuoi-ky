<?php
require_once __DIR__ . '/../includes/auth.php';
requireLibrarian();

$pdo = getDB();
$currentUser = currentUser();
$pageTitle = 'Quản lý Độc giả & Tài khoản';
$activeNav = 'readers';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('error', 'Phiên làm việc không hợp lệ. Vui lòng thử lại.');
        header('Location: ' . baseUrl('librarian/readers.php'));
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create_user') {
        $memberCode = strtoupper(trim((string)($_POST['member_code'] ?? '')));
        $fullName   = trim((string)($_POST['full_name'] ?? ''));
        $email      = strtolower(trim((string)($_POST['email'] ?? '')));
        $phone      = trim((string)($_POST['phone'] ?? ''));
        $department = trim((string)($_POST['department'] ?? ''));
        $role       = $_POST['role'] ?? 'reader';
        $password   = trim((string)($_POST['password'] ?? ''));

        if (!in_array($role, ['reader', 'librarian'], true)) {
            $role = 'reader';
        }

        if ($password === '') {
            $password = '123456';
        }

        if ($memberCode === '' || $fullName === '' || $email === '') {
            setFlash('error', 'Vui lòng nhập đầy đủ Mã thẻ, Họ tên và Email.');
            header('Location: ' . baseUrl('librarian/readers.php'));
            exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlash('error', 'Địa chỉ email không đúng định dạng.');
            header('Location: ' . baseUrl('librarian/readers.php'));
            exit;
        }

        try {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("
                INSERT INTO users (member_code, full_name, email, phone, department, password_hash, role, status)
                VALUES (:member_code, :full_name, :email, :phone, :department, :password_hash, :role, 'active')
            ");
            $stmt->execute([
                'member_code'   => $memberCode,
                'full_name'     => $fullName,
                'email'         => $email,
                'phone'         => $phone !== '' ? $phone : null,
                'department'    => $department !== '' ? $department : null,
                'password_hash' => $passwordHash,
                'role'          => $role,
            ]);

            $roleLabel = $role === 'librarian' ? 'Thủ thư' : 'Độc giả';
            setFlash('success', 'Đã thêm tài khoản ' . $roleLabel . ' [' . $memberCode . '] cho ' . $fullName . '.');
        } catch (PDOException $e) {
            if ((int)$e->getCode() === 23000) {
                setFlash('error', 'Mã thẻ hoặc Email đã được đăng ký trước đó.');
            } else {
                setFlash('error', 'Lỗi tạo tài khoản: ' . $e->getMessage());
            }
        }

        header('Location: ' . baseUrl('librarian/readers.php'));
        exit;
    }

    if ($action === 'toggle_status') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $myId         = (int)($currentUser['id'] ?? 0);

        if ($targetUserId <= 0) {
            setFlash('error', 'Tài khoản thao tác không hợp lệ.');
            header('Location: ' . baseUrl('librarian/readers.php'));
            exit;
        }

        if ($targetUserId === $myId) {
            setFlash('error', 'Không thể tự khóa tài khoản của chính bạn đang đăng nhập.');
            header('Location: ' . baseUrl('librarian/readers.php'));
            exit;
        }

        try {
            $stmtUser = $pdo->prepare("SELECT id, member_code, full_name, status FROM users WHERE id = :id");
            $stmtUser->execute(['id' => $targetUserId]);
            $targetUser = $stmtUser->fetch();

            if (!$targetUser) {
                throw new Exception('Không tìm thấy tài khoản độc giả.');
            }

            $newStatus = $targetUser['status'] === 'active' ? 'locked' : 'active';
            $stmtUpdate = $pdo->prepare("UPDATE users SET status = :status WHERE id = :id");
            $stmtUpdate->execute([
                'status' => $newStatus,
                'id'     => $targetUserId,
            ]);

            if ($newStatus === 'locked') {
                setFlash('success', 'Đã khóa tài khoản [' . $targetUser['member_code'] . '] ' . $targetUser['full_name'] . '.');
            } else {
                setFlash('success', 'Đã mở khóa tài khoản [' . $targetUser['member_code'] . '] ' . $targetUser['full_name'] . '.');
            }
        } catch (Exception $e) {
            setFlash('error', $e->getMessage());
        }

        header('Location: ' . baseUrl('librarian/readers.php'));
        exit;
    }

    if ($action === 'reset_password') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);

        try {
            $stmtUser = $pdo->prepare("SELECT id, member_code, full_name FROM users WHERE id = :id");
            $stmtUser->execute(['id' => $targetUserId]);
            $targetUser = $stmtUser->fetch();

            if (!$targetUser) {
                throw new Exception('Không tìm thấy tài khoản cần đặt lại mật khẩu.');
            }

            $defaultHash = password_hash('123456', PASSWORD_DEFAULT);
            $stmtReset = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE id = :id");
            $stmtReset->execute([
                'hash' => $defaultHash,
                'id'   => $targetUserId,
            ]);

            setFlash(
                'success',
                'Đã đặt lại mật khẩu mặc định (123456) cho tài khoản [' . $targetUser['member_code'] . '] ' . $targetUser['full_name'] . '.'
            );
        } catch (Exception $e) {
            setFlash('error', $e->getMessage());
        }

        header('Location: ' . baseUrl('librarian/readers.php'));
        exit;
    }

    if ($action === 'update_user') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $myId         = (int)($currentUser['id'] ?? 0);
        $memberCode   = strtoupper(trim((string)($_POST['member_code'] ?? '')));
        $fullName     = trim((string)($_POST['full_name'] ?? ''));
        $email        = strtolower(trim((string)($_POST['email'] ?? '')));
        $phone        = trim((string)($_POST['phone'] ?? ''));
        $department   = trim((string)($_POST['department'] ?? ''));
        $role         = $_POST['role'] ?? 'reader';
        $status       = $_POST['status'] ?? 'active';
        $newPassword  = trim((string)($_POST['new_password'] ?? ''));

        if (!in_array($role, ['reader', 'librarian'], true)) {
            $role = 'reader';
        }
        if (!in_array($status, ['active', 'locked'], true)) {
            $status = 'active';
        }

        if ($targetUserId <= 0 || $memberCode === '' || $fullName === '' || $email === '') {
            setFlash('error', 'Vui lòng điền đầy đủ Mã thẻ, Họ tên và Email khi cập nhật.');
            header('Location: ' . baseUrl('librarian/readers.php?edit=' . $targetUserId));
            exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlash('error', 'Địa chỉ email không đúng định dạng.');
            header('Location: ' . baseUrl('librarian/readers.php?edit=' . $targetUserId));
            exit;
        }

        if ($targetUserId === $myId) {
            if ($status === 'locked') {
                setFlash('error', 'Không thể tự khóa tài khoản của chính bạn đang đăng nhập.');
                header('Location: ' . baseUrl('librarian/readers.php?edit=' . $targetUserId));
                exit;
            }
            if ($role !== 'librarian') {
                setFlash('error', 'Không thể tự hạ quyền của tài khoản thủ thư đang đăng nhập.');
                header('Location: ' . baseUrl('librarian/readers.php?edit=' . $targetUserId));
                exit;
            }
        }

        try {
            $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE id = :id");
            $stmtCheck->execute(['id' => $targetUserId]);
            if (!$stmtCheck->fetch()) {
                throw new Exception('Không tìm thấy tài khoản cần cập nhật.');
            }

            if ($newPassword !== '') {
                $stmtUpdate = $pdo->prepare("
                    UPDATE users
                    SET member_code   = :member_code,
                        full_name     = :full_name,
                        email         = :email,
                        phone         = :phone,
                        department    = :department,
                        role          = :role,
                        status        = :status,
                        password_hash = :password_hash
                    WHERE id = :id
                ");
                $stmtUpdate->execute([
                    'member_code'   => $memberCode,
                    'full_name'     => $fullName,
                    'email'         => $email,
                    'phone'         => $phone !== '' ? $phone : null,
                    'department'    => $department !== '' ? $department : null,
                    'role'          => $role,
                    'status'        => $status,
                    'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                    'id'            => $targetUserId,
                ]);
            } else {
                $stmtUpdate = $pdo->prepare("
                    UPDATE users
                    SET member_code = :member_code,
                        full_name   = :full_name,
                        email       = :email,
                        phone       = :phone,
                        department  = :department,
                        role        = :role,
                        status      = :status
                    WHERE id = :id
                ");
                $stmtUpdate->execute([
                    'member_code' => $memberCode,
                    'full_name'   => $fullName,
                    'email'       => $email,
                    'phone'       => $phone !== '' ? $phone : null,
                    'department'  => $department !== '' ? $department : null,
                    'role'        => $role,
                    'status'      => $status,
                    'id'          => $targetUserId,
                ]);
            }

            if ($targetUserId === $myId) {
                $_SESSION['user'] = [
                    'id'          => $targetUserId,
                    'member_code' => $memberCode,
                    'full_name'   => $fullName,
                    'email'       => $email,
                    'department'  => $department !== '' ? $department : null,
                    'role'        => $role,
                    'status'      => $status,
                ];
            }

            setFlash('success', 'Đã cập nhật thông tin [' . $memberCode . '] ' . $fullName . ' thành công.');
            header('Location: ' . baseUrl('librarian/readers.php'));
            exit;
        } catch (PDOException $e) {
            if ((int)$e->getCode() === 23000) {
                setFlash('error', 'Mã thẻ hoặc Email bị trùng với tài khoản khác.');
            } else {
                setFlash('error', 'Lỗi cập nhật thông tin: ' . $e->getMessage());
            }
            header('Location: ' . baseUrl('librarian/readers.php?edit=' . $targetUserId));
            exit;
        } catch (Exception $e) {
            setFlash('error', $e->getMessage());
            header('Location: ' . baseUrl('librarian/readers.php'));
            exit;
        }
    }

    if ($action === 'delete_user') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $myId         = (int)($currentUser['id'] ?? 0);

        if ($targetUserId <= 0) {
            setFlash('error', 'Tài khoản cần xóa không hợp lệ.');
            header('Location: ' . baseUrl('librarian/readers.php'));
            exit;
        }

        if ($targetUserId === $myId) {
            setFlash('error', 'Không thể tự xóa tài khoản của chính bạn đang đăng nhập.');
            header('Location: ' . baseUrl('librarian/readers.php'));
            exit;
        }

        try {
            $pdo->beginTransaction();

            $stmtUser = $pdo->prepare("SELECT id, member_code, full_name FROM users WHERE id = :id FOR UPDATE");
            $stmtUser->execute(['id' => $targetUserId]);
            $targetUser = $stmtUser->fetch();

            if (!$targetUser) {
                throw new Exception('Không tìm thấy tài khoản cần xóa.');
            }

            $stmtActive = $pdo->prepare("
                SELECT COALESCE(SUM(bi.quantity), 0)
                FROM borrow_items bi
                INNER JOIN borrow_records br ON br.id = bi.borrow_id
                WHERE br.user_id = :uid AND br.status IN ('borrowed', 'overdue')
            ");
            $stmtActive->execute(['uid' => $targetUserId]);
            $activeHolding = (int)$stmtActive->fetchColumn();

            if ($activeHolding > 0) {
                throw new Exception(
                    'Không thể xóa tài khoản [' . $targetUser['member_code'] . '] ' . $targetUser['full_name'] . ' vì đang mượn ' . $activeHolding . ' cuốn sách chưa trả.'
                );
            }

            $stmtClearIssuer = $pdo->prepare("UPDATE borrow_records SET issued_by = NULL WHERE issued_by = :uid");
            $stmtClearIssuer->execute(['uid' => $targetUserId]);

            $stmtDelItems = $pdo->prepare("
                DELETE bi FROM borrow_items bi
                INNER JOIN borrow_records br ON br.id = bi.borrow_id
                WHERE br.user_id = :uid
            ");
            $stmtDelItems->execute(['uid' => $targetUserId]);

            $stmtDelRecords = $pdo->prepare("DELETE FROM borrow_records WHERE user_id = :uid");
            $stmtDelRecords->execute(['uid' => $targetUserId]);

            $stmtDelUser = $pdo->prepare("DELETE FROM users WHERE id = :uid");
            $stmtDelUser->execute(['uid' => $targetUserId]);

            $pdo->commit();
            setFlash('success', 'Đã xóa tài khoản [' . $targetUser['member_code'] . '] ' . $targetUser['full_name'] . ' khỏi hệ thống.');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            setFlash('error', $e->getMessage());
        }

        header('Location: ' . baseUrl('librarian/readers.php'));
        exit;
    }
}

$editId = (int)($_GET['edit'] ?? 0);
$editingUser = null;
if ($editId > 0) {
    $stmtEdit = $pdo->prepare("SELECT * FROM users WHERE id = :id");
    $stmtEdit->execute(['id' => $editId]);
    $editingUser = $stmtEdit->fetch() ?: null;
}

$searchQuery  = trim((string)($_GET['q'] ?? ''));
$roleFilter   = trim((string)($_GET['role'] ?? 'all'));
$statusFilter = trim((string)($_GET['status'] ?? 'all'));

$whereClauses = [];
$params = [];

if (in_array($roleFilter, ['reader', 'librarian'], true)) {
    $whereClauses[] = 'u.role = :role';
    $params['role'] = $roleFilter;
}

if (in_array($statusFilter, ['active', 'locked'], true)) {
    $whereClauses[] = 'u.status = :status';
    $params['status'] = $statusFilter;
}

if ($searchQuery !== '') {
    $whereClauses[] = '(u.member_code LIKE :q1 OR u.full_name LIKE :q2 OR u.email LIKE :q3 OR u.department LIKE :q4)';
    $kw = '%' . $searchQuery . '%';
    $params['q1'] = $kw;
    $params['q2'] = $kw;
    $params['q3'] = $kw;
    $params['q4'] = $kw;
}

$whereSql = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

$stmtUsers = $pdo->prepare("
    SELECT u.*,
           COALESCE((
               SELECT SUM(bi.quantity)
               FROM borrow_items bi
               INNER JOIN borrow_records br ON br.id = bi.borrow_id
               WHERE br.user_id = u.id AND br.status IN ('borrowed', 'overdue')
           ), 0) AS active_books,
           COALESCE((
               SELECT COUNT(*)
               FROM borrow_records br2
               WHERE br2.user_id = u.id AND br2.status = 'overdue'
           ), 0) AS overdue_loans,
           COALESCE((
               SELECT SUM(br3.fine_amount)
               FROM borrow_records br3
               WHERE br3.user_id = u.id
           ), 0) AS total_fines
    FROM users u
    {$whereSql}
    ORDER BY
        CASE u.role WHEN 'reader' THEN 1 ELSE 2 END ASC,
        u.member_code ASC
");
$stmtUsers->execute($params);
$usersList = $stmtUsers->fetchAll();

$userStats = $pdo->query("
    SELECT COUNT(*) AS total_users,
           COALESCE(SUM(CASE WHEN role = 'reader' AND status = 'active' THEN 1 ELSE 0 END), 0) AS active_readers,
           COALESCE(SUM(CASE WHEN status = 'locked' THEN 1 ELSE 0 END), 0) AS locked_users,
           COALESCE(SUM(CASE WHEN role = 'librarian' THEN 1 ELSE 0 END), 0) AS librarian_count
    FROM users
")->fetch();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <section class="page-header">
        <div>
            <div class="page-header-meta">
                <span class="mono-code">TRANG THỦ THƯ . QUẢN LÝ ĐỘC GIẢ &amp; TÀI KHOẢN</span>
            </div>
            <h1 class="page-title">Quản lý Độc giả &amp; Tài khoản</h1>
            <p class="page-subtitle">Thêm tài khoản độc giả mới, kiểm tra số sách đang mượn, theo dõi tiền phạt và hỗ trợ đặt lại mật khẩu cho sinh viên.</p>
        </div>
        <div class="page-actions">
            <button type="button" class="btn btn-primary" data-modal-open="modal-create-user">
                <?= icon('plus', 16) ?>
                <span>Thêm tài khoản mới</span>
            </button>
        </div>
    </section>

    <section class="kpi-strip" aria-label="Thống kê tài khoản">
        <article class="kpi-cell">
            <span class="kpi-label">TỔNG SỐ TÀI KHOẢN</span>
            <strong class="kpi-value mono-num"><?= (int)$userStats['total_users'] ?></strong>
            <span class="kpi-sub">Bao gồm cả độc giả và thủ thư</span>
        </article>
        <article class="kpi-cell">
            <span class="kpi-label">ĐỘC GIẢ ĐANG HOẠT ĐỘNG</span>
            <strong class="kpi-value mono-num"><?= (int)$userStats['active_readers'] ?></strong>
            <span class="kpi-sub">Được mượn tối đa <?= MAX_ACTIVE_LOANS_PER_READER ?> cuốn</span>
        </article>
        <article class="kpi-cell">
            <span class="kpi-label">TÀI KHOẢN BỊ KHÓA</span>
            <strong class="kpi-value mono-num"><?= (int)$userStats['locked_users'] ?></strong>
            <span class="kpi-sub">Tạm khóa quyền mượn sách</span>
        </article>
        <article class="kpi-cell">
            <span class="kpi-label">TÀI KHOẢN THỦ THƯ</span>
            <strong class="kpi-value mono-num"><?= (int)$userStats['librarian_count'] ?></strong>
            <span class="kpi-sub">Quản lý hoạt động thư viện</span>
        </article>
    </section>

    <?php if ($editingUser): ?>
        <?php $editingIsSelf = ((int)$editingUser['id'] === (int)($currentUser['id'] ?? 0)); ?>
        <section class="panel" style="margin-bottom: 24px;">
            <div class="panel-header">
                <h2 class="panel-title">
                    <?= icon('edit', 18) ?>
                    <span>Cập nhật Thông tin Tài khoản . <?= e($editingUser['member_code']) ?></span>
                </h2>
                <a href="<?= e(baseUrl('librarian/readers.php')) ?>" class="btn btn-ghost btn-sm">
                    <?= icon('close', 16) ?>
                    <span>Đóng khung sửa</span>
                </a>
            </div>
            <form method="post" action="<?= e(baseUrl('librarian/readers.php')) ?>" class="panel-body">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update_user">
                <input type="hidden" name="user_id" value="<?= (int)$editingUser['id'] ?>">

                <div class="form-grid-3">
                    <div class="form-group">
                        <label class="form-label" for="edit_member_code">Mã thẻ / Mã sinh viên</label>
                        <input
                            type="text"
                            id="edit_member_code"
                            name="member_code"
                            value="<?= e($editingUser['member_code']) ?>"
                            class="form-control mono-code"
                            maxlength="20"
                            required
                        >
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="edit_role">Vai trò</label>
                        <select id="edit_role" name="role" class="form-select" required>
                            <option value="reader" <?= $editingUser['role'] === 'reader' ? 'selected' : '' ?> <?= $editingIsSelf ? 'disabled' : '' ?>>
                                Độc giả (Sinh viên / Giảng viên)
                            </option>
                            <option value="librarian" <?= $editingUser['role'] === 'librarian' ? 'selected' : '' ?>>
                                Thủ thư quản lý
                            </option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="edit_status">Trạng thái</label>
                        <select id="edit_status" name="status" class="form-select" required>
                            <option value="active" <?= $editingUser['status'] === 'active' ? 'selected' : '' ?>>
                                Đang hoạt động
                            </option>
                            <option value="locked" <?= $editingUser['status'] === 'locked' ? 'selected' : '' ?> <?= $editingIsSelf ? 'disabled' : '' ?>>
                                Tạm khóa
                            </option>
                        </select>
                    </div>
                </div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label" for="edit_full_name">Họ và tên</label>
                        <input
                            type="text"
                            id="edit_full_name"
                            name="full_name"
                            value="<?= e($editingUser['full_name']) ?>"
                            class="form-control"
                            required
                        >
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="edit_department">Khoa / Lớp</label>
                        <input
                            type="text"
                            id="edit_department"
                            name="department"
                            value="<?= e($editingUser['department'] ?? '') ?>"
                            class="form-control"
                        >
                    </div>
                </div>

                <div class="form-grid-3">
                    <div class="form-group">
                        <label class="form-label" for="edit_email">Email</label>
                        <input
                            type="email"
                            id="edit_email"
                            name="email"
                            value="<?= e($editingUser['email']) ?>"
                            class="form-control"
                            required
                        >
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="edit_phone">Số điện thoại</label>
                        <input
                            type="text"
                            id="edit_phone"
                            name="phone"
                            value="<?= e($editingUser['phone'] ?? '') ?>"
                            class="form-control mono-num"
                        >
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="edit_new_password">Mật khẩu mới (Tùy chọn)</label>
                        <input
                            type="password"
                            id="edit_new_password"
                            name="new_password"
                            class="form-control mono-code"
                            placeholder="Để trống nếu giữ nguyên MK cũ"
                        >
                    </div>
                </div>

                <div class="page-actions" style="justify-content: flex-end; margin-top: 12px;">
                    <a href="<?= e(baseUrl('librarian/readers.php')) ?>" class="btn btn-ghost">Hủy</a>
                    <button type="submit" class="btn btn-primary">
                        <?= icon('check', 16) ?>
                        <span>Lưu thông tin</span>
                    </button>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <section class="panel" style="margin-bottom: 24px;">
        <div class="panel-body">
            <form method="get" action="<?= e(baseUrl('librarian/readers.php')) ?>" class="filter-bar">
                <div class="form-group" style="flex: 2; min-width: 240px; margin-bottom: 0;">
                    <label for="q" class="form-label">Tìm kiếm độc giả</label>
                    <input
                        type="search"
                        id="q"
                        name="q"
                        value="<?= e($searchQuery) ?>"
                        class="form-control"
                        placeholder="Nhập mã thẻ (SV202601), họ tên, email hoặc khoa..."
                    >
                </div>

                <div class="form-group" style="flex: 1; min-width: 170px; margin-bottom: 0;">
                    <label for="role" class="form-label">Vai trò</label>
                    <select id="role" name="role" class="form-select">
                        <option value="all" <?= $roleFilter === 'all' ? 'selected' : '' ?>>Tất cả vai trò</option>
                        <option value="reader" <?= $roleFilter === 'reader' ? 'selected' : '' ?>>Độc giả (Sinh viên / GV)</option>
                        <option value="librarian" <?= $roleFilter === 'librarian' ? 'selected' : '' ?>>Thủ thư</option>
                    </select>
                </div>

                <div class="form-group" style="flex: 1; min-width: 170px; margin-bottom: 0;">
                    <label for="status" class="form-label">Trạng thái</label>
                    <select id="status" name="status" class="form-select">
                        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Tất cả trạng thái</option>
                        <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Đang hoạt động</option>
                        <option value="locked" <?= $statusFilter === 'locked' ? 'selected' : '' ?>>Đang bị khóa</option>
                    </select>
                </div>

                <div class="page-actions" style="align-self: flex-end;">
                    <button type="submit" class="btn btn-secondary">
                        <?= icon('search', 16) ?>
                        <span>Tìm kiếm</span>
                    </button>
                    <?php if ($searchQuery !== '' || $roleFilter !== 'all' || $statusFilter !== 'all'): ?>
                        <a href="<?= e(baseUrl('librarian/readers.php')) ?>" class="btn btn-ghost">Đặt lại</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <h2 class="panel-title">
                <?= icon('users', 18) ?>
                <span>Danh sách Độc giả &amp; Tài khoản (<?= count($usersList) ?> tài khoản)</span>
            </h2>
        </div>
        <div class="panel-body">
            <?php if (empty($usersList)): ?>
                <div class="empty-state">
                    <?= icon('users', 28) ?>
                    <p>Không tìm thấy tài khoản nào phù hợp.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Mã thẻ / Vai trò</th>
                                <th>Họ và tên / Liên hệ</th>
                                <th>Khoa / Đơn vị</th>
                                <th class="text-right">Sách đang mượn</th>
                                <th class="text-right">Tổng tiền phạt</th>
                                <th>Trạng thái</th>
                                <th class="text-right">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($usersList as $u): ?>
                                <?php
                                $uId = (int)$u['id'];
                                $isSelf = ($uId === (int)($currentUser['id'] ?? 0));
                                $activeBooks = (int)$u['active_books'];
                                $overdueCount = (int)$u['overdue_loans'];
                                $totalFines = (float)$u['total_fines'];
                                ?>
                                <tr>
                                    <td>
                                        <strong class="mono-code"><?= e($u['member_code']) ?></strong>
                                        <div class="form-help">
                                            <?= $u['role'] === 'librarian' ? 'Thủ thư' : 'Độc giả' ?>
                                        </div>
                                    </td>
                                    <td>
                                        <strong><?= e($u['full_name']) ?></strong>
                                        <div class="form-help">
                                            <?= e($u['email']) ?>
                                            <?php if (!empty($u['phone'])): ?>
                                                <span> . <?= e($u['phone']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span><?= e($u['department'] ?? 'Chưa cập nhật') ?></span>
                                        <div class="form-help">
                                            Ngày tạo: <span class="mono-num"><?= formatDate($u['created_at']) ?></span>
                                        </div>
                                    </td>
                                    <td class="text-right mono-num">
                                        <strong><?= $activeBooks ?></strong> / <?= MAX_ACTIVE_LOANS_PER_READER ?> cuốn
                                        <?php if ($overdueCount > 0): ?>
                                            <div class="form-help" style="color: #B42318; font-weight: 600;">
                                                <?= $overdueCount ?> phiếu quá hạn
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-right mono-num">
                                        <?php if ($totalFines > 0): ?>
                                            <strong style="color: #B42318;"><?= formatVND($totalFines) ?></strong>
                                        <?php else: ?>
                                            <span>0 ₫</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($u['status'] === 'active'): ?>
                                            <span class="status-badge badge-active">
                                                <?= icon('check', 14) ?>
                                                <span>Hoạt động</span>
                                            </span>
                                        <?php else: ?>
                                            <span class="status-badge badge-locked">
                                                <?= icon('lock', 14) ?>
                                                <span>Đang khóa</span>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-right">
                                        <div style="display: inline-flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end;">
                                            <a
                                                href="<?= e(baseUrl('librarian/loans.php?q=' . urlencode($u['member_code']))) ?>"
                                                class="btn btn-ghost btn-sm"
                                                title="Xem lịch sử mượn trả"
                                            >
                                                <?= icon('receipt', 14) ?>
                                                <span>Phiếu mượn</span>
                                            </a>

                                            <a
                                                href="<?= e(baseUrl('librarian/readers.php?edit=' . $uId)) ?>"
                                                class="btn btn-secondary btn-sm"
                                                title="Sửa thông tin"
                                            >
                                                <?= icon('edit', 14) ?>
                                                <span>Sửa</span>
                                            </a>

                                            <form
                                                method="post"
                                                action="<?= e(baseUrl('librarian/readers.php')) ?>"
                                                style="display: inline;"
                                                onsubmit="return confirm('Đặt lại mật khẩu mặc định 123456 cho tài khoản này?');"
                                            >
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="reset_password">
                                                <input type="hidden" name="user_id" value="<?= $uId ?>">
                                                <button type="submit" class="btn btn-secondary btn-sm" title="Đặt lại mật khẩu mặc định 123456">
                                                    <span>Đặt lại MK</span>
                                                </button>
                                            </form>

                                            <?php if (!$isSelf): ?>
                                                <form method="post" action="<?= e(baseUrl('librarian/readers.php')) ?>" style="display: inline;">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="toggle_status">
                                                    <input type="hidden" name="user_id" value="<?= $uId ?>">
                                                    <?php if ($u['status'] === 'active'): ?>
                                                        <button type="submit" class="btn btn-danger btn-sm" title="Khóa tài khoản">
                                                            <?= icon('lock', 14) ?>
                                                            <span>Khóa thẻ</span>
                                                        </button>
                                                    <?php else: ?>
                                                        <button type="submit" class="btn btn-primary btn-sm" title="Mở khóa tài khoản">
                                                            <?= icon('unlock', 14) ?>
                                                            <span>Mở khóa</span>
                                                        </button>
                                                    <?php endif; ?>
                                                </form>

                                                <?php if ($activeBooks === 0): ?>
                                                    <form
                                                        method="post"
                                                        action="<?= e(baseUrl('librarian/readers.php')) ?>"
                                                        style="display: inline;"
                                                        onsubmit="return confirm('Bạn có chắc muốn xóa tài khoản này và lịch sử mượn trả liên quan?');"
                                                    >
                                                        <?= csrfField() ?>
                                                        <input type="hidden" name="action" value="delete_user">
                                                        <input type="hidden" name="user_id" value="<?= $uId ?>">
                                                        <button type="submit" class="btn btn-danger btn-sm" title="Xóa tài khoản">
                                                            <?= icon('trash', 14) ?>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="form-help" style="align-self: center;">Tài khoản hiện tại</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<dialog id="modal-create-user" class="modal-dialog">
    <div class="panel">
        <div class="panel-header">
            <h2 class="panel-title">
                <?= icon('user', 18) ?>
                <span>Thêm Tài khoản Độc giả / Thủ thư Mới</span>
            </h2>
            <button type="button" class="btn btn-ghost btn-sm" data-modal-close>
                <?= icon('close', 16) ?>
            </button>
        </div>
        <form method="post" action="<?= e(baseUrl('librarian/readers.php')) ?>" class="panel-body">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_user">

            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label" for="new_member_code">Mã thẻ / Mã sinh viên</label>
                    <input
                        type="text"
                        id="new_member_code"
                        name="member_code"
                        class="form-control mono-code"
                        placeholder="VD: SV202605 hoặc TT003"
                        maxlength="20"
                        required
                    >
                </div>
                <div class="form-group">
                    <label class="form-label" for="new_role">Vai trò</label>
                    <select id="new_role" name="role" class="form-select" required>
                        <option value="reader">Độc giả (Sinh viên / Giảng viên)</option>
                        <option value="librarian">Thủ thư quản lý</option>
                    </select>
                </div>
            </div>

            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label" for="new_full_name">Họ và tên</label>
                    <input
                        type="text"
                        id="new_full_name"
                        name="full_name"
                        class="form-control"
                        placeholder="Nhập họ tên đầy đủ"
                        required
                    >
                </div>
                <div class="form-group">
                    <label class="form-label" for="new_department">Khoa / Đơn vị</label>
                    <input
                        type="text"
                        id="new_department"
                        name="department"
                        class="form-control"
                        placeholder="VD: Khoa Công nghệ Thông tin"
                        required
                    >
                </div>
            </div>

            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label" for="new_email">Email</label>
                    <input
                        type="email"
                        id="new_email"
                        name="email"
                        class="form-control"
                        placeholder="VD: sinhvien@vanlanguni.vn"
                        required
                    >
                </div>
                <div class="form-group">
                    <label class="form-label" for="new_phone">Số điện thoại</label>
                    <input
                        type="text"
                        id="new_phone"
                        name="phone"
                        class="form-control mono-num"
                        placeholder="VD: 0912345678"
                    >
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="new_password">Mật khẩu khởi tạo (Mặc định: 123456)</label>
                <input
                    type="text"
                    id="new_password"
                    name="password"
                    value="123456"
                    class="form-control mono-code"
                    required
                >
                <span class="form-help">Mật khẩu được mã hóa bằng hàm password_hash trước khi lưu vào CSDL.</span>
            </div>

            <div class="page-actions" style="justify-content: flex-end; margin-top: 16px;">
                <button type="button" class="btn btn-ghost" data-modal-close>Hủy bỏ</button>
                <button type="submit" class="btn btn-primary">
                    <?= icon('check', 16) ?>
                    <span>Thêm tài khoản</span>
                </button>
            </div>
        </form>
    </div>
</dialog>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
