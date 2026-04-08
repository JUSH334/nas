<?php
// db.php — shared PDO connection
// Included by any page that needs the database

$host = 'db';  // Docker service name from docker-compose.yml
$db   = $_ENV['MYSQL_DATABASE'] ?? getenv('MYSQL_DATABASE') ?? 'nas_db';
$user = $_ENV['MYSQL_USER']     ?? getenv('MYSQL_USER')     ?? 'nas_user';
$pass = $_ENV['MYSQL_PASSWORD'] ?? getenv('MYSQL_PASSWORD') ?? '';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$db;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode(['error' => 'Database connection failed.']));
}
