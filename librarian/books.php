<?php
require_once __DIR__ . '/../includes/auth.php';
requireLibrarian();

$pdo = getDB();
$pageTitle = 'Quản lý Sách trong Thư viện';
$activeNav = 'books';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('error', 'Phiên làm việc không hợp lệ. Vui lòng thử lại.');
        header('Location: ' . baseUrl('librarian/books.php'));
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create_book') {
        $categoryId    = (int)($_POST['category_id'] ?? 0);
        $isbn          = trim((string)($_POST['isbn'] ?? ''));
        $callNumber    = trim((string)($_POST['call_number'] ?? ''));
        $title         = trim((string)($_POST['title'] ?? ''));
        $author        = trim((string)($_POST['author'] ?? ''));
        $publisher     = trim((string)($_POST['publisher'] ?? ''));
        $publishYear   = (int)($_POST['publish_year'] ?? date('Y'));
        $pages         = max((int)($_POST['pages'] ?? 250), 1);
        $shelfLocation = trim((string)($_POST['shelf_location'] ?? ''));
        $totalCopies   = max((int)($_POST['total_copies'] ?? 1), 1);
        $summary       = trim((string)($_POST['summary'] ?? ''));

        if ($categoryId <= 0 || $isbn === '' || $callNumber === '' || $title === '' || $author === '' || $publisher === '' || $shelfLocation === '') {
            setFlash('error', 'Vui lòng điền đầy đủ thông tin sách bắt buộc.');
            header('Location: ' . baseUrl('librarian/books.php'));
            exit;
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO books (
                    category_id, isbn, call_number, title, author,
                    publisher, publish_year, pages, shelf_location,
                    total_copies, available_copies, summary
                ) VALUES (
                    :category_id, :isbn, :call_number, :title, :author,
                    :publisher, :publish_year, :pages, :shelf_location,
                    :total_copies, :available_copies, :summary
                )
            ");
            $stmt->execute([
                'category_id'      => $categoryId,
                'isbn'             => $isbn,
                'call_number'      => $callNumber,
                'title'            => $title,
                'author'           => $author,
                'publisher'        => $publisher,
                'publish_year'     => $publishYear,
                'pages'            => $pages,
                'shelf_location'   => $shelfLocation,
                'total_copies'     => $totalCopies,
                'available_copies' => $totalCopies,
                'summary'          => $summary !== '' ? $summary : null,
            ]);
            setFlash('success', 'Đã thêm sách "' . $title . '" (' . $callNumber . ') vào kho.');
        } catch (PDOException $e) {
            if ((int)$e->getCode() === 23000) {
                setFlash('error', 'Mã ISBN hoặc Mã sách đã tồn tại trong cơ sở dữ liệu.');
            } else {
                setFlash('error', 'Lỗi lưu dữ liệu: ' . $e->getMessage());
            }
        }

        header('Location: ' . baseUrl('librarian/books.php'));
        exit;
    }

    if ($action === 'update_book') {
        $bookId        = (int)($_POST['book_id'] ?? 0);
        $categoryId    = (int)($_POST['category_id'] ?? 0);
        $isbn          = trim((string)($_POST['isbn'] ?? ''));
        $callNumber    = trim((string)($_POST['call_number'] ?? ''));
        $title         = trim((string)($_POST['title'] ?? ''));
        $author        = trim((string)($_POST['author'] ?? ''));
        $publisher     = trim((string)($_POST['publisher'] ?? ''));
        $publishYear   = (int)($_POST['publish_year'] ?? date('Y'));
        $pages         = max((int)($_POST['pages'] ?? 250), 1);
        $shelfLocation = trim((string)($_POST['shelf_location'] ?? ''));
        $totalCopies   = max((int)($_POST['total_copies'] ?? 1), 1);
        $summary       = trim((string)($_POST['summary'] ?? ''));

        if ($bookId <= 0 || $categoryId <= 0 || $isbn === '' || $callNumber === '' || $title === '' || $author === '') {
            setFlash('error', 'Thông tin cập nhật sách không hợp lệ.');
            header('Location: ' . baseUrl('librarian/books.php'));
            exit;
        }

        try {
            $pdo->beginTransaction();

            $stmtActive = $pdo->prepare("
                SELECT COALESCE(SUM(bi.quantity), 0)
                FROM borrow_items bi
                INNER JOIN borrow_records br ON br.id = bi.borrow_id
                WHERE bi.book_id = :book_id AND br.status IN ('borrowed', 'overdue')
            ");
            $stmtActive->execute(['book_id' => $bookId]);
            $currentlyBorrowed = (int)$stmtActive->fetchColumn();

            if ($totalCopies < $currentlyBorrowed) {
                throw new Exception(
                    sprintf(
                        'Không thể giảm tổng số sách xuống %d vì hiện có %d cuốn đang được mượn.',
                        $totalCopies,
                        $currentlyBorrowed
                    )
                );
            }

            $newAvailableCopies = $totalCopies - $currentlyBorrowed;

            $stmtUpdate = $pdo->prepare("
                UPDATE books
                SET category_id      = :category_id,
                    isbn             = :isbn,
                    call_number      = :call_number,
                    title            = :title,
                    author           = :author,
                    publisher        = :publisher,
                    publish_year     = :publish_year,
                    pages            = :pages,
                    shelf_location   = :shelf_location,
                    total_copies     = :total_copies,
                    available_copies = :available_copies,
                    summary          = :summary
                WHERE id = :id
            ");
            $stmtUpdate->execute([
                'category_id'      => $categoryId,
                'isbn'             => $isbn,
                'call_number'      => $callNumber,
                'title'            => $title,
                'author'           => $author,
                'publisher'        => $publisher,
                'publish_year'     => $publishYear,
                'pages'            => $pages,
                'shelf_location'   => $shelfLocation,
                'total_copies'     => $totalCopies,
                'available_copies' => $newAvailableCopies,
                'summary'          => $summary !== '' ? $summary : null,
                'id'               => $bookId,
            ]);

            $pdo->commit();
            setFlash('success', 'Đã cập nhật thông tin sách "' . $title . '" thành công.');
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((int)$e->getCode() === 23000) {
                setFlash('error', 'Mã ISBN hoặc Mã sách bị trùng với sách khác.');
            } else {
                setFlash('error', 'Không thể cập nhật: ' . $e->getMessage());
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            setFlash('error', $e->getMessage());
        }

        header('Location: ' . baseUrl('librarian/books.php'));
        exit;
    }

    if ($action === 'delete_book') {
        $bookId = (int)($_POST['book_id'] ?? 0);

        try {
            $pdo->beginTransaction();

            $stmtBook = $pdo->prepare("SELECT id, title, call_number FROM books WHERE id = :id FOR UPDATE");
            $stmtBook->execute(['id' => $bookId]);
            $book = $stmtBook->fetch();

            if (!$book) {
                throw new Exception('Sách cần xóa không tồn tại.');
            }

            $stmtRef = $pdo->prepare("
                SELECT COALESCE(SUM(CASE WHEN br.status IN ('borrowed', 'overdue') THEN 1 ELSE 0 END), 0) AS active_refs
                FROM borrow_items bi
                INNER JOIN borrow_records br ON br.id = bi.borrow_id
                WHERE bi.book_id = :book_id
            ");
            $stmtRef->execute(['book_id' => $bookId]);
            $activeRefs = (int)$stmtRef->fetchColumn();

            if ($activeRefs > 0) {
                throw new Exception(
                    'Không thể xóa sách "' . $book['title'] . '" vì hiện đang có độc giả mượn chưa trả.'
                );
            }

            $stmtDelItems = $pdo->prepare("DELETE FROM borrow_items WHERE book_id = :book_id");
            $stmtDelItems->execute(['book_id' => $bookId]);

            $pdo->exec("
                DELETE br FROM borrow_records br
                LEFT JOIN borrow_items bi ON bi.borrow_id = br.id
                WHERE br.status IN ('pending', 'returned', 'rejected')
                  AND bi.id IS NULL
            ");

            $stmtDel = $pdo->prepare("DELETE FROM books WHERE id = :id");
            $stmtDel->execute(['id' => $bookId]);

            $pdo->commit();
            setFlash('success', 'Đã xóa sách "' . $book['title'] . '" (' . $book['call_number'] . ') khỏi kho.');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            setFlash('error', $e->getMessage());
        }

        header('Location: ' . baseUrl('librarian/books.php'));
        exit;
    }
}

$categories = $pdo->query("
    SELECT id, dewey_code, name, shelf_zone
    FROM categories
    ORDER BY dewey_code ASC
")->fetchAll();

$editId = (int)($_GET['edit'] ?? 0);
$editingBook = null;
if ($editId > 0) {
    $stmtEdit = $pdo->prepare("SELECT * FROM books WHERE id = :id");
    $stmtEdit->execute(['id' => $editId]);
    $editingBook = $stmtEdit->fetch() ?: null;
}

$categoryFilter = (int)($_GET['category_id'] ?? 0);
$searchQuery    = trim((string)($_GET['q'] ?? ''));

$whereClauses = [];
$params = [];

if ($categoryFilter > 0) {
    $whereClauses[] = 'b.category_id = :category_id';
    $params['category_id'] = $categoryFilter;
}

if ($searchQuery !== '') {
    $whereClauses[] = '(b.title LIKE :q1 OR b.author LIKE :q2 OR b.call_number LIKE :q3 OR b.isbn LIKE :q4)';
    $kw = '%' . $searchQuery . '%';
    $params['q1'] = $kw;
    $params['q2'] = $kw;
    $params['q3'] = $kw;
    $params['q4'] = $kw;
}

$whereSql = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

$stmtBooks = $pdo->prepare("
    SELECT b.*,
           c.dewey_code, c.name AS category_name,
           COALESCE((
               SELECT SUM(bi.quantity)
               FROM borrow_items bi
               INNER JOIN borrow_records br ON br.id = bi.borrow_id
               WHERE bi.book_id = b.id AND br.status IN ('borrowed', 'overdue')
           ), 0) AS active_borrowed
    FROM books b
    INNER JOIN categories c ON c.id = b.category_id
    {$whereSql}
    ORDER BY c.dewey_code ASC, b.call_number ASC
");
$stmtBooks->execute($params);
$books = $stmtBooks->fetchAll();

$kpiInventory = $pdo->query("
    SELECT COUNT(*) AS titles,
           COALESCE(SUM(total_copies), 0) AS total_copies,
           COALESCE(SUM(available_copies), 0) AS available_copies,
           COALESCE(SUM(CASE WHEN available_copies = 0 THEN 1 ELSE 0 END), 0) AS out_of_stock_titles
    FROM books
")->fetch();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <section class="page-header">
        <div>
            <div class="page-header-meta">
                <span class="mono-code">TRANG THỦ THƯ . QUẢN LÝ KHO SÁCH</span>
            </div>
            <h1 class="page-title">Quản lý Sách trong Thư viện</h1>
            <p class="page-subtitle">Tìm kiếm sách, thêm sách mới, cập nhật vị trí kệ và kiểm tra số lượng sách tồn kho.</p>
        </div>
        <div class="page-actions">
            <button type="button" class="btn btn-primary" data-modal-open="modal-create-book">
                <?= icon('plus', 16) ?>
                <span>Thêm sách mới</span>
            </button>
        </div>
    </section>

    <section class="kpi-strip" aria-label="Thống kê kho sách">
        <article class="kpi-cell">
            <span class="kpi-label">TỔNG ĐẦU SÁCH</span>
            <strong class="kpi-value mono-num"><?= (int)$kpiInventory['titles'] ?></strong>
            <span class="kpi-sub">Thuộc <strong class="mono-num"><?= count($categories) ?></strong> thể loại sách</span>
        </article>
        <article class="kpi-cell">
            <span class="kpi-label">TỔNG SỐ CUỐN SÁCH</span>
            <strong class="kpi-value mono-num"><?= (int)$kpiInventory['total_copies'] ?></strong>
            <span class="kpi-sub">Tổng số lượng sách trong kho</span>
        </article>
        <article class="kpi-cell">
            <span class="kpi-label">SÁCH SẴN CÓ TRÊN KỆ</span>
            <strong class="kpi-value mono-num"><?= (int)$kpiInventory['available_copies'] ?></strong>
            <span class="kpi-sub">Có thể cho mượn ngay</span>
        </article>
        <article class="kpi-cell">
            <span class="kpi-label">ĐẦU SÁCH ĐÃ HẾT TRÊN KỆ</span>
            <strong class="kpi-value mono-num"><?= (int)$kpiInventory['out_of_stock_titles'] ?></strong>
            <span class="kpi-sub">Tất cả các cuốn đang được mượn</span>
        </article>
    </section>

    <?php if ($editingBook): ?>
        <section class="panel" style="margin-bottom: 24px;">
            <div class="panel-header">
                <h2 class="panel-title">
                    <?= icon('edit', 18) ?>
                    <span>Cập nhật Thông tin Sách . <?= e($editingBook['call_number']) ?></span>
                </h2>
                <a href="<?= e(baseUrl('librarian/books.php')) ?>" class="btn btn-ghost btn-sm">
                    <?= icon('close', 16) ?>
                    <span>Đóng khung sửa</span>
                </a>
            </div>
            <form method="post" action="<?= e(baseUrl('librarian/books.php')) ?>" class="panel-body">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update_book">
                <input type="hidden" name="book_id" value="<?= (int)$editingBook['id'] ?>">

                <div class="form-grid-3">
                    <div class="form-group">
                        <label class="form-label" for="edit_category_id">Thể loại sách</label>
                        <select id="edit_category_id" name="category_id" class="form-select" required>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= (int)$cat['id'] ?>" <?= (int)$editingBook['category_id'] === (int)$cat['id'] ? 'selected' : '' ?>>
                                    <?= e($cat['dewey_code']) ?> . <?= e($cat['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="edit_call_number">Mã sách</label>
                        <input type="text" id="edit_call_number" name="call_number" value="<?= e($editingBook['call_number']) ?>" class="form-control mono-code" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="edit_isbn">Mã ISBN</label>
                        <input type="text" id="edit_isbn" name="isbn" value="<?= e($editingBook['isbn']) ?>" class="form-control mono-code" required>
                    </div>
                </div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label" for="edit_title">Tên sách</label>
                        <input type="text" id="edit_title" name="title" value="<?= e($editingBook['title']) ?>" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="edit_author">Tác giả</label>
                        <input type="text" id="edit_author" name="author" value="<?= e($editingBook['author']) ?>" class="form-control" required>
                    </div>
                </div>

                <div class="form-grid-3">
                    <div class="form-group">
                        <label class="form-label" for="edit_publisher">Nhà xuất bản</label>
                        <input type="text" id="edit_publisher" name="publisher" value="<?= e($editingBook['publisher']) ?>" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="edit_publish_year">Năm xuất bản</label>
                        <input type="number" id="edit_publish_year" name="publish_year" value="<?= (int)$editingBook['publish_year'] ?>" min="1950" max="2035" class="form-control mono-num" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="edit_pages">Số trang</label>
                        <input type="number" id="edit_pages" name="pages" value="<?= (int)$editingBook['pages'] ?>" min="1" max="5000" class="form-control mono-num" required>
                    </div>
                </div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label" for="edit_shelf_location">Vị trí kệ sách</label>
                        <input type="text" id="edit_shelf_location" name="shelf_location" value="<?= e($editingBook['shelf_location']) ?>" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="edit_total_copies">Tổng số lượng sách</label>
                        <input type="number" id="edit_total_copies" name="total_copies" value="<?= (int)$editingBook['total_copies'] ?>" min="1" max="500" class="form-control mono-num" required>
                        <span class="form-help">Hệ thống tự động tính lại số cuốn còn trên kệ dựa vào số cuốn đang mượn.</span>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="edit_summary">Mô tả nội dung</label>
                    <textarea id="edit_summary" name="summary" rows="3" class="form-textarea"><?= e($editingBook['summary']) ?></textarea>
                </div>

                <div class="page-actions" style="justify-content: flex-end; margin-top: 12px;">
                    <a href="<?= e(baseUrl('librarian/books.php')) ?>" class="btn btn-ghost">Hủy</a>
                    <button type="submit" class="btn btn-primary">
                        <?= icon('check', 16) ?>
                        <span>Lưu thông tin sách</span>
                    </button>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <section class="panel" style="margin-bottom: 24px;">
        <div class="panel-body">
            <form method="get" action="<?= e(baseUrl('librarian/books.php')) ?>" class="filter-bar">
                <div class="form-group" style="flex: 2; min-width: 240px; margin-bottom: 0;">
                    <label for="q" class="form-label">Từ khóa tìm kiếm</label>
                    <input
                        type="search"
                        id="q"
                        name="q"
                        value="<?= e($searchQuery) ?>"
                        class="form-control"
                        placeholder="Tìm theo tên sách, tác giả, mã sách hoặc ISBN..."
                    >
                </div>

                <div class="form-group" style="flex: 1; min-width: 220px; margin-bottom: 0;">
                    <label for="category_id" class="form-label">Thể loại sách</label>
                    <select id="category_id" name="category_id" class="form-select">
                        <option value="0">Tất cả thể loại</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= (int)$cat['id'] ?>" <?= $categoryFilter === (int)$cat['id'] ? 'selected' : '' ?>>
                                <?= e($cat['dewey_code']) ?> . <?= e($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="page-actions" style="align-self: flex-end;">
                    <button type="submit" class="btn btn-secondary">
                        <?= icon('search', 16) ?>
                        <span>Tìm kiếm</span>
                    </button>
                    <?php if ($categoryFilter > 0 || $searchQuery !== ''): ?>
                        <a href="<?= e(baseUrl('librarian/books.php')) ?>" class="btn btn-ghost">Xóa lọc</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <h2 class="panel-title">
                <?= icon('book', 18) ?>
                <span>Danh sách Sách trong Kho (<?= count($books) ?> đầu sách)</span>
            </h2>
        </div>
        <div class="panel-body">
            <?php if (empty($books)): ?>
                <div class="empty-state">
                    <?= icon('book', 28) ?>
                    <p>Không tìm thấy cuốn sách nào phù hợp.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Mã sách / ISBN</th>
                                <th>Tên sách &amp; Tác giả</th>
                                <th>Thể loại sách</th>
                                <th>Vị trí kệ</th>
                                <th class="text-right">Số lượng (Còn / Tổng)</th>
                                <th class="text-right">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($books as $b): ?>
                                <?php
                                $bookId = (int)$b['id'];
                                $avail  = (int)$b['available_copies'];
                                $total  = (int)$b['total_copies'];
                                ?>
                                <tr>
                                    <td>
                                        <strong class="mono-code"><?= e($b['call_number']) ?></strong>
                                        <div class="form-help mono-code">ISBN: <?= e($b['isbn']) ?></div>
                                    </td>
                                    <td>
                                        <strong><?= e($b['title']) ?></strong>
                                        <div class="form-help">
                                            <?= e($b['author']) ?> . <?= e($b['publisher']) ?> (<span class="mono-num"><?= (int)$b['publish_year'] ?></span>, <span class="mono-num"><?= (int)$b['pages'] ?></span> tr.)
                                        </div>
                                    </td>
                                    <td>
                                        <span class="mono-code"><?= e($b['dewey_code']) ?></span>
                                        <div class="form-help"><?= e($b['category_name']) ?></div>
                                    </td>
                                    <td>
                                        <span><?= e($b['shelf_location']) ?></span>
                                    </td>
                                    <td class="text-right">
                                        <div class="mono-num">
                                            <strong><?= $avail ?></strong> / <?= $total ?> cuốn
                                        </div>
                                        <?php if ($avail > 0): ?>
                                            <span class="status-badge badge-active">Còn sách</span>
                                        <?php else: ?>
                                            <span class="status-badge badge-locked">Tạm hết</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-right">
                                        <div style="display: inline-flex; gap: 6px; justify-content: flex-end;">
                                            <a
                                                href="<?= e(baseUrl('librarian/books.php?edit=' . $bookId)) ?>"
                                                class="btn btn-secondary btn-sm"
                                                title="Sửa thông tin sách"
                                            >
                                                <?= icon('edit', 14) ?>
                                                <span>Sửa</span>
                                            </a>
                                            <form
                                                method="post"
                                                action="<?= e(baseUrl('librarian/books.php')) ?>"
                                                style="display: inline;"
                                                onsubmit="return confirm('Bạn có chắc muốn xóa sách này khỏi kho?');"
                                            >
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="delete_book">
                                                <input type="hidden" name="book_id" value="<?= $bookId ?>">
                                                <button type="submit" class="btn btn-danger btn-sm" title="Xóa sách">
                                                    <?= icon('trash', 14) ?>
                                                </button>
                                            </form>
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

<dialog id="modal-create-book" class="modal-dialog">
    <div class="panel">
        <div class="panel-header">
            <h2 class="panel-title">
                <?= icon('plus', 18) ?>
                <span>Thêm Sách Mới vào Kho</span>
            </h2>
            <button type="button" class="btn btn-ghost btn-sm" data-modal-close>
                <?= icon('close', 16) ?>
            </button>
        </div>
        <form method="post" action="<?= e(baseUrl('librarian/books.php')) ?>" class="panel-body">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_book">

            <div class="form-grid-3">
                <div class="form-group">
                    <label class="form-label" for="new_category_id">Thể loại sách</label>
                    <select id="new_category_id" name="category_id" class="form-select" required>
                        <option value="">Chọn thể loại...</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= (int)$cat['id'] ?>">
                                <?= e($cat['dewey_code']) ?> . <?= e($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="new_call_number">Mã sách</label>
                    <input type="text" id="new_call_number" name="call_number" class="form-control mono-code" placeholder="VD: CNTT-PHP-2026" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="new_isbn">Mã ISBN</label>
                    <input type="text" id="new_isbn" name="isbn" class="form-control mono-code" placeholder="VD: 978-604-73-9900-1" required>
                </div>
            </div>

            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label" for="new_title">Tên sách</label>
                    <input type="text" id="new_title" name="title" class="form-control" placeholder="Nhập tên sách hoặc giáo trình" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="new_author">Tác giả</label>
                    <input type="text" id="new_author" name="author" class="form-control" placeholder="VD: Nguyễn Văn A" required>
                </div>
            </div>

            <div class="form-grid-3">
                <div class="form-group">
                    <label class="form-label" for="new_publisher">Nhà xuất bản</label>
                    <input type="text" id="new_publisher" name="publisher" class="form-control" placeholder="VD: NXB Giáo dục" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="new_publish_year">Năm xuất bản</label>
                    <input type="number" id="new_publish_year" name="publish_year" value="<?= (int)date('Y') ?>" min="1950" max="2035" class="form-control mono-num" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="new_pages">Số trang</label>
                    <input type="number" id="new_pages" name="pages" value="320" min="1" max="5000" class="form-control mono-num" required>
                </div>
            </div>

            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label" for="new_shelf_location">Vị trí kệ sách</label>
                    <input type="text" id="new_shelf_location" name="shelf_location" class="form-control" placeholder="VD: Tầng 2 - Kệ B03 - Ngăn 1" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="new_total_copies">Số lượng nhập kho</label>
                    <input type="number" id="new_total_copies" name="total_copies" value="5" min="1" max="500" class="form-control mono-num" required>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="new_summary">Mô tả nội dung</label>
                <textarea id="new_summary" name="summary" rows="3" class="form-textarea" placeholder="Nhập mô tả tóm tắt nội dung cuốn sách..."></textarea>
            </div>

            <div class="page-actions" style="justify-content: flex-end; margin-top: 16px;">
                <button type="button" class="btn btn-ghost" data-modal-close>Hủy bỏ</button>
                <button type="submit" class="btn btn-primary">
                    <?= icon('check', 16) ?>
                    <span>Thêm sách mới</span>
                </button>
            </div>
        </form>
    </div>
</dialog>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
