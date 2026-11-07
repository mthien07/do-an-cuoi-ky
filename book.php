<?php

require_once __DIR__ . '/includes/auth.php';

$pdo = getDB();
$bookId = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['book_id'] ?? 0);

if ($bookId <= 0) {
    setFlash('error', 'Mã sách không hợp lệ.');
    header('Location: ' . baseUrl('index.php'));
    exit;
}

$stmt = $pdo->prepare("
    SELECT b.*, c.dewey_code, c.name AS category_name, c.shelf_zone
    FROM books b
    INNER JOIN categories c ON c.id = b.category_id
    WHERE b.id = :id
    LIMIT 1
");
$stmt->execute(['id' => $bookId]);
$book = $stmt->fetch();

if (!$book) {
    setFlash('error', 'Không tìm thấy thông tin cuốn sách này trong thư viện.');
    header('Location: ' . baseUrl('index.php'));
    exit;
}

$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_borrow') {
    if (!isLoggedIn()) {
        setFlash('error', 'Vui lòng đăng nhập tài khoản để đăng ký mượn sách.');
        header('Location: ' . baseUrl('login.php'));
        exit;
    }

    if (!verifyCsrf()) {
        setFlash('error', 'Phiên làm việc không hợp lệ. Vui lòng thử lại.');
        header('Location: ' . baseUrl('book.php?id=' . $bookId));
        exit;
    }

    if (!isReader()) {
        setFlash('error', 'Tài khoản Thủ thư vui lòng tạo phiếu trực tiếp tại mục Quản lý Mượn - Trả.');
        header('Location: ' . baseUrl('book.php?id=' . $bookId));
        exit;
    }

    $userStmt = $pdo->prepare("SELECT status FROM users WHERE id = :uid LIMIT 1");
    $userStmt->execute(['uid' => (int)$user['id']]);
    $liveUser = $userStmt->fetch();

    if (!$liveUser || $liveUser['status'] === 'locked') {
        setFlash('error', 'Thẻ thư viện của bạn đang bị khóa. Vui lòng liên hệ thủ thư để mở lại.');
        header('Location: ' . baseUrl('book.php?id=' . $bookId));
        exit;
    }

    if ((int)$book['available_copies'] <= 0) {
        setFlash('error', 'Sách này hiện đã được mượn hết. Vui lòng chọn sách khác.');
        header('Location: ' . baseUrl('book.php?id=' . $bookId));
        exit;
    }

    $dupStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM borrow_items bi
        INNER JOIN borrow_records br ON br.id = bi.borrow_id
        WHERE br.user_id = :uid
          AND bi.book_id = :bid
          AND br.status IN ('pending', 'borrowed', 'overdue')
    ");
    $dupStmt->execute([
        'uid' => (int)$user['id'],
        'bid' => $bookId,
    ]);
    if ((int)$dupStmt->fetchColumn() > 0) {
        setFlash('error', 'Bạn đang mượn hoặc đã gửi yêu cầu mượn cuốn sách này rồi.');
        header('Location: ' . baseUrl('book.php?id=' . $bookId));
        exit;
    }

    $activeCountStmt = $pdo->prepare("
        SELECT COALESCE(SUM(bi.quantity), 0)
        FROM borrow_items bi
        INNER JOIN borrow_records br ON br.id = bi.borrow_id
        WHERE br.user_id = :uid
          AND br.status IN ('pending', 'borrowed', 'overdue')
    ");
    $activeCountStmt->execute(['uid' => (int)$user['id']]);
    $currentActiveBooks = (int)$activeCountStmt->fetchColumn();

    if ($currentActiveBooks >= MAX_ACTIVE_LOANS_PER_READER) {
        setFlash('error', 'Bạn đã mượn tối đa ' . MAX_ACTIVE_LOANS_PER_READER . ' cuốn sách. Vui lòng trả sách cũ trước khi mượn thêm.');
        header('Location: ' . baseUrl('book.php?id=' . $bookId));
        exit;
    }

    $note = trim((string)($_POST['note'] ?? ''));
    if ($note === '') {
        $note = 'Đăng ký mượn sách online';
    }

    try {
        $pdo->beginTransaction();

        $receiptCode = generateReceiptCode($pdo);
        $borrowDate = date('Y-m-d');
        $dueDate = date('Y-m-d', strtotime('+' . DEFAULT_BORROW_DAYS . ' days'));

        $insertRecord = $pdo->prepare("
            INSERT INTO borrow_records (receipt_code, user_id, issued_by, borrow_date, due_date, status, fine_amount, note)
            VALUES (:code, :uid, NULL, :bdate, :ddate, 'pending', 0, :note)
        ");
        $insertRecord->execute([
            'code'  => $receiptCode,
            'uid'   => (int)$user['id'],
            'bdate' => $borrowDate,
            'ddate' => $dueDate,
            'note'  => mb_substr($note, 0, 250),
        ]);

        $borrowId = (int)$pdo->lastInsertId();

        $insertItem = $pdo->prepare("
            INSERT INTO borrow_items (borrow_id, book_id, quantity, return_condition)
            VALUES (:borrow_id, :book_id, 1, 'good')
        ");
        $insertItem->execute([
            'borrow_id' => $borrowId,
            'book_id'   => $bookId,
        ]);

        $pdo->commit();

        setFlash('success', 'Đã gửi yêu cầu mượn sách (Mã phiếu: ' . $receiptCode . ', hạn trả dự kiến: ' . formatDate($dueDate) . '). Vui lòng chờ Thủ thư duyệt.');
        header('Location: ' . baseUrl('my-loans.php'));
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setFlash('error', 'Có lỗi xảy ra khi tạo phiếu mượn. Vui lòng thử lại.');
        header('Location: ' . baseUrl('book.php?id=' . $bookId));
        exit;
    }
}

$alreadyHolding = false;
$currentActiveTotal = 0;
if ($user && $user['role'] === 'reader') {
    $chkStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM borrow_items bi
        INNER JOIN borrow_records br ON br.id = bi.borrow_id
        WHERE br.user_id = :uid AND bi.book_id = :bid AND br.status IN ('pending', 'borrowed', 'overdue')
    ");
    $chkStmt->execute(['uid' => (int)$user['id'], 'bid' => $bookId]);
    $alreadyHolding = ((int)$chkStmt->fetchColumn() > 0);

    $cntStmt = $pdo->prepare("
        SELECT COALESCE(SUM(bi.quantity), 0)
        FROM borrow_items bi
        INNER JOIN borrow_records br ON br.id = bi.borrow_id
        WHERE br.user_id = :uid AND br.status IN ('pending', 'borrowed', 'overdue')
    ");
    $cntStmt->execute(['uid' => (int)$user['id']]);
    $currentActiveTotal = (int)$cntStmt->fetchColumn();
}

$relStmt = $pdo->prepare("
    SELECT b.*, c.dewey_code, c.name AS category_name
    FROM books b
    INNER JOIN categories c ON c.id = b.category_id
    WHERE b.category_id = :cid AND b.id != :bid
    ORDER BY b.call_number ASC
    LIMIT 4
");
$relStmt->execute([
    'cid' => (int)$book['category_id'],
    'bid' => $bookId,
]);
$relatedBooks = $relStmt->fetchAll();

$avail = (int)$book['available_copies'];
$total = (int)$book['total_copies'];
$isAvail = $avail > 0;

$pageTitle = $book['title'] . ' (' . $book['call_number'] . ')';
$activeNav = 'catalog';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="page-header">
        <div>
            <div class="page-header-meta">
                <?= icon('book', 14) ?>
                <span>THÔNG TIN CHI TIẾT SÁCH</span>
            </div>
            <h1 class="page-title"><?= e($book['title']) ?></h1>
            <p class="page-subtitle">Tác giả: <strong><?= e($book['author']) ?></strong> &mdash; Thể loại: <?= e($book['category_name']) ?> (Mã: <?= e($book['dewey_code']) ?>)</p>
        </div>
        <div class="page-actions">
            <a href="<?= e(baseUrl('index.php')) ?>" class="btn btn-secondary">
                <?= icon('arrow-left', 16) ?>
                <span>Quay lại trang tìm sách</span>
            </a>
            <?php if (isLibrarian()): ?>
                <a href="<?= e(baseUrl('librarian/books.php')) ?>" class="btn btn-primary">
                    <?= icon('edit', 16) ?>
                    <span>Chỉnh sửa trong Quản lý Sách</span>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="split-layout">

        <article class="book-detail-sheet">
            <div class="book-detail-header">
                <span>MÃ SÁCH: <strong><?= e($book['call_number']) ?></strong></span>
                <span>ID SÁCH: #BK-<?= str_pad((string)$book['id'], 4, '0', STR_PAD_LEFT) ?></span>
            </div>

            <div class="book-meta-grid">
                <div class="book-meta-cell">
                    <div class="book-meta-label">MÃ ISBN</div>
                    <div class="book-meta-val"><?= e($book['isbn']) ?></div>
                </div>
                <div class="book-meta-cell">
                    <div class="book-meta-label">THỂ LOẠI</div>
                    <div class="book-meta-val"><?= e($book['dewey_code']) ?></div>
                </div>
                <div class="book-meta-cell">
                    <div class="book-meta-label">NĂM XUẤT BẢN</div>
                    <div class="book-meta-val"><?= (int)$book['publish_year'] ?></div>
                </div>
                <div class="book-meta-cell">
                    <div class="book-meta-label">SỐ TRANG</div>
                    <div class="book-meta-val"><?= (int)$book['pages'] ?> trang</div>
                </div>
            </div>

            <div class="panel-body">
                <div class="table-responsive" style="margin-bottom: var(--space-6);">
                    <table class="data-table">
                        <tbody>
                            <tr>
                                <th style="width: 220px;">Tên sách</th>
                                <td><strong><?= e($book['title']) ?></strong></td>
                            </tr>
                            <tr>
                                <th>Tác giả</th>
                                <td><?= e($book['author']) ?></td>
                            </tr>
                            <tr>
                                <th>Nhà xuất bản</th>
                                <td><?= e($book['publisher']) ?> (Năm xuất bản: <span class="mono-num"><?= (int)$book['publish_year'] ?></span>)</td>
                            </tr>
                            <tr>
                                <th>Thể loại sách</th>
                                <td><span class="mono-code">[<?= e($book['dewey_code']) ?>]</span> <?= e($book['category_name']) ?> (<?= e($book['shelf_zone']) ?>)</td>
                            </tr>
                            <tr>
                                <th>Vị trí kệ sách</th>
                                <td>
                                    <span class="mono-code" style="color: var(--accent-stamp); font-weight: 700;">
                                        <?= e($book['shelf_location']) ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <th>Số lượng sách</th>
                                <td>
                                    <span class="copy-counter <?= $isAvail ? 'is-available' : 'is-out' ?>">
                                        <?= $isAvail ? 'Còn ' . $avail . ' / ' . $total . ' cuốn có sẵn trên kệ' : 'Đã mượn hết (0 / ' . $total . ' cuốn)' ?>
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <h2 style="font-size: var(--text-sm); text-transform: uppercase; font-weight: 700; color: var(--text-secondary); margin-bottom: var(--space-2);">
                    MÔ TẢ NỘI DUNG SÁCH
                </h2>
                <p style="color: var(--text-ink); line-height: 1.65; background-color: var(--bg-paper); padding: var(--space-4); border: 1px solid var(--border-rule);">
                    <?= !empty($book['summary']) ? nl2br(e($book['summary'])) : 'Chưa có mô tả nội dung cho cuốn sách này.' ?>
                </p>
            </div>
        </article>

        <aside class="panel">
            <div class="panel-header">
                <h2 class="panel-title">
                    <?= icon('receipt', 18) ?>
                    <span>PHIẾU ĐĂNG KÝ MƯỢN SÁCH</span>
                </h2>
                <span class="mono-code" style="font-size: var(--text-xs); color: var(--text-muted);">ĐĂNG KÝ MƯỢN</span>
            </div>

            <div class="panel-body">
                <div style="padding: var(--space-3) var(--space-4); background-color: var(--bg-paper); border: 1px solid var(--border-rule); margin-bottom: var(--space-4); font-size: var(--text-xs);">
                    <p style="margin-bottom: 6px;">
                        <strong>Thời hạn mượn mặc định:</strong> <span class="mono-num"><?= DEFAULT_BORROW_DAYS ?> ngày</span> (Hạn trả dự kiến: <strong class="mono-num"><?= date('d/m/Y', strtotime('+' . DEFAULT_BORROW_DAYS . ' days')) ?></strong>)
                    </p>
                    <p style="margin-bottom: 6px;">
                        <strong>Số sách mượn tối đa:</strong> Tối đa <span class="mono-num"><?= MAX_ACTIVE_LOANS_PER_READER ?> cuốn</span> đang giữ hoặc chờ duyệt cùng lúc.
                    </p>
                    <p>
                        <strong>Tiền phạt quá hạn:</strong> <span class="mono-num"><?= formatVND(FINE_PER_DAY_VND) ?> / ngày</span>.
                    </p>
                </div>

                <?php if (!$user): ?>
                    <div class="flash-banner flash-info" style="margin-bottom: var(--space-4);">
                        <?= icon('user', 18) ?>
                        <span>Bạn cần đăng nhập tài khoản Sinh viên để gửi yêu cầu mượn cuốn sách này.</span>
                    </div>
                    <div style="display: flex; gap: var(--space-3);">
                        <a href="<?= e(baseUrl('login.php')) ?>" class="btn btn-primary btn-block">
                            <?= icon('user', 16) ?>
                            <span>Đăng nhập ngay</span>
                        </a>
                        <a href="<?= e(baseUrl('register.php')) ?>" class="btn btn-secondary btn-block">
                            <span>Đăng ký thẻ mới</span>
                        </a>
                    </div>
                <?php elseif (isLibrarian()): ?>
                    <div class="flash-banner flash-info" style="margin-bottom: var(--space-4);">
                        <?= icon('archive', 18) ?>
                        <span>Bạn đang đăng nhập bằng tài khoản Thủ thư. Vui lòng vào trang Quản lý Mượn - Trả để lập phiếu mượn cho sinh viên.</span>
                    </div>
                    <a href="<?= e(baseUrl('librarian/loans.php')) ?>" class="btn btn-primary btn-block">
                        <?= icon('receipt', 16) ?>
                        <span>Mở trang Quản lý Mượn - Trả</span>
                    </a>
                <?php elseif ($alreadyHolding): ?>
                    <div class="flash-banner flash-info" style="margin-bottom: var(--space-4);">
                        <?= icon('check', 18) ?>
                        <span>Bạn đang mượn hoặc đã có phiếu đăng ký chờ duyệt cho cuốn sách này.</span>
                    </div>
                    <a href="<?= e(baseUrl('my-loans.php')) ?>" class="btn btn-secondary btn-block">
                        <?= icon('receipt', 16) ?>
                        <span>Xem Sách đang mượn</span>
                    </a>
                <?php elseif (!$isAvail): ?>
                    <div class="flash-banner flash-error">
                        <?= icon('alert', 18) ?>
                        <span>Tất cả <?= $total ?> cuốn của đầu sách này hiện đã được mượn hết. Vui lòng quay lại khi có người trả sách.</span>
                    </div>
                <?php elseif ($currentActiveTotal >= MAX_ACTIVE_LOANS_PER_READER): ?>
                    <div class="flash-banner flash-error">
                        <?= icon('alert', 18) ?>
                        <span>Tài khoản của bạn đang giữ <?= $currentActiveTotal ?>/<?= MAX_ACTIVE_LOANS_PER_READER ?> cuốn (đã đạt mức tối đa). Vui lòng trả bớt sách trước khi mượn thêm.</span>
                    </div>
                <?php else: ?>
                    <form action="<?= e(baseUrl('book.php?id=' . $bookId)) ?>" method="post">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="request_borrow">
                        <input type="hidden" name="book_id" value="<?= $bookId ?>">

                        <div class="form-group">
                            <label class="form-label">Thông tin sinh viên mượn</label>
                            <input
                                type="text"
                                class="form-control mono-code"
                                value="<?= e($user['member_code'] . ' - ' . $user['full_name']) ?>"
                                readonly
                            >
                            <span class="form-help">Số sách đang mượn / chờ duyệt: <strong class="mono-num"><?= $currentActiveTotal ?> / <?= MAX_ACTIVE_LOANS_PER_READER ?></strong> cuốn</span>
                        </div>

                        <div class="form-group">
                            <label for="borrow-note" class="form-label">Ghi chú khi mượn (Không bắt buộc)</label>
                            <textarea
                                id="borrow-note"
                                name="note"
                                class="form-textarea"
                                rows="3"
                                placeholder="VD: Mượn sách tham khảo làm đồ án môn Lập trình Web..."
                            ></textarea>
                        </div>

                        <button type="submit" class="btn btn-primary btn-block">
                            <?= icon('check', 18) ?>
                            <span>Gửi đăng ký mượn sách (<?= DEFAULT_BORROW_DAYS ?> ngày)</span>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </aside>
    </div>

    <?php if (!empty($relatedBooks)): ?>
        <section style="margin-top: var(--space-8);" aria-label="Sách cùng thể loại">
            <div class="page-header" style="padding-top: var(--space-4); margin-bottom: var(--space-4);">
                <div>
                    <div class="page-header-meta">SÁCH CÙNG THỂ LOẠI (MÃ: <?= e($book['dewey_code']) ?>)</div>
                    <h2 style="font-size: var(--text-xl);">Các sách khác thuộc nhóm <?= e($book['category_name']) ?></h2>
                </div>
                <a href="<?= e(baseUrl('index.php?cat=' . urlencode($book['dewey_code']))) ?>" class="btn btn-ghost btn-sm">
                    <span>Xem tất cả thể loại <?= e($book['dewey_code']) ?></span>
                    <?= icon('arrow-right', 14) ?>
                </a>
            </div>

            <div class="catalog-grid">
                <?php foreach ($relatedBooks as $rel): ?>
                    <?php
                    $relAvail = (int)$rel['available_copies'];
                    $relTotal = (int)$rel['total_copies'];
                    ?>
                    <article class="index-card">
                        <div class="call-spine">
                            <span class="call-number-tag">Mã sách: <?= e($rel['call_number']) ?></span>
                            <span class="dewey-tag">Thể loại: <?= e($rel['dewey_code']) ?></span>
                        </div>
                        <div class="index-card-body">
                            <h3 class="index-card-title">
                                <a href="<?= e(baseUrl('book.php?id=' . (int)$rel['id'])) ?>">
                                    <?= e($rel['title']) ?>
                                </a>
                            </h3>
                            <p class="index-card-author"><?= e($rel['author']) ?></p>
                            <p class="index-card-pub"><?= e($rel['publisher']) ?> . Năm XB: <?= (int)$rel['publish_year'] ?></p>
                        </div>
                        <div class="index-card-footer">
                            <span class="shelf-tag">
                                <?= icon('archive', 14) ?>
                                <span><?= e($rel['shelf_location']) ?></span>
                            </span>
                            <div style="display: flex; align-items: center; gap: var(--space-2);">
                                <span class="copy-counter <?= $relAvail > 0 ? 'is-available' : 'is-out' ?>">
                                    Còn <?= $relAvail ?>/<?= $relTotal ?>
                                </span>
                                <a href="<?= e(baseUrl('book.php?id=' . (int)$rel['id'])) ?>" class="btn btn-secondary btn-sm">
                                    <span>Xem chi tiết</span>
                                </a>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
