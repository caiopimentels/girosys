<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

$pdo = getDB();
$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT f.*, c.nome AS conta_nome FROM cartao_faturas f LEFT JOIN contas_bancarias c ON c.id = f.conta_id WHERE f.id = ?');
$stmt->execute([$id]);
$fatura = $stmt->fetch();
if (!$fatura) {
    redirect('/cartoes/listar.php');
}
$stmt = $pdo->prepare('SELECT * FROM cartoes WHERE id = ?');
$stmt->execute([$fatura['cartao_id']]);
$cartao = $stmt->fetch();
$total = totalFatura($pdo, $id);

$descricaoFatura = 'Fatura ' . $cartao['nome'] . ' ' . nomeMes($fatura['competencia']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    $pdo->beginTransaction();
    try {
        if ($acao === 'pagar' && $fatura['status'] === 'aberta') {
            $data = dataInput('data') ?? throw new InvalidArgumentException('Informe a data do pagamento.');
            registrarPagamentos($pdo, 'fatura', $id, $descricaoFatura, $data, partesPagamentoInput(), isset($_POST['quitar']));
            flash('success', 'Pagamento da fatura lançado.');
        } elseif ($acao === 'estornar_parte') {
            estornarParte($pdo, 'fatura', $id, (int) ($_POST['movimentacao_id'] ?? 0));
            flash('success', 'Pagamento estornado; o valor voltou para a conta.');
        } elseif ($acao === 'reabrir' && $fatura['status'] === 'paga') {
            $pdo->prepare("UPDATE cartao_faturas SET status = 'aberta', data_pagamento = NULL WHERE id = ?")->execute([$id]);
            flash('success', 'Fatura reaberta.');
        }
        $pdo->commit();
    } catch (InvalidArgumentException $e) {
        $pdo->rollBack();
        flash('danger', $e->getMessage());
        redirecionarAposAcao('/cartoes/fatura.php?id=' . $id, true);
    }
    redirecionarAposAcao('/cartoes/fatura.php?id=' . $id);
}

$pagamentos = pagamentosFeitos($pdo, 'fatura', $id);
$pago = array_sum(array_column($pagamentos, 'valor'));
$falta = max(0, round($total - $pago, 2));

$stmt = $pdo->prepare(
    'SELECT p.*, cc.descricao, cc.data_compra, cc.parcelas, cc.id AS compra_id, cat.nome AS categoria_nome
     FROM cartao_parcelas p JOIN cartao_compras cc ON cc.id = p.compra_id LEFT JOIN categorias cat ON cat.id = cc.categoria_id
     WHERE p.fatura_id = ? ORDER BY cc.data_compra, cc.id'
);
$stmt->execute([$id]);
$itens = $stmt->fetchAll();

// Faturas vizinhas para navegar.
$nav = $pdo->prepare('SELECT id, competencia FROM cartao_faturas WHERE cartao_id = ? AND competencia IN (?, ?)');
$nav->execute([$cartao['id'], somarMesesCompetencia($fatura['competencia'], -1), somarMesesCompetencia($fatura['competencia'], 1)]);
$vizinhas = array_column($nav->fetchAll(), 'id', 'competencia');
$anterior = $vizinhas[somarMesesCompetencia($fatura['competencia'], -1)] ?? null;
$proxima = $vizinhas[somarMesesCompetencia($fatura['competencia'], 1)] ?? null;

$pageTitle = 'Fatura ' . $cartao['nome'] . ' · ' . nomeMes($fatura['competencia']);
$pageBreadcrumb = 'Financeiro · Cartões de crédito';
$activeMenu = 'cartoes';
$pageAction = '<a href="/pagar/listar.php" class="btn btn-outline-secondary">Contas a pagar</a>'
    . '<a href="/cartoes/listar.php" class="btn btn-outline-secondary">Cartões</a>';
require __DIR__ . '/../../includes/layout_header.php';
?>

<div style="max-width: 900px;">
    <?php // Em cima: o que é a fatura. ?>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <?= badgeStatus($fatura['status'] === 'aberta' && $pago > 0 ? 'parcial' : ($fatura['status'] === 'aberta' && $fatura['data_vencimento'] < date('Y-m-d') ? 'vencido' : $fatura['status'])) ?>
        <span class="text-muted small"><i class="bi bi-credit-card"></i> <?= e($cartao['nome']) ?> · <?= count($itens) ?> lançamento(s)</span>
        <?php if (!$modoModal): ?>
            <span class="ms-auto d-flex gap-1">
                <?php if ($anterior): ?><a href="/cartoes/fatura.php?id=<?= $anterior ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-left"></i> Anterior</a><?php endif; ?>
                <?php if ($proxima): ?><a href="/cartoes/fatura.php?id=<?= $proxima ?>" class="btn btn-sm btn-outline-secondary">Próxima <i class="bi bi-chevron-right"></i></a><?php endif; ?>
            </span>
        <?php endif; ?>
    </div>
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3"><div class="kpi-rotulo">Total</div><div class="fw-semibold text-num"><?= formatMoney($total) ?></div></div>
        <div class="col-6 col-md-3"><div class="kpi-rotulo">Vencimento</div><div class="fw-semibold"><?= formatDate($fatura['data_vencimento']) ?></div></div>
        <div class="col-6 col-md-3"><div class="kpi-rotulo">Pago</div><div class="fw-semibold text-num"><?= formatMoney($pago) ?></div></div>
        <div class="col-6 col-md-3"><div class="kpi-rotulo"><?= $fatura['status'] === 'paga' ? 'Quitada em' : 'Falta' ?></div><div class="fw-semibold text-num <?= $fatura['status'] === 'paga' ? 'valor-positivo' : 'valor-negativo' ?>"><?= $fatura['status'] === 'paga' ? formatDate($fatura['data_pagamento']) : formatMoney($falta) ?></div></div>
    </div>

    <details class="card mb-3" <?= $modoModal ? '' : 'open' ?>><summary class="card-body py-2 fw-semibold small">Compras desta fatura (<?= count($itens) ?>)</summary>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead><tr><th>Data</th><th>Compra</th><th>Categoria</th><th class="text-center">Parcela</th><th class="text-end">Valor</th></tr></thead>
                <tbody>
                <?php foreach ($itens as $i): ?>
                    <tr>
                        <td class="text-num"><?= formatDate($i['data_compra']) ?></td>
                        <td><a href="/cartoes/compra.php?id=<?= (int) $i['compra_id'] ?>" class="text-reset"><?= e($i['descricao']) ?></a></td>
                        <td><?= e($i['categoria_nome'] ?? '-') ?></td>
                        <td class="text-center"><?= (int) $i['numero'] ?>/<?= (int) $i['parcelas'] ?></td>
                        <td class="text-end text-num"><?= formatMoney($i['valor']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr class="fw-semibold"><td colspan="4">Total da fatura</td><td class="text-end text-num"><?= formatMoney($total) ?></td></tr></tfoot>
            </table>
        </div>
    </details>

    <?= listaPagamentosFeitos($pagamentos) ?>

    <?php // Embaixo: a forma de pagamento. ?>
    <?php if ($fatura['status'] === 'aberta'): ?>
    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-wallet2"></i> Forma de pagamento <?= $pago > 0 ? '· falta ' . formatMoney($falta) : '' ?></div>
        <div class="card"><div class="card-body"><?= formPagamento($pdo, $falta, idOuNull($cartao['conta_pagamento_id'])) ?></div></div>
    </div>
    <?php elseif ($pago < $total - 0.004): ?>
    <div class="alert alert-light border small">Quitada com desconto de <?= formatMoney($total - $pago) ?>.
        <form method="post" class="d-inline"><input type="hidden" name="acao" value="reabrir"><button class="btn btn-link btn-sm p-0">Reabrir</button></form></div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
