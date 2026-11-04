<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

$pdo = getDB();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT cp.*, p.nome AS pessoa_nome, cat.nome AS categoria_nome, c.nome AS conta_nome
     FROM contas_pagar cp LEFT JOIN pessoas p ON p.id = cp.pessoa_id LEFT JOIN categorias cat ON cat.id = cp.categoria_id LEFT JOIN contas_bancarias c ON c.id = cp.conta_id
     WHERE cp.id = ?'
);
$stmt->execute([$id]);
$conta = $stmt->fetch();
if (!$conta) {
    flash('danger', 'Conta não encontrada.');
    redirect('/pagar/listar.php');
}

$rotuloConta = 'conta a pagar #' . $id . ' (' . $conta['descricao'] . ', ' . formatMoney($conta['valor']) . ', vence ' . formatDate($conta['data_vencimento']) . ($conta['pessoa_nome'] ? ', ' . $conta['pessoa_nome'] : '') . ')';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    $pdo->beginTransaction();
    try {
        if ($acao === 'pagar' && $conta['status'] === 'pendente') {
            $data = dataInput('data') ?? throw new InvalidArgumentException('Informe a data do pagamento.');
            registrarPagamentos($pdo, 'pagar', $id, $conta['descricao'], $data, partesPagamentoInput(), isset($_POST['quitar']));
            registrarAtividade('contas_pagar', 'pagamento', 'Pago ' . descreverPartesPagamento(partesPagamentoInput()) . ' em ' . formatDate($data) . (isset($_POST['quitar']) ? ' (quitada)' : '') . ': ' . $rotuloConta);
            flash('success', 'Pagamento lançado.');
        } elseif ($acao === 'estornar_parte') {
            estornarParte($pdo, 'pagar', $id, (int) ($_POST['movimentacao_id'] ?? 0));
            registrarAtividade('contas_pagar', 'estorno', 'Pagamento estornado (lançamento #' . (int) ($_POST['movimentacao_id'] ?? 0) . '): ' . $rotuloConta);
            flash('success', 'Pagamento estornado; o valor voltou para a conta.');
        } elseif ($acao === 'reabrir' && $conta['status'] === 'pago') {
            // Quitada com desconto: volta a pendente sem mexer nas partes pagas.
            $pdo->prepare("UPDATE contas_pagar SET status = 'pendente', data_pagamento = NULL WHERE id = ?")->execute([$id]);
            registrarAtividade('contas_pagar', 'reabertura', 'Conta reaberta: ' . $rotuloConta);
            flash('success', 'Conta reaberta.');
        } elseif ($acao === 'alterar' && $conta['status'] === 'pendente') {
            $venc = dataInput('data_vencimento') ?? $conta['data_vencimento'];
            $pdo->prepare('UPDATE contas_pagar SET descricao = ?, valor = ?, data_vencimento = ?, categoria_id = ?, conta_id = ?, observacao = ? WHERE id = ?')
                ->execute([input('descricao') ?: $conta['descricao'], decimalInput('valor') ?: $conta['valor'], $venc, idOuNull($_POST['categoria_id'] ?? null), idOuNull($_POST['conta_id'] ?? null), input('observacao') ?: null, $id]);
            atualizarSituacaoPagamento($pdo, 'pagar', $id);
            registrarAtividade('contas_pagar', 'edicao', 'Alterada a ' . $rotuloConta . ': ' . descreverAlteracoes(
                ['descricao' => $conta['descricao'], 'valor' => formatMoney($conta['valor']), 'vencimento' => formatDate($conta['data_vencimento']), 'categoria' => $conta['categoria_id'], 'conta' => $conta['conta_id'], 'observacao' => $conta['observacao']],
                ['descricao' => input('descricao') ?: $conta['descricao'], 'valor' => formatMoney(decimalInput('valor') ?: $conta['valor']), 'vencimento' => formatDate($venc), 'categoria' => idOuNull($_POST['categoria_id'] ?? null), 'conta' => idOuNull($_POST['conta_id'] ?? null), 'observacao' => input('observacao') ?: null],
                ['descricao' => 'Descrição', 'valor' => 'Valor', 'vencimento' => 'Vencimento', 'categoria' => 'Categoria (id)', 'conta' => 'Conta (id)', 'observacao' => 'Observação']));
            flash('success', 'Conta alterada.');
        } elseif ($acao === 'excluir' && !(float) $conta['valor_pago']) {
            $pdo->prepare('DELETE FROM contas_pagar WHERE id = ?')->execute([$id]);
            registrarAtividade('contas_pagar', 'exclusao', 'Excluída a ' . $rotuloConta);
            $pdo->commit();
            flash('success', 'Conta excluída.');
            redirecionarAposAcao('/pagar/listar.php');
        }
        $pdo->commit();
    } catch (InvalidArgumentException $e) {
        $pdo->rollBack();
        flash('danger', $e->getMessage());
        redirecionarAposAcao('/pagar/baixar.php?id=' . $id, true);
    }
    redirecionarAposAcao('/pagar/baixar.php?id=' . $id);
}

$pagamentos = pagamentosFeitos($pdo, 'pagar', $id);
$pago = array_sum(array_column($pagamentos, 'valor'));
$falta = max(0, round($conta['valor'] - $pago, 2));
$status = statusTelaPagar($conta);
$pageTitle = $conta['descricao'];
$pageBreadcrumb = 'Financeiro · Contas a pagar';
$activeMenu = 'pagar';
$pageAction = '<a href="/pagar/listar.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Voltar</a>';
require __DIR__ . '/../../includes/layout_header.php';
?>

<div style="max-width: 900px;">
    <?php // Em cima: o que é a conta. ?>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <?= badgeStatus($status) ?>
        <span class="text-muted small"><?= e($conta['categoria_nome'] ?? 'Sem categoria') ?><?= $conta['pessoa_nome'] ? ' · ' . e($conta['pessoa_nome']) : '' ?></span>
        <span class="ms-auto d-flex gap-2">
            <?php if ($conta['status'] === 'pendente'): ?>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="const d = document.getElementById('editar-conta'); d.open = !d.open; d.scrollIntoView({block: 'nearest'});"><i class="bi bi-pencil"></i> Editar</button>
            <?php endif; ?>
            <?php if (!$pagamentos): ?>
                <form method="post" class="d-inline" onsubmit="return confirm('Excluir esta conta a pagar?');"><input type="hidden" name="acao" value="excluir"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Excluir</button></form>
            <?php endif; ?>
        </span>
    </div>
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3"><div class="kpi-rotulo">Valor</div><div class="fw-semibold text-num"><?= formatMoney($conta['valor']) ?></div></div>
        <div class="col-6 col-md-3"><div class="kpi-rotulo">Vencimento</div><div class="fw-semibold <?= $status === 'vencido' ? 'valor-negativo' : '' ?>"><?= formatDate($conta['data_vencimento']) ?></div></div>
        <div class="col-6 col-md-3"><div class="kpi-rotulo">Pago</div><div class="fw-semibold text-num"><?= formatMoney($pago) ?></div></div>
        <div class="col-6 col-md-3"><div class="kpi-rotulo"><?= $conta['status'] === 'pago' ? 'Quitada em' : 'Falta' ?></div><div class="fw-semibold text-num <?= $conta['status'] === 'pago' ? 'valor-positivo' : 'valor-negativo' ?>"><?= $conta['status'] === 'pago' ? formatDate($conta['data_pagamento']) : formatMoney($falta) ?></div></div>
        <?php if ($conta['observacao']): ?><div class="col-12 small text-muted"><?= nl2br(e($conta['observacao'])) ?></div><?php endif; ?>
    </div>

    <?php if ($conta['status'] === 'pendente'): ?>
    <details class="card mb-3" id="editar-conta" <?= !empty($_GET['editar']) ? 'open' : '' ?>><summary class="card-body py-2 fw-semibold small">Editar dados da conta</summary>
        <div class="card-body pt-0">
            <form method="post">
                <input type="hidden" name="acao" value="alterar">
                <div class="row g-2">
                    <div class="col-md-6"><label class="form-label small">Descrição</label><input type="text" name="descricao" class="form-control" value="<?= e($conta['descricao']) ?>"></div>
                    <div class="col-md-3"><label class="form-label small">Valor</label><input type="text" name="valor" class="form-control" data-moeda value="<?= formatDecimal($conta['valor']) ?>"></div>
                    <div class="col-md-3"><label class="form-label small">Vencimento</label><input type="date" name="data_vencimento" class="form-control" value="<?= e($conta['data_vencimento']) ?>"></div>
                    <div class="col-md-6"><label class="form-label small">Categoria</label><select name="categoria_id" class="form-select"><?= opcoesCategorias($pdo, 'despesa', idOuNull($conta['categoria_id'])) ?></select></div>
                    <div class="col-md-6"><label class="form-label small">Pagar pela conta</label><select name="conta_id" class="form-select"><?= opcoesContas($pdo, idOuNull($conta['conta_id']), '—') ?></select></div>
                    <div class="col-12"><label class="form-label small">Observação</label><textarea name="observacao" class="form-control" rows="2"><?= e($conta['observacao']) ?></textarea></div>
                    <div class="col-12 text-end"><button class="btn btn-sm btn-brand"><i class="bi bi-check-lg"></i> Salvar alterações</button></div>
                </div>
            </form>
        </div>
    </details>
    <?php endif; ?>

    <?= listaPagamentosFeitos($pagamentos) ?>

    <?php // Embaixo: a forma de pagamento. ?>
    <?php if ($conta['status'] === 'pendente'): ?>
    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-wallet2"></i> Forma de pagamento <?= $pago > 0 ? '· falta ' . formatMoney($falta) : '' ?></div>
        <div class="card"><div class="card-body"><?= formPagamento($pdo, $falta, idOuNull($conta['conta_id'])) ?></div></div>
    </div>
    <?php elseif ($conta['status'] === 'pago' && $pago < $conta['valor'] - 0.004): ?>
    <div class="alert alert-light border small">Quitada com desconto de <?= formatMoney($conta['valor'] - $pago) ?>.
        <form method="post" class="d-inline"><input type="hidden" name="acao" value="reabrir"><button class="btn btn-link btn-sm p-0">Reabrir</button></form></div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
