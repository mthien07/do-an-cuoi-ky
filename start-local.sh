set -e

PROJECT_DIR="$(cd "$(dirname "$0")" && pwd)"
RUNTIME_DIR="$PROJECT_DIR/.runtime"
MYSQL_DATA="$RUNTIME_DIR/mysql_data"
MYSQL_SOCK="/tmp/mysql.sock"
MYSQL_PID_FILE="$RUNTIME_DIR/mysql.pid"
PHP_PID_FILE="$RUNTIME_DIR/php-server.pid"

XAMPP_BIN="/Applications/XAMPP/xamppfiles/bin"
XAMPP_SBIN="/Applications/XAMPP/xamppfiles/sbin"
XAMPP_HTDOCS="/Applications/XAMPP/xamppfiles/htdocs"

PHP_BIN="$(command -v php || echo "$XAMPP_BIN/php")"
MYSQL_BIN="$(command -v mysql || echo "$XAMPP_BIN/mysql")"
MYSQLADMIN_BIN="$(command -v mysqladmin || echo "$XAMPP_BIN/mysqladmin")"

mkdir -p "$RUNTIME_DIR"

if [ -d "$XAMPP_HTDOCS" ] && [ ! -e "$XAMPP_HTDOCS/do-an-cuoi-ky" ]; then
    ln -s "$PROJECT_DIR" "$XAMPP_HTDOCS/do-an-cuoi-ky" 2>/dev/null || true
fi

echo "==> [1/3] Kiểm tra dịch vụ MySQL / MariaDB..."
if ! "$MYSQLADMIN_BIN" --socket="$MYSQL_SOCK" -u root ping >/dev/null 2>&1; then
    if [ ! -d "$MYSQL_DATA/mysql" ]; then
        echo "    Khởi tạo thư mục dữ liệu MariaDB local..."
        "$XAMPP_BIN/mysql_install_db" --no-defaults \
            --basedir="/Applications/XAMPP/xamppfiles" \
            --datadir="$MYSQL_DATA" \
            --auth-root-authentication-method=normal >/dev/null
    fi

    echo "    Đang bật MariaDB (XAMPP binary sẵn có trên máy)..."
    "$XAMPP_SBIN/mysqld" --no-defaults \
        --basedir="/Applications/XAMPP/xamppfiles" \
        --datadir="$MYSQL_DATA" \
        --socket="$MYSQL_SOCK" \
        --port=3306 \
        --bind-address=127.0.0.1 \
        --pid-file="$MYSQL_PID_FILE" \
        --log-error="$RUNTIME_DIR/mysql.err" &

    for i in {1..15}; do
        if "$MYSQLADMIN_BIN" --socket="$MYSQL_SOCK" -u root ping >/dev/null 2>&1; then
            break
        fi
        sleep 1
    done
fi

echo "==> [2/3] Kiểm tra & nạp CSDL do_an_thu_vien..."
DB_EXISTS=$("$MYSQL_BIN" --socket="$MYSQL_SOCK" -u root -N -s -e "SHOW DATABASES LIKE 'do_an_thu_vien';" 2>/dev/null || true)
if [ -z "$DB_EXISTS" ]; then
    "$MYSQL_BIN" --socket="$MYSQL_SOCK" -u root < "$PROJECT_DIR/database/schema_and_seed.sql"
    echo "    Đã tạo bảng và nạp dữ liệu mẫu thành công."
else
    echo "    CSDL do_an_thu_vien đã sẵn sàng."
fi

echo "==> [3/3] Khởi chạy PHP Web Server tại http://localhost:8000 ..."
if [ -f "$PHP_PID_FILE" ] && kill -0 "$(cat "$PHP_PID_FILE")" 2>/dev/null; then
    kill "$(cat "$PHP_PID_FILE")" 2>/dev/null || true
fi

"$PHP_BIN" -S 127.0.0.1:8000 -t "$PROJECT_DIR" > "$RUNTIME_DIR/php-server.log" 2>&1 &
echo $! > "$PHP_PID_FILE"
sleep 1

echo " HỆ THỐNG QUẢN LÝ THƯ VIỆN ĐÃ CHẠY THÀNH CÔNG!"
echo " - Địa chỉ PHP Server:   http://localhost:8000"
echo " - Địa chỉ XAMPP Apache: http://localhost/do-an-cuoi-ky"
echo " - Tài khoản Thủ thư:    TT001    / Mật khẩu: 123456"
echo " - Tài khoản Độc giả:    SV202601 / Mật khẩu: 123456"
echo " - Để tắt toàn bộ:       ./stop-local.sh"
