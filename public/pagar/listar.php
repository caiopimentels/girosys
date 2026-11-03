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
$acoesLaterais = [['Pagar selecionados', 'bi-check2-all', 'abrirPagamentoLote()']];

// Botão "Pagar" das contas em aberto: abre o modal já com a forma de pagamento.
$botaoPagar = fn ($c) => $c['status'] === 'pendente'
    ? '<button type="button" class="btn btn-sm btn-outline-success" title="Pagar" onclick="abrirDetalhe(\'' . ($c['tipo'] === 'fatura' ? '/cartoes/fatura.php?id=' : '/pagar/baixar.php?id=') . (int) $c['id'] . '\')"><i class="bi bi-check2-circle"></i></button> '
    : '';
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
                    <td><input type="checkbox" class="form-check-input selecao-item" value="<?= e($c['tipo'] . ':' . (int) $c['id']) ?>" data-aberto="<?= $c['status'] === 'pendente' ? 1 : 0 ?>" data-valor="<?= e((string) $c['valor']) ?>" data-falta="<?= e((string) ($c['status'] === 'pendente' ? max(0, $c['valor'] - $c['pago']) : 0)) ?>"></td>
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
                        <?= acoesLinha($link($c), null, '', true, 'Fatura de cartão: para tirar, exclua a compra em Cartões de crédito', $botaoPagar($c)) ?>
                    <?php else: ?>
                        <?= acoesLinha($link($c) . '&editar=1', $c['pago'] > 0 ? null : $link($c), 'Excluir esta conta a pagar?', true, 'Já tem pagamento: estorne os pagamentos antes de excluir', $botaoPagar($c)) ?>
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

<div class="modal fade" id="modal-pagar-lote" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" action="/pagar/pagar_lote.php" class="modal-content" id="form-pagar-lote">
            <div class="modal-header"><h5 class="modal-title">Pagar selecionados</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="alert alert-light border small" id="pagar-lote-resumo"></div>
                <input type="hidden" name="voltar" value="<?= e($_SERVER['REQUEST_URI'] ?? '/pagar/listar.php') ?>">
                <div class="mb-2"><label class="form-label">Data do pagamento</label><input type="date" name="data" class="form-control" required value="<?= date('Y-m-d') ?>" style="max-width: 200px;"></div>
                <label class="form-label mb-1">De quais contas saiu</label>
                <div id="lote-partes"></div>
                <button type="button" class="btn btn-sm btn-link px-0" id="lote-add-parte"><i class="bi bi-plus-lg"></i> Pagar parte por outra conta</button>
                <div class="d-flex justify-content-between small p-2 rounded bg-body-tertiary mt-1"><span>Informado: <strong id="lote-informado">-</strong></span><span id="lote-falta"></span></div>
                <div class="form-text mt-2">As contas marcadas são quitadas em ordem de vencimento, usando os valores na ordem acima. Faturas antigas que o saldo inicial já considera: escolha só "Não movimentar conta".</div>
                <template id="modelo-lote-parte">
                    <div class="input-group mb-2 parte">
                        <select name="partes[__i][conta_id]" class="form-select" style="max-width: 60%;"><?= opcoesContaSaida($pdo) ?></select>
                        <input type="text" name="partes[__i][valor]" class="form-control text-end" data-moeda inputmode="decimal">
                        <button type="button" class="btn btn-outline-secondary remover-parte" title="Tirar"><i class="bi bi-x"></i></button>
                    </div>
                </template>
                <div id="pagar-lote-ids"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-brand"><i class="bi bi-check2-all"></i> Pagar todas</button></div>
        </form>
    </div>
</div>
<script>
// Pagamento em lote: só as marcadas que ainda estão em aberto; o dinheiro
// pode ter saído de várias contas (uma linha por conta).
let totalLote = 0, seqLote = 0;
function novaParteLote(valor) {
    const caixa = document.getElementById('lote-partes');
    caixa.insertAdjacentHTML('beforeend', document.getElementById('modelo-lote-parte').innerHTML.replaceAll('__i', seqLote++));
    const linha = caixa.lastElementChild;
    if (valor > 0) linha.querySelector('input').value = Moeda.formatar(valor);
    return linha;
}
function conferirLote() {
    const informado = [...document.querySelectorAll('#lote-partes input')].reduce((s, c) => s + Moeda.ler(c.value), 0);
    const resto = Math.round((totalLote - informado) * 100) / 100;
    const sem = [...document.querySelectorAll('#lote-partes select')].some((s) => s.value === 'sem');
    document.getElementById('lote-informado').textContent = sem ? 'sem movimentar conta' : Moeda.reais(informado);
    document.getElementById('lote-falta').innerHTML = sem ? '' : resto > 0.004 ? 'Falta distribuir <strong class="valor-negativo">' + Moeda.reais(resto) + '</strong>'
        : resto < -0.004 ? '<span class="valor-negativo">Passou ' + Moeda.reais(-resto) + ' do total</span>' : '<span class="valor-positivo fw-semibold">Fecha o total</span>';
    return resto;
}
document.addEventListener('DOMContentLoaded', () => {
    const caixa = document.getElementById('lote-partes');
    document.getElementById('lote-add-parte').addEventListener('click', () => { novaParteLote(Math.max(0, conferirLote())).querySelector('select').focus(); conferirLote(); });
    caixa.addEventListener('click', (ev) => { if (ev.target.closest('.remover-parte') && caixa.children.length > 1) { ev.target.closest('.parte').remove(); conferirLote(); } });
    caixa.addEventListener('input', conferirLote);
    caixa.addEventListener('change', conferirLote);
    caixa.addEventListener('focusout', () => setTimeout(conferirLote));
});
function abrirPagamentoLote() {
    const marcadas = [...document.querySelectorAll('.selecao-item:checked')].filter((c) => c.dataset.aberto === '1');
    if (!marcadas.length) { alert('Marque na lista as contas em aberto que quer pagar.'); return; }
    const total = marcadas.reduce((s, c) => s + parseFloat(c.dataset.falta), 0);
    totalLote = Math.round(total * 100) / 100;
    document.getElementById('lote-partes').innerHTML = '';
    novaParteLote(totalLote);
    conferirLote();
    document.getElementById('pagar-lote-resumo').innerHTML = '<strong>' + marcadas.length + ' conta(s)</strong> · ' + total.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }) + ' a pagar';
    document.getElementById('pagar-lote-ids').innerHTML = marcadas.map((c) => '<input type="hidden" name="ids[]" value="' + c.value + '">').join('');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-pagar-lote')).show();
}
</script>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
