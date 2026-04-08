<?php
// auth.php — include at the top of every protected page

session_start();

function require_login(): void {
    if (!isset($_SESSION['user_id'])) {
        header('Location: /login.php');
        exit;
    }
}

function require_admin(): void {
    require_login();
    if ($_SESSION['role'] !== 'admin') {
        http_response_code(403);
        die('Access denied.');
    }
}

function current_user(): array {
    return [
        'id'       => $_SESSION['user_id']  ?? null,
        'username' => $_SESSION['username'] ?? '',
        'role'     => $_SESSION['role']     ?? 'user',
    ];
}

function is_admin(): bool {
    return ($_SESSION['role'] ?? '') === 'admin';
}
