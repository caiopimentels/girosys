<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

$pdo = getDB();

// Lançamento manual (tarifa, rendimento, aporte...) e exclusão de lançamento manual/transferência.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $voltar = '/contas/extrato.php?' . http_build_query(array_filter(['conta' => $_POST['voltar_conta'] ?? '', 'de' => $_POST['voltar_de'] ?? '', 'ate' => $_POST['voltar_ate'] ?? '']));
    if (($_POST['acao'] ?? '') === 'excluir') {
        $stmt = $pdo->prepare("SELECT * FROM movimentacoes WHERE id = ? AND origem IN ('ajuste', 'transferencia')");
        $stmt->execute([(int) $_POST['id']]);
        if ($mov = $stmt->fetch()) {
            removerMovimentacoes($pdo, $mov['origem'], (int) $mov['origem_id']);
            $nomeConta = $pdo->prepare('SELECT nome FROM contas_bancarias WHERE id = ?');
            $nomeConta->execute([$mov['conta_id']]);
            registrarAtividade('contas_bancarias', 'exclusao', ($mov['origem'] === 'transferencia' ? 'Transferência excluída' : 'Lançamento manual excluído') . ' (' . $nomeConta->fetchColumn() . '): '
                . $mov['descricao'] . ' · ' . ($mov['tipo'] === 'entrada' ? '+' : '-') . formatMoney($mov['valor']) . ' em ' . formatDate($mov['data']));
            flash('success', $mov['origem'] === 'transferencia' ? 'Transferência excluída (as duas pontas).' : 'Lançamento excluído.');
        }
        redirect($voltar);
    }

    $contaId = (int) ($_POST['conta_id'] ?? 0);
    $data = dataInput('data');
    $valor = decimalInput('valor');
    $tipo = ($_POST['tipo'] ?? '') === 'entrada' ? 'entrada' : 'saida';
    $descricao = input('descricao');
    if (!$contaId || !$data || $valor <= 0 || $descricao === '') {
        flash('danger', 'Para lançar, informe conta, data, valor e descrição.');
    } else {
        $movId = lancarMovimentacao($pdo, $contaId, $data, $tipo, $valor, $descricao, 'ajuste');
        $pdo->prepare('UPDATE movimentacoes SET origem_id = id WHERE id = ?')->execute([$movId]);
        $nomeConta = $pdo->prepare('SELECT nome FROM contas_bancarias WHERE id = ?');
        $nomeConta->execute([$contaId]);
        registrarAtividade('contas_bancarias', 'criacao', 'Lançamento manual no ' . $nomeConta->fetchColumn() . ': ' . $descricao . ' · ' . ($tipo === 'entrada' ? '+' : '-') . formatMoney($valor) . ' em ' . formatDate($data));
        flash('success', 'Lançamento registrado.');
    }
    redirect($voltar);
}

$contaId = isset($_GET['conta']) && $_GET['conta'] !== '' ? (int) $_GET['conta'] : null;
[$de, $ate, $deSql, $ateSql] = periodoFiltro(date('Y-m-01'), date('Y-m-t'));

$contas = contasComSaldo($pdo, false);
$contasPorId = array_column($contas, null, 'id');

// Saldo antes do período: saldo inicial (se começou antes) + movimentos anteriores.
$filtroConta = $contaId ? 'AND conta_id = ' . $contaId : '';
$saldoInicialContas = 0.0;
foreach ($contas as $c) {
    if ((!$contaId && $c['ativo']) || (int) $c['id'] === $contaId) {
        $saldoInicialContas += (float) $c['saldo_inicial'];
    }
}
$filtroAtivas = $contaId ? '' : 'AND conta_id IN (SELECT id FROM contas_bancarias WHERE ativo = 1)';
$stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(CASE WHEN tipo = 'entrada' THEN valor ELSE -valor END), 0) FROM movimentacoes WHERE data < ? {$filtroConta} {$filtroAtivas}"
);
$stmt->execute([$deSql]);
$saldoAnterior = $saldoInicialContas + (float) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT m.*, c.nome AS conta_nome, c.cor AS conta_cor FROM movimentacoes m JOIN contas_bancarias c ON c.id = m.conta_id
     WHERE m.data BETWEEN ? AND ? {$filtroConta} " . ($contaId ? '' : 'AND c.ativo = 1') . '
     ORDER BY m.data, m.id'
);
$stmt->execute([$deSql, $ateSql]);
$movimentos = $stmt->fetchAll();

$entradas = array_sum(array_map(fn ($m) => $m['tipo'] === 'entrada' ? (float) $m['valor'] : 0, $movimentos));
$saidas = array_sum(array_map(fn ($m) => $m['tipo'] === 'saida' ? (float) $m['valor'] : 0, $movimentos));

$linkOrigem = [
    'receber' => fn ($id) => '/receber/baixar.php?id=' . $id,
    'pagar' => fn ($id) => '/pagar/baixar.php?id=' . $id,
    'operacao' => fn ($id) => '/operacoes/ver.php?id=' . $id,
    'adiantamento' => fn ($id) => '/adiantamentos/listar.php#a' . $id,
    'fatura' => fn ($id) => '/cartoes/fatura.php?id=' . $id,
];

$pageTitle = $contaId ? 'Extrato · ' . ($contasPorId[$contaId]['nome'] ?? '') : 'Extrato · todas as contas';
$pageBreadcrumb = 'Contas bancárias';
$activeMenu = 'extrato';
$pageAction = '<button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Imprimir</button>'
    . '<button type="button" class="btn btn-brand" data-bs-toggle="modal" data-bs-target="#modal-lancamento"><i class="bi bi-plus-lg"></i> Lançamento manual</button>';
require __DIR__ . '/../../includes/layout_header.php';
?>

<div class="filtros nao-imprimir">
    <form method="get">
        <select name="conta" class="form-select form-select-sm" style="width: 220px;">
            <option value="">Todas as contas ativas</option>
            <?php foreach ($contas as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $contaId ? 'selected' : '' ?>><?= e($c['nome']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php $dataInicio = $de; $dataFim = $ate; require __DIR__ . '/../../includes/filtro_periodo.php'; ?>
        <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search"></i> Ver</button>
    </form>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="kpi-rotulo"><?= $de ? 'Saldo em ' . formatDate(date('Y-m-d', strtotime($de . ' -1 day'))) : 'Saldo inicial' ?></div><div class="kpi-valor"><?= formatMoney($saldoAnterior) ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="kpi-rotulo">Entradas</div><div class="kpi-valor valor-positivo"><?= formatMoney($entradas) ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="kpi-rotulo">Saídas</div><div class="kpi-valor valor-negativo"><?= formatMoney($saidas) ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="kpi-rotulo">Saldo em <?= $ate ? formatDate($ate) : 'hoje' ?></div><div class="kpi-valor"><?= formatMoney($saldoAnterior + $entradas - $saidas) ?></div></div></div></div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><th>Data</th><?php if (!$contaId): ?><th>Conta</th><?php endif; ?><th>Descrição</th><th class="text-end">Entrada</th><th class="text-end">Saída</th><th class="text-end">Saldo</th><th class="nao-imprimir"></th></tr></thead>
            <tbody>
            <?php if (!$movimentos): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">Nenhum lançamento no período.</td></tr>
            <?php endif; ?>
            <?php $saldo = $saldoAnterior; foreach ($movimentos as $m): $saldo += $m['tipo'] === 'entrada' ? $m['valor'] : -$m['valor']; ?>
                <tr>
                    <td class="text-num"><?= formatDate($m['data']) ?></td>
                    <?php if (!$contaId): ?><td><span class="ponto-status" style="background: <?= e($m['conta_cor']) ?>"></span> <?= e($m['conta_nome']) ?></td><?php endif; ?>
                    <td>
                        <?php if (isset($linkOrigem[$m['origem']]) && $m['origem_id']): ?>
                            <a href="<?= e($linkOrigem[$m['origem']]((int) $m['origem_id'])) ?>" class="text-reset"><?= e($m['descricao']) ?></a>
                        <?php else: ?>
                            <?= e($m['descricao']) ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-end text-num valor-positivo"><?= $m['tipo'] === 'entrada' ? formatMoney($m['valor']) : '' ?></td>
                    <td class="text-end text-num valor-negativo"><?= $m['tipo'] === 'saida' ? formatMoney($m['valor']) : '' ?></td>
                    <td class="text-end text-num <?= $saldo < 0 ? 'valor-negativo' : '' ?>"><?= formatMoney($saldo) ?></td>
                    <td class="text-end nao-imprimir">
                        <?php if (in_array($m['origem'], ['ajuste', 'transferencia'], true)): ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('Excluir este lançamento<?= $m['origem'] === 'transferencia' ? ' (sai das duas contas)' : '' ?>?');">
                                <input type="hidden" name="acao" value="excluir"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                <input type="hidden" name="voltar_conta" value="<?= (int) $contaId ?>"><input type="hidden" name="voltar_de" value="<?= e($de) ?>"><input type="hidden" name="voltar_ate" value="<?= e($ate) ?>">
                                <button class="btn btn-sm btn-outline-danger" title="Excluir"><i class="bi bi-trash"></i></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="modal-lancamento" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Lançamento manual</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <p class="small text-muted">Para tarifa, rendimento, aporte, retirada... Despesas do dia a dia ficam melhor em Contas a pagar.</p>
                <input type="hidden" name="voltar_conta" value="<?= (int) $contaId ?>"><input type="hidden" name="voltar_de" value="<?= e($de) ?>"><input type="hidden" name="voltar_ate" value="<?= e($ate) ?>">
                <div class="row g-3">
                    <div class="col-6"><label class="form-label">Conta</label><select name="conta_id" class="form-select" required><?= opcoesContas($pdo, $contaId) ?></select></div>
                    <div class="col-6"><label class="form-label">Tipo</label><select name="tipo" class="form-select"><option value="entrada">Entrada</option><option value="saida">Saída</option></select></div>
                    <div class="col-6"><label class="form-label">Data</label><input type="date" name="data" class="form-control" required value="<?= date('Y-m-d') ?>"></div>
                    <div class="col-6"><label class="form-label">Valor (R$)</label><input type="text" name="valor" class="form-control" data-moeda inputmode="decimal" required></div>
                    <div class="col-12"><label class="form-label">Descrição</label><input type="text" name="descricao" class="form-control" required maxlength="200"></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-brand">Lançar</button></div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
