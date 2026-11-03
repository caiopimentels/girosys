<?php

set_exception_handler(function (Throwable $e) {
    error_log('[Erro não tratado] ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());

    if (!headers_sent()) {
        http_response_code(500);
    }

    if (function_exists('ehRequisicaoAjax') && ehRequisicaoAjax()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['sucesso' => false, 'mensagem' => 'Erro interno no servidor.']);
    } else {
        echo '<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><title>Erro</title></head>'
            . '<body style="font-family:sans-serif;text-align:center;padding:60px 20px;color:#333;">'
            . '<h1>Ocorreu um erro inesperado</h1><p>Tente novamente em instantes.</p>'
            . '</body></html>';
    }
});

// Cookie de sessão protegido: só via HTTP, só em HTTPS quando disponível e
// não enviado em requisições de origem cruzada.
$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
if ($https) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/financeiro.php';
require_once __DIR__ . '/desconto.php';
require_once __DIR__ . '/cartoes.php';
require_once __DIR__ . '/pagamento_ui.php';
require_once __DIR__ . '/extrato_cliente.php';

csrfToken();

// Página aberta dentro do modal de detalhe das listas (ver layout_header.php).
$modoModal = !empty($_GET['modal']);

// Todo POST precisa devolver o token (campo _csrf ou cabeçalho X-CSRF-Token,
// que o /assets/js/csrf.js coloca sozinho em todo form e fetch).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tokenEnviado = (string) ($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $tokenEnviado)) {
        http_response_code(403);
        if (ehRequisicaoAjax()) {
            responderJson(['sucesso' => false, 'mensagem' => 'Sessão desatualizada — recarregue a página e tente de novo.']);
        }
        flash('danger', 'Sua sessão estava desatualizada. Recarregue a página e tente novamente.');
        redirect('/');
    }
}
