<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir') {
    $stmt = $pdo->prepare('SELECT * FROM adiantamentos WHERE id = ?');
    $stmt->execute([(int) ($_POST['id'] ?? $_GET['id'] ?? 0)]);
    $a = $stmt->fetch();
    if (!$a) {
        flash('danger', 'Adiantamento não encontrado.');
    } elseif ((float) $a['valor_utilizado'] > 0) {
        flash('danger', 'Este repasse já foi usado numa operação. Exclua a operação primeiro.');
    } elseif ($a['titulo_origem_id']) {
        flash('danger', 'Este repasse veio de um título devolvido para o cliente. Para tirar, desfaça a devolução no título.');
    } elseif ($a['operacao_origem_id']) {
        flash('danger', 'Este é o saldo devedor da operação #' . $a['operacao_origem_id'] . '. Ele sai junto se a operação for excluída.');
    } else {
        $pdo->beginTransaction();
        removerMovimentacoes($pdo, 'adiantamento', (int) $a['id']);
        $pdo->prepare('DELETE FROM adiantamentos WHERE id = ?')->execute([$a['id']]);
        $pdo->commit();
        flash('success', 'Repasse excluído; o valor voltou para a conta.');
    }
    redirect('/adiantamentos/listar.php');
}

$situacao = $_GET['situacao'] ?? 'aberto';
$busca = trim($_GET['busca'] ?? '');
$where = ['1=1'];
$params = [];
if ($situacao === 'aberto') {
    $where[] = 'a.valor - a.valor_utilizado > 0.004';
} elseif ($situacao === 'quitado') {
    $where[] = 'a.valor - a.valor_utilizado <= 0.004';
}
if ($busca !== '') {
    $where[] = 'p.nome LIKE ?';
    $params[] = "%{$busca}%";
}

$stmt = $pdo->prepare(
    'SELECT a.*, p.nome AS cliente_nome, p.taxa_padrao, c.nome AS conta_nome, pg.nome AS pagador_nome
     FROM adiantamentos a JOIN pessoas p ON p.id = a.cliente_id LEFT JOIN contas_bancarias c ON c.id = a.conta_id LEFT JOIN pessoas pg ON pg.id = a.pagador_id
     WHERE ' . implode(' AND ', $where) . ' ORDER BY a.data DESC, a.id DESC LIMIT 500'
);
$stmt->execute($params);
$adiantamentos = $stmt->fetchAll();

// Total em aberto por cliente, com o valor corrigido até hoje pela taxa dele.
$porCliente = [];
$hoje = date('Y-m-d');
foreach ($pdo->query('SELECT a.*, p.nome AS cliente_nome, p.taxa_padrao FROM adiantamentos a JOIN pessoas p ON p.id = a.cliente_id WHERE a.valor - a.valor_utilizado > 0.004')->fetchAll() as $a) {
    $aberto = $a['valor'] - $a['valor_utilizado'];
    $c = &$porCliente[$a['cliente_id']];
    $c['nome'] = $a['cliente_nome'];
    $c['taxa'] = $a['taxa_padrao'];
    $c['aberto'] = ($c['aberto'] ?? 0) + $aberto;
    $c['corrigido'] = ($c['corrigido'] ?? 0) + $aberto + jurosSimples($aberto, (float) $a['taxa_padrao'], max(0, diasEntre($a['data'], $hoje)));
    unset($c);
}
uasort($porCliente, fn ($a, $b) => $b['aberto'] <=> $a['aberto']);

$pageTitle = 'Repasses a clientes';
$pageBreadcrumb = 'Operações';
$activeMenu = 'adiantamentos';
$pageAction = '<a href="/adiantamentos/form.php" class="btn btn-brand"><i class="bi bi-plus-lg"></i> Novo repasse</a>';
require __DIR__ . '/../../includes/layout_header.php';
?>

<?php if ($porCliente): ?>
<div class="secao">
    <div class="secao-titulo"><i class="bi bi-people"></i> Em aberto por cliente</div>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead><tr><th>Cliente</th><th class="text-end">Taxa</th><th class="text-end">Em aberto</th><th class="text-end">Corrigido até hoje</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($porCliente as $id => $c): ?>
                    <tr>
                        <td><a href="/pessoas/form.php?id=<?= (int) $id ?>&tab=cliente" class="text-reset"><?= e($c['nome']) ?></a></td>
                        <td class="text-end"><?= $c['taxa'] !== null ? formatPercent($c['taxa']) : '<span class="text-muted small">sem taxa</span>' ?></td>
                        <td class="text-end text-num"><?= formatMoney($c['aberto']) ?></td>
                        <td class="text-end text-num fw-semibold"><?= formatMoney($c['corrigido']) ?></td>
                        <td class="text-end"><a href="/operacoes/form.php?cliente=<?= (int) $id ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-calculator"></i> Receber títulos</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr class="fw-semibold"><td colspan="2">Total</td><td class="text-end text-num"><?= formatMoney(array_sum(array_column($porCliente, 'aberto'))) ?></td><td class="text-end text-num"><?= formatMoney(array_sum(array_column($porCliente, 'corrigido'))) ?></td><td></td></tr></tfoot>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="filtros">
    <form method="get">
        <input type="text" name="busca" class="form-control form-control-sm" placeholder="Cliente" value="<?= e($busca) ?>" style="width: 240px;">
        <select name="situacao" class="form-select form-select-sm" style="width: 170px;">
            <option value="aberto" <?= $situacao === 'aberto' ? 'selected' : '' ?>>Em aberto</option>
            <option value="quitado" <?= $situacao === 'quitado' ? 'selected' : '' ?>>Quitados</option>
            <option value="todos" <?= $situacao === 'todos' ? 'selected' : '' ?>>Todos</option>
        </select>
        <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search"></i> Filtrar</button>
    </form>
    <span class="ms-auto text-muted small"><?= count($adiantamentos) ?> registro(s)</span>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><th>Data</th><th>Cliente</th><th>Pagador</th><th>Descrição</th><th>Saiu de</th><th class="text-end">Valor</th><th class="text-end">Usado</th><th class="text-end">Em aberto</th><th></th></tr></thead>
            <tbody>
            <?php if (!$adiantamentos): ?>
                <tr><td colspan="9" class="text-center text-muted py-4">Nenhum repasse.</td></tr>
            <?php endif; ?>
            <?php foreach ($adiantamentos as $a): $aberto = $a['valor'] - $a['valor_utilizado']; ?>
                <tr id="a<?= (int) $a['id'] ?>">
                    <td class="text-num"><?= formatDate($a['data']) ?></td>
                    <td><a href="/pessoas/form.php?id=<?= (int) $a['cliente_id'] ?>&tab=cliente" class="text-reset"><?= e($a['cliente_nome']) ?></a></td>
                    <td><?= $a['pagador_id'] ? '<a href="/pessoas/form.php?id=' . (int) $a['pagador_id'] . '" class="text-reset">' . e($a['pagador_nome']) . '</a>' : '<span class="text-muted">-</span>' ?></td>
                    <td>
                        <?= e($a['descricao']) ?: '-' ?>
                        <?php if ($a['operacao_origem_id']): ?> <a href="/operacoes/ver.php?id=<?= (int) $a['operacao_origem_id'] ?>" class="small">ver operação</a><?php endif; ?>
                        <?php if ($a['titulo_origem_id']): ?> <a href="#" onclick="event.preventDefault(); abrirDetalhe('/receber/baixar.php?id=<?= (int) $a['titulo_origem_id'] ?>')" class="small">ver título</a><?php endif; ?>
                    </td>
                    <td><?= e($a['conta_nome'] ?? '—') ?></td>
                    <td class="text-end text-num"><?= formatMoney($a['valor']) ?></td>
                    <td class="text-end text-num"><?= formatMoney($a['valor_utilizado']) ?></td>
                    <td class="text-end text-num <?= $aberto > 0.004 ? 'fw-semibold valor-negativo' : 'text-muted' ?>"><?= formatMoney($aberto) ?></td>
                    <?= acoesLinha('/adiantamentos/form.php?id=' . (int) $a['id'],
                        (float) $a['valor_utilizado'] == 0 && !$a['operacao_origem_id'] && !$a['titulo_origem_id'] ? '/adiantamentos/listar.php?id=' . (int) $a['id'] : null,
                        'Excluir este repasse? O valor volta para a conta de onde saiu.', false,
                        $a['titulo_origem_id'] ? 'Veio de título devolvido ao cliente: desfaça no título' : ($a['operacao_origem_id'] ? 'Saldo devedor de operação: sai junto se a operação for excluída' : 'Já usado numa operação: exclua a operação primeiro')) ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
