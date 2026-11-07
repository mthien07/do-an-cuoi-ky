<?php
require_once __DIR__ . '/constants.php';

function getDB()
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $socketPaths = [
        '/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock',
        '/tmp/mysql.sock',
        '/opt/homebrew/var/mysql/mysql.sock',
        '/var/run/mysqld/mysqld.sock',
    ];

    $endpoints = [
        sprintf('mysql:host=%s;port=%s;charset=utf8mb4', DB_HOST, DB_PORT),
    ];
    foreach ($socketPaths as $sock) {
        if (file_exists($sock)) {
            array_unshift($endpoints, sprintf('mysql:unix_socket=%s;charset=utf8mb4', $sock));
        }
    }

    $lastError = null;
    $isLocalHost = in_array(DB_HOST, ['127.0.0.1', 'localhost', '::1'], true);

    foreach ($endpoints as $baseDsn) {
        $dbDsn = $baseDsn . ';dbname=' . DB_NAME;

        try {
            $pdo = new PDO($dbDsn, DB_USER, DB_PASS, $options);
            if ($isLocalHost) {
                $hasBooksTable = (bool)$pdo->query("SHOW TABLES LIKE 'books'")->fetchColumn();
                if (!$hasBooksTable) {
                    ensureDatabaseBootstrapped($pdo);
                }
            }
            break;
        } catch (PDOException $directErr) {
            $lastError = $directErr;
        }

        if ($isLocalHost) {
            try {
                $serverPdo = new PDO($baseDsn, DB_USER, DB_PASS, $options);
                ensureDatabaseBootstrapped($serverPdo);

                $pdo = new PDO($dbDsn, DB_USER, DB_PASS, $options);
                break;
            } catch (PDOException $e) {
                $lastError = $e;
            }
        }
    }

    if ($pdo === null) {
        http_response_code(500);
        $msg = $lastError ? $lastError->getMessage() : 'Không tìm thấy MySQL Server';
        die('<!DOCTYPE html><html lang="vi"><head><meta charset="utf-8"><title>Lỗi kết nối CSDL</title>'
            . '<style>body{font-family:sans-serif;background:#F3F4F6;color:#121417;padding:48px;max-width:720px;margin:0 auto;}'
            . '.box{background:#FFFFFF;border:1px solid #1E232A;padding:24px;border-radius:4px;}</style></head><body>'
            . '<div class="box"><h2>Không thể kết nối CSDL MySQL (' . htmlspecialchars(DB_NAME) . ')</h2>'
            . '<p>Chi tiết lỗi: ' . htmlspecialchars($msg) . '</p>'
            . '<p>Vui lòng mở <strong>XAMPP</strong> và bật <strong>MySQL Database</strong>.</p></div></body></html>');
    }

    syncOverdueLoans($pdo);

    return $pdo;
}

function ensureDatabaseBootstrapped($serverPdo)
{
    $stmt = $serverPdo->prepare("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = :db");
    $stmt->execute(['db' => DB_NAME]);
    $exists = (bool)$stmt->fetchColumn();

    if ($exists) {
        $serverPdo->exec('USE `' . DB_NAME . '`');
        $checkTable = $serverPdo->query("SHOW TABLES LIKE 'books'")->fetchColumn();
        if ($checkTable) {
            return;
        }
    }

    $sqlFile = __DIR__ . '/../database/schema_and_seed.sql';
    if (!is_readable($sqlFile)) {
        return;
    }

    $sql = (string)file_get_contents($sqlFile);
    $serverPdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
    $serverPdo->exec($sql);
    $serverPdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
}

function syncOverdueLoans($pdo)
{
    static $synced = false;
    if ($synced) {
        return;
    }
    $synced = true;

    try {
        $sql = "UPDATE borrow_records
                SET status = 'overdue',
                    fine_amount = GREATEST(DATEDIFF(CURDATE(), due_date), 1) * :fine_rate
                WHERE status IN ('borrowed', 'overdue')
                  AND return_date IS NULL
                  AND due_date < CURDATE()";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['fine_rate' => FINE_PER_DAY_VND]);
    } catch (PDOException $e) {

    }
}
