<?php

require_once __DIR__ . '/includes/auth.php';

requireLogin();

$pdo = getDB();
$sessionUser = currentUser();
$userId = (int)$sessionUser['id'];

$userStmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
$userStmt->execute(['id' => $userId]);
$member = $userStmt->fetch();

if (!$member) {
    session_destroy();
    header('Location: ' . baseUrl('login.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_pending') {
    if (!verifyCsrf()) {
        setFlash('error', 'Phiên làm việc không hợp lệ. Vui lòng thử lại.');
        header('Location: ' . baseUrl('my-loans.php'));
        exit;
    }

    $borrowId = (int)($_POST['borrow_id'] ?? 0);

    $checkStmt = $pdo->prepare("
        SELECT id, receipt_code, status
        FROM borrow_records
        WHERE id = :bid AND user_id = :uid
        LIMIT 1
    ");
    $checkStmt->execute([
        'bid' => $borrowId,
        'uid' => $userId,
    ]);
    $record = $checkStmt->fetch();

    if (!$record) {
        setFlash('error', 'Không tìm thấy phiếu mượn cần hủy.');
    } elseif ($record['status'] !== 'pending') {
        setFlash('error', 'Chỉ có thể hủy phiếu khi đang ở trạng thái Chờ thủ thư duyệt.');
    } else {
        $delStmt = $pdo->prepare("DELETE FROM borrow_records WHERE id = :bid AND user_id = :uid AND status = 'pending'");
        $delStmt->execute([
            'bid' => $borrowId,
            'uid' => $userId,
        ]);
        setFlash('success', 'Đã hủy phiếu đăng ký mượn ' . $record['receipt_code'] . ' thành công.');
    }

    header('Location: ' . baseUrl('my-loans.php'));
    exit;
}

$loansStmt = $pdo->prepare("
    SELECT br.*, u_lib.full_name AS librarian_name
    FROM borrow_records br
    LEFT JOIN users u_lib ON u_lib.id = br.issued_by
    WHERE br.user_id = :uid
    ORDER BY br.created_at DESC, br.id DESC
");
$loansStmt->execute(['uid' => $userId]);
$loans = $loansStmt->fetchAll();

$itemsByLoan = [];
if (!empty($loans)) {
    $loanIds = [];
    foreach ($loans as $r) {
        $loanIds[] = (int)$r['id'];
    }
    $placeholders = implode(',', array_fill(0, count($loanIds), '?'));

    $itemsStmt = $pdo->prepare("
        SELECT bi.*, b.title, b.author, b.call_number, b.isbn, b.shelf_location
        FROM borrow_items bi
        INNER JOIN books b ON b.id = bi.book_id
        WHERE bi.borrow_id IN ($placeholders)
        ORDER BY bi.id ASC
    ");
    $itemsStmt->execute($loanIds);
    foreach ($itemsStmt->fetchAll() as $item) {
        $itemsByLoan[(int)$item['borrow_id']][] = $item;
    }
}

$activeHoldingBooks = 0;
$pendingRequests = 0;
$overdueCount = 0;
$totalFine = 0.0;

foreach ($loans as $row) {
    $bid = (int)$row['id'];
    $itemCount = 0;
    if (!empty($itemsByLoan[$bid])) {
        foreach ($itemsByLoan[$bid] as $it) {
            $itemCount += (int)$it['quantity'];
        }
    }

    if (in_array($row['status'], ['borrowed', 'overdue'], true)) {
        $activeHoldingBooks += $itemCount;
    }
    if ($row['status'] === 'pending') {
        $pendingRequests++;
    }
    if ($row['status'] === 'overdue') {
        $overdueCount++;
    }
    $totalFine += (float)$row['fine_amount'];
}

$pageTitle = 'Sách đang mượn & Lịch sử mượn trả';
$activeNav = 'my-loans';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <div>
            <div class="page-header-meta">
                <?= icon('receipt', 14) ?>
                <span>THÔNG TIN MƯỢN TRẢ CÁ NHÂN . MÃ THẺ <?= e($member['member_code']) ?></span>
            </div>
            <h1 class="page-title">Sách đang mượn &amp; Lịch sử mượn trả</h1>
            <p class="page-subtitle">
                Sinh viên: <strong><?= e($member['full_name']) ?></strong> . Khoa: <strong><?= e($member['department'] ?: 'Chưa cập nhật') ?></strong> . Email: <span class="mono-code"><?= e($member['email']) ?></span>
            </p>
        </div>
        <div class="page-actions">
            <span class="status-badge <?= $member['status'] === 'active' ? 'badge-active' : 'badge-locked' ?>">
                <?= icon($member['status'] === 'active' ? 'check' : 'lock', 14) ?>
                <span><?= $member['status'] === 'active' ? 'Thẻ đang hoạt động' : 'Thẻ đang bị khóa' ?></span>
            </span>
            <a href="<?= e(baseUrl('index.php')) ?>" class="btn btn-primary">
                <?= icon('search', 16) ?>
                <span>Tìm &amp; Mượn thêm sách</span>
            </a>
        </div>
    </div>

    <section class="kpi-strip" aria-label="Thống kê mượn trả cá nhân">
        <div class="kpi-cell">
            <div class="kpi-label">
                <span>MÃ THẺ ĐỘC GIẢ</span>
                <?= icon('user', 15) ?>
            </div>
            <div class="kpi-value"><?= e($member['member_code']) ?></div>
            <div class="kpi-sub"><?= e($member['department'] ?: 'Sinh viên') ?></div>
        </div>

        <div class="kpi-cell">
            <div class="kpi-label">
                <span>SÁCH ĐANG MƯỢN</span>
                <?= icon('book', 15) ?>
            </div>
            <div class="kpi-value"><?= $activeHoldingBooks ?> <span style="font-size: var(--text-base); color: var(--text-muted);">/ <?= MAX_ACTIVE_LOANS_PER_READER ?></span></div>
            <div class="kpi-sub">Số cuốn đang giữ hiện tại</div>
        </div>

        <div class="kpi-cell">
            <div class="kpi-label">
                <span>PHIẾU CHỜ DUYỆT</span>
                <?= icon('clock', 15) ?>
            </div>
            <div class="kpi-value"><?= $pendingRequests ?></div>
            <div class="kpi-sub">Phiếu đăng ký online</div>
        </div>

        <div class="kpi-cell">
            <div class="kpi-label">
                <span>TIỀN PHẠT QUÁ HẠN</span>
                <?= icon('alert', 15) ?>
            </div>
            <div class="kpi-value <?= $totalFine > 0 ? 'text-danger' : '' ?>"><?= formatVND($totalFine) ?></div>
            <div class="kpi-sub"><?= $overdueCount > 0 ? 'Có ' . $overdueCount . ' phiếu quá hạn trả' : 'Không có phiếu quá hạn' ?></div>
        </div>
    </section>

    <section class="panel" aria-label="Lịch sử phiếu mượn">
        <div class="panel-header">
            <h2 class="panel-title">
                <?= icon('archive', 18) ?>
                <span>DANH SÁCH PHIẾU MƯỢN SÁCH (<?= count($loans) ?> PHIẾU)</span>
            </h2>
            <span class="mono-code" style="font-size: var(--text-xs); color: var(--text-muted);">
                TIỀN PHẠT TRỄ HẠN: <?= formatVND(FINE_PER_DAY_VND) ?> / NGÀY
            </span>
        </div>

        <?php if (empty($loans)): ?>
            <div class="empty-state" style="border: none;">
                <p class="empty-state-title">Bạn chưa có phiếu mượn sách nào</p>
                <p style="margin: 0 auto var(--space-4) auto; font-size: var(--text-sm);">
                    Bạn có thể tìm sách tại trang chủ và bấm đăng ký online để mượn sách tại quầy thư viện.
                </p>
                <a href="<?= e(baseUrl('index.php')) ?>" class="btn btn-primary">
                    <?= icon('search', 16) ?>
                    <span>Tìm sách ngay</span>
                </a>
            </div>
        <?php else: ?>
            <div class="table-responsive" style="border: none;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Mã phiếu</th>
                            <th>Danh sách sách trong phiếu</th>
                            <th>Ngày mượn / Hạn trả</th>
                            <th>Thời hạn / Ngày trả</th>
                            <th class="text-right">Tiền phạt</th>
                            <th>Trạng thái</th>
                            <th class="text-right">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($loans as $loan): ?>
                            <?php
                            $bid = (int)$loan['id'];
                            $items = $itemsByLoan[$bid] ?? [];
                            $daysDiff = getDueDaysDiff($loan['due_date'], $loan['return_date']);
                            $fine = (float)$loan['fine_amount'];
                            ?>
                            <tr>
                                <td>
                                    <strong class="mono-code"><?= e($loan['receipt_code']) ?></strong>
                                    <?php if (!empty($loan['librarian_name'])): ?>
                                        <div style="font-size: var(--text-xs); color: var(--text-muted); margin-top: 4px;">
                                            Thủ thư: <?= e($loan['librarian_name']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if (empty($items)): ?>
                                        <span class="text-muted">Không có dữ liệu sách</span>
                                    <?php else: ?>
                                        <ul style="display: flex; flex-direction: column; gap: 8px;">
                                            <?php foreach ($items as $it): ?>
                                                <li style="padding-bottom: 6px; border-bottom: 1px dashed var(--border-rule);">
                                                    <a href="<?= e(baseUrl('book.php?id=' . (int)$it['book_id'])) ?>" style="font-weight: 700; color: var(--text-ink);">
                                                        <?= e($it['title']) ?>
                                                    </a>
                                                    <div style="font-size: var(--text-xs); color: var(--text-muted); margin-top: 2px;">
                                                        <span class="mono-code" style="color: var(--accent-stamp); font-weight: 600;">Mã sách: <?= e($it['call_number']) ?></span>
                                                        . Kệ: <?= e($it['shelf_location']) ?>
                                                        . Số lượng: <strong class="mono-num"><?= (int)$it['quantity'] ?></strong>
                                                        <?php if ($loan['status'] === 'returned' && $it['return_condition'] !== 'good'): ?>
                                                            . Tình trạng trả: <strong><?= $it['return_condition'] === 'damaged' ? 'Hư hỏng nhẹ' : 'Báo mất' ?></strong>
                                                        <?php endif; ?>
                                                    </div>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                    <?php if (!empty($loan['note'])): ?>
                                        <div style="font-size: var(--text-xs); color: var(--text-muted); margin-top: 6px;">
                                            Ghi chú: <?= e($loan['note']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td class="mono-num">
                                    <div>Mượn: <strong><?= formatDate($loan['borrow_date']) ?></strong></div>
                                    <div style="color: var(--text-muted); margin-top: 2px;">Hạn trả: <strong><?= formatDate($loan['due_date']) ?></strong></div>
                                </td>

                                <td>
                                    <?php if ($loan['status'] === 'returned'): ?>
                                        <span class="mono-num" style="color: var(--status-returned-fg); font-weight: 600;">
                                            Đã trả: <?= formatDate($loan['return_date']) ?>
                                        </span>
                                    <?php elseif ($loan['status'] === 'rejected'): ?>
                                        <span class="text-muted">Phiếu đã bị từ chối</span>
                                    <?php elseif ($loan['status'] === 'pending'): ?>
                                        <span class="text-muted">Chờ duyệt (<?= DEFAULT_BORROW_DAYS ?> ngày)</span>
                                    <?php else: ?>
                                        <?php if ($daysDiff < 0): ?>
                                            <span class="mono-num text-danger font-bold">
                                                Quá hạn <?= abs($daysDiff) ?> ngày
                                            </span>
                                        <?php elseif ($daysDiff === 0): ?>
                                            <span class="mono-num" style="color: var(--status-pending-fg); font-weight: 700;">
                                                Đến hạn trả hôm nay
                                            </span>
                                        <?php else: ?>
                                            <span class="mono-num" style="color: var(--status-borrowed-fg); font-weight: 600;">
                                                Còn <?= $daysDiff ?> ngày
                                            </span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>

                                <td class="text-right mono-num">
                                    <?php if ($fine > 0): ?>
                                        <strong class="text-danger"><?= formatVND($fine) ?></strong>
                                    <?php else: ?>
                                        <span class="text-muted">0 ₫</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?= renderLoanStatusBadge($loan['status']) ?>
                                </td>

                                <td class="text-right">
                                    <?php if ($loan['status'] === 'pending'): ?>
                                        <form action="<?= e(baseUrl('my-loans.php')) ?>" method="post" data-confirm="Bạn có chắc chắn muốn hủy phiếu đăng ký mượn <?= e($loan['receipt_code']) ?> không?">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="cancel_pending">
                                            <input type="hidden" name="borrow_id" value="<?= $bid ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">
                                                <?= icon('trash', 14) ?>
                                                <span>Hủy phiếu</span>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="mono-code text-muted" style="font-size: var(--text-xs);">Đã ghi nhận</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
