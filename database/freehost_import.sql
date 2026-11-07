SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS borrow_items;
DROP TABLE IF EXISTS borrow_records;
DROP TABLE IF EXISTS books;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_code VARCHAR(20) NOT NULL UNIQUE,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(120) NOT NULL UNIQUE,
    phone VARCHAR(20) DEFAULT NULL,
    department VARCHAR(100) DEFAULT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('librarian', 'reader') NOT NULL DEFAULT 'reader',
    status ENUM('active', 'locked') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dewey_code VARCHAR(10) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    shelf_zone VARCHAR(50) NOT NULL,
    description TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE books (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    isbn VARCHAR(25) NOT NULL UNIQUE,
    call_number VARCHAR(30) NOT NULL UNIQUE,
    title VARCHAR(200) NOT NULL,
    author VARCHAR(150) NOT NULL,
    publisher VARCHAR(120) NOT NULL,
    publish_year SMALLINT NOT NULL,
    pages SMALLINT DEFAULT 250,
    shelf_location VARCHAR(60) NOT NULL,
    total_copies INT NOT NULL DEFAULT 1,
    available_copies INT NOT NULL DEFAULT 1,
    summary TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_books_category FOREIGN KEY (category_id)
        REFERENCES categories(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX idx_books_search (title, author, call_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE borrow_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    receipt_code VARCHAR(25) NOT NULL UNIQUE,
    user_id INT NOT NULL,
    issued_by INT DEFAULT NULL,
    borrow_date DATE NOT NULL,
    due_date DATE NOT NULL,
    return_date DATE DEFAULT NULL,
    status ENUM('pending', 'borrowed', 'returned', 'overdue', 'rejected') NOT NULL DEFAULT 'borrowed',
    fine_amount DECIMAL(10, 0) NOT NULL DEFAULT 0,
    note VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_borrow_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_borrow_issuer FOREIGN KEY (issued_by)
        REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_borrow_status (status, due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE borrow_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    borrow_id INT NOT NULL,
    book_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    return_condition ENUM('good', 'damaged', 'lost') NOT NULL DEFAULT 'good',
    condition_note VARCHAR(150) DEFAULT NULL,
    CONSTRAINT fk_items_borrow FOREIGN KEY (borrow_id)
        REFERENCES borrow_records(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_items_book FOREIGN KEY (book_id)
        REFERENCES books(id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO users (id, member_code, full_name, email, phone, department, password_hash, role, status) VALUES
(1, 'TT001', 'Nguyễn Hoàng Minh', 'thuthu@thuvien.edu.vn', '0901234567', 'Phòng Thông tin - Thư viện', '$2y$10$BM21NsmzfMw/xF6aWVOnwOmYjp7uoHcLXtX0acCOFiqLxeCws8d1O', 'librarian', 'active'),
(2, 'TT002', 'Lê Thu Hà', 'halt@thuvien.edu.vn', '0902345678', 'Bộ phận Lưu hành Tài liệu', '$2y$10$BM21NsmzfMw/xF6aWVOnwOmYjp7uoHcLXtX0acCOFiqLxeCws8d1O', 'librarian', 'active'),
(3, 'SV202601', 'Dương Minh Thiện', 'thien20071005@gmail.com', '0918223344', 'Khoa Công nghệ Thông tin', '$2y$10$BM21NsmzfMw/xF6aWVOnwOmYjp7uoHcLXtX0acCOFiqLxeCws8d1O', 'reader', 'active'),
(4, 'SV202602', 'Trần Quốc Bảo', 'baotq.sv@thuvien.edu.vn', '0987654321', 'Khoa Kỹ thuật Phần mềm', '$2y$10$BM21NsmzfMw/xF6aWVOnwOmYjp7uoHcLXtX0acCOFiqLxeCws8d1O', 'reader', 'active'),
(5, 'SV202603', 'Phạm Ngọc Lan Anh', 'lananh.sv@thuvien.edu.vn', '0977112233', 'Khoa Hệ thống Thông tin', '$2y$10$BM21NsmzfMw/xF6aWVOnwOmYjp7uoHcLXtX0acCOFiqLxeCws8d1O', 'reader', 'active'),
(6, 'SV202604', 'Võ Đình Khôi', 'khoivd.sv@thuvien.edu.vn', '0933445566', 'Khoa Khoa học Máy tính', '$2y$10$BM21NsmzfMw/xF6aWVOnwOmYjp7uoHcLXtX0acCOFiqLxeCws8d1O', 'reader', 'locked');

INSERT INTO categories (id, dewey_code, name, shelf_zone, description) VALUES
(1, '004', 'Khoa học Máy tính & Kiến trúc Hệ thống', 'Tầng 2 . Khu A', 'Giáo trình kiến trúc máy tính, hệ điều hành, mạng máy tính và hạ tầng số.'),
(2, '005', 'Lập trình Phần mềm & Cơ sở Dữ liệu', 'Tầng 2 . Khu B', 'Sách chuyên khảo về ngôn ngữ lập trình, cấu trúc dữ liệu, giải thuật và hệ quản trị CSDL.'),
(3, '006', 'Trí tuệ Nhân tạo & Xử lý Dữ liệu', 'Tầng 2 . Khu C', 'Học máy, thị giác máy tính, xử lý ngôn ngữ tự nhiên và khai phá dữ liệu lớn.'),
(4, '510', 'Toán học Ứng dụng & Thống kê', 'Tầng 3 . Khu A', 'Giải tích, đại số tuyến tính, toán rời rạc, xác suất thống kê cho kỹ sư.'),
(5, '658', 'Quản trị Dự án & Kỹ năng Kỹ sư', 'Tầng 3 . Khu B', 'Quản lý dự án phần mềm Agile/Scrum, phân tích nghiệp vụ và thiết kế hệ thống.');

INSERT INTO books (id, category_id, isbn, call_number, title, author, publisher, publish_year, pages, shelf_location, total_copies, available_copies, summary) VALUES
(1, 2, '978-604-73-9102-8', '005.133 PHP-2025', 'Phát triển Ứng dụng Web với PHP 8 và MySQL hiện đại', 'PGS.TS. Nguyễn Văn Hòa', 'NXB Đại học Quốc gia TP.HCM', 2025, 412, 'Tầng 2 . Kệ B01 . Ngăn 2', 8, 6, 'Giáo trình học lập trình web cơ bản với PHP và MySQL, kết nối cơ sở dữ liệu PDO và xây dựng các chức năng thêm sửa xóa.'),
(2, 2, '978-604-67-2184-0', '005.74 EL-2024', 'Nguyên lý Hệ Quản trị Cơ sở Dữ liệu Quan hệ', 'TS. Trần Minh Tuấn, ThS. Lê Quang Vinh', 'NXB Khoa học và Kỹ thuật', 2024, 380, 'Tầng 2 . Kệ B02 . Ngăn 1', 10, 8, 'Tài liệu học phần Cơ sở dữ liệu: mô hình quan hệ, viết câu lệnh SQL, chuẩn hoá dữ liệu và thiết kế bảng.'),
(3, 2, '978-604-80-5512-7', '005.1 LE-2023', 'Cấu trúc Dữ liệu và Giải thuật Thực hành', 'Lê Minh Hoàng, Đỗ Xuân Huy', 'NXB Thông tin và Truyền thông', 2023, 468, 'Tầng 2 . Kệ B01 . Ngăn 1', 12, 11, 'Sách thực hành cấu trúc dữ liệu và giải thuật: danh sách liên kết, ngăn xếp, cây nhị phân và các thuật toán tìm kiếm, sắp xếp.'),
(4, 1, '978-604-73-8411-2', '004.6 TAN-2024', 'Mạng Máy tính và Giao thức Truyền thông Liên mạng', 'GS.TS. Đặng Văn Chuyết', 'NXB Bách Khoa Hà Nội', 2024, 520, 'Tầng 2 . Kệ A01 . Ngăn 3', 6, 5, 'Giáo trình mạng máy tính: mô hình OSI, TCP/IP, địa chỉ IP và cách cấu hình mạng nội bộ cơ bản.'),
(5, 1, '978-604-73-7721-3', '004.22 PAT-2023', 'Kiến trúc Máy tính và Tổ chức Hệ thống Vi xử lý', 'TS. Vũ Đức Lung', 'NXB Đại học Quốc gia TP.HCM', 2023, 356, 'Tầng 2 . Kệ A02 . Ngăn 1', 5, 5, 'Tài liệu môn Kiến trúc máy tính: nguyên lý hoạt động của CPU, bộ nhớ và các lệnh hợp ngữ cơ bản.'),
(6, 1, '978-604-67-1988-5', '005.43 SIL-2024', 'Nguyên lý Hệ Điều hành Unix và Linux', 'TS. Ngô Bá Hùng, ThS. Phạm Thế Phi', 'NXB Đại học Cần Thơ', 2024, 395, 'Tầng 2 . Kệ A01 . Ngăn 2', 7, 6, 'Giáo trình hệ điều hành: quản lý tiến trình, bộ nhớ, tệp tin và các lệnh điều khiển dòng lệnh trên Linux.'),
(7, 3, '978-604-80-6190-6', '006.31 NGU-2025', 'Nhập môn Học máy và Khai phá Dữ liệu Thực nghiệm', 'PGS.TS. Từ Minh Phương', 'NXB Khoa học và Kỹ thuật', 2025, 440, 'Tầng 2 . Kệ C01 . Ngăn 1', 6, 4, 'Tài liệu nhập môn máy học: làm quen với các thuật toán phân lớp, hồi quy và tiền xử lý dữ liệu cơ bản.'),
(8, 3, '978-604-73-9501-9', '006.35 LA-2025', 'Xử lý Ngôn ngữ Tự nhiên Tiếng Việt', 'TS. Nguyễn Thị Minh Huyền', 'NXB Đại học Quốc gia Hà Nội', 2025, 328, 'Tầng 2 . Kệ C02 . Ngăn 2', 4, 4, 'Tài liệu cơ bản về xử lý ngôn ngữ tự nhiên tiếng Việt, tách từ và phân loại câu chữ cơ bản.'),
(9, 4, '978-604-0-31204-4', '511.1 ROS-2022', 'Toán Rời rạc Ứng dụng trong Tin học', 'GS. Nguyễn Hữu Anh', 'NXB Giáo dục Việt Nam', 2022, 340, 'Tầng 3 . Kệ A03 . Ngăn 1', 15, 14, 'Giáo trình Toán rời rạc cho sinh viên CNTT: logic mệnh đề, tập hợp, phép đếm và lý thuyết đồ thị cơ bản.'),
(10, 4, '978-604-0-29811-9', '512.5 STR-2023', 'Đại số Tuyến tính và Ứng dụng trong Khoa học Dữ liệu', 'PGS.TS. Bùi Xuân Hải', 'NXB Giáo dục Việt Nam', 2023, 310, 'Tầng 3 . Kệ A03 . Ngăn 2', 9, 9, 'Sách đại số tuyến tính: ma trận, định thức, giải hệ phương trình tuyến tính và không gian vector.'),
(11, 5, '978-604-67-3012-5', '658.404 SOM-2024', 'Kỹ nghệ Phần mềm: Từ Phân tích Yêu cầu đến Triển khai', 'PGS.TS. Huỳnh Quyết Thắng', 'NXB Bách Khoa Hà Nội', 2024, 480, 'Tầng 3 . Kệ B01 . Ngăn 1', 8, 7, 'Giáo trình Kỹ nghệ phần mềm: quy trình phát triển phần mềm, khảo sát yêu cầu và vẽ sơ đồ ca sử dụng (Use case).'),
(12, 5, '978-604-80-4910-2', '658.05 MUL-2023', 'Quản lý Dự án Công nghệ Thông tin Thực hành', 'ThS. Đặng Hoàng Khang', 'NXB Thông tin và Truyền thông', 2023, 276, 'Tầng 3 . Kệ B02 . Ngăn 3', 3, 2, 'Tài liệu hướng dẫn quản lý dự án công nghệ thông tin và kỹ năng làm việc nhóm của sinh viên.');

INSERT INTO borrow_records (id, receipt_code, user_id, issued_by, borrow_date, due_date, return_date, status, fine_amount, note) VALUES
(1, 'PM-202610-001', 3, 1, '2026-10-15', '2026-10-29', NULL, 'borrowed', 0, 'Sinh viên mượn phục vụ đồ án học kỳ 1'),
(2, 'PM-202610-002', 4, 1, '2026-10-01', '2026-10-15', NULL, 'overdue', 45000, 'Quá hạn 9 ngày (5.000đ/ngày)'),
(3, 'PM-202609-018', 3, 2, '2026-09-20', '2026-10-04', '2026-10-02', 'returned', 0, 'Đã trả đúng hạn, sách nguyên vẹn'),
(4, 'PM-202610-003', 5, 2, '2026-10-18', '2026-11-01', NULL, 'borrowed', 0, 'Mượn đọc tại nhà 14 ngày'),
(5, 'PM-202610-004', 3, NULL, '2026-10-24', '2026-11-07', NULL, 'pending', 0, 'Sinh viên đăng ký mượn sách online');

INSERT INTO borrow_items (borrow_id, book_id, quantity, return_condition, condition_note) VALUES
(1, 1, 1, 'good', NULL),
(1, 2, 1, 'good', NULL),
(2, 4, 1, 'good', NULL),
(2, 7, 1, 'good', NULL),
(3, 3, 1, 'good', 'Sách tốt'),
(4, 1, 1, 'good', NULL),
(4, 6, 1, 'good', NULL),
(4, 12, 1, 'good', NULL),
(5, 9, 1, 'good', NULL),
(5, 11, 1, 'good', NULL);
