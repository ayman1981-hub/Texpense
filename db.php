<?php
/**
 * db.php — PDO database connection for the Group Travel Expense Tracker.
 *
 * XAMPP defaults: host 127.0.0.1, user root, empty password.
 * Included by index.php, add_expense.php, and any future endpoint.
 */

declare(strict_types=1);

// --- Session -----------------------------------------------------------------
// The CSRF token lives in the session. (The active trip does NOT: it travels
// in the URL as ?trip_id=… — see resolve_active_trip_id() in lib.php — so a
// dashboard link is shareable and two tabs can sit on two different trips.)
// It must be started before any output and before the connection is used.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- Connection settings -----------------------------------------------------
$dbHost    = '127.0.0.1';            // use 127.0.0.1 rather than 'localhost' so PDO uses TCP, not a socket path
$dbName    = 'travel_tracker';
$dbUser    = 'root';
$dbPass    = '';                     // empty on a stock XAMPP MySQL/MariaDB install
$dbCharset = 'utf8mb4';

$dsn = "mysql:host={$dbHost};dbname={$dbName};charset={$dbCharset}";

$options = [
    // Throw exceptions instead of returning false — makes failures impossible to ignore.
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    // Fetch rows as associative arrays: $row['amount'].
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    // Use real server-side prepared statements (stronger protection against SQL injection).
    PDO::ATTR_EMULATE_PREPARES   => false,
    // Keep DECIMAL/INT values as PHP numbers rather than strings.
    PDO::ATTR_STRINGIFY_FETCHES  => false,
];

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
} catch (PDOException $e) {
    // Log the technical detail for the developer; never display it to the browser.
    error_log('[travel_tracker] DB connection failed: ' . $e->getMessage());

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }

    // Clean, generic message — no credentials, host names, or stack traces leaked.
    exit(
        '<div style="max-width:32rem;margin:4rem auto;padding:1.5rem;'
        . 'font-family:system-ui,-apple-system,Segoe UI,sans-serif;'
        . 'border:1px solid #e5c07b;border-radius:8px;background:#fffdf5;color:#5c4a10;">'
        . '<strong>Database unavailable</strong>'
        . '<p style="margin:.5rem 0 0;">We could not connect to the travel tracker database. '
        . 'Please make sure MySQL is running in the XAMPP control panel and that the '
        . '<code>travel_tracker</code> database exists, then reload this page.</p>'
        . '</div>'
    );
}
