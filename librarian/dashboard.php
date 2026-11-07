<?php
require_once __DIR__ . '/../includes/auth.php';
requireLibrarian();

$pdo = getDB();
$pageTitle = 'Thống kê Quản lý Thư viện';
$activeNav = 'dashboard';

$bookStats = $pdo->query("
    SELECT COUNT(*) AS total_titles,
           COALESCE(SUM(total_copies), 0) AS total_copies,
           COALESCE(SUM(available_copies), 0) AS available_copies
    FROM books
")->fetch();

$circulatingBooks = (int)$pdo->query("
    SELECT COALESCE(SUM(bi.quantity), 0)
    FROM borrow_items bi
    INNER JOIN borrow_records br ON br.id = bi.borrow_id
    WHERE br.status IN ('borrowed', 'overdue')
")->fetchColumn();

$overdueStats = $pdo->query("
    SELECT COUNT(*) AS overdue_count,
           COALESCE(SUM(fine_amount), 0) AS total_fine
    FROM borrow_records
    WHERE status = 'overdue'
")->fetch();

$pendingStats = $pdo->query("
    SELECT COUNT(DISTINCT br.id) AS pending_count,
           COALESCE(SUM(bi.quantity), 0) AS pending_books
    FROM borrow_records br
    LEFT JOIN borrow_items bi ON bi.borrow_id = br.id
    WHERE br.status = 'pending'
")->fetch();

$overdueLoans = $pdo->query("
    SELECT br.id, br.receipt_code, br.borrow_date, br.due_date, br.fine_amount, br.note,
           u.member_code, u.full_name, u.phone, u.department,
           GROUP_CONCAT(CONCAT(b.call_number, ' . ', b.title) SEPARATOR ' | ') AS book_list,
           COUNT(bi.id) AS item_count
    FROM borrow_records br
    INNER JOIN users u ON u.id = br.user_id
    LEFT JOIN borrow_items bi ON bi.borrow_id = br.id
    LEFT JOIN books b ON b.id = bi.book_id
    WHERE br.status = 'overdue'
    GROUP BY br.id
    ORDER BY br.due_date ASC
    LIMIT 10
")->fetchAll();

$pendingLoans = $pdo->query("
    SELECT br.id, br.receipt_code, br.borrow_date, br.due_date, br.note,
           u.member_code, u.full_name, u.department,
           GROUP_CONCAT(CONCAT(b.call_number, ' . ', b.title) SEPARATOR ' | ') AS book_list,
           COUNT(bi.id) AS item_count
    FROM borrow_records br
    INNER JOIN users u ON u.id = br.user_id
    LEFT JOIN borrow_items bi ON bi.borrow_id = br.id
    LEFT JOIN books b ON b.id = bi.book_id
    WHERE br.status = 'pending'
    GROUP BY br.id
    ORDER BY br.created_at ASC
    LIMIT 10
")->fetchAll();

$categoryStats = $pdo->query("
    SELECT c.id, c.dewey_code, c.name, c.shelf_zone,
           COUNT(b.id) AS title_count,
           COALESCE(SUM(b.total_copies), 0) AS total_copies,
           COALESCE(SUM(b.available_copies), 0) AS available_copies
    FROM categories c
    LEFT JOIN books b ON b.category_id = c.id
    GROUP BY c.id
    ORDER BY c.dewey_code ASC
")->fetchAll();

$recentLogs = $pdo->query("
    SELECT br.id, br.receipt_code, br.borrow_date, br.due_date, br.return_date, br.status, br.fine_amount,
           u.member_code, u.full_name,
           COUNT(bi.id) AS book_count
    FROM borrow_records br
    INNER JOIN users u ON u.id = br.user_id
    LEFT JOIN borrow_items bi ON bi.borrow_id = br.id
    GROUP BY br.id
    ORDER BY br.created_at DESC, br.id DESC
    LIMIT 6
")->fetchAll();

$totalCopiesAll = max((int)($bookStats['total_copies'] ?? 0), 1);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <section class="page-header">
        <div>
            <div class="page-header-meta">
                <span class="mono-code">TRANG THỦ THƯ . BẢNG THỐNG KÊ</span>
            </div>
            <h1 class="page-title">Thống kê Quản lý Thư viện</h1>
            <p class="page-subtitle">Theo dõi số lượng sách trong kho, kiểm tra phiếu mượn quá hạn và duyệt các yêu cầu mượn sách của sinh viên.</p>
        </div>
        <div class="page-actions">
            <a href="<?= e(baseUrl('librarian/loans.php')) ?>" class="btn btn-primary">
                <?= icon('receipt', 16) ?>
                <span>Quản lý Mượn - Trả</span>
            </a>
            <a href="<?= e(baseUrl('librarian/books.php')) ?>" class="btn btn-secondary">
                <?= icon('plus', 16) ?>
                <span>Thêm sách mới</span>
            </a>
        </div>
    </section>

    <section class="kpi-strip" aria-label="Thống kê thư viện">
        <article class="kpi-cell">
            <span class="kpi-label">TỔNG SỐ SÁCH TRONG KHO</span>
            <strong class="kpi-value mono-num"><?= (int)$bookStats['total_copies'] ?></strong>
            <span class="kpi-sub">Thuộc <strong class="mono-num"><?= (int)$bookStats['total_titles'] ?></strong> đầu sách các loại</span>
        </article>

        <article class="kpi-cell">
            <span class="kpi-label">SÁCH ĐANG ĐƯỢC MƯỢN</span>
            <strong class="kpi-value mono-num"><?= $circulatingBooks ?></strong>
            <span class="kpi-sub">Còn <strong class="mono-num"><?= (int)$bookStats['available_copies'] ?></strong> cuốn sẵn có trên kệ</span>
        </article>

        <article class="kpi-cell">
            <span class="kpi-label">PHIẾU MƯỢN QUÁ HẠN</span>
            <strong class="kpi-value mono-num"><?= (int)$overdueStats['overdue_count'] ?></strong>
            <span class="kpi-sub">Tiền phạt dự kiến: <strong class="mono-num"><?= formatVND($overdueStats['total_fine']) ?></strong></span>
        </article>

        <article class="kpi-cell">
            <span class="kpi-label">YÊU CẦU MƯỢN CHỜ DUYỆT</span>
            <strong class="kpi-value mono-num"><?= (int)$pendingStats['pending_count'] ?></strong>
            <span class="kpi-sub">Tổng cộng <strong class="mono-num"><?= (int)$pendingStats['pending_books'] ?></strong> cuốn đăng ký mượn</span>
        </article>
    </section>

    <div class="split-layout">
        <div class="main-column">

            <section class="panel" style="margin-bottom: 24px;">
                <div class="panel-header">
                    <div>
                        <h2 class="panel-title">
                            <?= icon('alert', 18) ?>
                            <span>Danh sách Phiếu mượn Quá hạn</span>
                        </h2>
                    </div>
                    <a href="<?= e(baseUrl('librarian/loans.php?status=overdue')) ?>" class="btn btn-ghost btn-sm">
                        <span>Xem tất cả</span>
                        <?= icon('arrow-right', 14) ?>
                    </a>
                </div>
                <div class="panel-body">
                    <?php if (empty($overdueLoans)): ?>
                        <div class="empty-state">
                            <?= icon('check', 24) ?>
                            <p>Hiện không có phiếu mượn nào bị quá hạn trả sách.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Mã phiếu</th>
                                        <th>Độc giả</th>
                                        <th>Sách đang mượn</th>
                                        <th>Hạn trả</th>
                                        <th class="text-right">Tiền phạt</th>
                                        <th class="text-right">Thao tác</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($overdueLoans as $loan): ?>
                                        <?php $daysLate = abs(getDueDaysDiff($loan['due_date'])); ?>
                                        <tr>
                                            <td>
                                                <span class="mono-code"><?= e($loan['receipt_code']) ?></span>
                                            </td>
                                            <td>
                                                <strong><?= e($loan['full_name']) ?></strong>
                                                <div class="form-help">
                                                    <span class="mono-code"><?= e($loan['member_code']) ?></span>
                                                    <?php if (!empty($loan['phone'])): ?>
                                                        <span> . <?= e($loan['phone']) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div><strong class="mono-num"><?= (int)$loan['item_count'] ?> cuốn</strong></div>
                                                <div class="form-help"><?= e($loan['book_list']) ?></div>
                                            </td>
                                            <td>
                                                <span class="mono-num"><?= formatDate($loan['due_date']) ?></span>
                                                <div class="form-help" style="color: #B42318; font-weight: 600;">
                                                    Trễ <span class="mono-num"><?= $daysLate ?></span> ngày
                                                </div>
                                            </td>
                                            <td class="text-right mono-num" style="font-weight: 600; color: #B42318;">
                                                <?= formatVND($loan['fine_amount']) ?>
                                            </td>
                                            <td class="text-right">
                                                <a href="<?= e(baseUrl('librarian/loans.php?status=overdue&q=' . urlencode($loan['receipt_code']))) ?>" class="btn btn-secondary btn-sm">
                                                    <span>Nhận trả sách</span>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="panel">
                <div class="panel-header">
                    <div>
                        <h2 class="panel-title">
                            <?= icon('clock', 18) ?>
                            <span>Yêu cầu Mượn Sách Chờ duyệt</span>
                        </h2>
                    </div>
                    <a href="<?= e(baseUrl('librarian/loans.php?status=pending')) ?>" class="btn btn-ghost btn-sm">
                        <span>Mở danh sách duyệt</span>
                        <?= icon('arrow-right', 14) ?>
                    </a>
                </div>
                <div class="panel-body">
                    <?php if (empty($pendingLoans)): ?>
                        <div class="empty-state">
                            <?= icon('check', 24) ?>
                            <p>Tất cả yêu cầu đăng ký mượn sách đã được xử lý.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Mã phiếu</th>
                                        <th>Độc giả đăng ký</th>
                                        <th>Sách yêu cầu</th>
                                        <th>Ngày gửi</th>
                                        <th class="text-right">Thao tác nhanh</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pendingLoans as $req): ?>
                                        <tr>
                                            <td>
                                                <span class="mono-code"><?= e($req['receipt_code']) ?></span>
                                            </td>
                                            <td>
                                                <strong><?= e($req['full_name']) ?></strong>
                                                <div class="form-help">
                                                    <span class="mono-code"><?= e($req['member_code']) ?></span>
                                                    <span> . <?= e($req['department'] ?? 'Độc giả') ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div><strong class="mono-num"><?= (int)$req['item_count'] ?> cuốn</strong></div>
                                                <div class="form-help"><?= e($req['book_list']) ?></div>
                                            </td>
                                            <td class="mono-num"><?= formatDate($req['borrow_date']) ?></td>
                                            <td class="text-right">
                                                <a href="<?= e(baseUrl('librarian/loans.php?status=pending&q=' . urlencode($req['receipt_code']))) ?>" class="btn btn-primary btn-sm">
                                                    <?= icon('check', 14) ?>
                                                    <span>Xét duyệt</span>
                                                </a>
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

        <aside class="side-column">

            <section class="panel" style="margin-bottom: 24px;">
                <div class="panel-header">
                    <h2 class="panel-title">
                        <?= icon('archive', 18) ?>
                        <span>Thống kê Sách theo Thể loại</span>
                    </h2>
                    <a href="<?= e(baseUrl('librarian/categories.php')) ?>" class="btn btn-ghost btn-sm">Chi tiết</a>
                </div>
                <div class="panel-body">
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Mã</th>
                                    <th>Tên thể loại</th>
                                    <th class="text-right">Còn / Tổng</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($categoryStats as $cat): ?>
                                    <?php
                                    $copies = (int)$cat['total_copies'];
                                    $avail = (int)$cat['available_copies'];
                                    $ratio = round(($copies / $totalCopiesAll) * 100);
                                    ?>
                                    <tr>
                                        <td>
                                            <span class="mono-code"><?= e($cat['dewey_code']) ?></span>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600; font-size: 0.875rem; margin-bottom: 4px;">
                                                <?= e($cat['name']) ?>
                                            </div>
                                            <div style="background: #E4E7EC; height: 6px; width: 100%; overflow: hidden; border-radius: 2px;">
                                                <div style="background: #141619; height: 100%; width: <?= $ratio ?>%;"></div>
                                            </div>
                                            <div class="form-help" style="margin-top: 4px;">
                                                <?= e($cat['shelf_zone']) ?> . <span class="mono-num"><?= (int)$cat['title_count'] ?></span> đầu sách (<span class="mono-num"><?= $ratio ?>%</span> kho)
                                            </div>
                                        </td>
                                        <td class="text-right mono-num">
                                            <strong><?= $avail ?></strong> / <?= $copies ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <section class="panel">
                <div class="panel-header">
                    <h2 class="panel-title">
                        <?= icon('receipt', 18) ?>
                        <span>Phiếu mượn Mới nhất</span>
                    </h2>
                </div>
                <div class="panel-body">
                    <?php if (empty($recentLogs)): ?>
                        <div class="empty-state">
                            <p>Chưa có phiếu mượn nào.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Phiếu / Độc giả</th>
                                        <th>Hạn trả</th>
                                        <th class="text-right">Trạng thái</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentLogs as $log): ?>
                                        <tr>
                                            <td>
                                                <a href="<?= e(baseUrl('librarian/loans.php?q=' . urlencode($log['receipt_code']))) ?>" class="mono-code">
                                                    <?= e($log['receipt_code']) ?>
                                                </a>
                                                <div class="form-help">
                                                    <?= e($log['full_name']) ?> (<span class="mono-num"><?= (int)$log['book_count'] ?></span> cuốn)
                                                </div>
                                            </td>
                                            <td class="mono-num">
                                                <?= formatDate($log['due_date']) ?>
                                            </td>
                                            <td class="text-right">
                                                <?= renderLoanStatusBadge($log['status']) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </aside>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
