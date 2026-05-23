<?php

function requer_login(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['usuario_id'])) {
        header('Location: /login.php');
        exit;
    }
}

function requer_admin(): void {
    requer_login();
    if ($_SESSION['papel'] !== 'admin') {
        header('Location: /aluno/dashboard.php');
        exit;
    }
}

function requer_aluno(): void {
    requer_login();
    if ($_SESSION['papel'] !== 'aluno') {
        header('Location: /admin/dashboard.php');
        exit;
    }
}

function usuario_logado(): array|false {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['usuario_id'])) return false;
    return [
        'id'    => $_SESSION['usuario_id'],
        'nome'  => $_SESSION['nome'],
        'email' => $_SESSION['email'],
        'papel' => $_SESSION['papel'],
    ];
}

function eh_admin(): bool {
    if (session_status() === PHP_SESSION_NONE) session_start();
    return ($_SESSION['papel'] ?? '') === 'admin';
}

function csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '" />';
}

function verificar_csrf(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $token = $_POST['csrf_token'] ?? '';
    if (empty($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die('Token inválido. Recarregue a página e tente novamente.');
    }
}
