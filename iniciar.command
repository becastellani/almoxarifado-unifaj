#!/bin/bash
# ALMOX.SYS — Inicializador (Mac)
# Clique duplo neste arquivo para iniciar o sistema

DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$DIR"

clear
echo "======================================"
echo "   ALMOX.SYS — Sistema de Almoxarifado"
echo "======================================"
echo ""

# Verifica se PHP está disponível
PHP_BIN=""
for candidate in php /opt/homebrew/bin/php /usr/local/bin/php /usr/bin/php; do
    if command -v "$candidate" &>/dev/null; then
        PHP_BIN="$candidate"
        break
    fi
done

if [ -z "$PHP_BIN" ]; then
    echo "ERRO: PHP não encontrado no sistema."
    echo ""
    echo "Instale via Homebrew:"
    echo "  1. Abra o Terminal"
    echo "  2. Cole e pressione Enter:"
    echo "     /bin/bash -c \"\$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)\""
    echo "  3. Depois: brew install php"
    echo ""
    read -p "Pressione Enter para fechar..."
    exit 1
fi

# Verifica se a porta 8000 já está em uso
if lsof -i :8000 &>/dev/null; then
    echo "Porta 8000 já está em uso."
    echo "Abrindo o navegador no servidor existente..."
    echo ""
    sleep 1
    open "http://localhost:8000"
    echo "Pressione Ctrl+C para encerrar."
    wait
    exit 0
fi

echo "  Endereço : http://localhost:8000"
echo "  PHP      : $($PHP_BIN --version | head -1)"
echo ""
echo "  Para encerrar: feche esta janela"
echo ""
echo "--------------------------------------"

# Abre o navegador após 1.5s (dá tempo do servidor iniciar)
(sleep 1.5 && open "http://localhost:8000") &

# Inicia o servidor
"$PHP_BIN" -S localhost:8000 2>&1
