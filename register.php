<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth.php';

if (!empty($_SESSION['usuario_id'])) {
    header('Location: /index.php');
    exit;
}

$erro    = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();
    $nome  = trim($_POST['nome']  ?? '');
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha']      ?? '';
    $conf  = $_POST['confirmar']  ?? '';

    if (!$nome || !$email || !$senha || !$conf) {
        $erro = 'Preencha todos os campos.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erro = 'E-mail inválido.';
    } elseif (strlen($senha) < 6) {
        $erro = 'A senha deve ter pelo menos 6 caracteres.';
    } elseif ($senha !== $conf) {
        $erro = 'As senhas não coincidem.';
    } else {
        $db = get_db();

        $chk = $db->prepare("SELECT id FROM usuarios WHERE email = ?");
        $chk->execute([$email]);
        if ($chk->fetch()) {
            $erro = 'Este e-mail já está cadastrado.';
        } else {
            $db->prepare("INSERT INTO usuarios (nome, email, senha_hash, papel, aprovado) VALUES (?, ?, ?, 'aluno', 1)")
               ->execute([$nome, $email, password_hash($senha, PASSWORD_DEFAULT)]);
            $sucesso = 'Cadastro realizado! Você já pode fazer login.';
        }
    }
}

$page_title = 'Cadastro — ALMOX.SYS';
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

    <h1 class="auth-title">Criar conta</h1>
    <p class="auth-subtitle">Preencha os dados abaixo para se cadastrar.</p>

    <?php if ($erro): ?>
      <div class="alert alert-error"><?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>

    <?php if ($sucesso): ?>
      <div class="alert alert-success"><?= htmlspecialchars($sucesso) ?></div>
      <div class="auth-footer" style="margin-top:0">
        <a href="/login.php">← Ir para o login</a>
      </div>
    <?php else: ?>

    <form method="POST" action="/register.php">
      <?= csrf_field() ?>
      <div class="field">
        <label class="field-label">Nome completo <span class="required">*</span></label>
        <input type="text" name="nome" placeholder="Ex: João Pedro Silva"
               value="<?= htmlspecialchars($_POST['nome'] ?? '') ?>" required autofocus />
      </div>

      <div class="field">
        <label class="field-label">E-mail <span class="required">*</span></label>
        <input type="email" name="email" placeholder="seu@email.com"
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required />
      </div>

      <div class="field-row">
        <div class="field">
          <label class="field-label">Senha <span class="required">*</span></label>
          <input type="password" name="senha" placeholder="Mín. 6 caracteres" required />
        </div>
        <div class="field">
          <label class="field-label">Confirmar senha <span class="required">*</span></label>
          <input type="password" name="confirmar" placeholder="Repita a senha" required />
        </div>
      </div>

      <button type="submit" class="btn btn-primary w-full" style="margin-top:8px;justify-content:center">
        Criar conta →
      </button>
    </form>

    <div class="auth-footer">
      Já tem conta? <a href="/login.php">Fazer login</a>
    </div>

    <?php endif; ?>

  </div>

</div>

<script src="/assets/app.js"></script>
</body>
</html>
