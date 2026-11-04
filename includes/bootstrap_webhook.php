<?php
// Carga mínima para quem chama o sistema sem estar logado (webhook do Inter,
// rotinas de linha de comando): sem sessão e sem a proteção CSRF dos
// formulários — a segurança é o segredo na URL e a conferência na API.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/cripto.php';
require_once __DIR__ . '/financeiro.php';
require_once __DIR__ . '/desconto.php';
require_once __DIR__ . '/auditoria.php';
require_once __DIR__ . '/smtp_mailer.php';
require_once __DIR__ . '/inter.php';

// Sem ninguém logado: o que acontece aqui fica na auditoria como "Sistema".
function usuarioAtualId(): ?int
{
    return null;
}

function usuarioAtualNome(): string
{
    return 'Sistema';
}

set_exception_handler(function (Throwable $e) {
    error_log('[Erro não tratado] ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
    notificarErroSistema($e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n\n" . $e->getTraceAsString(), PHP_SAPI === 'cli' ? 'Rotina: ' . implode(' ', $_SERVER['argv'] ?? []) : 'Webhook: ' . ($_SERVER['REQUEST_URI'] ?? ''));
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        http_response_code(500);
    }
    echo PHP_SAPI === 'cli' ? 'Erro: ' . $e->getMessage() . "\n" : '{"ok":false}';
});
