PROJECT_DIR="$(cd "$(dirname "$0")" && pwd)"
RUNTIME_DIR="$PROJECT_DIR/.runtime"
MYSQL_SOCK="/tmp/mysql.sock"
PHP_PID_FILE="$RUNTIME_DIR/php-server.pid"
MYSQL_PID_FILE="$RUNTIME_DIR/mysql.pid"
MYSQLADMIN_BIN="/Applications/XAMPP/xamppfiles/bin/mysqladmin"

if [ -f "$PHP_PID_FILE" ]; then
    PHP_PID="$(cat "$PHP_PID_FILE")"
    if kill -0 "$PHP_PID" 2>/dev/null; then
        echo "Đang dừng PHP Web Server (PID $PHP_PID)..."
        kill "$PHP_PID" 2>/dev/null || true
    fi
    rm -f "$PHP_PID_FILE"
fi

if [ -f "$MYSQL_PID_FILE" ] && [ -x "$MYSQLADMIN_BIN" ]; then
    echo "Đang dừng MariaDB local..."
    "$MYSQLADMIN_BIN" --socket="$MYSQL_SOCK" -u root shutdown 2>/dev/null || true
    rm -f "$MYSQL_PID_FILE"
fi

echo "Đã dừng toàn bộ tiến trình của dự án an toàn."
