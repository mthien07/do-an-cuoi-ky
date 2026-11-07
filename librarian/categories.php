<?php
require_once __DIR__ . '/../includes/auth.php';
requireLibrarian();

$pdo = getDB();
$pageTitle = 'Quản lý Thể loại Sách';
$activeNav = 'categories';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('error', 'Phiên làm việc không hợp lệ. Vui lòng thử lại.');
        header('Location: ' . baseUrl('librarian/categories.php'));
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create_category') {
        $deweyCode   = trim((string)($_POST['dewey_code'] ?? ''));
        $name        = trim((string)($_POST['name'] ?? ''));
        $shelfZone   = trim((string)($_POST['shelf_zone'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));

        if ($deweyCode === '' || $name === '' || $shelfZone === '') {
            setFlash('error', 'Vui lòng nhập đầy đủ Mã thể loại, Tên thể loại và Vị trí khu vực kệ.');
            header('Location: ' . baseUrl('librarian/categories.php'));
            exit;
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO categories (dewey_code, name, shelf_zone, description)
                VALUES (:dewey_code, :name, :shelf_zone, :description)
            ");
            $stmt->execute([
                'dewey_code'  => $deweyCode,
                'name'        => $name,
                'shelf_zone'  => $shelfZone,
                'description' => $description !== '' ? $description : null,
            ]);
            setFlash('success', 'Đã thêm thể loại [' . $deweyCode . '] ' . $name . '.');
        } catch (PDOException $e) {
            if ((int)$e->getCode() === 23000) {
                setFlash('error', 'Mã thể loại "' . $deweyCode . '" đã tồn tại trong hệ thống.');
            } else {
                setFlash('error', 'Lỗi lưu thể loại: ' . $e->getMessage());
            }
        }

        header('Location: ' . baseUrl('librarian/categories.php'));
        exit;
    }

    if ($action === 'update_category') {
        $categoryId  = (int)($_POST['category_id'] ?? 0);
        $deweyCode   = trim((string)($_POST['dewey_code'] ?? ''));
        $name        = trim((string)($_POST['name'] ?? ''));
        $shelfZone   = trim((string)($_POST['shelf_zone'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));

        if ($categoryId <= 0 || $deweyCode === '' || $name === '' || $shelfZone === '') {
            setFlash('error', 'Dữ liệu cập nhật thể loại không hợp lệ.');
            header('Location: ' . baseUrl('librarian/categories.php'));
            exit;
        }

        try {
            $stmt = $pdo->prepare("
                UPDATE categories
                SET dewey_code  = :dewey_code,
                    name        = :name,
                    shelf_zone  = :shelf_zone,
                    description = :description
                WHERE id = :id
            ");
            $stmt->execute([
                'dewey_code'  => $deweyCode,
                'name'        => $name,
                'shelf_zone'  => $shelfZone,
                'description' => $description !== '' ? $description : null,
                'id'          => $categoryId,
            ]);
            setFlash('success', 'Đã cập nhật thông tin thể loại [' . $deweyCode . '].');
        } catch (PDOException $e) {
            if ((int)$e->getCode() === 23000) {
                setFlash('error', 'Mã thể loại "' . $deweyCode . '" bị trùng với thể loại khác.');
            } else {
                setFlash('error', 'Không thể cập nhật: ' . $e->getMessage());
            }
        }

        header('Location: ' . baseUrl('librarian/categories.php'));
        exit;
    }

    if ($action === 'delete_category') {
        $categoryId = (int)($_POST['category_id'] ?? 0);

        try {
            $stmtCat = $pdo->prepare("SELECT id, dewey_code, name FROM categories WHERE id = :id");
            $stmtCat->execute(['id' => $categoryId]);
            $cat = $stmtCat->fetch();

            if (!$cat) {
                throw new Exception('Thể loại cần xóa không tồn tại.');
            }

            $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM books WHERE category_id = :category_id");
            $stmtCount->execute(['category_id' => $categoryId]);
            $bookCount = (int)$stmtCount->fetchColumn();

            if ($bookCount > 0) {
                throw new Exception(
                    sprintf(
                        'Không thể xóa thể loại [%s] "%s" vì đang có %d đầu sách thuộc thể loại này.',
                        $cat['dewey_code'],
                        $cat['name'],
                        $bookCount
                    )
                );
            }

            $stmtDel = $pdo->prepare("DELETE FROM categories WHERE id = :id");
            $stmtDel->execute(['id' => $categoryId]);
            setFlash('success', 'Đã xóa thể loại [' . $cat['dewey_code'] . '] ' . $cat['name'] . '.');
        } catch (Exception $e) {
            setFlash('error', $e->getMessage());
        }

        header('Location: ' . baseUrl('librarian/categories.php'));
        exit;
    }
}

$editId = (int)($_GET['edit'] ?? 0);
$editingCategory = null;
if ($editId > 0) {
    $stmtEdit = $pdo->prepare("SELECT * FROM categories WHERE id = :id");
    $stmtEdit->execute(['id' => $editId]);
    $editingCategory = $stmtEdit->fetch() ?: null;
}

$categories = $pdo->query("
    SELECT c.*,
           COUNT(b.id) AS title_count,
           COALESCE(SUM(b.total_copies), 0) AS total_copies,
           COALESCE(SUM(b.available_copies), 0) AS available_copies
    FROM categories c
    LEFT JOIN books b ON b.category_id = c.id
    GROUP BY c.id
    ORDER BY c.dewey_code ASC
")->fetchAll();

$totalCategories = count($categories);
$totalTitles = 0;
$totalCopies = 0;
$emptyCategories = 0;
foreach ($categories as $c) {
    $t = (int)$c['title_count'];
    $totalTitles += $t;
    $totalCopies += (int)$c['total_copies'];
    if ($t === 0) {
        $emptyCategories++;
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <section class="page-header">
        <div>
            <div class="page-header-meta">
                <span class="mono-code">TRANG THỦ THƯ . QUẢN LÝ THỂ LOẠI SÁCH</span>
            </div>
            <h1 class="page-title">Quản lý Thể loại Sách</h1>
            <p class="page-subtitle">Quản lý danh sách các thể loại sách, sắp xếp vị trí khu vực kệ và thống kê số lượng sách theo từng thể loại.</p>
        </div>
        <div class="page-actions">
            <button type="button" class="btn btn-primary" data-modal-open="modal-create-category">
                <?= icon('plus', 16) ?>
                <span>Thêm thể loại mới</span>
            </button>
        </div>
    </section>

    <section class="kpi-strip" aria-label="Thống kê thể loại sách">
        <article class="kpi-cell">
            <span class="kpi-label">TỔNG SỐ THỂ LOẠI</span>
            <strong class="kpi-value mono-num"><?= $totalCategories ?></strong>
            <span class="kpi-sub">Các nhóm thể loại sách trong thư viện</span>
        </article>
        <article class="kpi-cell">
            <span class="kpi-label">TỔNG ĐẦU SÁCH</span>
            <strong class="kpi-value mono-num"><?= $totalTitles ?></strong>
            <span class="kpi-sub">Đã được phân vào các thể loại</span>
        </article>
        <article class="kpi-cell">
            <span class="kpi-label">TỔNG SỐ CUỐN SÁCH</span>
            <strong class="kpi-value mono-num"><?= $totalCopies ?></strong>
            <span class="kpi-sub">Đang lưu trữ tại các khu vực kệ</span>
        </article>
        <article class="kpi-cell">
            <span class="kpi-label">THỂ LOẠI CHƯA CÓ SÁCH</span>
            <strong class="kpi-value mono-num"><?= $emptyCategories ?></strong>
            <span class="kpi-sub">Có thể xóa hoặc thêm sách mới</span>
        </article>
    </section>

    <div class="split-layout">
        <div class="main-column">
            <section class="panel">
                <div class="panel-header">
                    <h2 class="panel-title">
                        <?= icon('archive', 18) ?>
                        <span>Danh sách Thể loại Sách (<?= $totalCategories ?> thể loại)</span>
                    </h2>
                </div>
                <div class="panel-body">
                    <?php if (empty($categories)): ?>
                        <div class="empty-state">
                            <?= icon('archive', 28) ?>
                            <p>Chưa có thể loại sách nào được tạo.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Mã thể loại</th>
                                        <th>Tên thể loại &amp; Mô tả</th>
                                        <th>Vị trí khu vực kệ</th>
                                        <th class="text-right">Đầu sách</th>
                                        <th class="text-right">Số cuốn (Còn / Tổng)</th>
                                        <th class="text-right">Thao tác</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($categories as $cat): ?>
                                        <?php
                                        $catId = (int)$cat['id'];
                                        $titleCount = (int)$cat['title_count'];
                                        ?>
                                        <tr>
                                            <td>
                                                <strong class="mono-code"><?= e($cat['dewey_code']) ?></strong>
                                            </td>
                                            <td>
                                                <strong><?= e($cat['name']) ?></strong>
                                                <?php if (!empty($cat['description'])): ?>
                                                    <div class="form-help"><?= e($cat['description']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span><?= e($cat['shelf_zone']) ?></span>
                                            </td>
                                            <td class="text-right mono-num">
                                                <strong><?= $titleCount ?></strong>
                                            </td>
                                            <td class="text-right mono-num">
                                                <strong><?= (int)$cat['available_copies'] ?></strong> / <?= (int)$cat['total_copies'] ?>
                                            </td>
                                            <td class="text-right">
                                                <div style="display: inline-flex; gap: 6px; justify-content: flex-end;">
                                                    <a
                                                        href="<?= e(baseUrl('librarian/categories.php?edit=' . $catId)) ?>"
                                                        class="btn btn-secondary btn-sm"
                                                    >
                                                        <?= icon('edit', 14) ?>
                                                        <span>Sửa</span>
                                                    </a>
                                                    <?php if ($titleCount === 0): ?>
                                                        <form
                                                            method="post"
                                                            action="<?= e(baseUrl('librarian/categories.php')) ?>"
                                                            style="display: inline;"
                                                            onsubmit="return confirm('Bạn có chắc muốn xóa thể loại sách này?');"
                                                        >
                                                            <?= csrfField() ?>
                                                            <input type="hidden" name="action" value="delete_category">
                                                            <input type="hidden" name="category_id" value="<?= $catId ?>">
                                                            <button type="submit" class="btn btn-danger btn-sm" title="Xóa thể loại trống">
                                                                <?= icon('trash', 14) ?>
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <a
                                                            href="<?= e(baseUrl('librarian/books.php?category_id=' . $catId)) ?>"
                                                            class="btn btn-ghost btn-sm"
                                                            title="Xem các sách thuộc thể loại này"
                                                        >
                                                            <?= icon('book', 14) ?>
                                                            <span>Xem sách</span>
                                                        </a>
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

        <aside class="side-column">
            <?php if ($editingCategory): ?>
                <section class="panel">
                    <div class="panel-header">
                        <h2 class="panel-title">
                            <?= icon('edit', 18) ?>
                            <span>Sửa Thể loại [<?= e($editingCategory['dewey_code']) ?>]</span>
                        </h2>
                        <a href="<?= e(baseUrl('librarian/categories.php')) ?>" class="btn btn-ghost btn-sm">Hủy</a>
                    </div>
                    <form method="post" action="<?= e(baseUrl('librarian/categories.php')) ?>" class="panel-body">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="update_category">
                        <input type="hidden" name="category_id" value="<?= (int)$editingCategory['id'] ?>">

                        <div class="form-group">
                            <label class="form-label" for="edit_dewey_code">Mã thể loại (VD: 004, 005, 510)</label>
                            <input
                                type="text"
                                id="edit_dewey_code"
                                name="dewey_code"
                                value="<?= e($editingCategory['dewey_code']) ?>"
                                class="form-control mono-code"
                                maxlength="10"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="edit_name">Tên thể loại</label>
                            <input
                                type="text"
                                id="edit_name"
                                name="name"
                                value="<?= e($editingCategory['name']) ?>"
                                class="form-control"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="edit_shelf_zone">Vị trí khu vực kệ</label>
                            <input
                                type="text"
                                id="edit_shelf_zone"
                                name="shelf_zone"
                                value="<?= e($editingCategory['shelf_zone']) ?>"
                                class="form-control"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="edit_description">Mô tả thể loại</label>
                            <textarea
                                id="edit_description"
                                name="description"
                                rows="4"
                                class="form-textarea"
                            ><?= e($editingCategory['description']) ?></textarea>
                        </div>

                        <div class="page-actions" style="justify-content: flex-end; margin-top: 16px;">
                            <a href="<?= e(baseUrl('librarian/categories.php')) ?>" class="btn btn-ghost">Đóng</a>
                            <button type="submit" class="btn btn-primary">
                                <?= icon('check', 16) ?>
                                <span>Lưu thay đổi</span>
                            </button>
                        </div>
                    </form>
                </section>
            <?php else: ?>
                <section class="panel">
                    <div class="panel-header">
                        <h2 class="panel-title">
                            <?= icon('plus', 18) ?>
                            <span>Thêm Nhanh Thể loại Sách</span>
                        </h2>
                    </div>
                    <form method="post" action="<?= e(baseUrl('librarian/categories.php')) ?>" class="panel-body">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="create_category">

                        <div class="form-group">
                            <label class="form-label" for="quick_dewey_code">Mã thể loại</label>
                            <input
                                type="text"
                                id="quick_dewey_code"
                                name="dewey_code"
                                class="form-control mono-code"
                                placeholder="VD: 003 hoặc CNTT"
                                maxlength="10"
                                required
                            >
                            <span class="form-help">Nhập mã ký hiệu ngắn gọn cho thể loại sách.</span>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="quick_name">Tên thể loại</label>
                            <input
                                type="text"
                                id="quick_name"
                                name="name"
                                class="form-control"
                                placeholder="VD: Lập trình &amp; Phần mềm"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="quick_shelf_zone">Vị trí khu vực kệ</label>
                            <input
                                type="text"
                                id="quick_shelf_zone"
                                name="shelf_zone"
                                class="form-control"
                                placeholder="VD: Tầng 2 - Khu D"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="quick_description">Mô tả thể loại</label>
                            <textarea
                                id="quick_description"
                                name="description"
                                rows="3"
                                class="form-textarea"
                                placeholder="Ghi chú các loại sách thuộc nhóm này..."
                            ></textarea>
                        </div>

                        <div class="page-actions" style="justify-content: flex-end; margin-top: 16px;">
                            <button type="submit" class="btn btn-primary">
                                <?= icon('plus', 16) ?>
                                <span>Thêm thể loại</span>
                            </button>
                        </div>
                    </form>
                </section>
            <?php endif; ?>
        </aside>
    </div>
</div>

<dialog id="modal-create-category" class="modal-dialog">
    <div class="panel">
        <div class="panel-header">
            <h2 class="panel-title">
                <?= icon('plus', 18) ?>
                <span>Thêm Thể loại Sách Mới</span>
            </h2>
            <button type="button" class="btn btn-ghost btn-sm" data-modal-close>
                <?= icon('close', 16) ?>
            </button>
        </div>
        <form method="post" action="<?= e(baseUrl('librarian/categories.php')) ?>" class="panel-body">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_category">

            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label" for="modal_dewey_code">Mã thể loại</label>
                    <input type="text" id="modal_dewey_code" name="dewey_code" class="form-control mono-code" placeholder="VD: 003" maxlength="10" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="modal_shelf_zone">Vị trí khu vực kệ</label>
                    <input type="text" id="modal_shelf_zone" name="shelf_zone" class="form-control" placeholder="VD: Tầng 3 - Khu C" required>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="modal_name">Tên thể loại</label>
                <input type="text" id="modal_name" name="name" class="form-control" placeholder="Nhập tên thể loại sách" required>
            </div>

            <div class="form-group">
                <label class="form-label" for="modal_description">Mô tả thể loại</label>
                <textarea id="modal_description" name="description" rows="3" class="form-textarea" placeholder="Mô tả các môn học hoặc chủ đề sách thuộc thể loại này..."></textarea>
            </div>

            <div class="page-actions" style="justify-content: flex-end; margin-top: 16px;">
                <button type="button" class="btn btn-ghost" data-modal-close>Hủy</button>
                <button type="submit" class="btn btn-primary">
                    <?= icon('check', 16) ?>
                    <span>Lưu thể loại</span>
                </button>
            </div>
        </form>
    </div>
</dialog>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
