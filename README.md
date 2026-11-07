# BÁO CÁO ĐỒ ÁN CUỐI KỲ: WEBSITE QUẢN LÝ THƯ VIỆN

- **Môn học:** Lập trình ứng dụng web (Mã HP: `71ITSE30503`)
- **Khoa:** Công nghệ Thông tin - Trường Đại học Văn Lang
- **Sinh viên thực hiện:** Dương Minh Thiện
- **Email:** thien20071005@gmail.com
- **Công nghệ sử dụng:** PHP, MySQL (MariaDB), HTML, CSS, JavaScript

---

## 1. Giới thiệu đề tài

Website được xây dựng để phục vụ công tác quản lý thư viện trường học, gồm 2 nhóm người dùng:
1. **Sinh viên:** Có thể tra cứu sách theo tên, tác giả, thể loại, xem vị trí kệ sách và gửi yêu cầu mượn sách trực tuyến, theo dõi lịch sử mượn trả của bản thân.
2. **Thủ thư:** Quản lý danh mục sách, quản lý thể loại, quản lý tài khoản độc giả, duyệt phiếu mượn online, lập phiếu mượn tại quầy, ghi nhận trả sách và tự động tính tiền phạt trễ hạn.

---

## 2. Tài khoản chạy thử (Demo)

Trang đăng nhập (`login.php`) có sẵn các nút bấm điền nhanh tài khoản để tiện báo cáo:

| Vai trò | Mã đăng nhập | Mật khẩu | Mô tả tài khoản |
| :--- | :--- | :--- | :--- |
| **Thủ thư** | `TT001` | `123456` | Quản lý toàn bộ hệ thống thư viện |
| **Thủ thư 2** | `TT002` | `123456` | Tài khoản thủ thư bộ phận lưu hành |
| **Sinh viên** | `SV202601` | `123456` | Sinh viên mượn sách bình thường |
| **Sinh viên (Quá hạn)** | `SV202602` | `123456` | Có phiếu mượn quá hạn để thử tính tiền phạt |
| **Tài khoản bị khóa** | `SV202604` | `123456` | Dùng để thử chức năng chặn đăng nhập |

---

## 3. Các chức năng đã làm

### Phía sinh viên
- Tra cứu và tìm kiếm sách theo từ khóa, lọc theo thể loại.
- Xem chi tiết từng cuốn sách (vị trí trên kệ, số lượng còn trong kho, tóm tắt).
- Đăng ký tài khoản thẻ thư viện mới.
- Gửi yêu cầu mượn sách online (tối đa 3 cuốn/lần, mượn 14 ngày).
- Xem lịch sử mượn sách, hạn trả, trạng thái phiếu và tiền phạt nếu quá hạn.
- Tự hủy yêu cầu mượn khi thủ thư chưa duyệt.

### Phía thủ thư
- **Bảng thống kê:** Tổng số đầu sách, số độc giả, số phiếu đang mượn, sách quá hạn và tổng tiền phạt.
- **Quản lý sách:** Xem danh sách, tìm kiếm, thêm sách mới, sửa thông tin, xóa sách.
- **Quản lý thể loại:** Thêm, sửa, xóa danh mục thể loại sách và khu vực kệ.
- **Quản lý độc giả:** Xem danh sách, thêm bạn đọc, khóa/mở khóa thẻ, đặt lại mật khẩu.
- **Quản lý mượn trả:** Duyệt phiếu mượn online, tạo phiếu mượn tại quầy, cập nhật ngày trả sách, tính tiền phạt trễ hạn (5.000đ/ngày), gia hạn hạn trả.

---

## 4. Hướng dẫn chạy đồ án (Localhost)

1. Mở phần mềm **XAMPP**, bấm Start **MySQL**.
2. Mở Terminal / CMD tại thư mục dự án và chạy:
   ```bash
   ./start-local.sh
   ```
   *(Hoặc chạy lệnh PHP server trực tiếp: `php -S localhost:8000`)*
3. Mở trình duyệt web truy cập:
   ```
   http://localhost:8000
   ```
   *(Hệ thống sẽ tự động tạo cơ sở dữ liệu `do_an_thu_vien` và nạp sẵn dữ liệu mẫu nếu chưa có).*
4. Khi muốn dừng server:
   ```bash
   ./stop-local.sh
   ```

---

## 5. Cấu trúc thư mục

- `index.php`: Trang chủ tra cứu sách
- `book.php`: Xem thông tin chi tiết sách và mượn sách
- `login.php`: Trang đăng nhập
- `register.php`: Trang đăng ký tài khoản sinh viên
- `logout.php`: Đăng xuất khỏi hệ thống
- `my-loans.php`: Trang theo dõi sách đã mượn của sinh viên
- `librarian/`: Thư mục chứa các trang quản trị của thủ thư
  - `dashboard.php`: Trang thống kê
  - `books.php`: Quản lý sách
  - `categories.php`: Quản lý thể loại
  - `loans.php`: Quản lý mượn trả
  - `readers.php`: Quản lý độc giả
- `config/`: Cấu hình kết nối cơ sở dữ liệu
- `database/`: File script tạo bảng và dữ liệu mẫu SQL
- `includes/`: Header, footer và các hàm xử lý dùng chung
- `assets/`: File CSS, JavaScript và giao diện
