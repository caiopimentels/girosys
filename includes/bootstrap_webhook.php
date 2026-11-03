<?php
// Carga mínima para quem chama o sistema sem estar logado (webhook do Inter,
// rotinas de linha de comando): sem sessão e sem a proteção CSRF dos
// formulários — a segurança é o segredo na URL e a conferência na API.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/cripto.php';
require_once __DIR__ . '/financeiro.php';
require_once __DIR__ . '/desconto.php';
require_once __DIR__ . '/inter.php';

// Funções de sessão usadas em alguns lançamentos (ex.: usuário da operação).
if (!function_exists('usuarioAtualId')) {
    function usuarioAtualId(): ?int
    {
        return null;
    }
}
