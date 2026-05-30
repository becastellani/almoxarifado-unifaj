<?php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 28800);
    session_set_cookie_params([
        'lifetime' => 28800,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
