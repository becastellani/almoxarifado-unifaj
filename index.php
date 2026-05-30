<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/db.php';

if (!empty($_SESSION['usuario_id'])) {
    if ($_SESSION['papel'] === 'admin') {
        header('Location: /admin/dashboard.php');
    } else {
        header('Location: /aluno/dashboard.php');
    }
    exit;
}

header('Location: /login.php');
exit;
