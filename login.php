<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth.php';

if (!empty($_SESSION['usuario_id'])) {
    header('Location: /index.php');
    exit;
}

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';

    if (!$email || !$senha) {
        $erro = 'Preencha e-mail e senha.';
    } else {
        $db   = get_db();
        $stmt = $db->prepare("SELECT * FROM usuarios WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($senha, $user['senha_hash'])) {
            $erro = 'E-mail ou senha incorretos.';
        } elseif (!$user['aprovado']) {
            $erro = 'Conta ainda não aprovada pelo administrador.';
        } else {
            $_SESSION['usuario_id'] = $user['id'];
            $_SESSION['nome']       = $user['nome'];
            $_SESSION['email']      = $user['email'];
            $_SESSION['papel']      = $user['papel'];
            session_regenerate_id(true);
            header('Location: /index.php');
            exit;
        }
    }
}

$page_title = 'Login — ALMOX.SYS';
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="dark">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= $page_title ?></title>
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600&family=IBM+Plex+Sans:wght@300;400;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="/assets/style.css" />
</head>
<body>

<div style="position:fixed;top:16px;right:16px;z-index:999">
  <button class="theme-toggle" id="themeToggle" title="Alternar tema">
    <span class="theme-icon">🌙</span>
  </button>
</div>

<div class="wrapper-sm">

  <div class="auth-card fade-up">

    <div class="auth-logo">
      <div class="logo-icon" style="width:40px;height:40px"></div>
      <span class="logo-text" style="font-size:16px">ALMOX<span class="logo-dot">.</span>SYS</span>
    </div>

    <h1 class="auth-title">Bem-vindo de volta</h1>
    <p class="auth-subtitle">Entre com suas credenciais para acessar o sistema.</p>

    <?php if ($erro): ?>
      <div class="alert alert-error"><?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>

    <form method="POST" action="/login.php">
      <?= csrf_field() ?>
      <div class="field">
        <label class="field-label">E-mail <span class="required">*</span></label>
        <input type="email" name="email" placeholder="seu@email.com"
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autofocus />
      </div>

      <div class="field">
        <label class="field-label">Senha <span class="required">*</span></label>
        <input type="password" name="senha" placeholder="••••••••" required />
      </div>

      <button type="submit" class="btn btn-primary w-full" style="margin-top:8px;justify-content:center">
        Entrar →
      </button>
    </form>

    <div class="auth-footer">
      Não tem conta? <a href="/register.php">Criar cadastro</a>
    </div>


  </div>

</div>

<script src="/assets/app.js"></script>
</body>
</html>
