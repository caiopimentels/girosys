<?php
// Contas a pagar: despesas lançadas + faturas dos cartões, numa lista só.
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

$pdo = getDB();
$situacao = $_GET['situacao'] ?? 'pendentes';
$tipo = $_GET['tipo'] ?? '';
$busca = trim($_GET['busca'] ?? '');
$categoriaId = idOuNull($_GET['categoria'] ?? null);
[$de, $ate] = periodoFiltro();

// Despesas e faturas no mesmo formato (status: pendente | pago).
$base = "
    SELECT 'despesa' AS tipo, cp.id, cp.descricao, cp.data_vencimento, cp.valor, COALESCE(cp.valor_pago, 0) AS pago,
           cp.status, cp.data_pagamento, cp.categoria_id, cat.nome AS categoria_nome, p.nome AS quem, c.nome AS conta_nome
    FROM contas_pagar cp
    LEFT JOIN pessoas p ON p.id = cp.pessoa_id LEFT JOIN categorias cat ON cat.id = cp.categoria_id LEFT JOIN contas_bancarias c ON c.id = cp.conta_id
    WHERE cp.status <> 'cancelado'
    UNION ALL
    SELECT 'fatura', f.id, CONCAT('Fatura ', ca.nome, ' · ', f.competencia), f.data_vencimento, SUM(pa.valor), COALESCE(f.valor_pago, 0),
           IF(f.status = 'paga', 'pago', 'pendente'), f.data_pagamento, NULL, 'Cartão de crédito', ca.nome, c.nome
    FROM cartao_faturas f JOIN cartoes ca ON ca.id = f.cartao_id JOIN cartao_parcelas pa ON pa.fatura_id = f.id
    LEFT JOIN contas_bancarias c ON c.id = ca.conta_pagamento_id
    GROUP BY f.id";

$where = ['1=1'];
$params = [];
match ($situacao) {
    'pendentes' => $where[] = "x.status = 'pendente'",
    'vencidas' => $where[] = "x.status = 'pendente' AND x.data_vencimento < CURDATE()",
    'parciais' => $where[] = "x.status = 'pendente' AND x.pago > 0",
    'pagas' => $where[] = "x.status = 'pago'",
    default => null,
};
if (in_array($tipo, ['despesa', 'fatura'], true)) {
    $where[] = 'x.tipo = ?';
    $params[] = $tipo;
}
if ($busca !== '') {
    $where[] = '(x.descricao LIKE ? OR x.quem LIKE ?)';
    array_push($params, "%{$busca}%", "%{$busca}%");
}
if ($categoriaId) {
    $where[] = 'x.categoria_id = ?';
    $params[] = $categoriaId;
}
$campoData = $situacao === 'pagas' ? 'x.data_pagamento' : 'x.data_vencimento';
if ($de) {
    $where[] = "{$campoData} >= ?";
    $params[] = $de;
}
if ($ate) {
    $where[] = "{$campoData} <= ?";
    $params[] = $ate;
}

$stmt = $pdo->prepare("SELECT * FROM ({$base}) x WHERE " . implode(' AND ', $where) . ' ORDER BY ' . ($situacao === 'pagas' ? 'x.data_pagamento DESC' : 'x.data_vencimento ASC') . ', x.tipo, x.id LIMIT 1000');
$stmt->execute($params);
$contas = $stmt->fetchAll();

$totValor = array_sum(array_column($contas, 'valor'));
$totPago = array_sum(array_column($contas, 'pago'));
$totFalta = array_sum(array_map(fn ($c) => $c['status'] === 'pendente' ? max(0, $c['valor'] - $c['pago']) : 0, $contas));

// Quanto falta pagar por mês (despesas + faturas) — para planejar.
$porMes = $pdo->query(
    "SELECT DATE_FORMAT(x.data_vencimento, '%Y-%m') AS mes, SUM(GREATEST(x.valor - x.pago, 0)) AS falta
     FROM ({$base}) x WHERE x.status = 'pendente' AND x.data_vencimento < DATE_FORMAT(CURDATE() + INTERVAL 6 MONTH, '%Y-%m-01')
     GROUP BY mes ORDER BY mes"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$informacoes = [
    ['Contas na página', count($contas), ''],
    ['Total da página', formatMoney($totValor), ''],
    ['Já pago', formatMoney($totPago), 'valor-positivo'],
    ['Falta pagar', formatMoney($totFalta), $totFalta > 0 ? 'valor-negativo' : ''],
];
$somasSelecao = ['valor' => 'Valor', 'falta' => 'Falta pagar'];

$link = fn ($c) => $c['tipo'] === 'fatura' ? '/cartoes/fatura.php?id=' . (int) $c['id'] : '/pagar/baixar.php?id=' . (int) $c['id'];

$pageTitle = 'Contas a pagar';
$pageBreadcrumb = 'Financeiro';
$activeMenu = 'pagar';
$pageAction = '<a href="/cartoes/compra.php" class="btn btn-outline-secondary"><i class="bi bi-credit-card"></i> Compra no cartão</a>'
    . '<a href="/pagar/form.php" class="btn btn-brand"><i class="bi bi-plus-lg"></i> Lançar despesa</a>';
require __DIR__ . '/../../includes/layout_header.php';
?>

<div class="pedidos-layout"><div class="pedidos-conteudo">
<div class="filtros">
    <form method="get">
        <select name="situacao" class="form-select form-select-sm" style="width: 150px;">
            <?php foreach (['pendentes' => 'A pagar', 'vencidas' => 'Vencidas', 'parciais' => 'Pagas em parte', 'pagas' => 'Pagas', 'todas' => 'Todas'] as $k => $v): ?>
                <option value="<?= $k ?>" <?= $situacao === $k ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
        </select>
        <select name="tipo" class="form-select form-select-sm" style="width: 170px;">
            <option value="">Despesas e faturas</option>
            <option value="despesa" <?= $tipo === 'despesa' ? 'selected' : '' ?>>Só despesas</option>
            <option value="fatura" <?= $tipo === 'fatura' ? 'selected' : '' ?>>Só faturas de cartão</option>
        </select>
        <select name="categoria" class="form-select form-select-sm" style="width: 180px;"><?= str_replace('— sem categoria —', 'Todas as categorias', opcoesCategorias($pdo, 'despesa', $categoriaId)) ?></select>
        <input type="text" name="busca" class="form-control form-control-sm" placeholder="Descrição, fornecedor ou cartão" value="<?= e($busca) ?>" style="width: 220px;">
        <?php $dataInicio = $de; $dataFim = $ate; $periodoRotulo = $situacao === 'pagas' ? 'Pagamento' : 'Vencimento'; require __DIR__ . '/../../includes/filtro_periodo.php'; ?>
        <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search"></i> Filtrar</button>
    </form>
    <span class="ms-auto text-muted small"><?= count($contas) ?> conta(s)</span>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><th style="width: 32px;"><input type="checkbox" class="form-check-input selecao-todos" title="Marcar todos"></th><th>Vencimento</th><th>Descrição</th><th>Categoria</th><th>Fornecedor / cartão</th><th>Conta</th><th class="text-end">Valor</th><th class="text-end">Pago</th><th class="text-end">Falta</th><th>Situação</th><th></th></tr></thead>
            <tbody>
            <?php if (!$contas): ?>
                <tr><td colspan="11" class="text-center text-muted py-4">Nada por aqui.</td></tr>
            <?php endif; ?>
            <?php foreach ($contas as $c): $st = statusTelaPagar(['status' => $c['status'], 'data_vencimento' => $c['data_vencimento'], 'valor_pago' => $c['pago']]); ?>
                <tr class="cursor-pointer" data-detalhe="<?= $link($c) ?>">
                    <td><input type="checkbox" class="form-check-input selecao-item" data-valor="<?= e((string) $c['valor']) ?>" data-falta="<?= e((string) ($c['status'] === 'pendente' ? max(0, $c['valor'] - $c['pago']) : 0)) ?>"></td>
                    <td class="text-num <?= $st === 'vencido' ? 'valor-negativo fw-semibold' : '' ?>"><?= formatDate($c['data_vencimento']) ?></td>
                    <td><?= $c['tipo'] === 'fatura' ? '<i class="bi bi-credit-card text-muted"></i> ' . e(preg_replace_callback('/(\d{4}-\d{2})$/', fn ($m) => nomeMes($m[1]), $c['descricao'])) : e($c['descricao']) ?></td>
                    <td><?= e($c['categoria_nome'] ?? '-') ?></td>
                    <td><?= e($c['quem'] ?? '-') ?></td>
                    <td><?= e($c['conta_nome'] ?? '—') ?></td>
                    <td class="text-end text-num"><?= formatMoney($c['valor']) ?></td>
                    <td class="text-end text-num"><?= $c['pago'] > 0 ? formatMoney($c['pago']) : '-' ?></td>
                    <td class="text-end text-num fw-semibold"><?= $c['status'] === 'pendente' ? formatMoney(max(0, $c['valor'] - $c['pago'])) : '-' ?></td>
                    <td><?= badgeStatus($st) ?><?= $c['status'] === 'pago' ? ' <span class="small text-muted">' . formatDate($c['data_pagamento']) . '</span>' : '' ?></td>
                    <?php if ($c['tipo'] === 'fatura'): ?>
                        <?= acoesLinha($link($c), null, '', true, 'Fatura de cartão: para tirar, exclua a compra em Cartões de crédito') ?>
                    <?php else: ?>
                        <?= acoesLinha($link($c) . '&editar=1', $c['pago'] > 0 ? null : $link($c), 'Excluir esta conta a pagar?', true, 'Já tem pagamento: estorne os pagamentos antes de excluir') ?>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php if ($contas): ?>
            <tfoot><tr class="fw-semibold"><td colspan="6">Total</td><td class="text-end text-num"><?= formatMoney($totValor) ?></td><td class="text-end text-num"><?= formatMoney($totPago) ?></td><td class="text-end text-num"><?= formatMoney($totFalta) ?></td><td colspan="2"></td></tr></tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

</div><!-- /pedidos-conteudo -->
<?php require __DIR__ . '/../../includes/painel_lateral.php'; ?>
</div><!-- /pedidos-layout -->

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
