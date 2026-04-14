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
