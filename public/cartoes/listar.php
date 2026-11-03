<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

$pdo = getDB();
$cartoes = $pdo->query(
    "SELECT c.*,
        (SELECT COALESCE(SUM(p.valor), 0) FROM cartao_parcelas p JOIN cartao_faturas f ON f.id = p.fatura_id WHERE f.cartao_id = c.id AND f.status = 'aberta')
          - (SELECT COALESCE(SUM(f.valor_pago), 0) FROM cartao_faturas f WHERE f.cartao_id = c.id AND f.status = 'aberta') AS comprometido
     FROM cartoes c ORDER BY c.ativo DESC, c.nome"
)->fetchAll();

// Faturas em aberto (e as pagas do mês atual em diante) por cartão e mês — próximos 12 meses.
$mesAtual = date('Y-m');
$meses = [];
for ($i = -1; $i < 12; $i++) {
    $meses[] = somarMesesCompetencia($mesAtual, $i);
}
$stmt = $pdo->prepare(
    'SELECT f.*, COALESCE(SUM(p.valor), 0) AS total FROM cartao_faturas f LEFT JOIN cartao_parcelas p ON p.fatura_id = f.id
     WHERE f.competencia BETWEEN ? AND ? GROUP BY f.id'
);
$stmt->execute([$meses[0], end($meses)]);
$faturas = [];
foreach ($stmt->fetchAll() as $f) {
    $faturas[$f['cartao_id']][$f['competencia']] = $f;
}
// Atrasadas (antes do mês passado e ainda em aberto).
$atrasadas = $pdo->prepare(
    "SELECT f.*, c.nome AS cartao_nome, SUM(p.valor) - COALESCE(f.valor_pago, 0) AS total FROM cartao_faturas f JOIN cartoes c ON c.id = f.cartao_id JOIN cartao_parcelas p ON p.fatura_id = f.id
     WHERE f.status = 'aberta' AND f.competencia < ? GROUP BY f.id ORDER BY f.competencia"
);
$atrasadas->execute([$meses[0]]);
$atrasadas = $atrasadas->fetchAll();

$compras = $pdo->query(
    "SELECT cc.*, c.nome AS cartao_nome, cat.nome AS categoria_nome,
        SUM(CASE WHEN f.status = 'paga' THEN 1 ELSE 0 END) AS pagas,
        SUM(CASE WHEN f.status = 'aberta' THEN p.valor ELSE 0 END) AS restante,
        MAX(f.competencia) AS ultima
     FROM cartao_compras cc JOIN cartoes c ON c.id = cc.cartao_id LEFT JOIN categorias cat ON cat.id = cc.categoria_id
     JOIN cartao_parcelas p ON p.compra_id = cc.id JOIN cartao_faturas f ON f.id = p.fatura_id
     GROUP BY cc.id HAVING restante > 0 ORDER BY cc.data_compra DESC"
)->fetchAll();

$pageTitle = 'Cartões de crédito';
$pageBreadcrumb = 'Financeiro';
$activeMenu = 'cartoes';
$pageAction = '<a href="/cartoes/form.php" class="btn btn-outline-secondary"><i class="bi bi-plus-lg"></i> Novo cartão</a>'
    . ($cartoes ? '<a href="/cartoes/compra.php" class="btn btn-brand"><i class="bi bi-bag-plus"></i> Lançar compra</a>' : '');
require __DIR__ . '/../../includes/layout_header.php';
?>

<?php if (!$cartoes): ?>
    <div class="card"><div class="card-body text-center text-muted py-4">Nenhum cartão cadastrado. <a href="/cartoes/form.php">Cadastre o primeiro</a> com o dia de fechamento e de vencimento da fatura.</div></div>
<?php else: ?>

<?php if ($atrasadas): ?>
    <div class="alert alert-danger">
        <strong>Faturas em aberto de meses anteriores:</strong>
        <?php foreach ($atrasadas as $f): ?>
            <a href="/cartoes/fatura.php?id=<?= (int) $f['id'] ?>" class="alert-link ms-2"><?= e($f['cartao_nome']) ?> <?= nomeMes($f['competencia']) ?> (<?= formatMoney($f['total']) ?>)</a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
// Resumo: limite × comprometido (faturas em aberto) dos cartões ativos com limite.
$comLimite = array_filter($cartoes, fn ($c) => $c['ativo'] && $c['limite'] > 0);
$limiteTotal = array_sum(array_column($comLimite, 'limite'));
$usadoTotal = array_sum(array_map(fn ($c) => max(0, (float) $c['comprometido']), $comLimite));
$pctGlobal = $limiteTotal > 0 ? $usadoTotal / $limiteTotal * 100 : 0;
$corUso = fn (float $pct) => $pct >= 90 ? 'bg-danger' : ($pct >= 70 ? 'bg-warning' : 'bg-success');
?>
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body py-3"><div class="kpi-rotulo">Limite total</div><div class="kpi-valor"><?= formatMoney($limiteTotal) ?></div><div class="small text-muted"><?= count($comLimite) ?> cartão(ões) com limite</div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body py-3"><div class="kpi-rotulo">Usado (faturas em aberto)</div><div class="kpi-valor valor-negativo"><?= formatMoney($usadoTotal) ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body py-3"><div class="kpi-rotulo">Disponível</div><div class="kpi-valor valor-positivo"><?= formatMoney($limiteTotal - $usadoTotal) ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body py-3">
        <div class="kpi-rotulo">Uso global</div>
        <div class="kpi-valor"><?= number_format($pctGlobal, 1, ',', '.') ?>%</div>
        <div class="progress mt-1" style="height: 8px;"><div class="progress-bar <?= $corUso($pctGlobal) ?>" style="width: <?= min(100, $pctGlobal) ?>%"></div></div>
    </div></div></div>
</div>

<div class="secao">
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead><tr>
                    <th>Cartão</th><th>Fecha / vence</th><th class="text-end">Fatura de <?= nomeMes($mesAtual) ?></th>
                    <th class="text-end">Usado</th><th class="text-end">Limite</th><th class="text-end">Disponível</th><th style="width: 190px;">Uso do limite</th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($cartoes as $c): ?>
                    <?php $atual = $faturas[$c['id']][$mesAtual] ?? null; $usado = max(0, (float) $c['comprometido']); $pct = $c['limite'] > 0 ? $usado / $c['limite'] * 100 : null; ?>
                    <tr class="<?= $c['ativo'] ? '' : 'opacity-50' ?>">
                        <td class="fw-semibold"><i class="bi bi-credit-card text-muted"></i> <?= e($c['nome']) ?><?= $c['ativo'] ? '' : ' <span class="badge text-bg-secondary">Inativo</span>' ?><?= $c['bandeira'] ? '<div class="small text-muted fw-normal">' . e($c['bandeira']) . '</div>' : '' ?></td>
                        <td class="small text-muted">dia <?= (int) $c['dia_fechamento'] ?> / dia <?= (int) $c['dia_vencimento'] ?></td>
                        <td class="text-end text-num">
                            <?php if ($atual && $atual['total'] > 0): ?><a href="#" class="text-reset" onclick="event.preventDefault(); abrirDetalhe('/cartoes/fatura.php?id=<?= (int) $atual['id'] ?>')"><?= formatMoney($atual['total']) ?></a> <?= $atual['status'] === 'paga' ? '<i class="bi bi-check-circle-fill valor-positivo" title="Paga"></i>' : '' ?>
                            <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                        </td>
                        <td class="text-end text-num"><?= formatMoney($usado) ?></td>
                        <td class="text-end text-num"><?= $c['limite'] > 0 ? formatMoney($c['limite']) : '<span class="text-muted">-</span>' ?></td>
                        <td class="text-end text-num <?= $pct !== null && $pct >= 100 ? 'valor-negativo' : '' ?>"><?= $c['limite'] > 0 ? formatMoney($c['limite'] - $usado) : '<span class="text-muted">-</span>' ?></td>
                        <td>
                            <?php if ($pct !== null): ?>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="progress flex-grow-1" style="height: 8px;"><div class="progress-bar <?= $corUso($pct) ?>" style="width: <?= min(100, $pct) ?>%"></div></div>
                                    <span class="small text-num" style="width: 44px; text-align: right;"><?= number_format($pct, 0, ',', '.') ?>%</span>
                                </div>
                            <?php else: ?><span class="small text-muted">sem limite</span><?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <a href="/cartoes/compra.php?cartao=<?= (int) $c['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Lançar compra"><i class="bi bi-bag-plus"></i></a>
                            <a href="/cartoes/form.php?id=<?= (int) $c['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Editar cartão"><i class="bi bi-pencil"></i></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr class="fw-semibold">
                    <td colspan="3">Total (ativos com limite)</td>
                    <td class="text-end text-num"><?= formatMoney($usadoTotal) ?></td><td class="text-end text-num"><?= formatMoney($limiteTotal) ?></td><td class="text-end text-num"><?= formatMoney($limiteTotal - $usadoTotal) ?></td>
                    <td><div class="d-flex align-items-center gap-2"><div class="progress flex-grow-1" style="height: 8px;"><div class="progress-bar <?= $corUso($pctGlobal) ?>" style="width: <?= min(100, $pctGlobal) ?>%"></div></div><span class="small text-num" style="width: 44px; text-align: right;"><?= number_format($pctGlobal, 0, ',', '.') ?>%</span></div></td>
                    <td></td>
                </tr></tfoot>
            </table>
        </div>
    </div>
</div>

<div class="secao">
    <div class="secao-titulo"><i class="bi bi-calendar3"></i> Faturas mês a mês</div>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead><tr><th>Mês</th><?php foreach ($cartoes as $c): ?><th class="text-end"><?= e($c['nome']) ?></th><?php endforeach; ?><th class="text-end">Total</th></tr></thead>
                <tbody>
                <?php foreach ($meses as $m): ?>
                    <?php $totalMes = 0; ?>
                    <tr class="<?= $m === $mesAtual ? 'table-light fw-semibold' : '' ?>">
                        <td><?= nomeMes($m) ?></td>
                        <?php foreach ($cartoes as $c): $f = $faturas[$c['id']][$m] ?? null; $totalMes += $f['total'] ?? 0; ?>
                            <td class="text-end text-num">
                                <?php if ($f && $f['total'] > 0): ?>
                                    <a href="/cartoes/fatura.php?id=<?= (int) $f['id'] ?>" class="text-reset"><?= formatMoney($f['total']) ?></a>
                                    <?= $f['status'] === 'paga' ? '<i class="bi bi-check-circle-fill valor-positivo" title="Paga"></i>' : '' ?>
                                <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                        <td class="text-end text-num fw-semibold"><?= $totalMes > 0 ? formatMoney($totalMes) : '-' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="secao">
    <div class="secao-titulo"><i class="bi bi-bag"></i> Compras parceladas em andamento</div>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead><tr><th>Compra</th><th>Cartão</th><th>Data</th><th>Categoria</th><th class="text-end">Total</th><th class="text-center">Parcelas pagas</th><th class="text-end">Falta pagar</th><th>Termina em</th><th></th></tr></thead>
                <tbody>
                <?php if (!$compras): ?><tr><td colspan="9" class="text-center text-muted py-3">Nenhuma compra em aberto.</td></tr><?php endif; ?>
                <?php foreach ($compras as $cc): ?>
                    <tr class="cursor-pointer" onclick="window.location='/cartoes/compra.php?id=<?= (int) $cc['id'] ?>'">
                        <td><?= e($cc['descricao']) ?></td>
                        <td><?= e($cc['cartao_nome']) ?></td>
                        <td class="text-num"><?= formatDate($cc['data_compra']) ?></td>
                        <td><?= e($cc['categoria_nome'] ?? '-') ?></td>
                        <td class="text-end text-num"><?= formatMoney($cc['valor_total']) ?></td>
                        <td class="text-center"><?= (int) $cc['pagas'] ?>/<?= (int) $cc['parcelas'] ?></td>
                        <td class="text-end text-num fw-semibold"><?= formatMoney($cc['restante']) ?></td>
                        <td><?= nomeMes($cc['ultima']) ?></td>
                        <?= acoesLinha('/cartoes/compra.php?id=' . (int) $cc['id'], $cc['pagas'] > 0 ? null : '/cartoes/compra.php?id=' . (int) $cc['id'],
                            'Excluir a compra "' . $cc['descricao'] . '" e todas as parcelas?', false, 'Tem parcela em fatura paga: estorne o pagamento da fatura antes') ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
