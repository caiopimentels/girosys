<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

$pdo = getDB();
[$de, $ate, $deSql, $ateSql] = periodoFiltro(date('Y-m-01', strtotime('-2 months')), date('Y-m-d'));
$busca = trim($_GET['busca'] ?? '');

$where = ['o.data_base BETWEEN ? AND ?'];
$params = [$deSql, $ateSql];
if ($busca !== '') {
    $where[] = 'p.nome LIKE ?';
    $params[] = "%{$busca}%";
}

$stmt = $pdo->prepare(
    "SELECT o.*, p.nome AS cliente_nome,
            (SELECT COUNT(*) FROM contas_receber r WHERE r.operacao_id = o.id) AS qtd_titulos,
            (SELECT cp.status FROM contas_pagar cp WHERE cp.operacao_id = o.id ORDER BY cp.id DESC LIMIT 1) AS pagamento_agendado,
            (SELECT cp.data_vencimento FROM contas_pagar cp WHERE cp.operacao_id = o.id ORDER BY cp.id DESC LIMIT 1) AS pagamento_agendado_para,
            (SELECT COUNT(*) FROM contas_receber r WHERE r.operacao_id = o.id AND r.status IN ('pendente', 'devolvido')) AS em_aberto,
            (SELECT COUNT(*) FROM contas_receber r WHERE r.operacao_id = o.id AND (r.status IN ('recebido', 'devolvido') OR r.vezes_devolvido > 0)) AS travados
     FROM operacoes o JOIN pessoas p ON p.id = o.cliente_id
     WHERE " . implode(' AND ', $where) . ' ORDER BY o.data_base DESC, o.id DESC'
);
$stmt->execute($params);
$operacoes = $stmt->fetchAll();

$tot = ['bruto' => 0, 'liquido' => 0, 'lucro' => 0];
foreach ($operacoes as $o) {
    $tot['bruto'] += $o['total_bruto'];
    $tot['liquido'] += $o['total_liquido'];
    $tot['lucro'] += $o['total_juros'] + $o['juros_emprestimo'];
}

$pageTitle = 'Operações realizadas';
$pageBreadcrumb = 'Operações';
$activeMenu = 'operacoes';
$pageAction = '<a href="/operacoes/form.php" class="btn btn-brand"><i class="bi bi-plus-lg"></i> Nova operação</a>';
require __DIR__ . '/../../includes/layout_header.php';
?>

<div class="filtros">
    <form method="get">
        <input type="text" name="busca" class="form-control form-control-sm" placeholder="Cliente" value="<?= e($busca) ?>" style="width: 220px;">
        <?php $dataInicio = $de; $dataFim = $ate; require __DIR__ . '/../../includes/filtro_periodo.php'; ?>
        <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search"></i> Filtrar</button>
    </form>
    <span class="ms-auto text-muted small"><?= count($operacoes) ?> operação(ões)</span>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card h-100"><div class="card-body"><div class="kpi-rotulo">Títulos descontados no período</div><div class="kpi-valor"><?= formatMoney($tot['bruto']) ?></div></div></div></div>
    <div class="col-md-4"><div class="card h-100"><div class="card-body"><div class="kpi-rotulo">Líquido</div><div class="kpi-valor"><?= formatMoney($tot['liquido']) ?></div></div></div></div>
    <div class="col-md-4"><div class="card h-100"><div class="card-body"><div class="kpi-rotulo">Lucro (juros)</div><div class="kpi-valor valor-positivo"><?= formatMoney($tot['lucro']) ?></div></div></div></div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><th>#</th><th>Data base</th><th>Cliente</th><th class="text-end">Taxa</th><th class="text-center">Títulos</th><th class="text-end">Bruto</th><th class="text-end">Líquido</th><th class="text-end">Repasses corrig.</th><th class="text-end">Diferença</th><th class="text-end">Lucro</th><th></th></tr></thead>
            <tbody>
            <?php if (!$operacoes): ?>
                <tr><td colspan="11" class="text-center text-muted py-4">Nenhuma operação no período.</td></tr>
            <?php endif; ?>
            <?php foreach ($operacoes as $o): ?>
                <tr class="cursor-pointer" onclick="window.location='/operacoes/ver.php?id=<?= (int) $o['id'] ?>'">
                    <td><?= (int) $o['id'] ?></td>
                    <td class="text-num"><?= formatDate($o['data_base']) ?></td>
                    <td><?= e($o['cliente_nome']) ?></td>
                    <td class="text-end"><?= formatPercent($o['taxa']) ?></td>
                    <td class="text-center"><?= (int) $o['qtd_titulos'] ?><?php if ($o['em_aberto']): ?> <span class="small text-muted">(<?= (int) $o['em_aberto'] ?> em aberto)</span><?php endif; ?></td>
                    <td class="text-end text-num"><?= formatMoney($o['total_bruto']) ?></td>
                    <td class="text-end text-num"><?= formatMoney($o['total_liquido']) ?></td>
                    <td class="text-end text-num"><?= formatMoney($o['emprestado_atualizado']) ?></td>
                    <td class="text-end text-num <?= $o['saldo'] < 0 ? 'valor-negativo' : '' ?>"><?= formatMoney($o['saldo']) ?>
                        <?php if ($o['saldo'] > 0 && ($o['pagamento_agendado'] === 'pendente' || (!$o['pagamento_agendado'] && !($o['valor_pago_cliente'] > 0) && !($o['desconto_pagamento'] > 0)))): ?>
                            <div><span class="badge text-bg-warning" title="Diferença ainda não paga ao cliente"><?= $o['pagamento_agendado'] ? 'agendado ' . formatDate($o['pagamento_agendado_para']) : 'a pagar' ?></span></div>
                        <?php endif; ?></td>
                    <td class="text-end text-num valor-positivo"><?= formatMoney($o['total_juros'] + $o['juros_emprestimo']) ?></td>
                    <?= acoesLinha('/operacoes/ver.php?id=' . (int) $o['id'] . '&editar=1', $o['travados'] ? null : '/operacoes/ver.php?id=' . (int) $o['id'],
                        'Excluir a operação #' . (int) $o['id'] . '? Os títulos saem de Contas a receber e os repasses voltam a ficar em aberto.', false,
                        'Tem título recebido ou devolvido: estorne esses títulos antes de excluir') ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
