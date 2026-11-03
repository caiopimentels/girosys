<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

$pdo = getDB();
$situacao = $_GET['situacao'] ?? 'abertos';
$busca = trim($_GET['busca'] ?? '');
$contaId = idOuNull($_GET['conta'] ?? null);
$tipo = $_GET['tipo'] ?? '';
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
    default => null,
};
if ($busca !== '') {
    $where[] = '(e.nome LIKE ? OR cl.nome LIKE ? OR r.numero_titulo LIKE ? OR r.descricao LIKE ?)';
    array_push($params, "%{$busca}%", "%{$busca}%", "%{$busca}%", "%{$busca}%");
}
if ($contaId) {
    $where[] = 'COALESCE(r.conta_recebimento_id, r.conta_destino_id) = ?';
    $params[] = $contaId;
}
if ($tipo !== '' && array_key_exists($tipo, tiposTitulo(true))) {
    $where[] = 'r.tipo = ?';
    $params[] = $tipo;
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
    'SELECT r.*, e.nome AS emitente_nome, cl.nome AS cliente_nome, cl.taxa_padrao AS cliente_taxa, c.nome AS conta_nome
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
$acoesLaterais = [['Receber selecionados', 'bi-check2-all', 'abrirBaixaLote()']];

$pageTitle = 'Contas a receber';
$pageBreadcrumb = 'Financeiro';
$activeMenu = 'receber';
$pageAction = '<a href="/receber/form.php" class="btn btn-outline-secondary"><i class="bi bi-plus-lg"></i> Lançamento avulso</a>'
    . '<a href="/operacoes/form.php" class="btn btn-brand"><i class="bi bi-calculator"></i> Nova operação</a>';
require __DIR__ . '/../../includes/layout_header.php';
?>

<div class="pedidos-layout"><div class="pedidos-conteudo">
<div class="filtros">
    <form method="get">
        <select name="situacao" class="form-select form-select-sm" style="width: 150px;">
            <?php foreach (['abertos' => 'Em aberto', 'vencidos' => 'Vencidos', 'devolvidos' => 'Devolvidos', 'recebidos' => 'Recebidos', 'transferidos' => 'Devolvidos ao cliente', 'cancelados' => 'Cancelados', 'todos' => 'Todos'] as $k => $v): ?>
                <option value="<?= $k ?>" <?= $situacao === $k ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
        </select>
        <select name="tipo" class="form-select form-select-sm" style="width: 150px;">
            <option value="">Todos os tipos</option>
            <?php foreach (tiposTitulo(true) as $k => $v): ?><option value="<?= $k ?>" <?= $tipo === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
        </select>
        <select name="conta" class="form-select form-select-sm" style="width: 170px;"><?= opcoesContas($pdo, $contaId, 'Todas as contas') ?></select>
        <input type="text" name="busca" class="form-control form-control-sm" placeholder="Pagador, cliente ou nº" value="<?= e($busca) ?>" style="width: 200px;">
        <?php $dataInicio = $de; $dataFim = $ate; $periodoRotulo = $situacao === 'recebidos' ? 'Recebido' : 'Vencimento'; require __DIR__ . '/../../includes/filtro_periodo.php'; ?>
        <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search"></i> Filtrar</button>
    </form>
    <span class="ms-auto text-muted small"><?= count($titulos) ?> título(s) · <strong><?= formatMoney($total) ?></strong><?php if ($totalAtualizado > $total + 0.004): ?> · atualizado hoje <strong class="valor-negativo"><?= formatMoney($totalAtualizado) ?></strong><?php endif; ?></span>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><th style="width: 32px;"><input type="checkbox" class="form-check-input selecao-todos" title="Marcar todos"></th><th>Vencimento</th><th>Tipo</th><th>Pagador</th><th>Cliente</th><th>Nº</th><th class="text-end">Valor</th><th class="text-end">Atualizado hoje</th><th>Conta</th><th>Situação</th><th></th></tr></thead>
            <tbody>
            <?php if (!$titulos): ?>
                <tr><td colspan="11" class="text-center text-muted py-4">Nada por aqui.</td></tr>
            <?php endif; ?>
            <?php foreach ($titulos as $t): ?>
                <?php $podeExcluir = in_array($t['status'], ['pendente', 'devolvido', 'cancelado'], true) && (!$t['vezes_devolvido'] || $t['status'] === 'cancelado'); ?>
                <tr class="cursor-pointer" data-detalhe="/receber/baixar.php?id=<?= (int) $t['id'] ?>">
                    <td><input type="checkbox" class="form-check-input selecao-item" value="<?= (int) $t['id'] ?>"
                        data-valor="<?= e((string) ($t['status'] === 'recebido' ? $t['valor_recebido'] : $t['valor'])) ?>"
                        data-atualizado="<?= e((string) ($t['status'] === 'recebido' ? $t['valor_recebido'] : $atualizacoes[$t['id']]['total'])) ?>"
                        data-aberto="<?= in_array($t['status'], ['pendente', 'devolvido'], true) ? 1 : 0 ?>"></td>
                    <td class="text-num <?= statusTelaReceber($t) === 'vencido' ? 'valor-negativo fw-semibold' : '' ?>"><?= formatDate($t['data_vencimento']) ?></td>
                    <td><?= tipoTituloLabel($t['tipo']) ?></td>
                    <td>
                        <?= e($t['emitente_nome'] ?? $t['descricao'] ?? '-') ?>
                        <?php if (isset($classificacoes[$t['emitente_id']]) && $classificacoes[$t['emitente_id']] !== 'bom' && $classificacoes[$t['emitente_id']] !== 'sem_historico'): ?>
                            <?= badgeClassificacao($classificacoes[$t['emitente_id']]) ?>
                        <?php endif; ?>
                    </td>
                    <td><?= $t['cliente_nome'] ? e($t['cliente_nome']) : '<span class="text-muted">-</span>' ?></td>
                    <td><?= e($t['numero_titulo']) ?: '-' ?></td>
                    <td class="text-end text-num"><?= formatMoney($t['status'] === 'recebido' ? $t['valor_recebido'] : $t['valor']) ?></td>
                    <?php $at = $atualizacoes[$t['id']]; ?>
                    <td class="text-end text-num"><?= $at['dias'] > 0 && $at['total'] > $t['valor'] ? '<span class="valor-negativo fw-semibold">' . formatMoney($at['total']) . '</span>' : '<span class="text-muted">-</span>' ?></td>
                    <td><?= e($t['conta_nome'] ?? '—') ?></td>
                    <td><?= badgeStatusAtraso(statusTelaReceber($t), $at['dias']) ?><?= $t['status'] === 'recebido' ? ' <span class="small text-muted">' . formatDate($t['data_recebimento']) . '</span>' : '' ?></td>
                    <?= acoesLinha('/receber/baixar.php?id=' . (int) $t['id'] . '&editar=1', $podeExcluir ? '/receber/baixar.php?id=' . (int) $t['id'] : null,
                        'Excluir este título?' . ($t['operacao_id'] ? ' Os totais da operação serão recalculados.' : ''), true,
                        match ($t['status']) {
                            'recebido' => 'Já recebido: estorne o recebimento antes de excluir',
                            'transferido' => 'Devolvido ao cliente: desfaça a devolução antes',
                            default => 'Já voltou devolvido: fica no histórico do pagador',
                        }) ?>
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
<script>
// Baixa em lote: só os marcados que ainda estão em aberto (pendentes/devolvidos).
function abrirBaixaLote() {
    const marcados = [...document.querySelectorAll('.selecao-item:checked')].filter((c) => c.dataset.aberto === '1');
    if (!marcados.length) { alert('Marque na lista os títulos em aberto que quer receber.'); return; }
    const total = marcados.reduce((s, c) => s + parseFloat(c.dataset.atualizado), 0);
    document.getElementById('lote-resumo').innerHTML = '<strong>' + marcados.length + ' título(s)</strong> · ' + total.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }) + ' atualizado até hoje';
    document.getElementById('lote-ids').innerHTML = marcados.map((c) => '<input type="hidden" name="ids[]" value="' + c.value + '">').join('');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-lote')).show();
}
</script>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
