<?php
/**
 * Database Connection Handler
 * Supports both MySQL (XAMPP) and PostgreSQL (Supabase) based on .env
 */

// Load environment variables if not already loaded
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $name  = trim($name);
            $value = trim(trim($value), '"\'');
            if (!getenv($name)) {
                putenv("{$name}={$value}");
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
}

$driver = getenv('DB_DRIVER') ?: ($_ENV['DB_DRIVER'] ?? 'mysql');

$pdo  = null;
$conn = null;

// Parse DATABASE_URL / INTERNAL_DATABASE_URL if provided by cloud host (Render, Railway, Supabase)
$dbUrl = getenv('DATABASE_URL') ?: (getenv('INTERNAL_DATABASE_URL') ?: (getenv('EXTERNAL_DATABASE_URL') ?: ($_ENV['DATABASE_URL'] ?? '')));
if (!empty($dbUrl)) {
    $parsedUrl = parse_url($dbUrl);
    if ($parsedUrl) {
        $driver = 'pgsql';
        $dbHost = $parsedUrl['host'] ?? '127.0.0.1';
        $dbPort = $parsedUrl['port'] ?? 5432;
        $dbUser = isset($parsedUrl['user']) ? rawurldecode($parsedUrl['user']) : 'postgres';
        $dbPass = isset($parsedUrl['pass']) ? rawurldecode($parsedUrl['pass']) : '';
        $dbName = isset($parsedUrl['path']) ? ltrim($parsedUrl['path'], '/') : 'postgres';
    }
}

if ($driver === 'pgsql') {
    if (!isset($dbHost)) {
        $dbHost = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? '127.0.0.1');
        $dbPort = getenv('DB_PORT') ?: ($_ENV['DB_PORT'] ?? '5432');
        $dbName = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? 'postgres');
        $dbUser = getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? 'postgres');
        $dbPass = getenv('DB_PASS') ?: ($_ENV['DB_PASS'] ?? '');
    }
    $dbSslMode = getenv('DB_SSLMODE') ?: ($_ENV['DB_SSLMODE'] ?? 'require');

    try {
        $dsn = "pgsql:host={$dbHost};port={$dbPort};dbname={$dbName}" . ($dbSslMode ? ";sslmode={$dbSslMode}" : "");
        $pdo = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => true,
        ]);
    } catch (PDOException $e) {
        if ($dbSslMode === 'require') {
            try {
                $dsnFallback = "pgsql:host={$dbHost};port={$dbPort};dbname={$dbName};sslmode=prefer";
                $pdo = new PDO($dsnFallback, $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => true,
                ]);
            } catch (PDOException $e2) {
                $pdo = null;
                error_log("PostgreSQL Connection Warning: " . $e2->getMessage());
            }
        } else {
            $pdo = null;
            error_log("PostgreSQL Connection Warning: " . $e->getMessage());
        }
    }
} else {
    // MySQL (XAMPP default)
    $host = getenv('MYSQL_HOST') ?: ($_ENV['MYSQL_HOST'] ?? '127.0.0.1');
    $port = getenv('MYSQL_PORT') ?: ($_ENV['MYSQL_PORT'] ?? '3306');
    $user = getenv('MYSQL_USER') ?: ($_ENV['MYSQL_USER'] ?? 'root');
    $pass = getenv('MYSQL_PASS') ?: ($_ENV['MYSQL_PASS'] ?? '');
    $name = getenv('MYSQL_NAME') ?: ($_ENV['MYSQL_NAME'] ?? 'telegram_support_db');

    // Create mysqli connection
    $conn = @mysqli_connect($host, $user, $pass, $name, (int)$port);
    if (!$conn) {
        die("MySQL Connection Failed: " . mysqli_connect_error() . "\nCheck if MySQL is running in XAMPP and database '{$name}' exists.\n");
    }
    mysqli_set_charset($conn, "utf8mb4");

    // Create PDO connection
    try {
        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        // Fallback silently if mysqli works
    }
}
