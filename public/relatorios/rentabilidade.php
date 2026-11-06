<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

$pdo = getDB();
[$de, $ate, $deSql, $ateSql] = periodoFiltro(date('Y-m-01'), date('Y-m-d'));
$visao = in_array($_GET['visao'] ?? '', ['mes', 'cliente', 'atraso'], true) ? $_GET['visao'] : 'mes';

$linhas = ganhosPeriodo($pdo, $deSql, $ateSql);
$totais = agruparGanhos($linhas, fn () => 'total')['total'] ?? array_fill_keys([...array_keys(componentesGanho()), 'isento', 'total', 'bruto', 'liquido', 'operacoes'], 0.0);
$atraso = jurosMultaRecebidos($pdo, $deSql, $ateSql);
$multaTotal = array_sum(array_column($atraso, 'multa_parte'));
$jurosAtrasoTotal = array_sum(array_column($atraso, 'juros_parte'));
$desagioRealizado = array_sum(array_map(fn ($l) => $l['realizado'] ?? 0, array_filter($linhas, fn ($l) => $l['componente'] === 'desagio')));

$porMes = agruparGanhos($linhas, fn ($l) => substr($l['data'], 0, 7));
krsort($porMes);
$porCliente = agruparGanhos($linhas, fn ($l) => $l['cliente_id'] ? $l['cliente_id'] . '|' . $l['cliente_nome'] : '0|Sem cliente (títulos avulsos)');
uasort($porCliente, fn ($a, $b) => $b['total'] <=> $a['total']);

$pct = fn ($v, $base) => $base > 0 ? formatPercent(round($v / $base * 100, 2)) : '—';
$qs = fn ($v) => '?' . http_build_query(['de' => $de, 'ate' => $ate, 'visao' => $v]);

$pageTitle = 'Rentabilidade';
$pageBreadcrumb = 'Relatórios';
$activeMenu = 'rentabilidade';
$pageAction = '<button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Imprimir</button>';
require __DIR__ . '/../../includes/layout_header.php';
?>

<div class="filtros nao-imprimir">
    <form method="get">
        <input type="hidden" name="visao" value="<?= e($visao) ?>">
        <?php $dataInicio = $de; $dataFim = $ate; $periodoRotulo = 'Período'; require __DIR__ . '/../../includes/filtro_periodo.php'; ?>
        <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search"></i> Filtrar</button>
    </form>
    <span class="ms-auto text-muted small"><?= ($de || $ate) ? formatDate($de ?: $deSql) . ' a ' . formatDate($ate ?: date('Y-m-d')) : 'Todo o período' ?></span>
</div>

<div class="row g-3 mb-3">
    <div class="col-12 col-lg-4"><div class="card h-100 border-success"><div class="card-body">
        <div class="kpi-rotulo">Ganho total no período</div>
        <div class="kpi-valor valor-positivo"><?= formatMoney($totais['total']) ?></div>
        <div class="small text-muted"><?= (int) $totais['operacoes'] ?> operação(ões) · <?= formatMoney($totais['bruto']) ?> em títulos descontados<?= $totais['liquido'] > 0 ? ' · ' . $pct($totais['desagio'] + $totais['repasses'], $totais['liquido']) . ' sobre o líquido pago' : '' ?></div>
    </div></div></div>
    <div class="col-6 col-lg-2"><div class="card h-100"><div class="card-body">
        <div class="kpi-rotulo">Deságio</div>
        <div class="kpi-valor"><?= formatMoney($totais['desagio']) ?></div>
        <div class="small text-muted">já recebido <?= formatMoney($desagioRealizado) ?></div>
    </div></div></div>
    <div class="col-6 col-lg-2"><div class="card h-100"><div class="card-body">
        <div class="kpi-rotulo">Juros dos repasses</div>
        <div class="kpi-valor"><?= formatMoney($totais['repasses']) ?></div>
        <div class="small text-muted">correção cobrada nas operações</div>
    </div></div></div>
    <div class="col-6 col-lg-2"><div class="card h-100"><div class="card-body">
        <div class="kpi-rotulo">Juros e multa por atraso</div>
        <div class="kpi-valor"><?= formatMoney($totais['atraso']) ?></div>
        <div class="small text-muted">juros <?= formatMoney($jurosAtrasoTotal) ?> · multa <?= formatMoney($multaTotal) ?></div>
    </div></div></div>
    <div class="col-6 col-lg-2"><div class="card h-100"><div class="card-body">
        <div class="kpi-rotulo">Renegociações</div>
        <div class="kpi-valor"><?= formatMoney($totais['renegociacao']) ?></div>
        <div class="small text-muted"><?= $totais['isento'] > 0 ? 'isentei ' . formatMoney($totais['isento']) : 'juros/multa cobrados' ?></div>
    </div></div></div>
</div>

<ul class="nav nav-tabs mb-3 nao-imprimir">
    <li class="nav-item"><a class="nav-link <?= $visao === 'mes' ? 'active' : '' ?>" href="<?= e($qs('mes')) ?>">Por mês</a></li>
    <li class="nav-item"><a class="nav-link <?= $visao === 'cliente' ? 'active' : '' ?>" href="<?= e($qs('cliente')) ?>">Por cliente</a></li>
    <li class="nav-item"><a class="nav-link <?= $visao === 'atraso' ? 'active' : '' ?>" href="<?= e($qs('atraso')) ?>">Juros e multa recebidos <span class="badge text-bg-light"><?= count($atraso) ?></span></a></li>
</ul>

<?php if ($visao === 'atraso'): ?>
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><th>Recebido em</th><th>Título</th><th>Pagador</th><th>Cliente</th><th>Vencimento</th><th class="text-end">Dias</th><th class="text-end">Valor</th><th class="text-end">Recebido</th><th class="text-end">Multa</th><th class="text-end">Juros</th><th class="text-end">Total</th></tr></thead>
            <tbody>
            <?php if (!$atraso): ?><tr><td colspan="11" class="text-center text-muted py-4">Nenhum recebimento com juros ou multa no período.</td></tr><?php endif; ?>
            <?php foreach ($atraso as $t): ?>
                <tr>
                    <td class="text-num"><?= formatDate($t['data_recebimento']) ?></td>
                    <td><a href="/receber/baixar.php?id=<?= (int) $t['id'] ?>">#<?= (int) $t['id'] ?></a> <span class="text-muted small"><?= e(tipoTituloLabel($t['tipo'])) ?></span></td>
                    <td><?= e($t['emitente_nome'] ?? '—') ?></td>
                    <td><?= e($t['cliente_nome'] ?? '—') ?></td>
                    <td class="text-num"><?= formatDate($t['data_vencimento']) ?></td>
                    <td class="text-end"><?= (int) $t['dias_atraso'] ?></td>
                    <td class="text-end text-num"><?= formatMoney($t['valor']) ?></td>
                    <td class="text-end text-num"><?= formatMoney($t['valor_recebido']) ?></td>
                    <td class="text-end text-num"><?= $t['multa_parte'] > 0 ? formatMoney($t['multa_parte']) : '—' ?></td>
                    <td class="text-end text-num"><?= formatMoney($t['juros_parte']) ?></td>
                    <td class="text-end text-num fw-semibold valor-positivo"><?= formatMoney($t['acrescimo']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php if ($atraso): ?>
            <tfoot><tr class="fw-semibold"><td colspan="8">Total</td><td class="text-end text-num"><?= formatMoney($multaTotal) ?></td><td class="text-end text-num"><?= formatMoney($jurosAtrasoTotal) ?></td><td class="text-end text-num valor-positivo"><?= formatMoney($totais['atraso']) ?></td></tr></tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>
<div class="form-text">A multa é separada pela regra do tipo de recebimento (% sobre o valor); o restante do acréscimo é juros. Conta pela data em que o dinheiro entrou.</div>

<?php else: $grupos = $visao === 'mes' ? $porMes : $porCliente; ?>
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr>
                <th><?= $visao === 'mes' ? 'Mês' : 'Cliente' ?></th><th class="text-center">Operações</th><th class="text-end">Títulos descontados</th>
                <?php foreach (componentesGanho() as $rotulo): ?><th class="text-end"><?= e($rotulo) ?></th><?php endforeach; ?>
                <th class="text-end">Total</th><th class="text-end" title="Deságio + juros dos repasses sobre o líquido pago nas operações">Rentab.</th>
            </tr></thead>
            <tbody>
            <?php if (!$grupos): ?><tr><td colspan="9" class="text-center text-muted py-4">Nenhum ganho no período.</td></tr><?php endif; ?>
            <?php foreach ($grupos as $chave => $g): ?>
                <tr>
                    <td><?php if ($visao === 'mes'): ?><?= e(nomeMes($chave)) ?><?php else: [$cid, $cnome] = explode('|', $chave, 2); ?><?= $cid ? '<a href="/pessoas/form.php?id=' . (int) $cid . '" class="text-reset">' . e($cnome) . '</a>' : '<span class="text-muted">' . e($cnome) . '</span>' ?><?php endif; ?></td>
                    <td class="text-center"><?= (int) $g['operacoes'] ?: '—' ?></td>
                    <td class="text-end text-num"><?= $g['bruto'] > 0 ? formatMoney($g['bruto']) : '—' ?></td>
                    <?php foreach (array_keys(componentesGanho()) as $k): ?><td class="text-end text-num"><?= $g[$k] > 0 ? formatMoney($g[$k]) : '—' ?></td><?php endforeach; ?>
                    <td class="text-end text-num fw-semibold valor-positivo"><?= formatMoney($g['total']) ?></td>
                    <td class="text-end text-num small"><?= $pct($g['desagio'] + $g['repasses'], $g['liquido']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php if (count($grupos) > 1): ?>
            <tfoot><tr class="fw-semibold">
                <td>Total</td><td class="text-center"><?= (int) $totais['operacoes'] ?></td><td class="text-end text-num"><?= formatMoney($totais['bruto']) ?></td>
                <?php foreach (array_keys(componentesGanho()) as $k): ?><td class="text-end text-num"><?= formatMoney($totais[$k]) ?></td><?php endforeach; ?>
                <td class="text-end text-num valor-positivo"><?= formatMoney($totais['total']) ?></td><td class="text-end text-num small"><?= $pct($totais['desagio'] + $totais['repasses'], $totais['liquido']) ?></td>
            </tr></tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>
<div class="form-text">Deságio e juros dos repasses contam pela data base da operação; juros e multa por atraso, pela data do recebimento; renegociação, pela data em que foi feita (encargos levados para os novos títulos ou para o cliente).</div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
