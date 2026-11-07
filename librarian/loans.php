<?php
require_once __DIR__ . '/../includes/auth.php';
requireLibrarian();

$pdo = getDB();
$currentUser = currentUser();
$pageTitle = 'Quản lý Mượn - Trả Sách';
$activeNav = 'loans';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('error', 'Phiên làm việc không hợp lệ. Vui lòng thử lại.');
        header('Location: ' . baseUrl('librarian/loans.php'));
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create_loan') {
        $readerId = (int)($_POST['user_id'] ?? 0);
        $dueDate  = trim((string)($_POST['due_date'] ?? ''));
        $note     = trim((string)($_POST['note'] ?? ''));
        $rawBooks = $_POST['book_ids'] ?? [];

        $bookIds = [];
        if (is_array($rawBooks)) {
            foreach ($rawBooks as $bId) {
                $id = (int)$bId;
                if ($id > 0 && !in_array($id, $bookIds, true)) {
                    $bookIds[] = $id;
                }
            }
        }

        if ($readerId <= 0) {
            setFlash('error', 'Vui lòng chọn độc giả mượn sách.');
            header('Location: ' . baseUrl('librarian/loans.php'));
            exit;
        }

        if (empty($bookIds) || count($bookIds) > MAX_BOOKS_PER_LOAN) {
            setFlash('error', 'Mỗi phiếu mượn cần chọn từ 1 đến tối đa ' . MAX_BOOKS_PER_LOAN . ' cuốn sách khác nhau.');
            header('Location: ' . baseUrl('librarian/loans.php'));
            exit;
        }

        if ($dueDate === '' || strtotime($dueDate) < strtotime('today')) {
            $dueDate = date('Y-m-d', strtotime('+' . DEFAULT_BORROW_DAYS . ' days'));
        }

        try {
            $pdo->beginTransaction();

            $stmtUser = $pdo->prepare("SELECT id, full_name, member_code, status FROM users WHERE id = :id FOR UPDATE");
            $stmtUser->execute(['id' => $readerId]);
            $reader = $stmtUser->fetch();

            if (!$reader || $reader['status'] !== 'active') {
                throw new Exception('Tài khoản độc giả không tồn tại hoặc đang bị khóa.');
            }

            $stmtActiveCount = $pdo->prepare("
                SELECT COALESCE(SUM(bi.quantity), 0)
                FROM borrow_items bi
                INNER JOIN borrow_records br ON br.id = bi.borrow_id
                WHERE br.user_id = :user_id AND br.status IN ('borrowed', 'overdue')
            ");
            $stmtActiveCount->execute(['user_id' => $readerId]);
            $currentHolding = (int)$stmtActiveCount->fetchColumn();

            if ($currentHolding + count($bookIds) > MAX_ACTIVE_LOANS_PER_READER) {
                throw new Exception(
                    sprintf(
                        'Độc giả %s đang mượn %d cuốn (tối đa %d cuốn cùng lúc). Không thể mượn thêm %d cuốn.',
                        $reader['member_code'],
                        $currentHolding,
                        MAX_ACTIVE_LOANS_PER_READER,
                        count($bookIds)
                    )
                );
            }

            $stmtCheckBook = $pdo->prepare("SELECT id, title, call_number, available_copies FROM books WHERE id = :id FOR UPDATE");
            $stmtDecBook   = $pdo->prepare("UPDATE books SET available_copies = available_copies - 1 WHERE id = :id AND available_copies > 0");

            foreach ($bookIds as $bId) {
                $stmtCheckBook->execute(['id' => $bId]);
                $book = $stmtCheckBook->fetch();
                if (!$book) {
                    throw new Exception('Không tìm thấy sách mã #' . $bId . ' trong kho.');
                }
                if ((int)$book['available_copies'] <= 0) {
                    throw new Exception('Sách "' . $book['title'] . '" (' . $book['call_number'] . ') đã hết trên kệ.');
                }
            }

            $receiptCode = generateReceiptCode($pdo);
            $stmtInsertRecord = $pdo->prepare("
                INSERT INTO borrow_records (receipt_code, user_id, issued_by, borrow_date, due_date, status, fine_amount, note)
                VALUES (:receipt_code, :user_id, :issued_by, CURDATE(), :due_date, 'borrowed', 0, :note)
            ");
            $stmtInsertRecord->execute([
                'receipt_code' => $receiptCode,
                'user_id'      => $readerId,
                'issued_by'    => $currentUser['id'] ?? null,
                'due_date'     => $dueDate,
                'note'         => $note !== '' ? $note : 'Lập phiếu mượn trực tiếp tại thư viện',
            ]);

            $borrowId = (int)$pdo->lastInsertId();
            $stmtInsertItem = $pdo->prepare("
                INSERT INTO borrow_items (borrow_id, book_id, quantity, return_condition)
                VALUES (:borrow_id, :book_id, 1, 'good')
            ");

            foreach ($bookIds as $bId) {
                $stmtDecBook->execute(['id' => $bId]);
                if ($stmtDecBook->rowCount() === 0) {
                    throw new Exception('Không thể cập nhật số lượng cho sách #' . $bId . '.');
                }
                $stmtInsertItem->execute([
                    'borrow_id' => $borrowId,
                    'book_id'   => $bId,
                ]);
            }

            $pdo->commit();
            setFlash('success', 'Đã lập phiếu mượn ' . $receiptCode . ' thành công cho độc giả ' . $reader['full_name'] . '.');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            setFlash('error', $e->getMessage());
        }

        header('Location: ' . baseUrl('librarian/loans.php'));
        exit;
    }

    if ($action === 'approve_loan') {
        $loanId = (int)($_POST['loan_id'] ?? 0);

        try {
            $pdo->beginTransaction();

            $stmtLoan = $pdo->prepare("
                SELECT br.*, u.full_name, u.member_code, u.status AS user_status
                FROM borrow_records br
                INNER JOIN users u ON u.id = br.user_id
                WHERE br.id = :id
                FOR UPDATE
            ");
            $stmtLoan->execute(['id' => $loanId]);
            $loan = $stmtLoan->fetch();

            if (!$loan || $loan['status'] !== 'pending') {
                throw new Exception('Phiếu mượn không ở trạng thái chờ duyệt hoặc đã được xử lý.');
            }

            if ($loan['user_status'] !== 'active') {
                throw new Exception('Tài khoản độc giả ' . $loan['member_code'] . ' đang bị khóa, không thể duyệt phiếu.');
            }

            $stmtItems = $pdo->prepare("
                SELECT bi.book_id, bi.quantity, b.title, b.call_number, b.available_copies
                FROM borrow_items bi
                INNER JOIN books b ON b.id = bi.book_id
                WHERE bi.borrow_id = :borrow_id
                FOR UPDATE
            ");
            $stmtItems->execute(['borrow_id' => $loanId]);
            $items = $stmtItems->fetchAll();

            if (empty($items)) {
                throw new Exception('Phiếu mượn không có sách nào bên trong.');
            }

            $stmtDecBook = $pdo->prepare("
                UPDATE books
                SET available_copies = available_copies - :qty
                WHERE id = :id AND available_copies >= :qty_check
            ");

            foreach ($items as $item) {
                $qty = max((int)$item['quantity'], 1);
                if ((int)$item['available_copies'] < $qty) {
                    throw new Exception(
                        'Sách "' . $item['title'] . '" (' . $item['call_number'] . ') đã hết trong kho (Còn: ' . $item['available_copies'] . ').'
                    );
                }
                $stmtDecBook->execute([
                    'qty'       => $qty,
                    'id'        => (int)$item['book_id'],
                    'qty_check' => $qty,
                ]);
                if ($stmtDecBook->rowCount() === 0) {
                    throw new Exception('Không đủ số lượng sách "' . $item['title'] . '" trong kho.');
                }
            }

            $stmtUpdateLoan = $pdo->prepare("
                UPDATE borrow_records
                SET status = 'borrowed',
                    issued_by = :issued_by,
                    borrow_date = CURDATE(),
                    due_date = DATE_ADD(CURDATE(), INTERVAL " . (int)DEFAULT_BORROW_DAYS . " DAY)
                WHERE id = :id
            ");
            $stmtUpdateLoan->execute([
                'issued_by' => $currentUser['id'] ?? null,
                'id'        => $loanId,
            ]);

            $pdo->commit();
            setFlash('success', 'Đã duyệt phiếu mượn ' . $loan['receipt_code'] . ' thành công.');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            setFlash('error', $e->getMessage());
        }

        header('Location: ' . baseUrl('librarian/loans.php'));
        exit;
    }

    if ($action === 'reject_loan') {
        $loanId = (int)($_POST['loan_id'] ?? 0);
        $reason = trim((string)($_POST['reject_reason'] ?? ''));

        try {
            $pdo->beginTransaction();

            $stmtLoan = $pdo->prepare("SELECT id, receipt_code, status, note FROM borrow_records WHERE id = :id FOR UPDATE");
            $stmtLoan->execute(['id' => $loanId]);
            $loan = $stmtLoan->fetch();

            if (!$loan || $loan['status'] !== 'pending') {
                throw new Exception('Chỉ có thể từ chối phiếu đang chờ duyệt.');
            }

            $finalNote = $reason !== '' ? 'Từ chối duyệt: ' . $reason : 'Thủ thư từ chối yêu cầu mượn sách';

            $stmtReject = $pdo->prepare("
                UPDATE borrow_records
                SET status = 'rejected',
                    issued_by = :issued_by,
                    note = :note
                WHERE id = :id
            ");
            $stmtReject->execute([
                'issued_by' => $currentUser['id'] ?? null,
                'note'      => $finalNote,
                'id'        => $loanId,
            ]);

            $pdo->commit();
            setFlash('success', 'Đã từ chối phiếu mượn ' . $loan['receipt_code'] . '.');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            setFlash('error', $e->getMessage());
        }

        header('Location: ' . baseUrl('librarian/loans.php'));
        exit;
    }

    if ($action === 'return_loan') {
        $loanId          = (int)($_POST['loan_id'] ?? 0);
        $returnCondition = $_POST['return_condition'] ?? 'good';
        $conditionNote   = trim((string)($_POST['condition_note'] ?? ''));

        if (!in_array($returnCondition, ['good', 'damaged', 'lost'], true)) {
            $returnCondition = 'good';
        }

        try {
            $pdo->beginTransaction();

            $stmtLoan = $pdo->prepare("SELECT * FROM borrow_records WHERE id = :id FOR UPDATE");
            $stmtLoan->execute(['id' => $loanId]);
            $loan = $stmtLoan->fetch();

            if (!$loan || !in_array($loan['status'], ['borrowed', 'overdue'], true)) {
                throw new Exception('Phiếu mượn không hợp lệ hoặc đã được trả trước đó.');
            }

            $stmtItems = $pdo->prepare("SELECT id, book_id, quantity FROM borrow_items WHERE borrow_id = :borrow_id FOR UPDATE");
            $stmtItems->execute(['borrow_id' => $loanId]);
            $items = $stmtItems->fetchAll();

            if ($returnCondition !== 'lost') {
                $stmtRestoreBook = $pdo->prepare("
                    UPDATE books
                    SET available_copies = LEAST(total_copies, available_copies + :qty)
                    WHERE id = :id
                ");
                foreach ($items as $item) {
                    $stmtRestoreBook->execute([
                        'qty' => max((int)$item['quantity'], 1),
                        'id'  => (int)$item['book_id'],
                    ]);
                }
            } else {

                $stmtLostBook = $pdo->prepare("
                    UPDATE books
                    SET total_copies = GREATEST(available_copies, total_copies - :qty)
                    WHERE id = :id
                ");
                foreach ($items as $item) {
                    $stmtLostBook->execute([
                        'qty' => max((int)$item['quantity'], 1),
                        'id'  => (int)$item['book_id'],
                    ]);
                }
            }

            $stmtUpdateItems = $pdo->prepare("
                UPDATE borrow_items
                SET return_condition = :cond,
                    condition_note = :cnote
                WHERE borrow_id = :borrow_id
            ");
            $stmtUpdateItems->execute([
                'cond'      => $returnCondition,
                'cnote'     => $conditionNote !== '' ? $conditionNote : null,
                'borrow_id' => $loanId,
            ]);

            $dueDiff = getDueDaysDiff($loan['due_date'], date('Y-m-d'));
            $daysLate = $dueDiff < 0 ? abs($dueDiff) : 0;
            $overdueFine = $daysLate * FINE_PER_DAY_VND;

            $conditionFine = 0;
            if ($returnCondition === 'damaged') {
                $conditionFine = DAMAGED_BOOK_FINE_VND;
            } elseif ($returnCondition === 'lost') {
                $conditionFine = LOST_BOOK_FINE_VND;
            }

            $totalFine = $overdueFine + $conditionFine;

            $noteParts = [];
            if (!empty($loan['note'])) {
                $noteParts[] = $loan['note'];
            }
            if ($conditionNote !== '') {
                $noteParts[] = 'Ghi chú trả: ' . $conditionNote;
            } elseif ($returnCondition === 'damaged') {
                $noteParts[] = 'Sách trả bị hư hỏng nhẹ';
            } elseif ($returnCondition === 'lost') {
                $noteParts[] = 'Độc giả làm mất sách (đã tính tiền đền bù)';
            }
            $updatedNote = implode(' . ', $noteParts);

            $stmtFinish = $pdo->prepare("
                UPDATE borrow_records
                SET status = 'returned',
                    return_date = CURDATE(),
                    fine_amount = :fine_amount,
                    note = :note
                WHERE id = :id
            ");
            $stmtFinish->execute([
                'fine_amount' => $totalFine,
                'note'        => mb_substr($updatedNote, 0, 250),
                'id'          => $loanId,
            ]);

            $pdo->commit();
            $msg = 'Đã nhận trả sách cho phiếu ' . $loan['receipt_code'] . '.';
            if ($totalFine > 0) {
                $msg .= ' Tổng tiền phạt: ' . formatVND($totalFine) . '.';
            }
            setFlash('success', $msg);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            setFlash('error', $e->getMessage());
        }

        header('Location: ' . baseUrl('librarian/loans.php'));
        exit;
    }

    if ($action === 'update_loan') {
        $loanId  = (int)($_POST['loan_id'] ?? 0);
        $dueDate = trim((string)($_POST['due_date'] ?? ''));
        $note    = trim((string)($_POST['note'] ?? ''));

        $parsedDate = DateTimeImmutable::createFromFormat('Y-m-d', $dueDate);
        if ($loanId <= 0 || !$parsedDate || $parsedDate->format('Y-m-d') !== $dueDate) {
            setFlash('error', 'Ngày hạn trả không hợp lệ.');
            header('Location: ' . baseUrl('librarian/loans.php'));
            exit;
        }

        try {
            $pdo->beginTransaction();

            $stmtLoan = $pdo->prepare("SELECT * FROM borrow_records WHERE id = :id FOR UPDATE");
            $stmtLoan->execute(['id' => $loanId]);
            $loan = $stmtLoan->fetch();

            if (!$loan) {
                throw new Exception('Không tìm thấy phiếu mượn cần cập nhật.');
            }

            $newStatus = $loan['status'];
            $newFine   = (float)$loan['fine_amount'];

            if (in_array($loan['status'], ['borrowed', 'overdue'], true)) {
                $today = date('Y-m-d');
                if ($dueDate < $today) {
                    $newStatus = 'overdue';
                    $diffDays  = abs(getDueDaysDiff($dueDate, $today));
                    $newFine   = max($diffDays, 1) * FINE_PER_DAY_VND;
                } else {
                    $newStatus = 'borrowed';
                    $newFine   = 0;
                }
            }

            $stmtUpdate = $pdo->prepare("
                UPDATE borrow_records
                SET due_date    = :due_date,
                    note        = :note,
                    status      = :status,
                    fine_amount = :fine_amount
                WHERE id = :id
            ");
            $stmtUpdate->execute([
                'due_date'    => $dueDate,
                'note'        => $note !== '' ? mb_substr($note, 0, 250) : null,
                'status'      => $newStatus,
                'fine_amount' => $newFine,
                'id'          => $loanId,
            ]);

            $pdo->commit();
            setFlash('success', 'Đã cập nhật hạn trả và ghi chú cho phiếu ' . $loan['receipt_code'] . '.');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            setFlash('error', $e->getMessage());
        }

        header('Location: ' . baseUrl('librarian/loans.php'));
        exit;
    }

    if ($action === 'delete_loan') {
        $loanId = (int)($_POST['loan_id'] ?? 0);

        if ($loanId <= 0) {
            setFlash('error', 'Phiếu mượn cần xóa không hợp lệ.');
            header('Location: ' . baseUrl('librarian/loans.php'));
            exit;
        }

        try {
            $pdo->beginTransaction();

            $stmtLoan = $pdo->prepare("SELECT id, receipt_code, status FROM borrow_records WHERE id = :id FOR UPDATE");
            $stmtLoan->execute(['id' => $loanId]);
            $loan = $stmtLoan->fetch();

            if (!$loan) {
                throw new Exception('Không tìm thấy phiếu mượn cần xóa.');
            }

            if (in_array($loan['status'], ['borrowed', 'overdue'], true)) {
                throw new Exception(
                    'Không thể xóa phiếu ' . $loan['receipt_code'] . ' đang có sách mượn chưa trả. Vui lòng nhận trả sách trước khi xóa.'
                );
            }

            $stmtDelItems = $pdo->prepare("DELETE FROM borrow_items WHERE borrow_id = :borrow_id");
            $stmtDelItems->execute(['borrow_id' => $loanId]);

            $stmtDelRecord = $pdo->prepare("DELETE FROM borrow_records WHERE id = :id");
            $stmtDelRecord->execute(['id' => $loanId]);

            $pdo->commit();
            setFlash('success', 'Đã xóa phiếu mượn ' . $loan['receipt_code'] . ' khỏi danh sách.');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            setFlash('error', $e->getMessage());
        }

        header('Location: ' . baseUrl('librarian/loans.php'));
        exit;
    }
}

$statusFilter = trim((string)($_GET['status'] ?? 'all'));
$validStatuses = ['all', 'pending', 'borrowed', 'overdue', 'returned', 'rejected'];
if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = 'all';
}
$searchQuery = trim((string)($_GET['q'] ?? ''));

$whereClauses = [];
$params = [];

if ($statusFilter !== 'all') {
    $whereClauses[] = 'br.status = :status';
    $params['status'] = $statusFilter;
}

if ($searchQuery !== '') {
    $whereClauses[] = '(br.receipt_code LIKE :q1 OR u.member_code LIKE :q2 OR u.full_name LIKE :q3)';
    $kw = '%' . $searchQuery . '%';
    $params['q1'] = $kw;
    $params['q2'] = $kw;
    $params['q3'] = $kw;
}

$whereSql = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

$stmtLoans = $pdo->prepare("
    SELECT br.*,
           u.member_code, u.full_name AS reader_name, u.department, u.phone,
           lib.full_name AS issuer_name
    FROM borrow_records br
    INNER JOIN users u ON u.id = br.user_id
    LEFT JOIN users lib ON lib.id = br.issued_by
    {$whereSql}
    ORDER BY
        CASE br.status
            WHEN 'overdue'  THEN 1
            WHEN 'pending'  THEN 2
            WHEN 'borrowed' THEN 3
            WHEN 'returned' THEN 4
            ELSE 5
        END ASC,
        br.id DESC
");
$stmtLoans->execute($params);
$loans = $stmtLoans->fetchAll();

$itemsByBorrowId = [];
if (!empty($loans)) {
    $loanIds = array_column($loans, 'id');
    $placeholders = implode(',', array_fill(0, count($loanIds), '?'));
    $stmtAllItems = $pdo->prepare("
        SELECT bi.borrow_id, bi.book_id, bi.quantity, bi.return_condition, bi.condition_note,
               b.call_number, b.title, b.isbn
        FROM borrow_items bi
        INNER JOIN books b ON b.id = bi.book_id
        WHERE bi.borrow_id IN ({$placeholders})
        ORDER BY bi.id ASC
    ");
    $stmtAllItems->execute($loanIds);
    foreach ($stmtAllItems->fetchAll() as $row) {
        $itemsByBorrowId[(int)$row['borrow_id']][] = $row;
    }
}

$statusCountsRaw = $pdo->query("
    SELECT status, COUNT(*) AS cnt
    FROM borrow_records
    GROUP BY status
")->fetchAll();
$statusCounts = [
    'all'      => 0,
    'pending'  => 0,
    'borrowed' => 0,
    'overdue'  => 0,
    'returned' => 0,
    'rejected' => 0,
];
foreach ($statusCountsRaw as $sc) {
    $st = $sc['status'];
    $c  = (int)$sc['cnt'];
    if (isset($statusCounts[$st])) {
        $statusCounts[$st] = $c;
    }
    $statusCounts['all'] += $c;
}

$activeReaders = $pdo->query("
    SELECT id, member_code, full_name, department
    FROM users
    WHERE role = 'reader' AND status = 'active'
    ORDER BY member_code ASC
")->fetchAll();

$availableBooks = $pdo->query("
    SELECT id, call_number, title, author, available_copies, total_copies
    FROM books
    WHERE available_copies > 0
    ORDER BY call_number ASC
")->fetchAll();

$defaultDueDate = date('Y-m-d', strtotime('+' . DEFAULT_BORROW_DAYS . ' days'));

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <section class="page-header">
        <div>
            <div class="page-header-meta">
                <span class="mono-code">TRANG THỦ THƯ . QUẢN LÝ MƯỢN TRẢ SÁCH</span>
            </div>
            <h1 class="page-title">Quản lý Mượn - Trả Sách</h1>
            <p class="page-subtitle">Lập phiếu mượn sách tại thư viện, duyệt yêu cầu đăng ký mượn của sinh viên và nhận trả sách kèm tính tiền phạt quá hạn.</p>
        </div>
        <div class="page-actions">
            <button type="button" class="btn btn-primary" data-modal-open="modal-create-loan">
                <?= icon('plus', 16) ?>
                <span>Lập phiếu mượn mới</span>
            </button>
        </div>
    </section>

    <section class="kpi-strip" aria-label="Thống kê trạng thái phiếu mượn">
        <article class="kpi-cell">
            <span class="kpi-label">PHIẾU CHỜ DUYỆT</span>
            <strong class="kpi-value mono-num"><?= $statusCounts['pending'] ?></strong>
            <span class="kpi-sub">Yêu cầu đăng ký mượn của sinh viên</span>
        </article>
        <article class="kpi-cell">
            <span class="kpi-label">PHIẾU ĐANG MƯỢN</span>
            <strong class="kpi-value mono-num"><?= $statusCounts['borrowed'] ?></strong>
            <span class="kpi-sub">Thời hạn mượn <?= DEFAULT_BORROW_DAYS ?> ngày</span>
        </article>
        <article class="kpi-cell">
            <span class="kpi-label">PHIẾU QUÁ HẠN TRẢ</span>
            <strong class="kpi-value mono-num"><?= $statusCounts['overdue'] ?></strong>
            <span class="kpi-sub">Phạt <?= formatVND(FINE_PER_DAY_VND) ?> / ngày trễ</span>
        </article>
        <article class="kpi-cell">
            <span class="kpi-label">PHIẾU ĐÃ TRẢ SÁCH</span>
            <strong class="kpi-value mono-num"><?= $statusCounts['returned'] ?></strong>
            <span class="kpi-sub">Tổng cộng <strong class="mono-num"><?= $statusCounts['all'] ?></strong> phiếu mượn</span>
        </article>
    </section>

    <section class="panel" style="margin-bottom: 24px;">
        <div class="panel-body">
            <form method="get" action="<?= e(baseUrl('librarian/loans.php')) ?>" class="filter-bar">
                <div class="form-group" style="flex: 2; min-width: 240px; margin-bottom: 0;">
                    <label for="q" class="form-label">Tìm kiếm phiếu mượn</label>
                    <input
                        type="search"
                        id="q"
                        name="q"
                        value="<?= e($searchQuery) ?>"
                        class="form-control"
                        placeholder="Nhập mã phiếu (PM-202610-001), mã thẻ SV hoặc họ tên độc giả..."
                    >
                </div>

                <div class="form-group" style="flex: 1; min-width: 190px; margin-bottom: 0;">
                    <label for="status" class="form-label">Trạng thái phiếu</label>
                    <select id="status" name="status" class="form-select">
                        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Tất cả trạng thái (<?= $statusCounts['all'] ?>)</option>
                        <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Chờ duyệt (<?= $statusCounts['pending'] ?>)</option>
                        <option value="borrowed" <?= $statusFilter === 'borrowed' ? 'selected' : '' ?>>Đang mượn (<?= $statusCounts['borrowed'] ?>)</option>
                        <option value="overdue" <?= $statusFilter === 'overdue' ? 'selected' : '' ?>>Quá hạn trả (<?= $statusCounts['overdue'] ?>)</option>
                        <option value="returned" <?= $statusFilter === 'returned' ? 'selected' : '' ?>>Đã trả sách (<?= $statusCounts['returned'] ?>)</option>
                        <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>Đã từ chối (<?= $statusCounts['rejected'] ?>)</option>
                    </select>
                </div>

                <div class="page-actions" style="align-self: flex-end;">
                    <button type="submit" class="btn btn-secondary">
                        <?= icon('filter', 16) ?>
                        <span>Lọc danh sách</span>
                    </button>
                    <?php if ($statusFilter !== 'all' || $searchQuery !== ''): ?>
                        <a href="<?= e(baseUrl('librarian/loans.php')) ?>" class="btn btn-ghost">Đặt lại</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <h2 class="panel-title">
                <?= icon('receipt', 18) ?>
                <span>Danh sách phiếu mượn (<?= count($loans) ?> phiếu)</span>
            </h2>
        </div>
        <div class="panel-body">
            <?php if (empty($loans)): ?>
                <div class="empty-state">
                    <?= icon('receipt', 28) ?>
                    <p>Không tìm thấy phiếu mượn nào phù hợp.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Mã phiếu</th>
                                <th>Độc giả</th>
                                <th>Sách trong phiếu</th>
                                <th>Thời gian mượn trả</th>
                                <th>Trạng thái</th>
                                <th class="text-right">Tiền phạt</th>
                                <th class="text-right">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($loans as $loan): ?>
                                <?php
                                $loanId = (int)$loan['id'];
                                $items  = $itemsByBorrowId[$loanId] ?? [];
                                $diffDays = getDueDaysDiff($loan['due_date'], $loan['return_date']);
                                ?>
                                <tr>
                                    <td>
                                        <strong class="mono-code"><?= e($loan['receipt_code']) ?></strong>
                                        <div class="form-help">
                                            <?php if (!empty($loan['issuer_name'])): ?>
                                                Thủ thư: <?= e($loan['issuer_name']) ?>
                                            <?php else: ?>
                                                Đăng ký trực tuyến
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <strong><?= e($loan['reader_name']) ?></strong>
                                        <div class="form-help">
                                            <span class="mono-code"><?= e($loan['member_code']) ?></span>
                                            <?php if (!empty($loan['department'])): ?>
                                                <span> . <?= e($loan['department']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (empty($items)): ?>
                                            <span class="form-help">Không có dữ liệu sách</span>
                                        <?php else: ?>
                                            <ul style="list-style: none; padding: 0; margin: 0; display: grid; gap: 4px;">
                                                <?php foreach ($items as $it): ?>
                                                    <li>
                                                        <span class="mono-code"><?= e($it['call_number']) ?></span>
                                                        <span> . <?= e($it['title']) ?></span>
                                                        <?php if ($loan['status'] === 'returned' && $it['return_condition'] !== 'good'): ?>
                                                            <span class="status-badge badge-overdue" style="margin-left: 4px;">
                                                                <?= $it['return_condition'] === 'damaged' ? 'Hư hỏng' : 'Báo mất' ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                        <?php if (!empty($loan['note'])): ?>
                                            <div class="form-help" style="margin-top: 4px;">
                                                Ghi chú: <?= e($loan['note']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="form-help">
                                            Mượn: <strong class="mono-num"><?= formatDate($loan['borrow_date']) ?></strong>
                                        </div>
                                        <div class="form-help">
                                            Hạn trả: <strong class="mono-num"><?= formatDate($loan['due_date']) ?></strong>
                                        </div>
                                        <?php if (!empty($loan['return_date'])): ?>
                                            <div class="form-help">
                                                Đã trả: <strong class="mono-num"><?= formatDate($loan['return_date']) ?></strong>
                                            </div>
                                        <?php elseif (in_array($loan['status'], ['borrowed', 'overdue'], true)): ?>
                                            <div class="form-help" style="font-weight: 600; color: <?= $diffDays < 0 ? '#B42318' : '#141619' ?>;">
                                                <?php if ($diffDays < 0): ?>
                                                    Quá hạn <span class="mono-num"><?= abs($diffDays) ?></span> ngày
                                                <?php elseif ($diffDays === 0): ?>
                                                    Đến hạn trả hôm nay
                                                <?php else: ?>
                                                    Còn <span class="mono-num"><?= $diffDays ?></span> ngày
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= renderLoanStatusBadge($loan['status']) ?>
                                    </td>
                                    <td class="text-right mono-num">
                                        <?php if ((float)$loan['fine_amount'] > 0): ?>
                                            <strong style="color: #B42318;"><?= formatVND($loan['fine_amount']) ?></strong>
                                        <?php else: ?>
                                            <span>0 ₫</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-right">
                                        <div style="display: inline-flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end;">
                                            <?php if ($loan['status'] === 'pending'): ?>
                                                <form method="post" action="<?= e(baseUrl('librarian/loans.php')) ?>" style="display: inline;">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="approve_loan">
                                                    <input type="hidden" name="loan_id" value="<?= $loanId ?>">
                                                    <button type="submit" class="btn btn-primary btn-sm">
                                                        <?= icon('check', 14) ?>
                                                        <span>Duyệt phiếu</span>
                                                    </button>
                                                </form>
                                                <button
                                                    type="button"
                                                    class="btn btn-danger btn-sm"
                                                    data-modal-open="modal-reject-<?= $loanId ?>"
                                                >
                                                    <?= icon('close', 14) ?>
                                                    <span>Từ chối</span>
                                                </button>
                                            <?php elseif (in_array($loan['status'], ['borrowed', 'overdue'], true)): ?>
                                                <button
                                                    type="button"
                                                    class="btn btn-primary btn-sm"
                                                    data-modal-open="modal-return-<?= $loanId ?>"
                                                >
                                                    <?= icon('check', 14) ?>
                                                    <span>Nhận trả sách</span>
                                                </button>
                                            <?php endif; ?>

                                            <button
                                                type="button"
                                                class="btn btn-secondary btn-sm"
                                                data-modal-open="modal-edit-loan-<?= $loanId ?>"
                                                title="Gia hạn hoặc sửa ghi chú"
                                            >
                                                <?= icon('edit', 14) ?>
                                                <span>Sửa / Gia hạn</span>
                                            </button>

                                            <?php if (in_array($loan['status'], ['pending', 'returned', 'rejected'], true)): ?>
                                                <form
                                                    method="post"
                                                    action="<?= e(baseUrl('librarian/loans.php')) ?>"
                                                    style="display: inline;"
                                                    onsubmit="return confirm('Bạn có chắc muốn xóa phiếu mượn <?= e($loan['receipt_code']) ?>?');"
                                                >
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="delete_loan">
                                                    <input type="hidden" name="loan_id" value="<?= $loanId ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm" title="Xóa phiếu mượn">
                                                        <?= icon('trash', 14) ?>
                                                        <span>Xóa phiếu</span>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>

                                        <?php if ($loan['status'] === 'pending'): ?>

                                            <dialog id="modal-reject-<?= $loanId ?>" class="modal-dialog" style="text-align: left;">
                                                <div class="panel">
                                                    <div class="panel-header">
                                                        <h3 class="panel-title">Từ chối phiếu <?= e($loan['receipt_code']) ?></h3>
                                                        <button type="button" class="btn btn-ghost btn-sm" data-modal-close>
                                                            <?= icon('close', 16) ?>
                                                        </button>
                                                    </div>
                                                    <form method="post" action="<?= e(baseUrl('librarian/loans.php')) ?>" class="panel-body">
                                                        <?= csrfField() ?>
                                                        <input type="hidden" name="action" value="reject_loan">
                                                        <input type="hidden" name="loan_id" value="<?= $loanId ?>">
                                                        <div class="form-group">
                                                            <label class="form-label" for="reject_reason_<?= $loanId ?>">Lý do từ chối</label>
                                                            <textarea
                                                                id="reject_reason_<?= $loanId ?>"
                                                                name="reject_reason"
                                                                class="form-textarea"
                                                                rows="3"
                                                                placeholder="Ví dụ: Độc giả chưa trả sách quá hạn hoặc sách đang bảo trì..."
                                                                required
                                                            ></textarea>
                                                        </div>
                                                        <div class="page-actions" style="justify-content: flex-end; margin-top: 16px;">
                                                            <button type="button" class="btn btn-ghost" data-modal-close>Hủy</button>
                                                            <button type="submit" class="btn btn-danger">Xác nhận từ chối</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </dialog>
                                        <?php elseif (in_array($loan['status'], ['borrowed', 'overdue'], true)): ?>

                                            <dialog id="modal-return-<?= $loanId ?>" class="modal-dialog" style="text-align: left;">
                                                <div class="panel">
                                                    <div class="panel-header">
                                                        <h3 class="panel-title">Nhận trả sách . <?= e($loan['receipt_code']) ?></h3>
                                                        <button type="button" class="btn btn-ghost btn-sm" data-modal-close>
                                                            <?= icon('close', 16) ?>
                                                        </button>
                                                    </div>
                                                    <form method="post" action="<?= e(baseUrl('librarian/loans.php')) ?>" class="panel-body">
                                                        <?= csrfField() ?>
                                                        <input type="hidden" name="action" value="return_loan">
                                                        <input type="hidden" name="loan_id" value="<?= $loanId ?>">

                                                        <p class="form-help" style="margin-bottom: 12px;">
                                                            Độc giả: <strong><?= e($loan['reader_name']) ?></strong> (<span class="mono-code"><?= e($loan['member_code']) ?></span>) .
                                                            Hạn trả: <strong class="mono-num"><?= formatDate($loan['due_date']) ?></strong>
                                                        </p>

                                                        <?php if ($diffDays < 0): ?>
                                                            <div class="flash-banner flash-error" style="margin-bottom: 16px;">
                                                                <?= icon('alert', 16) ?>
                                                                <span>Phiếu trễ hạn <strong class="mono-num"><?= abs($diffDays) ?> ngày</strong>. Tiền phạt quá hạn: <strong class="mono-num"><?= formatVND(abs($diffDays) * FINE_PER_DAY_VND) ?></strong>.</span>
                                                            </div>
                                                        <?php endif; ?>

                                                        <div class="form-group">
                                                            <label class="form-label" for="return_condition_<?= $loanId ?>">Tình trạng sách khi trả</label>
                                                            <select id="return_condition_<?= $loanId ?>" name="return_condition" class="form-select">
                                                                <option value="good">Bình thường, nguyên vẹn (+0 ₫)</option>
                                                                <option value="damaged">Hư hỏng nhẹ: rách bìa / bẩn trang (+<?= formatVND(DAMAGED_BOOK_FINE_VND) ?>)</option>
                                                                <option value="lost">Mất sách: đền bù sách (+<?= formatVND(LOST_BOOK_FINE_VND) ?>)</option>
                                                            </select>
                                                            <span class="form-help">Nếu chọn "Bình thường" hoặc "Hư hỏng nhẹ", số lượng sách trong kho sẽ tự động cộng lại.</span>
                                                        </div>

                                                        <div class="form-group">
                                                            <label class="form-label" for="condition_note_<?= $loanId ?>">Ghi chú khi nhận sách</label>
                                                            <input
                                                                type="text"
                                                                id="condition_note_<?= $loanId ?>"
                                                                name="condition_note"
                                                                class="form-control"
                                                                placeholder="Ví dụ: Đã kiểm đủ sách..."
                                                            >
                                                        </div>

                                                        <div class="page-actions" style="justify-content: flex-end; margin-top: 16px;">
                                                            <button type="button" class="btn btn-ghost" data-modal-close>Đóng</button>
                                                            <button type="submit" class="btn btn-primary">
                                                                <?= icon('check', 16) ?>
                                                                <span>Xác nhận trả sách</span>
                                                            </button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </dialog>
                                        <?php endif; ?>

                                        <dialog id="modal-edit-loan-<?= $loanId ?>" class="modal-dialog" style="text-align: left;">
                                            <div class="panel">
                                                <div class="panel-header">
                                                    <h3 class="panel-title">
                                                        <?= icon('edit', 18) ?>
                                                        <span>Sửa / Gia hạn Phiếu . <?= e($loan['receipt_code']) ?></span>
                                                    </h3>
                                                    <button type="button" class="btn btn-ghost btn-sm" data-modal-close>
                                                        <?= icon('close', 16) ?>
                                                    </button>
                                                </div>
                                                <form method="post" action="<?= e(baseUrl('librarian/loans.php')) ?>" class="panel-body">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="update_loan">
                                                    <input type="hidden" name="loan_id" value="<?= $loanId ?>">

                                                    <p class="form-help" style="margin-bottom: 12px;">
                                                        Độc giả: <strong><?= e($loan['reader_name']) ?></strong> (<span class="mono-code"><?= e($loan['member_code']) ?></span>) .
                                                        Ngày mượn: <strong class="mono-num"><?= formatDate($loan['borrow_date']) ?></strong>
                                                    </p>

                                                    <div class="form-group">
                                                        <label class="form-label" for="edit_due_date_<?= $loanId ?>">Hạn trả mới</label>
                                                        <input
                                                            type="date"
                                                            id="edit_due_date_<?= $loanId ?>"
                                                            name="due_date"
                                                            value="<?= e($loan['due_date']) ?>"
                                                            class="form-control mono-num"
                                                            required
                                                        >
                                                        <span class="form-help">Nếu gia hạn thêm ngày cho phiếu quá hạn, trạng thái sẽ chuyển lại thành Đang mượn.</span>
                                                    </div>

                                                    <div class="form-group">
                                                        <label class="form-label" for="edit_note_<?= $loanId ?>">Ghi chú phiếu mượn</label>
                                                        <textarea
                                                            id="edit_note_<?= $loanId ?>"
                                                            name="note"
                                                            rows="3"
                                                            class="form-textarea"
                                                            placeholder="Nhập ghi chú gia hạn..."
                                                        ><?= e($loan['note'] ?? '') ?></textarea>
                                                    </div>

                                                    <div class="page-actions" style="justify-content: flex-end; margin-top: 16px;">
                                                        <button type="button" class="btn btn-ghost" data-modal-close>Hủy</button>
                                                        <button type="submit" class="btn btn-primary">
                                                            <?= icon('check', 16) ?>
                                                            <span>Lưu thay đổi</span>
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </dialog>
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

<dialog id="modal-create-loan" class="modal-dialog">
    <div class="panel">
        <div class="panel-header">
            <h2 class="panel-title">
                <?= icon('plus', 18) ?>
                <span>Lập Phiếu Mượn Sách Mới</span>
            </h2>
            <button type="button" class="btn btn-ghost btn-sm" data-modal-close>
                <?= icon('close', 16) ?>
            </button>
        </div>
        <form method="post" action="<?= e(baseUrl('librarian/loans.php')) ?>" class="panel-body">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_loan">

            <div class="form-grid-2">
                <div class="form-group">
                    <label for="create_user_id" class="form-label">Độc giả mượn sách</label>
                    <select id="create_user_id" name="user_id" class="form-select" required>
                        <option value="">Chọn độc giả...</option>
                        <?php foreach ($activeReaders as $r): ?>
                            <option value="<?= (int)$r['id'] ?>">
                                <?= e($r['member_code']) ?> . <?= e($r['full_name']) ?> (<?= e($r['department'] ?? 'Độc giả') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="create_due_date" class="form-label">Hạn trả sách (Mặc định +<?= DEFAULT_BORROW_DAYS ?> ngày)</label>
                    <input
                        type="date"
                        id="create_due_date"
                        name="due_date"
                        value="<?= e($defaultDueDate) ?>"
                        min="<?= e(date('Y-m-d')) ?>"
                        class="form-control"
                        required
                    >
                </div>
            </div>

            <div class="form-group">
                <label for="book_slot_1" class="form-label">Sách mượn thứ 1 (Bắt buộc)</label>
                <select id="book_slot_1" name="book_ids[]" class="form-select" required>
                    <option value="">Chọn sách trong kho...</option>
                    <?php foreach ($availableBooks as $b): ?>
                        <option value="<?= (int)$b['id'] ?>">
                            [<?= e($b['call_number']) ?>] <?= e($b['title']) ?> (Còn <?= (int)$b['available_copies'] ?>/<?= (int)$b['total_copies'] ?> cuốn)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-grid-2">
                <div class="form-group">
                    <label for="book_slot_2" class="form-label">Sách mượn thứ 2 (Tùy chọn)</label>
                    <select id="book_slot_2" name="book_ids[]" class="form-select">
                        <option value="">Không chọn thêm</option>
                        <?php foreach ($availableBooks as $b): ?>
                            <option value="<?= (int)$b['id'] ?>">
                                [<?= e($b['call_number']) ?>] <?= e($b['title']) ?> (Còn <?= (int)$b['available_copies'] ?> cuốn)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="book_slot_3" class="form-label">Sách mượn thứ 3 (Tùy chọn)</label>
                    <select id="book_slot_3" name="book_ids[]" class="form-select">
                        <option value="">Không chọn thêm</option>
                        <?php foreach ($availableBooks as $b): ?>
                            <option value="<?= (int)$b['id'] ?>">
                                [<?= e($b['call_number']) ?>] <?= e($b['title']) ?> (Còn <?= (int)$b['available_copies'] ?> cuốn)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label for="create_note" class="form-label">Ghi chú phiếu mượn</label>
                <input
                    type="text"
                    id="create_note"
                    name="note"
                    class="form-control"
                    placeholder="Ví dụ: Sinh viên mượn giáo trình ôn thi cuối kỳ..."
                >
            </div>

            <div class="page-actions" style="justify-content: flex-end; margin-top: 16px;">
                <button type="button" class="btn btn-ghost" data-modal-close>Hủy bỏ</button>
                <button type="submit" class="btn btn-primary">
                    <?= icon('check', 16) ?>
                    <span>Tạo phiếu mượn</span>
                </button>
            </div>
        </form>
    </div>
</dialog>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
