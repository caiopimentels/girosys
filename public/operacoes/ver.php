<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

$pdo = getDB();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir') {
    try {
        $resumo = resumoOperacaoAuditoria($pdo, $id);
        excluirOperacao($pdo, $id);
        registrarAtividade('operacoes', 'exclusao', 'Excluída a ' . $resumo);
        flash('success', 'Operação #' . $id . ' excluída. Títulos removidos e repasses liberados.');
        redirecionarAposAcao('/operacoes/listar.php');
    } catch (InvalidArgumentException $e) {
        flash('danger', $e->getMessage());
        redirecionarAposAcao('/operacoes/ver.php?id=' . $id);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['acao'] ?? '', ['pagamento_cliente', 'desfazer_pagamento_cliente'], true)) {
    $pdo->beginTransaction();
    try {
        if ($_POST['acao'] === 'pagamento_cliente') {
            $modo = input('pagamento_cliente') === 'agendado' ? 'agendado' : 'pago';
            $data = dataInput('data_pagamento_cliente') ?? throw new InvalidArgumentException($modo === 'pago' ? 'Informe a data do pagamento.' : 'Informe a data prevista.');
            registrarPagamentoCliente($pdo, $id, $modo, $data, idOuNull($_POST['conta_pagamento_id'] ?? null));
            registrarAtividade('operacoes', 'pagamento', 'Pagamento ao cliente: ' . resumoOperacaoAuditoria($pdo, $id) . descricaoPagamentoClienteAuditoria($pdo, $id));
            flash('success', $modo === 'pago' ? 'Pagamento ao cliente lançado.' : 'Pagamento ao cliente agendado em Contas a pagar.');
        } else {
            $antes = descricaoPagamentoClienteAuditoria($pdo, $id);
            desfazerPagamentoCliente($pdo, $id);
            registrarAtividade('operacoes', 'estorno', 'Pagamento ao cliente desfeito (antes:' . $antes . '): ' . resumoOperacaoAuditoria($pdo, $id));
            flash('success', 'Pagamento ao cliente desfeito: a diferença voltou para "não lançada".');
        }
        $pdo->commit();
    } catch (InvalidArgumentException $e) {
        $pdo->rollBack();
        flash('danger', $e->getMessage());
    }
    redirecionarAposAcao('/operacoes/ver.php?id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'editar') {
    $dataBase = dataInput('data_base');
    $taxa = paraDecimal(input('taxa'));
    if (!$dataBase || $taxa < 0 || $taxa > 100) {
        flash('danger', 'Informe data base e taxa válidas.');
    } else {
        $antes = resumoOperacaoAuditoria($pdo, $id);
        $pdo->beginTransaction();
        editarOperacao($pdo, $id, $dataBase, $taxa, (int) input('dias_compensacao', '0'), input('observacao'));
        registrarAtividade('operacoes', 'recalculo', 'Recalculada: antes ' . $antes . ' · agora ' . preg_replace('/^operação #\d+ de [^(]+/', '', resumoOperacaoAuditoria($pdo, $id)));
        $pdo->commit();
        flash('success', 'Operação recalculada com os novos dados. A diferença já paga ao cliente e o residual lançado não mudam.');
    }
    redirect('/operacoes/ver.php?id=' . $id);
}

$stmt = $pdo->prepare(
    'SELECT o.*, p.nome AS cliente_nome, p.documento AS cliente_documento, c.nome AS conta_pagamento_nome
     FROM operacoes o JOIN pessoas p ON p.id = o.cliente_id LEFT JOIN contas_bancarias c ON c.id = o.conta_pagamento_id
     WHERE o.id = ?'
);
$stmt->execute([$id]);
$op = $stmt->fetch();
if (!$op) {
    flash('danger', 'Operação não encontrada.');
    redirect('/operacoes/listar.php');
}

$stmt = $pdo->prepare(
    'SELECT r.*, e.nome AS emitente_nome, e.documento AS emitente_documento, c.nome AS conta_nome
     FROM contas_receber r LEFT JOIN pessoas e ON e.id = r.emitente_id LEFT JOIN contas_bancarias c ON c.id = r.conta_destino_id
     WHERE r.operacao_id = ? ORDER BY r.data_vencimento, r.id'
);
$stmt->execute([$id]);
$titulos = $stmt->fetchAll();
// O residual (cliente ainda deve) também é conta a receber da operação, mas não é título descontado.
$residual = current(array_filter($titulos, fn ($t) => $t['juros'] === null)) ?: null;
$titulos = array_values(array_filter($titulos, fn ($t) => $t['juros'] !== null));

$stmt = $pdo->prepare(
    'SELECT oa.*, a.data, a.descricao, c.nome AS conta_nome
     FROM operacao_adiantamentos oa JOIN adiantamentos a ON a.id = oa.adiantamento_id LEFT JOIN contas_bancarias c ON c.id = a.conta_id
     WHERE oa.operacao_id = ? ORDER BY a.data'
);
$stmt->execute([$id]);
$usados = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT * FROM adiantamentos WHERE operacao_origem_id = ?');
$stmt->execute([$id]);
$sobra = $stmt->fetch();

$pagCliente = pagamentoClienteOperacao($pdo, $op);

$recebido = array_sum(array_map(fn ($t) => $t['status'] === 'recebido' ? (float) $t['valor_recebido'] : 0, $titulos));
$podeExcluir = !array_filter(array_filter([...$titulos, $residual]), fn ($t) => $t['status'] === 'recebido' || $t['status'] === 'devolvido' || $t['vezes_devolvido'] > 0);

$pageTitle = 'Operação #' . $op['id'] . ' · ' . $op['cliente_nome'];
$pageBreadcrumb = 'Operações · ' . formatDate($op['data_base']);
$activeMenu = 'operacoes';
$pageAction = '<button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Imprimir borderô</button>'
    . ($podeExcluir ? '<form method="post" class="d-inline" onsubmit="return confirm(\'Excluir a operação inteira? Os títulos saem de Contas a receber e os repasses voltam a ficar em aberto.\');"><input type="hidden" name="acao" value="excluir"><input type="hidden" name="id" value="' . (int) $op['id'] . '"><button class="btn btn-outline-danger"><i class="bi bi-trash"></i> Excluir</button></form>' : '')
    . '<a href="/operacoes/form.php?cliente=' . (int) $op['cliente_id'] . '" class="btn btn-brand"><i class="bi bi-plus-lg"></i> Nova operação deste cliente</a>';
require __DIR__ . '/../../includes/layout_header.php';
?>

<details class="card mb-3 nao-imprimir" id="editar-operacao" <?= !empty($_GET['editar']) ? 'open' : '' ?>>
    <summary class="card-body py-2 fw-semibold small"><i class="bi bi-pencil"></i> Editar operação (data base, taxa, compensação)</summary>
    <div class="card-body pt-0">
        <form method="post" class="row g-2 align-items-end">
            <input type="hidden" name="acao" value="editar">
            <div class="col-md-3"><label class="form-label small">Data base</label><input type="date" name="data_base" class="form-control" required value="<?= e($op['data_base']) ?>"></div>
            <div class="col-md-2"><label class="form-label small">Taxa (% a.m.)</label><input type="text" name="taxa" class="form-control" inputmode="decimal" required value="<?= e(rtrim(rtrim(formatDecimal($op['taxa'], 4), '0'), ',')) ?>"></div>
            <div class="col-md-2"><label class="form-label small">Dias compensação</label><input type="number" name="dias_compensacao" class="form-control" min="0" max="30" value="<?= (int) $op['dias_compensacao'] ?>"></div>
            <div class="col-md-5"><label class="form-label small">Observação</label><input type="text" name="observacao" class="form-control" value="<?= e($op['observacao']) ?>"></div>
            <div class="col-12 d-flex justify-content-between align-items-center">
                <span class="form-text m-0">Recalcula os juros de todos os títulos e a correção dos repasses. Para mudar um título (valor, vencimento, pagador), clique nele abaixo.</span>
                <button class="btn btn-sm btn-brand"><i class="bi bi-check-lg"></i> Salvar e recalcular</button>
            </div>
        </form>
    </div>
</details>

<?php if ($pagCliente['situacao'] === 'nao_lancado'): ?>
<div class="card mb-3 border-warning nao-imprimir"><div class="card-body">
    <div class="fw-semibold mb-2"><i class="bi bi-wallet2"></i> Diferença de <?= formatMoney($op['saldo']) ?> ao cliente: já pagou ou vai pagar quando?</div>
    <form method="post" class="row g-2 align-items-end">
        <input type="hidden" name="acao" value="pagamento_cliente">
        <div class="col-md-4">
            <div class="form-check"><input type="radio" class="form-check-input" name="pagamento_cliente" id="pc-pago" value="pago" checked><label class="form-check-label small" for="pc-pago">Já foi pago (sai da conta)</label></div>
            <div class="form-check"><input type="radio" class="form-check-input" name="pagamento_cliente" id="pc-agendado" value="agendado"><label class="form-check-label small" for="pc-agendado">Agendar (vira conta a pagar)</label></div>
        </div>
        <div class="col-md-3"><label class="form-label small mb-0">Data (paga ou prevista)</label><input type="date" name="data_pagamento_cliente" class="form-control form-control-sm" value="<?= e($op['data_base']) ?>" required></div>
        <div class="col-md-3"><label class="form-label small mb-0">Conta</label><select name="conta_pagamento_id" class="form-select form-select-sm"><?= opcoesContas($pdo, null, 'Sai de qual conta?') ?></select></div>
        <div class="col-md-2"><button class="btn btn-sm btn-brand w-100"><i class="bi bi-check-lg"></i> Lançar</button></div>
    </form>
</div></div>
<?php elseif (in_array($pagCliente['situacao'], ['pago', 'agendado'], true) && (!$pagCliente['conta_pagar'] || $pagCliente['situacao'] === 'agendado')): ?>
<div class="text-end small mb-2 nao-imprimir">
    <form method="post" class="d-inline" onsubmit="return confirm('<?= $pagCliente['situacao'] === 'pago' ? 'Desfazer o pagamento ao cliente? A saída é estornada da conta e a diferença volta a ficar a pagar.' : 'Desfazer o agendamento? A conta a pagar é excluída.' ?>');">
        <input type="hidden" name="acao" value="desfazer_pagamento_cliente">
        <button class="btn btn-link btn-sm p-0 text-muted"><?= $pagCliente['situacao'] === 'pago' ? 'Não foi pago? Desfazer o pagamento ao cliente' : 'Desfazer o agendamento do pagamento ao cliente' ?></button>
    </form>
</div>
<?php endif; ?>

<div class="secao">
    <div class="dados-lista">
        <div class="dados-item"><span class="dados-rotulo">Cliente</span><span class="dados-valor"><a href="/pessoas/form.php?id=<?= (int) $op['cliente_id'] ?>" class="text-reset"><?= e($op['cliente_nome']) ?></a> <?= $op['cliente_documento'] ? '· ' . e(documentoFormatado($op['cliente_documento'])) : '' ?></span></div>
        <div class="dados-item"><span class="dados-rotulo">Data base</span><span class="dados-valor"><?= formatDate($op['data_base']) ?></span></div>
        <div class="dados-item"><span class="dados-rotulo">Taxa</span><span class="dados-valor"><?= formatPercent($op['taxa']) ?> ao mês</span></div>
        <div class="dados-item"><span class="dados-rotulo">Dias de compensação</span><span class="dados-valor"><?= (int) $op['dias_compensacao'] ?></span></div>
        <div class="dados-item"><span class="dados-rotulo">Registrada em</span><span class="dados-valor"><?= formatDateTime($op['criado_em']) ?></span></div>
        <?php if ($op['observacao']): ?><div class="dados-item"><span class="dados-rotulo">Observação</span><span class="dados-valor"><?= nl2br(e($op['observacao'])) ?></span></div><?php endif; ?>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="kpi-rotulo">Valor dos títulos</div><div class="kpi-valor"><?= formatMoney($op['total_bruto']) ?></div><div class="small text-muted">Líquido: <?= formatMoney($op['total_liquido']) ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="kpi-rotulo">Repasses corrigidos</div><div class="kpi-valor"><?= formatMoney($op['emprestado_atualizado']) ?></div><div class="small text-muted"><?= formatMoney($op['valor_emprestado']) ?> + <?= formatMoney($op['juros_emprestimo']) ?> de juros</div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
        <div class="kpi-rotulo"><?= $op['saldo'] < 0 ? 'Cliente continua devendo' : 'Diferença ao cliente' ?></div>
        <div class="kpi-valor <?= $op['saldo'] < 0 ? 'valor-negativo' : '' ?>"><?= formatMoney(abs($op['saldo'])) ?></div>
        <div class="small text-muted">
            <?php if ($pagCliente['situacao'] === 'pago' && !$pagCliente['conta_pagar']): ?><span class="badge text-bg-success">Pago</span> em <?= formatDate($pagCliente['data']) ?> de <?= e($op['conta_pagamento_nome']) ?>
            <?php elseif ($pagCliente['situacao'] === 'pago'): ?><span class="badge text-bg-success">Pago</span> em <?= formatDate($pagCliente['data']) ?> · <a href="/pagar/baixar.php?id=<?= (int) $pagCliente['conta_pagar']['id'] ?>">conta a pagar #<?= (int) $pagCliente['conta_pagar']['id'] ?></a>
            <?php elseif ($pagCliente['situacao'] === 'agendado' || $pagCliente['situacao'] === 'parcial'): ?>
                <?= badgeStatus(statusTelaPagar($pagCliente['conta_pagar'])) ?> <?= $pagCliente['situacao'] === 'parcial' ? 'pago ' . formatMoney($pagCliente['pago']) . ' · ' : '' ?>agendado para <?= formatDate($pagCliente['data']) ?>
                <div class="mt-1 nao-imprimir"><a href="/pagar/baixar.php?id=<?= (int) $pagCliente['conta_pagar']['id'] ?>" class="btn btn-sm btn-outline-success"><i class="bi bi-cash-coin"></i> Pagar agora</a></div>
            <?php elseif ($pagCliente['situacao'] === 'nao_lancado'): ?><span class="badge text-bg-warning">A pagar</span> pagamento não lançado
            <?php elseif ($sobra): ?>Ficou como saldo devedor (repasse em aberto)
            <?php elseif ($residual): ?><a href="/receber/baixar.php?id=<?= (int) $residual['id'] ?>">Conta a receber</a> vencendo <?= formatDate($residual['data_vencimento']) ?> · <?= badgeStatus(statusTelaReceber($residual)) ?><?php endif; ?>
        </div>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="kpi-rotulo">Lucro da operação</div><div class="kpi-valor valor-positivo"><?= formatMoney($op['total_juros'] + $op['juros_emprestimo']) ?></div><div class="small text-muted">Recebido até agora: <?= formatMoney($recebido) ?></div></div></div></div>
</div>

<div class="secao">
    <div class="secao-titulo"><i class="bi bi-receipt"></i> Títulos</div>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead><tr><th>Tipo</th><th>Pagador</th><th>Banco / nº</th><th>Vencimento</th><th class="text-end">Valor</th><th class="text-end">Dias</th><th class="text-end">Juros</th><th class="text-end">Líquido</th><th>Conta</th><th class="nao-imprimir">Situação</th></tr></thead>
                <tbody>
                <?php foreach ($titulos as $t): ?>
                    <tr class="cursor-pointer" data-detalhe="/receber/baixar.php?id=<?= (int) $t['id'] ?>">
                        <td><?= tipoTituloLabel($t['tipo']) ?></td>
                        <td><?= e($t['emitente_nome']) ?><?= $t['emitente_documento'] ? '<div class="small text-muted">' . e(documentoFormatado($t['emitente_documento'])) . '</div>' : '' ?></td>
                        <td><?= e(trim($t['banco_titulo'] . ' ' . $t['numero_titulo'])) ?: '-' ?></td>
                        <td class="text-num"><?= formatDate($t['data_vencimento']) ?></td>
                        <td class="text-end text-num"><?= formatMoney($t['valor']) ?></td>
                        <td class="text-end"><?= (int) $t['dias'] ?></td>
                        <td class="text-end text-num"><?= formatMoney($t['juros']) ?></td>
                        <td class="text-end text-num fw-semibold"><?= formatMoney($t['valor_liquido']) ?></td>
                        <td><?= e($t['conta_nome'] ?? '—') ?></td>
                        <td class="nao-imprimir"><?= badgeStatus(statusTelaReceber($t)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr class="fw-semibold"><td colspan="4">Total</td><td class="text-end text-num"><?= formatMoney($op['total_bruto']) ?></td><td></td><td class="text-end text-num"><?= formatMoney($op['total_juros']) ?></td><td class="text-end text-num"><?= formatMoney($op['total_liquido']) ?></td><td colspan="2"></td></tr></tfoot>
            </table>
        </div>
    </div>
</div>

<?php if ($usados): ?>
<div class="secao">
    <div class="secao-titulo"><i class="bi bi-cash-coin"></i> Repasses quitados nesta operação</div>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead><tr><th>Data</th><th>Descrição</th><th>Saiu de</th><th class="text-end">Valor usado</th><th class="text-end">Dias</th><th class="text-end">Juros</th><th class="text-end">Corrigido</th></tr></thead>
                <tbody>
                <?php foreach ($usados as $u): ?>
                    <tr>
                        <td class="text-num"><?= formatDate($u['data']) ?></td>
                        <td><?= e($u['descricao']) ?: '-' ?></td>
                        <td><?= e($u['conta_nome'] ?? '—') ?></td>
                        <td class="text-end text-num"><?= formatMoney($u['valor_usado']) ?></td>
                        <td class="text-end"><?= (int) $u['dias'] ?></td>
                        <td class="text-end text-num"><?= formatMoney($u['juros']) ?></td>
                        <td class="text-end text-num fw-semibold"><?= formatMoney($u['valor_usado'] + $u['juros']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr class="fw-semibold"><td colspan="3">Total</td><td class="text-end text-num"><?= formatMoney($op['valor_emprestado']) ?></td><td></td><td class="text-end text-num"><?= formatMoney($op['juros_emprestimo']) ?></td><td class="text-end text-num"><?= formatMoney($op['emprestado_atualizado']) ?></td></tr></tfoot>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!$podeExcluir): ?>
    <div class="small text-muted nao-imprimir">Para excluir a operação, estorne antes os títulos já recebidos ou devolvidos.</div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
