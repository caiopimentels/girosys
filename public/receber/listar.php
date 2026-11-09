<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

$pdo = getDB();
$situacao = $_GET['situacao'] ?? 'abertos';
$busca = trim($_GET['busca'] ?? '');
$contaId = idOuNull($_GET['conta'] ?? null);
$tipo = $_GET['tipo'] ?? '';
$boleto = in_array($_GET['boleto'] ?? '', ['a_registrar', 'registrados'], true) ? $_GET['boleto'] : '';
[$de, $ate] = periodoFiltro();

$where = ['1=1'];
$params = [];
match ($situacao) {
    'abertos' => $where[] = "r.status IN ('pendente', 'devolvido')",
    'vencidos' => $where[] = "r.status = 'pendente' AND r.data_vencimento < CURDATE()",
    'devolvidos' => $where[] = "r.status = 'devolvido'",
    'recebidos' => $where[] = "r.status = 'recebido'",
    'cancelados' => $where[] = "r.status = 'cancelado'",
    'transferidos' => $where[] = "r.status = 'transferido'",
    'renegociados' => $where[] = "r.status = 'renegociado'",
    default => null,
};
if ($busca !== '') {
    $where[] = '(e.nome LIKE ? OR cl.nome LIKE ? OR r.numero_titulo LIKE ? OR r.boleto_nosso_numero LIKE ? OR r.descricao LIKE ?)';
    array_push($params, "%{$busca}%", "%{$busca}%", "%{$busca}%", "%{$busca}%", "%{$busca}%");
}
if ($contaId) {
    $where[] = 'COALESCE(r.conta_recebimento_id, r.conta_destino_id) = ?';
    $params[] = $contaId;
}
if ($tipo !== '' && array_key_exists($tipo, tiposTitulo(true))) {
    $where[] = 'r.tipo = ?';
    $params[] = $tipo;
}
if ($boleto === 'a_registrar') {
    $where[] = sqlBoletoARegistrar($pdo);
} elseif ($boleto === 'registrados') {
    $where[] = "(r.boleto_nosso_numero IS NOT NULL OR (r.boleto_codigo IS NOT NULL AND r.boleto_situacao NOT IN ('CANCELADO', 'EXPIRADO')))";
}
// Imprimir / planilha só dos marcados na tela (ids[]); sem marcação, tudo o que está filtrado.
$idsMarcados = array_values(array_unique(array_filter(array_map('intval', (array) ($_GET['ids'] ?? [])))));
if ($idsMarcados && (isset($_GET['imprimir']) || isset($_GET['csv']))) {
    $where[] = 'r.id IN (' . implode(',', $idsMarcados) . ')';
}
$campoData = $situacao === 'recebidos' ? 'r.data_recebimento' : 'r.data_vencimento';
if ($de) {
    $where[] = "{$campoData} >= ?";
    $params[] = $de;
}
if ($ate) {
    $where[] = "{$campoData} <= ?";
    $params[] = $ate;
}

$stmt = $pdo->prepare(
    'SELECT r.*, e.nome AS emitente_nome, e.documento AS emitente_documento, cl.nome AS cliente_nome, cl.taxa_padrao AS cliente_taxa, c.nome AS conta_nome
     FROM contas_receber r
     LEFT JOIN pessoas e ON e.id = r.emitente_id
     LEFT JOIN pessoas cl ON cl.id = r.cliente_id
     LEFT JOIN contas_bancarias c ON c.id = COALESCE(r.conta_recebimento_id, r.conta_destino_id)
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY ' . ($situacao === 'recebidos' ? 'r.data_recebimento DESC' : 'r.data_vencimento ASC') . ', r.id LIMIT 1000'
);
$stmt->execute($params);
$titulos = $stmt->fetchAll();
$total = array_sum(array_map(fn ($t) => (float) ($t['status'] === 'recebido' ? $t['valor_recebido'] : $t['valor']), $titulos));
// Vencidos: valor atualizado até hoje (juros/multa pela regra do tipo — Cadastros › Tipos de recebimento).
$atualizacoes = [];
foreach ($titulos as $t) {
    $atualizacoes[$t['id']] = atualizacaoReceber($pdo, $t);
}
$totalAtualizado = array_sum(array_map(fn ($t) => $t['status'] === 'recebido' ? (float) $t['valor_recebido'] : $atualizacoes[$t['id']]['total'], $titulos));

// Classificação de cada emitente (uma consulta por emitente distinto).
$classificacoes = [];
foreach (array_unique(array_filter(array_column($titulos, 'emitente_id'))) as $eid) {
    $classificacoes[$eid] = historicoEmitente($pdo, (int) $eid)['classificacao'];
}

$vencidos = array_filter($titulos, fn ($t) => statusTelaReceber($t) === 'vencido' || $t['status'] === 'devolvido');
$informacoes = [
    ['Títulos na página', count($titulos), ''],
    ['Total da página', formatMoney($total), ''],
    ['Atualizado hoje (com juros/multa)', formatMoney($totalAtualizado), $totalAtualizado > $total + 0.004 ? 'valor-negativo' : ''],
    ['Vencidos / devolvidos', count($vencidos) . ' · ' . formatMoney(array_sum(array_map(fn ($t) => $atualizacoes[$t['id']]['total'], $vencidos))), $vencidos ? 'valor-negativo' : ''],
];
$somasSelecao = ['valor' => 'Valor', 'atualizado' => 'Atualizado hoje'];
$acoesLaterais = [['Receber selecionados', 'bi-check2-all', 'abrirBaixaLote()'], ['Renegociar selecionados', 'bi-arrow-left-right', 'renegociarSelecionados()']];
// Boleto: só para quem pode emitir e se algum tipo registra boleto (Cadastros › Tipos de recebimento).
$podeBoleto = usuarioTemPermissao('emitir_boleto') && tiposComBoleto($pdo);
if ($podeBoleto) {
    $acoesLaterais[] = ['Registrar boleto dos selecionados', 'bi-upc-scan', 'abrirRegistroBoletos()'];
}
$contasBoleto = $podeBoleto ? contasInter($pdo) : [];

// Planilha (CSV para o Excel) com tudo o que está filtrado na tela.
if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="contas-a-receber-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // acentos certos no Excel
    // ID e Nosso número permitem devolver a planilha preenchida em
    // /receber/atualizar_planilha.php. O nosso número vai como ="123" para o
    // Excel não virar notação científica nem comer zeros à esquerda.
    fputcsv($out, ['ID', 'Vencimento', 'Tipo', 'Pagador', 'CPF/CNPJ pagador', 'Cliente', 'Nº', 'Nosso número', 'Descrição', 'Valor', 'Atualizado hoje', 'Dias de atraso',
        'Conta', 'Situação', 'Recebido em', 'Valor recebido', 'Operação'], ';', '"', '');
    foreach ($titulos as $t) {
        $at = $atualizacoes[$t['id']];
        fputcsv($out, [
            $t['id'], formatDate($t['data_vencimento']), tipoTituloLabel($t['tipo']), $t['emitente_nome'], documentoFormatado($t['emitente_documento']), $t['cliente_nome'],
            $t['numero_titulo'], $t['boleto_nosso_numero'] ? '="' . $t['boleto_nosso_numero'] . '"' : '', $t['descricao'], formatDecimal($t['valor']), formatDecimal($t['status'] === 'recebido' ? $t['valor_recebido'] : $at['total']),
            $at['dias'] ?: '', $t['conta_nome'], ['vencido' => 'Vencido', 'pendente' => 'Pendente', 'recebido' => 'Recebido', 'devolvido' => 'Devolvido',
                'cancelado' => 'Cancelado', 'renegociado' => 'Renegociado', 'transferido' => 'Devolvido ao cliente'][statusTelaReceber($t)] ?? $t['status'],
            formatDate($t['data_recebimento']) === '-' ? '' : formatDate($t['data_recebimento']), $t['valor_recebido'] !== null ? formatDecimal($t['valor_recebido']) : '',
            $t['operacao_id'] ? '#' . $t['operacao_id'] : '',
        ], ';', '"', '');
    }
    exit;
}

// Relatório para imprimir / salvar em PDF, com os mesmos filtros da tela.
if (isset($_GET['imprimir'])) {
    $situacoesRel = ['abertos' => 'Em aberto', 'vencidos' => 'Vencidos', 'devolvidos' => 'Devolvidos', 'recebidos' => 'Recebidos', 'transferidos' => 'Devolvidos ao cliente', 'renegociados' => 'Renegociados', 'cancelados' => 'Cancelados', 'todos' => 'Todos'];
    $filtrosRel = array_filter([
        $idsMarcados ? 'Só os ' . count($idsMarcados) . ' título(s) selecionado(s)' : null,
        'Situação: ' . ($situacoesRel[$situacao] ?? $situacao),
        $tipo ? 'Tipo: ' . tipoTituloLabel($tipo) : null,
        $contaId ? 'Conta: ' . ($pdo->query('SELECT nome FROM contas_bancarias WHERE id = ' . (int) $contaId)->fetchColumn()) : null,
        $busca !== '' ? 'Busca: ' . $busca : null,
        $de || $ate ? ($situacao === 'recebidos' ? 'Recebido' : 'Vencimento') . ': ' . ($de ? formatDate($de) : 'início') . ' a ' . ($ate ? formatDate($ate) : 'fim') : null,
    ]);
    ?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <title>Contas a receber · <?= date('d/m/Y') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?= e(assetUrl('/assets/css/app.css')) ?>" rel="stylesheet">
    <link href="<?= e(assetUrl('/assets/css/sistema.css')) ?>" rel="stylesheet">
    <style>
        body { background: #fff; font-size: .78rem; }
        .folha { padding: 1rem 1.5rem; }
        table td, table th { padding: .25rem .4rem !important; }
        @media print { .nao-imprimir { display: none !important; } .folha { padding: 0; } @page { size: landscape; margin: 10mm; } }
    </style>
</head>
<body>
<div class="folha">
    <div class="d-flex justify-content-between align-items-start border-bottom pb-2 mb-2">
        <div><div class="fw-bold fs-5"><?= e(nomeSistema()) ?></div><div class="text-muted">Relatório de contas a receber</div></div>
        <div class="text-end small text-muted"><?= e(implode(' · ', $filtrosRel)) ?><br>Emitido em <?= date('d/m/Y H:i') ?></div>
    </div>
    <table class="table table-sm align-middle">
        <thead><tr><th>Vencimento</th><th>Tipo</th><th>Pagador</th><th>Cliente</th><th>Nº</th><th>Conta</th><th class="text-end">Valor</th><th class="text-end">Atualizado hoje</th><th>Situação</th></tr></thead>
        <tbody>
        <?php foreach ($titulos as $t): $at = $atualizacoes[$t['id']]; $st = statusTelaReceber($t); ?>
            <tr>
                <td class="text-num <?= $st === 'vencido' ? 'valor-negativo' : '' ?>"><?= formatDate($t['data_vencimento']) ?></td>
                <td><?= e(tipoTituloLabel($t['tipo'])) ?></td>
                <td><?= e($t['emitente_nome'] ?? $t['descricao'] ?? '-') ?></td>
                <td><?= e($t['cliente_nome'] ?? '-') ?></td>
                <td><?= e($t['numero_titulo']) ?: '-' ?></td>
                <td><?= e($t['conta_nome'] ?? '—') ?></td>
                <td class="text-end text-num"><?= formatMoney($t['status'] === 'recebido' ? $t['valor_recebido'] : $t['valor']) ?></td>
                <td class="text-end text-num <?= $at['total'] > $t['valor'] + 0.004 ? 'valor-negativo' : '' ?>"><?= $t['status'] === 'recebido' ? '-' : formatMoney($at['total']) ?></td>
                <td><?= $st === 'vencido' || $st === 'devolvido' ? ($st === 'vencido' ? 'Vencido' : 'Devolvido') . ($at['dias'] ? ' há ' . $at['dias'] . ' dia' . ($at['dias'] > 1 ? 's' : '') : '')
                    : (['pendente' => 'Pendente', 'recebido' => 'Recebido ' . formatDate($t['data_recebimento']), 'cancelado' => 'Cancelado', 'renegociado' => 'Renegociado', 'transferido' => 'Devolvido ao cliente'][$st] ?? $st) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$titulos): ?><tr><td colspan="9" class="text-center text-muted py-3">Nada com esses filtros.</td></tr><?php endif; ?>
        </tbody>
        <tfoot><tr class="fw-semibold"><td colspan="6"><?= count($titulos) ?> título(s)</td><td class="text-end text-num"><?= formatMoney($total) ?></td><td class="text-end text-num"><?= formatMoney($totalAtualizado) ?></td><td></td></tr></tfoot>
    </table>
    <div class="text-center mt-3 nao-imprimir"><button class="btn btn-brand" onclick="window.print()">Imprimir / salvar PDF</button></div>
</div>
<script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
</body>
</html>
    <?php
    exit;
}

$pageTitle = 'Contas a receber';
$pageBreadcrumb = 'Financeiro';
$activeMenu = 'receber';
$pageAction = '<a href="?' . e(http_build_query(array_merge($_GET, ['imprimir' => 1]))) . '" target="_blank" class="btn btn-outline-secondary" data-com-selecao title="Com títulos marcados, imprime só eles"><i class="bi bi-printer"></i> Imprimir</a>'
    . '<a href="?' . e(http_build_query(array_merge($_GET, ['csv' => 1]))) . '" class="btn btn-outline-secondary" data-com-selecao title="Com títulos marcados, só eles vão para a planilha"><i class="bi bi-filetype-csv"></i> Planilha</a>'
    . '<a href="/receber/atualizar_planilha.php" class="btn btn-outline-secondary" title="Baixe a Planilha, preencha o nosso número e envie de volta"><i class="bi bi-upload"></i> Atualizar pela planilha</a>'
    . (usuarioTemPermissao('baixar_receber') ? '<a href="/receber/importar_banco.php" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-spreadsheet"></i> Importar planilha do banco</a>' : '')
    . '<a href="/receber/form.php" class="btn btn-outline-secondary"><i class="bi bi-plus-lg"></i> Lançamento avulso</a>'
    . '<a href="/operacoes/form.php" class="btn btn-brand"><i class="bi bi-calculator"></i> Nova operação</a>';
require __DIR__ . '/../../includes/layout_header.php';
?>

<div class="pedidos-layout"><div class="pedidos-conteudo">
<div class="filtros">
    <form method="get">
        <select name="situacao" class="form-select form-select-sm" style="width: 150px;">
            <?php foreach (['abertos' => 'Em aberto', 'vencidos' => 'Vencidos', 'devolvidos' => 'Devolvidos', 'recebidos' => 'Recebidos', 'transferidos' => 'Devolvidos ao cliente', 'renegociados' => 'Renegociados', 'cancelados' => 'Cancelados', 'todos' => 'Todos'] as $k => $v): ?>
                <option value="<?= $k ?>" <?= $situacao === $k ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
        </select>
        <select name="tipo" class="form-select form-select-sm" style="width: 150px;">
            <option value="">Todos os tipos</option>
            <?php foreach (tiposTitulo(true) as $k => $v): ?><option value="<?= $k ?>" <?= $tipo === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
        </select>
        <select name="conta" class="form-select form-select-sm" style="width: 170px;"><?= opcoesContas($pdo, $contaId, 'Todas as contas') ?></select>
        <select name="boleto" class="form-select form-select-sm" style="width: 160px;" title="Boleto no banco">
            <?php foreach (['' => 'Boleto: todos', 'a_registrar' => 'Boleto a registrar', 'registrados' => 'Boleto registrado'] as $k => $v): ?><option value="<?= $k ?>" <?= $boleto === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
        </select>
        <input type="text" name="busca" class="form-control form-control-sm" placeholder="Pagador, cliente ou nº" value="<?= e($busca) ?>" style="width: 200px;">
        <?php $dataInicio = $de; $dataFim = $ate; $periodoRotulo = $situacao === 'recebidos' ? 'Recebido' : 'Vencimento'; require __DIR__ . '/../../includes/filtro_periodo.php'; ?>
        <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search"></i> Filtrar</button>
    </form>
    <span class="ms-auto text-muted small"><?= count($titulos) ?> título(s) · <strong><?= formatMoney($total) ?></strong><?php if ($totalAtualizado > $total + 0.004): ?> · atualizado hoje <strong class="valor-negativo"><?= formatMoney($totalAtualizado) ?></strong><?php endif; ?></span>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><th style="width: 32px;"><input type="checkbox" class="form-check-input selecao-todos" title="Marcar todos"></th><th>Vencimento</th><th>Tipo<div class="small text-muted fw-normal">Conta</div></th><th>Pagador<div class="small text-muted fw-normal">Cliente</div></th><th>Nº</th><th class="text-end">Valor<div class="small text-muted fw-normal">Atualizado hoje</div></th><th>Situação</th><th></th></tr></thead>
            <tbody>
            <?php if (!$titulos): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">Nada por aqui.</td></tr>
            <?php endif; ?>
            <?php foreach ($titulos as $t): ?>
                <?php $podeExcluir = in_array($t['status'], ['pendente', 'devolvido', 'cancelado'], true) && (!$t['vezes_devolvido'] || $t['status'] === 'cancelado'); ?>
                <tr class="cursor-pointer" data-detalhe="/receber/baixar.php?id=<?= (int) $t['id'] ?>">
                    <td><input type="checkbox" class="form-check-input selecao-item" value="<?= (int) $t['id'] ?>"
                        data-valor="<?= e((string) ($t['status'] === 'recebido' ? $t['valor_recebido'] : $t['valor'])) ?>"
                        data-atualizado="<?= e((string) ($t['status'] === 'recebido' ? $t['valor_recebido'] : $atualizacoes[$t['id']]['total'])) ?>"
                        data-aberto="<?= in_array($t['status'], ['pendente', 'devolvido'], true) ? 1 : 0 ?>"
                        data-boleto="<?= $podeBoleto && !motivoNaoRegistrarBoleto($pdo, $t) ? 1 : 0 ?>"></td>
                    <td class="text-num <?= statusTelaReceber($t) === 'vencido' ? 'valor-negativo fw-semibold' : '' ?>"><?= formatDate($t['data_vencimento']) ?></td>
                    <?php $at = $atualizacoes[$t['id']]; $pagador = $t['emitente_nome'] ?? $t['descricao'] ?? '-'; ?>
                    <td><?= e(tipoTituloLabel($t['tipo'])) ?><?php if ($t['boleto_codigo'] && !in_array($t['boleto_situacao'], ['CANCELADO', 'EXPIRADO'], true)): ?> <i class="bi bi-upc-scan text-muted" title="Boleto Inter: <?= e(rotuloSituacaoBoleto($t['boleto_situacao'])) ?>"></i><?php endif; ?><div class="small text-muted text-truncate" style="max-width: 150px;"><?= e($t['conta_nome'] ?? '—') ?></div></td>
                    <td>
                        <div class="d-flex align-items-center gap-1">
                            <span class="text-truncate" style="max-width: 300px;" title="<?= e($pagador) ?>"><?= e($pagador) ?></span>
                            <?php if (isset($classificacoes[$t['emitente_id']]) && $classificacoes[$t['emitente_id']] !== 'bom' && $classificacoes[$t['emitente_id']] !== 'sem_historico'): ?>
                                <?= badgeClassificacao($classificacoes[$t['emitente_id']]) ?>
                            <?php endif; ?>
                        </div>
                        <div class="small text-muted text-truncate" style="max-width: 300px;"><?= $t['cliente_nome'] ? e($t['cliente_nome']) : '-' ?></div>
                    </td>
                    <td><?= e($t['numero_titulo']) ?: ($t['boleto_nosso_numero'] ? '' : '-') ?><?php if ($t['boleto_nosso_numero'] && $t['boleto_nosso_numero'] !== $t['numero_titulo']): ?><div class="small text-muted" title="Nosso número do boleto">nosso nº <?= e($t['boleto_nosso_numero']) ?></div><?php endif; ?></td>
                    <td class="text-end text-num">
                        <?= formatMoney($t['status'] === 'recebido' ? $t['valor_recebido'] : $t['valor']) ?>
                        <?php if ($at['dias'] > 0 && $at['total'] > $t['valor']): ?><div class="small valor-negativo fw-semibold"><?= formatMoney($at['total']) ?></div><?php endif; ?>
                    </td>
                    <td><?= badgeStatusAtraso(statusTelaReceber($t), $at['dias']) ?><?= $t['status'] === 'recebido' ? ' <span class="small text-muted">' . formatDate($t['data_recebimento']) . '</span>' : '' ?></td>
                    <?= acoesLinha('/receber/baixar.php?id=' . (int) $t['id'] . '&editar=1', $podeExcluir ? '/receber/baixar.php?id=' . (int) $t['id'] : null,
                        'Excluir este título?' . ($t['operacao_id'] ? ' Os totais da operação serão recalculados.' : ''), true,
                        match ($t['status']) {
                            'recebido' => 'Já recebido: estorne o recebimento antes de excluir',
                            'transferido' => 'Devolvido ao cliente: desfaça a devolução antes',
                            'renegociado' => 'Renegociado: desfaça a renegociação antes',
                            default => 'Já voltou devolvido: fica no histórico do pagador',
                        },
                        ($podeBoleto && !motivoNaoRegistrarBoleto($pdo, $t)
                            ? '<button type="button" class="btn btn-sm btn-outline-primary" title="Registrar boleto no banco" onclick="registrarBoletos([' . (int) $t['id'] . '])"><i class="bi bi-upc-scan"></i></button> '
                            : '')
                        . (in_array($t['status'], ['pendente', 'devolvido'], true)
                            ? '<button type="button" class="btn btn-sm btn-outline-success" title="Receber" onclick="abrirDetalhe(\'/receber/baixar.php?id=' . (int) $t['id'] . '\')"><i class="bi bi-check2-circle"></i></button> '
                            : '')) ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

</div><!-- /pedidos-conteudo -->
<?php require __DIR__ . '/../../includes/painel_lateral.php'; ?>
</div><!-- /pedidos-layout -->

<div class="modal fade" id="modal-lote" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" action="/receber/baixar_lote.php" class="modal-content" id="form-lote">
            <div class="modal-header"><h5 class="modal-title">Receber selecionados</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="alert alert-light border small" id="lote-resumo"></div>
                <input type="hidden" name="voltar" value="<?= e($_SERVER['REQUEST_URI'] ?? '/receber/listar.php') ?>">
                <div class="row g-3">
                    <div class="col-6"><label class="form-label">Data do recebimento</label><input type="date" name="data" class="form-control" required value="<?= date('Y-m-d') ?>"></div>
                    <div class="col-6"><label class="form-label">Entrou na conta</label><select name="conta_id" class="form-select"><?= opcoesContas($pdo, null, 'A conta de cada título') ?></select></div>
                    <div class="col-12">
                        <label class="form-check"><input type="checkbox" class="form-check-input" name="atualizado" value="1" checked> Receber o valor atualizado até a data (juros/multa dos vencidos)</label>
                        <div class="form-text">Desmarcado, recebe o valor original de cada título. Para negociar um valor diferente, receba o título sozinho.</div>
                    </div>
                </div>
                <div id="lote-ids"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-brand"><i class="bi bi-check2-all"></i> Receber todos</button></div>
        </form>
    </div>
</div>
<form method="post" action="/receber/registrar_boletos.php" id="form-boletos" class="d-none">
    <input type="hidden" name="voltar" value="<?= e($_SERVER['REQUEST_URI'] ?? '/receber/listar.php') ?>">
    <input type="hidden" name="conta_id" id="boletos-conta">
    <div id="boletos-ids"></div>
</form>
<div class="modal fade" id="modal-boletos" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Registrar boleto no banco</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="alert alert-light border small" id="boletos-resumo"></div>
                <?php if (!$contasBoleto): ?>
                    <div class="alert alert-warning small mb-0">Nenhuma conta do Inter está pronta para emitir boleto. Configure em Configurações › Contas bancárias › API do banco.</div>
                <?php else: ?>
                    <label class="form-label">Registrar na conta</label>
                    <select class="form-select" id="boletos-conta-escolha">
                        <option value=""><?= count($contasBoleto) === 1 ? e($contasBoleto[0]['nome']) : 'A conta de cada título' ?></option>
                        <?php if (count($contasBoleto) > 1): foreach ($contasBoleto as $cb): ?><option value="<?= (int) $cb['id'] ?>"><?= e($cb['nome']) ?></option><?php endforeach; endif; ?>
                    </select>
                    <div class="form-text">Os que já têm boleto (ou nosso número digitado) e os de tipo que não registra boleto, como cheque, ficam como estão. Multa e juros do boleto saem da regra do tipo.</div>
                <?php endif; ?>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <?php if ($contasBoleto): ?><button type="button" class="btn btn-brand" id="boletos-confirmar"><i class="bi bi-upc-scan"></i> Registrar</button><?php endif; ?></div>
        </div>
    </div>
</div>
<script>
// Imprimir / Planilha: com títulos marcados, leva só eles (ids[] na URL).
document.querySelectorAll('[data-com-selecao]').forEach((link) => link.addEventListener('click', () => {
    const url = new URL(link.href, location.href);
    url.searchParams.delete('ids[]');
    document.querySelectorAll('.selecao-item:checked').forEach((c) => url.searchParams.append('ids[]', c.value));
    link.href = url.toString();
}));
// Registrar boleto: um (botão da linha) ou os marcados que precisam de boleto.
function registrarBoletos(ids) {
    const linhas = ids.length === 1 ? 'o boleto deste título' : ids.length + ' boleto(s)';
    document.getElementById('boletos-resumo').innerHTML = 'Registrar <strong>' + linhas + '</strong> no banco. O pagador recebe o boleto com Pix.';
    document.getElementById('boletos-ids').innerHTML = ids.map((id) => '<input type="hidden" name="ids[]" value="' + id + '">').join('');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-boletos')).show();
}
function abrirRegistroBoletos() {
    const marcados = [...document.querySelectorAll('.selecao-item:checked')];
    const validos = marcados.filter((c) => c.dataset.boleto === '1').map((c) => c.value);
    if (!validos.length) { alert(marcados.length ? 'Nenhum dos marcados precisa de boleto (já têm boleto/nosso número, não estão em aberto ou o tipo não registra boleto).' : 'Marque na lista os títulos para registrar o boleto. Dica: filtre por "Boleto a registrar".'); return; }
    if (validos.length > 50) { alert('Marque no máximo 50 por vez.'); return; }
    registrarBoletos(validos);
}
document.getElementById('boletos-confirmar')?.addEventListener('click', (ev) => {
    ev.target.disabled = true;
    ev.target.innerHTML = 'Registrando...';
    document.getElementById('boletos-conta').value = document.getElementById('boletos-conta-escolha')?.value || '';
    document.getElementById('form-boletos').submit();
});
// Baixa em lote: só os marcados que ainda estão em aberto (pendentes/devolvidos).
function abrirBaixaLote() {
    const marcados = [...document.querySelectorAll('.selecao-item:checked')].filter((c) => c.dataset.aberto === '1');
    if (!marcados.length) { alert('Marque na lista os títulos em aberto que quer receber.'); return; }
    const total = marcados.reduce((s, c) => s + parseFloat(c.dataset.atualizado), 0);
    document.getElementById('lote-resumo').innerHTML = '<strong>' + marcados.length + ' título(s)</strong> · ' + total.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }) + ' atualizado até hoje';
    document.getElementById('lote-ids').innerHTML = marcados.map((c) => '<input type="hidden" name="ids[]" value="' + c.value + '">').join('');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-lote')).show();
}
// Renegociar: abre a tela com os marcados em aberto (um pagador por vez).
function renegociarSelecionados() {
    const marcados = [...document.querySelectorAll('.selecao-item:checked')].filter((c) => c.dataset.aberto === '1');
    if (!marcados.length) { alert('Marque na lista os títulos em aberto que quer renegociar.'); return; }
    location.href = '/receber/renegociar.php?' + marcados.map((c) => 'ids[]=' + encodeURIComponent(c.value)).join('&');
}
</script>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
