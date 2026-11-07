<?php

require_once __DIR__ . '/includes/auth.php';

$pdo = getDB();

$q = trim((string)($_GET['q'] ?? ''));
$catCode = trim((string)($_GET['cat'] ?? ''));
$onlyAvailable = isset($_GET['avail']) && $_GET['avail'] === '1';

$statsRow = $pdo->query("
    SELECT
        COUNT(*) AS total_titles,
        COALESCE(SUM(total_copies), 0) AS total_volumes,
        COALESCE(SUM(available_copies), 0) AS available_volumes
    FROM books
")->fetch();

$categories = $pdo->query("
    SELECT c.*, COUNT(b.id) AS book_count
    FROM categories c
    LEFT JOIN books b ON b.category_id = c.id
    GROUP BY c.id
    ORDER BY c.dewey_code ASC
")->fetchAll();

$where = [];
$params = [];

if ($q !== '') {
    $where[] = "(b.title LIKE :q1 OR b.author LIKE :q2 OR b.isbn LIKE :q3 OR b.call_number LIKE :q4)";
    $kw = '%' . $q . '%';
    $params['q1'] = $kw;
    $params['q2'] = $kw;
    $params['q3'] = $kw;
    $params['q4'] = $kw;
}

if ($catCode !== '') {
    $where[] = "c.dewey_code = :cat";
    $params['cat'] = $catCode;
}

if ($onlyAvailable) {
    $where[] = "b.available_copies > 0";
}

$sql = "
    SELECT b.*, c.dewey_code, c.name AS category_name, c.shelf_zone
    FROM books b
    INNER JOIN categories c ON c.id = b.category_id
";

if (!empty($where)) {
    $sql .= " WHERE " . implode(' AND ', $where);
}

$sql .= " ORDER BY b.call_number ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$books = $stmt->fetchAll();

$pageTitle = 'Trang chủ - Tìm sách thư viện';
$activeNav = 'catalog';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">

    <section class="opac-hero" aria-label="Khu vực tìm kiếm sách">
        <div class="opac-hero-search">
            <div>
                <div class="opac-kicker">
                    <?= icon('archive', 15) ?>
                    <span>THƯ VIỆN KHOA CÔNG NGHỆ THÔNG TIN</span>
                </div>
                <h1 class="opac-headline">Tìm kiếm &amp; Đăng ký Mượn Sách Thư viện</h1>
                <p class="opac-lead">
                    Tìm nhanh theo Tên sách, tác giả, mã ISBN hoặc Mã sách trên từng kệ sách trong thư viện.
                </p>
            </div>

            <form action="<?= e(baseUrl('index.php')) ?>" method="get" class="opac-search-box" role="search">
                <?php if ($catCode !== ''): ?>
                    <input type="hidden" name="cat" value="<?= e($catCode) ?>">
                <?php endif; ?>
                <?php if ($onlyAvailable): ?>
                    <input type="hidden" name="avail" value="1">
                <?php endif; ?>

                <input
                    type="search"
                    name="q"
                    value="<?= e($q) ?>"
                    class="form-control"
                    placeholder="Nhập Tên sách, tác giả, ISBN hoặc Mã sách (VD: 005.133, PHP 8, Nguyễn Văn Hòa)..."
                    aria-label="Từ khóa tìm kiếm sách"
                >
                <button type="submit" class="btn btn-primary">
                    <?= icon('search', 18) ?>
                    <span>Tìm sách</span>
                </button>
            </form>
        </div>

        <aside class="opac-hero-index" aria-label="Thống kê nhanh kho sách">
            <div>
                <p class="form-label" style="margin-bottom: var(--space-2);">
                    <span>THỐNG KÊ KHO SÁCH HIỆN TẠI</span>
                    <span class="mono-code">KHOA CNTT</span>
                </p>
                <div class="index-stat-row">
                    <div class="index-stat-box">
                        <div class="index-stat-num"><?= (int)$statsRow['total_titles'] ?></div>
                        <div class="index-stat-label">Đầu sách</div>
                    </div>
                    <div class="index-stat-box">
                        <div class="index-stat-num"><?= (int)$statsRow['total_volumes'] ?></div>
                        <div class="index-stat-label">Tổng số cuốn</div>
                    </div>
                    <div class="index-stat-box">
                        <div class="index-stat-num text-stamp"><?= (int)$statsRow['available_volumes'] ?></div>
                        <div class="index-stat-label">Sách có sẵn</div>
                    </div>
                </div>
            </div>

            <div class="dewey-quick-list">
                <?php foreach ($categories as $cat): ?>
                    <?php
                    $isActiveCat = ($catCode === $cat['dewey_code']);
                    $chipUrl = baseUrl('index.php?cat=' . urlencode($cat['dewey_code']) . ($q !== '' ? '&q=' . urlencode($q) : '') . ($onlyAvailable ? '&avail=1' : ''));
                    ?>
                    <a href="<?= e($chipUrl) ?>" class="dewey-chip <?= $isActiveCat ? 'is-active' : '' ?>">
                        <span>
                            <span class="dewey-chip-code"><?= e($cat['dewey_code']) ?></span>
                            <span><?= e($cat['name']) ?></span>
                        </span>
                        <span class="mono-num"><?= (int)$cat['book_count'] ?> đầu sách</span>
                    </a>
                <?php endforeach; ?>
            </div>
        </aside>
    </section>

    <section class="catalog-layout" aria-label="Danh sách sách">
        <aside class="catalog-sidebar">
            <div class="catalog-sidebar-title">
                <span>BỘ LỌC TÌM SÁCH</span>
                <?= icon('filter', 15) ?>
            </div>

            <form action="<?= e(baseUrl('index.php')) ?>" method="get">
                <div class="form-group">
                    <label for="filter-q" class="form-label">Từ khóa tìm kiếm</label>
                    <input
                        type="text"
                        id="filter-q"
                        name="q"
                        value="<?= e($q) ?>"
                        class="form-control"
                        placeholder="Tên sách, tác giả, Mã sách..."
                    >
                </div>

                <div class="form-group">
                    <label for="filter-cat" class="form-label">Thể loại sách</label>
                    <select id="filter-cat" name="cat" class="form-select">
                        <option value="">Tất cả Thể loại sách</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= e($cat['dewey_code']) ?>" <?= $catCode === $cat['dewey_code'] ? 'selected' : '' ?>>
                                [<?= e($cat['dewey_code']) ?>] <?= e($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="checkbox-card">
                        <input type="checkbox" name="avail" value="1" <?= $onlyAvailable ? 'checked' : '' ?>>
                        <span>
                            <strong style="display:block; font-size: var(--text-xs);">Chỉ hiện sách còn trên kệ</strong>
                            <span class="form-help">Lọc các sách có số cuốn còn lại lớn hơn 0</span>
                        </span>
                    </label>
                </div>

                <div style="display: flex; flex-direction: column; gap: var(--space-2); margin-top: var(--space-4);">
                    <button type="submit" class="btn btn-primary btn-block">
                        <?= icon('search', 16) ?>
                        <span>Lọc danh sách</span>
                    </button>
                    <?php if ($q !== '' || $catCode !== '' || $onlyAvailable): ?>
                        <a href="<?= e(baseUrl('index.php')) ?>" class="btn btn-ghost btn-block">
                            <?= icon('close', 16) ?>
                            <span>Xóa bộ lọc</span>
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </aside>

        <div>
            <div class="filter-bar" style="margin-bottom: var(--space-4); padding: var(--space-3) var(--space-5);">
                <div>
                    <span class="mono-code" style="font-size: var(--text-xs); color: var(--text-muted);">DANH SÁCH SÁCH:</span>
                    <strong class="mono-num" style="margin-left: var(--space-2);"><?= count($books) ?> Sách phù hợp</strong>
                    <?php if ($catCode !== ''): ?>
                        <span class="status-badge badge-borrowed" style="margin-left: var(--space-2);">Thể loại: <?= e($catCode) ?></span>
                    <?php endif; ?>
                    <?php if ($q !== ''): ?>
                        <span class="status-badge badge-pending" style="margin-left: var(--space-2);">Từ khóa: "<?= e($q) ?>"</span>
                    <?php endif; ?>
                </div>
                <div class="mono-code" style="font-size: var(--text-xs); color: var(--text-muted);">
                    SẮP XẾP THEO MÃ SÁCH (A-Z)
                </div>
            </div>

            <?php if (empty($books)): ?>
                <div class="empty-state">
                    <p class="empty-state-title">Không tìm thấy cuốn sách nào phù hợp</p>
                    <p style="margin: 0 auto var(--space-4) auto; font-size: var(--text-sm);">
                        Vui lòng kiểm tra lại từ khóa tìm kiếm hoặc bấm xóa bộ lọc để xem toàn bộ sách trong thư viện.
                    </p>
                    <a href="<?= e(baseUrl('index.php')) ?>" class="btn btn-secondary">
                        <span>Hiển thị tất cả sách</span>
                    </a>
                </div>
            <?php else: ?>
                <div class="catalog-grid">
                    <?php foreach ($books as $book): ?>
                        <?php
                        $avail = (int)$book['available_copies'];
                        $total = (int)$book['total_copies'];
                        $isAvail = $avail > 0;
                        ?>
                        <article class="index-card">
                            <div class="call-spine">
                                <span class="call-number-tag">Mã sách: <?= e($book['call_number']) ?></span>
                                <span class="dewey-tag">Thể loại: <?= e($book['category_name']) ?> (<?= e($book['dewey_code']) ?>)</span>
                            </div>

                            <div class="index-card-body">
                                <h2 class="index-card-title">
                                    <a href="<?= e(baseUrl('book.php?id=' . (int)$book['id'])) ?>">
                                        <?= e($book['title']) ?>
                                    </a>
                                </h2>
                                <p class="index-card-author"><?= e($book['author']) ?></p>
                                <p class="index-card-pub">
                                    <?= e($book['publisher']) ?> . Năm XB: <?= (int)$book['publish_year'] ?> . ISBN: <?= e($book['isbn']) ?>
                                </p>
                                <?php if (!empty($book['summary'])): ?>
                                    <p class="index-card-summary"><?= e($book['summary']) ?></p>
                                <?php endif; ?>
                            </div>

                            <div class="index-card-footer">
                                <div class="shelf-tag" title="Vị trí kệ sách">
                                    <?= icon('archive', 14) ?>
                                    <span><?= e($book['shelf_location']) ?></span>
                                </div>

                                <div style="display: flex; align-items: center; gap: var(--space-3);">
                                    <span class="copy-counter <?= $isAvail ? 'is-available' : 'is-out' ?>">
                                        <?= $isAvail ? 'Còn: ' . $avail . '/' . $total . ' cuốn' : 'Đã hết sách (0/' . $total . ')' ?>
                                    </span>
                                    <a href="<?= e(baseUrl('book.php?id=' . (int)$book['id'])) ?>" class="btn btn-secondary btn-sm">
                                        <span>Xem chi tiết</span>
                                        <?= icon('arrow-right', 14) ?>
                                    </a>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
