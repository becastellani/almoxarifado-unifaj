<?php

define('DB_PATH', __DIR__ . '/database/almoxarifado.db');

function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON;');
        criar_schema($pdo);
    }
    return $pdo;
}

function criar_schema(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS usuarios (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            nome        TEXT    NOT NULL,
            email       TEXT    UNIQUE NOT NULL,
            senha_hash  TEXT    NOT NULL,
            papel       TEXT    NOT NULL DEFAULT 'aluno',
            aprovado    INTEGER NOT NULL DEFAULT 1,
            criado_em   DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS materiais (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            codigo          TEXT    UNIQUE NOT NULL,
            nome            TEXT    NOT NULL,
            descricao       TEXT,
            unidade         TEXT    NOT NULL DEFAULT 'UN',
            qtd_disponivel  INTEGER NOT NULL DEFAULT 0,
            qtd_total       INTEGER NOT NULL DEFAULT 0,
            criado_em       DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS solicitacoes (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            usuario_id      INTEGER NOT NULL,
            status          TEXT    NOT NULL DEFAULT 'pendente',
            urgencia        TEXT    NOT NULL DEFAULT 'media',
            justificativa   TEXT,
            local_entrega   TEXT,
            data_necessaria DATE,
            criado_em       DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        );

        CREATE TABLE IF NOT EXISTS itens_solicitacao (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            solicitacao_id  INTEGER NOT NULL,
            material_id     INTEGER NOT NULL,
            qtd_solicitada  INTEGER NOT NULL DEFAULT 1,
            FOREIGN KEY (solicitacao_id) REFERENCES solicitacoes(id),
            FOREIGN KEY (material_id)   REFERENCES materiais(id)
        );

        CREATE TABLE IF NOT EXISTS movimentacoes (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            solicitacao_id  INTEGER NOT NULL,
            tipo            TEXT    NOT NULL,
            usuario_id      INTEGER NOT NULL,
            observacao      TEXT,
            criado_em       DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (solicitacao_id) REFERENCES solicitacoes(id),
            FOREIGN KEY (usuario_id)     REFERENCES usuarios(id)
        );
    ");

    // Seed: admin padrão se não existir
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
    $stmt->execute(['admin@almox.local']);
    if (!$stmt->fetch()) {
        $pdo->prepare("INSERT INTO usuarios (nome, email, senha_hash, papel) VALUES (?, ?, ?, 'admin')")
            ->execute(['Administrador', 'admin@almox.local', password_hash('admin123', PASSWORD_DEFAULT)]);
    }
}
