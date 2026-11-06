<?php

// Espera que a página defina $pageTitle e $activeMenu antes de incluir este arquivo.
// Opcionalmente $pageAction (HTML dos botões) e $pageBreadcrumb (texto).

$pageTitle = $pageTitle ?? nomeSistema();
$activeMenu = $activeMenu ?? '';
$pageAction = $pageAction ?? '';
$pageBreadcrumb = $pageBreadcrumb ?? '';
$nomeSistema = nomeSistema();
$corPrimaria = corSistema('primaria');
$corSecundaria = corSistema('secundaria');

// Aberto dentro do modal de detalhe (?modal=1): só o miolo da página — o
// título vai para o cabeçalho do modal e não há menu, rodapé nem scripts.
if (!empty($modoModal)) {
    echo '<div class="modal-conteudo" data-titulo="' . e($pageTitle) . '" data-subtitulo="' . e($pageBreadcrumb) . '">';
    return;
}

// Menus do topo: chave => [rótulo, itens[]]. Item: [texto, link, chave do $activeMenu, divisória antes?].
$menus = [
    'operacoes' => [
        'rotulo' => 'Operações',
        'itens' => [
            ['Nova operação de desconto', '/operacoes/form.php', 'operacao-nova'],
            ['Operações realizadas', '/operacoes/listar.php', 'operacoes'],
            ['Repasses a clientes', '/adiantamentos/listar.php', 'adiantamentos', true],
        ],
    ],
    'financeiro' => [
        'rotulo' => 'Financeiro',
        'itens' => [
            ['Contas a receber', '/receber/listar.php', 'receber'],
            ['Contas a pagar', '/pagar/listar.php', 'pagar'],
            ['Recebimentos e pagamentos', '/financeiro/movimentacao.php', 'movimentacao'],
            ['Cartões de crédito', '/cartoes/listar.php', 'cartoes', true],
        ],
    ],
    'contas' => [
        'rotulo' => 'Contas bancárias',
        'itens' => [
            ['Saldos', '/contas/listar.php', 'contas'],
            ['Extrato', '/contas/extrato.php', 'extrato'],
            ['Transferência entre contas', '/contas/transferir.php', 'transferir'],
        ],
    ],
    'relatorios' => [
        'rotulo' => 'Relatórios',
        'itens' => [
            ['Rentabilidade (quanto ganhei)', '/relatorios/rentabilidade.php', 'rentabilidade'],
        ],
    ],
    'cadastros' => [
        'rotulo' => 'Cadastros',
        'itens' => [
            ['Clientes', '/pessoas/listar.php?papel=cliente', 'pessoas-cliente'],
            ['Pagadores (emitentes dos títulos)', '/pessoas/listar.php?papel=pagador', 'pessoas-pagador'],
            ['Fornecedores', '/pessoas/listar.php?papel=fornecedor', 'pessoas-fornecedor'],
            ['Categorias', '/categorias/listar.php', 'categorias', true],
            ['Tipos de recebimento', '/configuracoes/recebimento.php', 'config-recebimento'],
        ],
    ],
];

// Cada item só aparece para quem tem a permissão da tela (ver permissaoDaPagina).
foreach ($menus as $chaveMenu => $menu) {
    $menus[$chaveMenu]['itens'] = array_values(array_filter($menu['itens'], function ($item) {
        $permissao = permissaoDaPagina((string) parse_url($item[1], PHP_URL_PATH));
        return !$permissao || usuarioTemPermissao($permissao[0]);
    }));
    if (!$menus[$chaveMenu]['itens']) {
        unset($menus[$chaveMenu]);
    }
}
?>
<!doctype html>
<html lang="pt-br" data-bs-theme="<?= usuarioLogado() && usuarioAtualTema() === 'escuro' ? 'dark' : 'light' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrfToken()) ?>">
    <title><?= e($pageTitle) ?> - <?= e($nomeSistema) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;450;500;600&display=swap" rel="stylesheet">
    <link href="<?= e(assetUrl('/assets/css/app.css')) ?>" rel="stylesheet">
    <link href="<?= e(assetUrl('/assets/css/sistema.css')) ?>" rel="stylesheet">
    <?php if ($iconeAba = urlIconeAba()): ?><link rel="icon" type="image/png" href="<?= e($iconeAba) ?>"><?php endif; ?>
    <style>
    :root {
        --accent: <?= e($corPrimaria) ?>;
        --accent-dark: <?= e($corSecundaria) ?>;
        --accent-soft: color-mix(in srgb, <?= e($corPrimaria) ?> 10%, white);
    }
    </style>
</head>
<body>

<header class="topnav">
    <div class="topnav-inner">
        <span class="topnav-logo"><?= htmlLogoSistema('topnav') ?></span>
        <a class="topnav-marca" href="/index.php"><?= e($nomeSistema) ?></a>

        <button class="topnav-toggle" type="button" onclick="document.getElementById('menu-principal').classList.toggle('aberto')">
            <i class="bi bi-list"></i>
        </button>

        <nav class="topnav-menu" id="menu-principal">
            <?php if (usuarioTemPermissao('ver_dashboard')): ?><a class="topnav-item <?= $activeMenu === 'dashboard' ? 'ativo' : '' ?>" href="/index.php">Início</a><?php endif; ?>
            <?php foreach ($menus as $menu): ?>
                <?php $grupoAtivo = $activeMenu !== '' && in_array($activeMenu, array_column($menu['itens'], 2), true); ?>
                <div class="dropdown topnav-grupo">
                    <button class="topnav-item <?= $grupoAtivo ? 'ativo' : '' ?>" data-bs-toggle="dropdown" type="button">
                        <?= e($menu['rotulo']) ?> <i class="bi bi-chevron-down topnav-seta"></i>
                    </button>
                    <div class="dropdown-menu topnav-dropdown">
                        <?php foreach ($menu['itens'] as $i => $item): ?>
                            <?php if ($i > 0 && ($item[3] ?? false)): ?><div class="dropdown-divider"></div><?php endif; ?>
                            <a class="dropdown-item" href="<?= e($item[1]) ?>"><?= e($item[0]) ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </nav>

        <div class="topnav-direita">
            <?php if (usuarioTemPermissao('operacoes')): ?><a href="/operacoes/form.php" class="btn btn-sm btn-brand d-none d-lg-inline-flex align-items-center gap-1"><i class="bi bi-calculator"></i> Descontar</a><?php endif; ?>

            <button type="button" class="topnav-icone" title="Trocar para tema <?= usuarioAtualTema() === 'escuro' ? 'claro' : 'escuro' ?>" onclick="alternarTema()">
                <i class="bi <?= usuarioAtualTema() === 'escuro' ? 'bi-sun' : 'bi-moon-stars' ?>"></i>
            </button>

            <span class="topnav-sessao" id="topnav-sessao-timer" title="Tempo até a sessão expirar por inatividade">
                <i class="bi bi-clock-history"></i> <span id="sessao-timer-valor">--:--</span>
            </span>

            <?php if (usuarioEhAdmin()): ?>
            <div class="dropdown">
                <button class="topnav-icone <?= in_array($activeMenu, ['usuarios', 'perfis', 'configuracoes'], true) ? 'ativo' : '' ?>" data-bs-toggle="dropdown" type="button" title="Administração">
                    <i class="bi bi-gear"></i>
                </button>
                <div class="dropdown-menu dropdown-menu-end topnav-dropdown">
                    <a class="dropdown-item" href="/usuarios/listar.php">Usuários</a>
                    <a class="dropdown-item" href="/perfis/listar.php">Perfis de acesso</a>
                    <a class="dropdown-item" href="/configuracoes/form.php">Configurações</a>
                    <a class="dropdown-item" href="/configuracoes/auditoria.php">Auditoria</a>
                </div>
            </div>
            <?php endif; ?>

            <div class="dropdown">
                <button class="topnav-usuario" data-bs-toggle="dropdown" type="button">
                    <span class="topnav-avatar"><?= e(mb_strtoupper(mb_substr(usuarioAtualNome(), 0, 1))) ?></span>
                    <span class="d-none d-md-inline"><?= e(usuarioAtualNome()) ?></span>
                </button>
                <div class="dropdown-menu dropdown-menu-end topnav-dropdown">
                    <a class="dropdown-item" href="/minha_conta.php">Minha conta</a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="/logout.php">Sair</a>
                </div>
            </div>
        </div>
    </div>
</header>

<main class="pagina">
    <div class="pagina-cabecalho">
        <div class="pagina-titulo-area">
            <?php if ($pageBreadcrumb): ?><div class="pagina-breadcrumb"><?= e($pageBreadcrumb) ?></div><?php endif; ?>
            <h1 class="pagina-titulo"><?= e($pageTitle) ?></h1>
        </div>
        <?php if ($pageAction): ?>
            <div class="pagina-acoes"><?= $pageAction ?></div>
        <?php endif; ?>
    </div>

    <?php foreach (getFlashes() as $flash): ?>
        <div class="alert alert-<?= e($flash['tipo']) ?> alert-dismissible fade show" role="alert">
            <?= e($flash['mensagem']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endforeach; ?>
