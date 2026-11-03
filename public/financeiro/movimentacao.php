<?php
// Recebimentos e pagamentos: o que já entrou/saiu das contas (realizado) e o
// que está para entrar/sair (previsto: contas a receber, despesas e faturas
// em aberto), com filtros. No previsto mostra o saldo projetado dia a dia.
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

$pdo = getDB();
$hoje = date('Y-m-d');

$tiposMov = [
    // Um item por tipo de recebimento cadastrado (Cadastros › Tipos de recebimento).
    'Recebimentos' => ['titulos' => 'Títulos descontados (todos)']
        + array_combine(array_map(fn ($k) => 'titulo_' . $k, array_keys(tiposTitulo(true))), array_map(fn ($v) => '· ' . $v, tiposTitulo(true)))
        + [
        'residual' => 'Residuais de operação',
        'receber_avulso' => 'Recebimentos avulsos',
        ],
    'Pagamentos' => [
        'despesa' => 'Despesas',
        'fatura' => 'Faturas de cartão',
        'adiantamento' => 'Repasses a clientes',
        'pagamento_cliente' => 'Diferença paga ao cliente (operação)',
    ],
    'Outros' => [
        'transferencia' => 'Transferências entre contas',
        'ajuste' => 'Lançamentos manuais',
    ],
];
$rotuloTipo = array_merge(...array_values($tiposMov));
$rotuloTipo = array_map(fn ($r) => ltrim($r, '· '), $rotuloTipo);

$situacao = in_array($_GET['situacao'] ?? '', ['realizado', 'previsto', 'todos'], true) ? $_GET['situacao'] : 'realizado';
$padraoDe = ['realizado' => date('Y-m-01'), 'previsto' => $hoje, 'todos' => date('Y-m-01')][$situacao];
$padraoAte = ['realizado' => $hoje, 'previsto' => date('Y-m-d', strtotime('+60 days')), 'todos' => date('Y-m-d', strtotime('+60 days'))][$situacao];
[$de, $ate, $deSql, $ateSql] = periodoFiltro($padraoDe, $padraoAte);
$atrasados = isset($_GET['filtrado']) ? isset($_GET['atrasados']) : true;
$direcao = in_array($_GET['direcao'] ?? '', ['entrada', 'saida'], true) ? $_GET['direcao'] : '';
$tipo = ($_GET['tipo'] ?? '') === 'titulos' || isset($rotuloTipo[$_GET['tipo'] ?? '']) ? $_GET['tipo'] : '';
$contaId = idOuNull($_GET['conta'] ?? null);
$categoriaId = idOuNull($_GET['categoria'] ?? null);
$pessoa = trim($_GET['pessoa'] ?? '');

$tipoReceber = "CASE WHEN r.operacao_id IS NULL THEN 'receber_avulso' WHEN r.juros IS NULL THEN 'residual' ELSE CONCAT('titulo_', r.tipo) END";

$realizado = "
    SELECT m.data, 'realizado' AS situacao, m.tipo AS direcao,
        CASE m.origem WHEN 'receber' THEN {$tipoReceber} WHEN 'pagar' THEN 'despesa' WHEN 'fatura' THEN 'fatura'
            WHEN 'adiantamento' THEN 'adiantamento' WHEN 'operacao' THEN 'pagamento_cliente' WHEN 'transferencia' THEN 'transferencia' ELSE 'ajuste' END AS tipo,
        m.descricao, COALESCE(pr.nome, pp.nome, pa.nome, po.nome) AS pessoa_nome,
        m.conta_id, c.nome AS conta_nome, COALESCE(r.categoria_id, cp.categoria_id) AS categoria_id,
        COALESCE(catr.nome, catp.nome, IF(m.origem = 'fatura', 'Cartão de crédito', NULL)) AS categoria_nome,
        m.valor, m.origem, m.origem_id
    FROM movimentacoes m
    JOIN contas_bancarias c ON c.id = m.conta_id
    LEFT JOIN contas_receber r ON m.origem = 'receber' AND r.id = m.origem_id
    LEFT JOIN pessoas pr ON pr.id = r.emitente_id
    LEFT JOIN categorias catr ON catr.id = r.categoria_id
    LEFT JOIN contas_pagar cp ON m.origem = 'pagar' AND cp.id = m.origem_id
    LEFT JOIN pessoas pp ON pp.id = cp.pessoa_id
    LEFT JOIN categorias catp ON catp.id = cp.categoria_id
    LEFT JOIN adiantamentos ad ON m.origem = 'adiantamento' AND ad.id = m.origem_id
    LEFT JOIN pessoas pa ON pa.id = ad.cliente_id
    LEFT JOIN operacoes op ON m.origem = 'operacao' AND op.id = m.origem_id
    LEFT JOIN pessoas po ON po.id = op.cliente_id";

$previsto = "
    SELECT r.data_vencimento AS data, 'previsto' AS situacao, 'entrada' AS direcao, {$tipoReceber} AS tipo,
        CONCAT_WS(' · ', NULLIF(r.descricao, ''), IF(r.numero_titulo IS NULL, NULL, CONCAT('nº ', r.numero_titulo)), IF(r.status = 'devolvido', 'DEVOLVIDO', NULL)) AS descricao,
        pr.nome AS pessoa_nome, r.conta_destino_id AS conta_id, c.nome AS conta_nome, r.categoria_id, catr.nome AS categoria_nome, r.valor, 'receber' AS origem, r.id AS origem_id
    FROM contas_receber r
    LEFT JOIN pessoas pr ON pr.id = r.emitente_id
    LEFT JOIN contas_bancarias c ON c.id = r.conta_destino_id
    LEFT JOIN categorias catr ON catr.id = r.categoria_id
    WHERE r.status IN ('pendente', 'devolvido')
    UNION ALL
    SELECT cp.data_vencimento, 'previsto', 'saida', 'despesa', cp.descricao, pp.nome, cp.conta_id, c.nome, cp.categoria_id, catp.nome,
        cp.valor - COALESCE(cp.valor_pago, 0), 'pagar', cp.id
    FROM contas_pagar cp
    LEFT JOIN pessoas pp ON pp.id = cp.pessoa_id
    LEFT JOIN contas_bancarias c ON c.id = cp.conta_id
    LEFT JOIN categorias catp ON catp.id = cp.categoria_id
    WHERE cp.status = 'pendente'
    UNION ALL
    SELECT f.data_vencimento, 'previsto', 'saida', 'fatura', CONCAT('Fatura ', ca.nome, ' ', f.competencia), NULL, ca.conta_pagamento_id, c.nome, NULL, 'Cartão de crédito',
        SUM(pa.valor) - COALESCE(f.valor_pago, 0), 'fatura', f.id
    FROM cartao_faturas f JOIN cartoes ca ON ca.id = f.cartao_id JOIN cartao_parcelas pa ON pa.fatura_id = f.id
    LEFT JOIN contas_bancarias c ON c.id = ca.conta_pagamento_id
    WHERE f.status = 'aberta'
    GROUP BY f.id";

$partes = [];
$params = [];
if ($situacao !== 'previsto') {
    $partes[] = "SELECT * FROM ({$realizado}) re WHERE re.data BETWEEN ? AND ?";
    array_push($params, $deSql, $ateSql);
}
if ($situacao !== 'realizado') {
    $partes[] = "SELECT * FROM ({$previsto}) pr WHERE pr.valor > 0.004 AND (pr.data BETWEEN ? AND ?" . ($atrasados ? ' OR pr.data < LEAST(?, ?)' : '') . ')';
    array_push($params, $deSql, $ateSql);
    if ($atrasados) {
        array_push($params, $deSql, $hoje);
    }
}

$where = ['1=1'];
if ($direcao) {
    $where[] = 'x.direcao = ?';
    $params[] = $direcao;
}
if ($tipo === 'titulos') {
    $where[] = "x.tipo LIKE 'titulo\\_%'";
} elseif ($tipo) {
    $where[] = 'x.tipo = ?';
    $params[] = $tipo;
} else {
    $where[] = "x.tipo <> 'transferencia'"; // só aparecem escolhendo o tipo (senão inflam entradas e saídas)
}
if ($contaId) {
    $where[] = 'x.conta_id = ?';
    $params[] = $contaId;
}
if ($categoriaId) {
    $where[] = 'x.categoria_id = ?';
    $params[] = $categoriaId;
}
if ($pessoa !== '') {
    $where[] = 'x.pessoa_nome LIKE ?';
    $params[] = "%{$pessoa}%";
}

$sql = 'SELECT * FROM (' . implode(' UNION ALL ', $partes) . ') x WHERE ' . implode(' AND ', $where)
    . " ORDER BY x.data, x.situacao = 'previsto', x.direcao = 'saida', x.origem_id LIMIT 3000";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$linhas = $stmt->fetchAll();

$link = fn ($l) => match ($l['origem']) {
    'receber' => '/receber/baixar.php?id=' . (int) $l['origem_id'],
    'pagar' => '/pagar/baixar.php?id=' . (int) $l['origem_id'],
    'fatura' => '/cartoes/fatura.php?id=' . (int) $l['origem_id'],
    'operacao' => '/operacoes/ver.php?id=' . (int) $l['origem_id'],
    'adiantamento' => '/adiantamentos/listar.php#a' . (int) $l['origem_id'],
    default => '/contas/extrato.php?conta=' . (int) $l['conta_id'],
};
$descricao = fn ($l) => preg_replace_callback('/(\d{4}-\d{2})$/', fn ($m) => nomeMes($m[1]), (string) $l['descricao']);

// Saldo acumulado só faz sentido sem filtros que cortam lançamentos.
$mostrarSaldo = $situacao !== 'todos' && !$direcao && !$tipo && !$categoriaId && $pessoa === '';
$saldoInicial = 0.0;
if ($mostrarSaldo) {
    $contas = contasComSaldo($pdo, false, $situacao === 'realizado' ? date('Y-m-d', strtotime($deSql . ' -1 day')) : null);
    foreach ($contas as $c) {
        if ($contaId ? (int) $c['id'] === $contaId : $c['ativo']) {
            $saldoInicial += (float) $c['saldo'];
        }
    }
}

$entradas = $saidas = 0.0;
$porTipo = $porConta = [];
foreach ($linhas as $l) {
    $v = (float) $l['valor'];
    $l['direcao'] === 'entrada' ? $entradas += $v : $saidas += $v;
    $sinal = $l['direcao'] === 'entrada' ? $v : -$v;
    $porTipo[$l['tipo']] = ($porTipo[$l['tipo']] ?? 0) + $sinal;
    $porConta[$l['conta_nome'] ?? '— sem conta definida —'] = ($porConta[$l['conta_nome'] ?? '— sem conta definida —'] ?? 0) + $sinal;
}

if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="recebimentos-pagamentos-' . ($de ?: 'inicio') . '-a-' . ($ate ?: 'fim') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Data', 'Situação', 'Tipo', 'Descrição', 'Pessoa', 'Conta', 'Categoria', 'Entrada', 'Saída'], ';', '"', '');
    foreach ($linhas as $l) {
        fputcsv($out, [formatDate($l['data']), $l['situacao'] === 'realizado' ? 'Realizado' : 'Previsto', $rotuloTipo[$l['tipo']] ?? $l['tipo'], $descricao($l), $l['pessoa_nome'],
            $l['conta_nome'], $l['categoria_nome'], $l['direcao'] === 'entrada' ? formatDecimal($l['valor']) : '', $l['direcao'] === 'saida' ? formatDecimal($l['valor']) : ''], ';', '"', '');
    }
    exit;
}

$pageTitle = 'Recebimentos e pagamentos';
$pageBreadcrumb = 'Financeiro';
$activeMenu = 'movimentacao';
$pageAction = '<a href="?' . e(http_build_query(array_merge($_GET, ['csv' => 1]))) . '" class="btn btn-outline-secondary"><i class="bi bi-filetype-csv"></i> Planilha</a>'
    . '<button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Imprimir</button>';
require __DIR__ . '/../../includes/layout_header.php';
?>

<ul class="nav nav-tabs mb-3 nao-imprimir">
    <?php foreach (['realizado' => 'Realizado (extrato)', 'previsto' => 'Previsto (a receber e a pagar)', 'todos' => 'Realizado + previsto'] as $k => $v): ?>
        <li class="nav-item"><a class="nav-link <?= $situacao === $k ? 'active' : '' ?>" href="?situacao=<?= $k ?><?= $contaId ? '&conta=' . $contaId : '' ?>"><?= $v ?></a></li>
    <?php endforeach; ?>
</ul>

<div class="filtros nao-imprimir">
    <form method="get">
        <input type="hidden" name="situacao" value="<?= e($situacao) ?>"><input type="hidden" name="filtrado" value="1">
        <?php $dataInicio = $de; $dataFim = $ate; require __DIR__ . '/../../includes/filtro_periodo.php'; ?>
        <select name="direcao" class="form-select form-select-sm" style="width: 140px;">
            <option value="">Entradas e saídas</option>
            <option value="entrada" <?= $direcao === 'entrada' ? 'selected' : '' ?>>Só entradas</option>
            <option value="saida" <?= $direcao === 'saida' ? 'selected' : '' ?>>Só saídas</option>
        </select>
        <select name="tipo" class="form-select form-select-sm" style="width: 210px;">
            <option value="">Todos os tipos</option>
            <?php foreach ($tiposMov as $grupo => $itens): ?>
                <optgroup label="<?= e($grupo) ?>">
                    <?php foreach ($itens as $k => $v): ?><option value="<?= $k ?>" <?= $tipo === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
                </optgroup>
            <?php endforeach; ?>
        </select>
        <select name="conta" class="form-select form-select-sm" style="width: 170px;"><?= opcoesContas($pdo, $contaId, 'Todas as contas') ?></select>
        <select name="categoria" class="form-select form-select-sm" style="width: 170px;">
            <option value="">Todas as categorias</option>
            <?php foreach ($pdo->query('SELECT id, nome, tipo FROM categorias ORDER BY tipo DESC, nome')->fetchAll() as $cat): ?>
                <option value="<?= (int) $cat['id'] ?>" <?= (int) $cat['id'] === $categoriaId ? 'selected' : '' ?>><?= e($cat['nome']) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="pessoa" class="form-control form-control-sm" placeholder="Pessoa" value="<?= e($pessoa) ?>" style="width: 150px;">
        <?php if ($situacao !== 'realizado'): ?>
            <label class="small d-flex align-items-center gap-1"><input type="checkbox" class="form-check-input mt-0" name="atrasados" <?= $atrasados ? 'checked' : '' ?>> com atrasados</label>
        <?php endif; ?>
        <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search"></i> Filtrar</button>
        <a href="?situacao=<?= e($situacao) ?>" class="btn btn-sm btn-link">Limpar</a>
    </form>
</div>

<div class="row g-3 mb-3">
    <?php if ($mostrarSaldo): ?>
    <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
        <div class="kpi-rotulo"><?= $situacao === 'realizado' ? ($de ? 'Saldo em ' . formatDate(date('Y-m-d', strtotime($de . ' -1 day'))) : 'Saldo inicial') : 'Saldo hoje' ?></div>
        <div class="kpi-valor <?= $saldoInicial < 0 ? 'valor-negativo' : '' ?>"><?= formatMoney($saldoInicial) ?></div>
    </div></div></div>
    <?php endif; ?>
    <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="kpi-rotulo">Entradas<?= $situacao === 'previsto' ? ' previstas' : '' ?></div><div class="kpi-valor valor-positivo"><?= formatMoney($entradas) ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="kpi-rotulo">Saídas<?= $situacao === 'previsto' ? ' previstas' : '' ?></div><div class="kpi-valor valor-negativo"><?= formatMoney($saidas) ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
        <div class="kpi-rotulo"><?= $mostrarSaldo ? ($situacao === 'previsto' ? 'Saldo projetado em ' . ($ate ? formatDate($ate) : '...') : 'Saldo em ' . ($ate ? formatDate($ate) : 'hoje')) : 'Resultado' ?></div>
        <?php $final = ($mostrarSaldo ? $saldoInicial : 0) + $entradas - $saidas; ?>
        <div class="kpi-valor <?= $final < 0 ? 'valor-negativo' : '' ?>"><?= formatMoney($final) ?></div>
    </div></div></div>
</div>

<div class="row g-4">
    <div class="col-xl-9">
        <div class="card">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead><tr><th>Data</th><?php if ($situacao === 'todos'): ?><th></th><?php endif; ?><th>Tipo</th><th>Descrição</th><th>Pessoa</th><th>Conta</th><th>Categoria</th><th class="text-end">Entrada</th><th class="text-end">Saída</th><?php if ($mostrarSaldo): ?><th class="text-end">Saldo</th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php if (!$linhas): ?><tr><td colspan="10" class="text-center text-muted py-4">Nada no período com esses filtros.</td></tr><?php endif; ?>
                    <?php $saldo = $saldoInicial; foreach ($linhas as $l): $saldo += $l['direcao'] === 'entrada' ? $l['valor'] : -$l['valor']; $atrasado = $l['situacao'] === 'previsto' && $l['data'] < $hoje; ?>
                        <tr class="cursor-pointer <?= $l['situacao'] === 'previsto' ? 'text-body-secondary' : '' ?>" <?= in_array($l['origem'], ['receber', 'pagar', 'fatura'], true) ? 'data-detalhe="' . e($link($l)) . '"' : 'onclick="window.location=\'' . e($link($l)) . '\'"' ?>>
                            <td class="text-num <?= $atrasado ? 'valor-negativo fw-semibold' : '' ?>"><?= formatDate($l['data']) ?></td>
                            <?php if ($situacao === 'todos'): ?><td><?= $l['situacao'] === 'previsto' ? '<span class="badge text-bg-light">previsto</span>' : '' ?></td><?php endif; ?>
                            <td class="small"><?= e($rotuloTipo[$l['tipo']] ?? $l['tipo']) ?></td>
                            <td><?= e($descricao($l)) ?: '-' ?></td>
                            <td><?= e($l['pessoa_nome'] ?? '-') ?></td>
                            <td><?= e($l['conta_nome'] ?? '—') ?></td>
                            <td class="small"><?= e($l['categoria_nome'] ?? '-') ?></td>
                            <td class="text-end text-num valor-positivo"><?= $l['direcao'] === 'entrada' ? formatMoney($l['valor']) : '' ?></td>
                            <td class="text-end text-num valor-negativo"><?= $l['direcao'] === 'saida' ? formatMoney($l['valor']) : '' ?></td>
                            <?php if ($mostrarSaldo): ?><td class="text-end text-num fw-semibold <?= $saldo < 0 ? 'valor-negativo' : '' ?>"><?= formatMoney($saldo) ?></td><?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php if ($linhas): ?>
                    <tfoot><tr class="fw-semibold"><td colspan="<?= $situacao === 'todos' ? 7 : 6 ?>">Total (<?= count($linhas) ?> lançamentos)</td><td class="text-end text-num"><?= formatMoney($entradas) ?></td><td class="text-end text-num"><?= formatMoney($saidas) ?></td><?php if ($mostrarSaldo): ?><td></td><?php endif; ?></tr></tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
        <?php if (!$tipo): ?><div class="form-text">Transferências entre contas só aparecem escolhendo esse tipo. Linhas em cinza são previstas; data em vermelho = atrasada.</div><?php endif; ?>
    </div>
    <div class="col-xl-3">
        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-tags"></i> Por tipo</div>
            <div class="card"><table class="table table-sm mb-0">
                <?php arsort($porTipo); foreach ($porTipo as $t => $v): ?>
                    <tr><td><a class="text-reset" href="?<?= e(http_build_query(array_merge($_GET, ['tipo' => $t, 'filtrado' => 1]))) ?>"><?= e($rotuloTipo[$t] ?? $t) ?></a></td><td class="text-end text-num <?= $v < 0 ? 'valor-negativo' : 'valor-positivo' ?>"><?= formatMoney($v) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$porTipo): ?><tr><td class="text-muted">-</td></tr><?php endif; ?>
            </table></div>
        </div>
        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-bank"></i> Por conta</div>
            <div class="card"><table class="table table-sm mb-0">
                <?php foreach ($porConta as $c => $v): ?>
                    <tr><td><?= e($c) ?></td><td class="text-end text-num <?= $v < 0 ? 'valor-negativo' : 'valor-positivo' ?>"><?= formatMoney($v) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$porConta): ?><tr><td class="text-muted">-</td></tr><?php endif; ?>
            </table></div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
