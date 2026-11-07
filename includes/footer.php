<?php

?>
    </main>

    <footer class="site-footer">
        <div class="container footer-grid">
            <div class="footer-col">
                <p class="footer-title"><?= e(APP_NAME) ?></p>
                <p class="footer-desc">Website hỗ trợ sinh viên tìm kiếm sách trực tuyến, đăng ký mượn sách online và giúp thủ thư quản lý sách, độc giả, phiếu mượn trả tại Thư viện Khoa CNTT - Trường Đại học Văn Lang.</p>
            </div>
            <div class="footer-col">
                <p class="footer-label">QUY ĐỊNH MƯỢN TRẢ SÁCH</p>
                <ul class="footer-list">
                    <li>Thời hạn mượn mặc định: <strong class="mono-num"><?= DEFAULT_BORROW_DAYS ?> ngày</strong></li>
                    <li>Số lượng mượn tối đa mỗi phiếu: <strong class="mono-num"><?= MAX_BOOKS_PER_LOAN ?> cuốn</strong></li>
                    <li>Tiền phạt trả sách trễ hạn: <strong class="mono-num"><?= formatVND(FINE_PER_DAY_VND) ?> / ngày</strong></li>
                </ul>
            </div>
            <div class="footer-col">
                <p class="footer-label">THÔNG TIN ĐỒ ÁN</p>
                <ul class="footer-list">
                    <li>Môn học: <strong>Lập trình ứng dụng web (Mã HP: 71ITSE30503)</strong></li>
                    <li>Đơn vị: <strong>Khoa CNTT - Trường Đại học Văn Lang</strong></li>
                    <li>Sinh viên thực hiện: <strong>Dương Minh Thiện</strong> (PHP &amp; MySQL)</li>
                </ul>
            </div>
        </div>
    </footer>

    <script src="<?= e(baseUrl('assets/js/app.js')) ?>"></script>
</body>
</html>
