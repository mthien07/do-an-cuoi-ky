<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/icons.php';

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatVND($amount)
{
    $num = (float)$amount;
    if ($num <= 0) {
        return '0 ₫';
    }
    return number_format($num, 0, ',', '.') . ' ₫';
}

function formatDate($dateStr)
{
    if (empty($dateStr)) {
        return 'Chưa có';
    }
    $ts = strtotime($dateStr);
    return $ts ? date('d/m/Y', $ts) : e($dateStr);
}

function getDueDaysDiff($dueDate, $returnDate = null)
{
    $due = new DateTime($dueDate);
    $ref = $returnDate ? new DateTime($returnDate) : new DateTime('today');
    $diff = $ref->diff($due);
    return (int)$diff->format('%r%a');
}

function renderLoanStatusBadge($status)
{
    $map = [
        'pending'  => ['class' => 'badge-pending',  'icon' => 'clock',  'label' => 'Chờ thủ thư duyệt'],
        'borrowed' => ['class' => 'badge-borrowed', 'icon' => 'book',   'label' => 'Đang mượn'],
        'overdue'  => ['class' => 'badge-overdue',  'icon' => 'alert',  'label' => 'Quá hạn trả'],
        'returned' => ['class' => 'badge-returned', 'icon' => 'check',  'label' => 'Đã trả sách'],
        'rejected' => ['class' => 'badge-rejected', 'icon' => 'close',  'label' => 'Từ chối'],
    ];
    $item = $map[$status] ?? ['class' => 'badge-pending', 'icon' => 'clock', 'label' => $status];
    return sprintf(
        '<span class="status-badge %s">%s<span>%s</span></span>',
        $item['class'],
        icon($item['icon'], 14),
        e($item['label'])
    );
}

function generateReceiptCode($pdo)
{
    $prefix = 'PM-' . date('Ym') . '-';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM borrow_records WHERE receipt_code LIKE :prefix");
    $stmt->execute(['prefix' => $prefix . '%']);
    $count = (int)$stmt->fetchColumn() + 1;
    return $prefix . str_pad((string)$count, 3, '0', STR_PAD_LEFT);
}

function setFlash($type, $message)
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function csrfToken()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf_token'];
}

function csrfField()
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

function verifyCsrf()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $token = $_POST['csrf_token'] ?? '';
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$token);
}

function baseUrl($path = '')
{
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $root = preg_replace('#/librarian$#', '', rtrim($scriptDir, '/'));
    $cleanPath = ltrim($path, '/');
    if ($cleanPath === '') {
        return ($root === '' ? '/' : $root . '/');
    }
    return ($root === '' ? '' : $root) . '/' . $cleanPath;
}
