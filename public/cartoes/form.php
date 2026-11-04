<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

$pdo = getDB();
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$cartao = ['nome' => '', 'bandeira' => '', 'dia_fechamento' => '', 'dia_vencimento' => '', 'limite' => null, 'conta_pagamento_id' => null, 'ativo' => 1];
if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM cartoes WHERE id = ?');
    $stmt->execute([$id]);
    $cartao = $stmt->fetch() ?: redirect('/cartoes/listar.php');
}

$erros = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cartaoAntes = $cartao;
    $cartao = [
        'nome' => input('nome'),
        'bandeira' => input('bandeira'),
        'dia_fechamento' => (int) input('dia_fechamento'),
        'dia_vencimento' => (int) input('dia_vencimento'),
        'limite' => input('limite') === '' ? null : decimalInput('limite'),
        'conta_pagamento_id' => idOuNull($_POST['conta_pagamento_id'] ?? null),
        'ativo' => isset($_POST['ativo']) ? 1 : 0,
    ];
    if ($cartao['nome'] === '') {
        $erros[] = 'Informe o nome do cartão.';
    }
    if ($cartao['dia_fechamento'] < 1 || $cartao['dia_fechamento'] > 31 || $cartao['dia_vencimento'] < 1 || $cartao['dia_vencimento'] > 31) {
        $erros[] = 'Dias de fechamento e vencimento devem ser de 1 a 31.';
    }
    if (!$erros) {
        $valores = [$cartao['nome'], $cartao['bandeira'] ?: null, $cartao['dia_fechamento'], $cartao['dia_vencimento'], $cartao['limite'], $cartao['conta_pagamento_id'], $cartao['ativo']];
        if ($id) {
            $pdo->prepare('UPDATE cartoes SET nome = ?, bandeira = ?, dia_fechamento = ?, dia_vencimento = ?, limite = ?, conta_pagamento_id = ?, ativo = ? WHERE id = ?')->execute([...$valores, $id]);
        } else {
            $pdo->prepare('INSERT INTO cartoes (nome, bandeira, dia_fechamento, dia_vencimento, limite, conta_pagamento_id, ativo) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute($valores);
        }
        $rotulos = ['nome' => 'Nome', 'bandeira' => 'Bandeira', 'dia_fechamento' => 'Fechamento', 'dia_vencimento' => 'Vencimento', 'limite' => 'Limite', 'conta_pagamento_id' => 'Conta de pagamento (id)', 'ativo' => 'Ativo'];
        registrarAtividade('cartoes', $id ? 'edicao' : 'criacao', $id ? 'Cartão ' . $cartao['nome'] . ': ' . descreverAlteracoes($cartaoAntes, $cartao, $rotulos)
            : 'Cartão cadastrado: ' . $cartao['nome'] . ' · fecha dia ' . $cartao['dia_fechamento'] . ', vence dia ' . $cartao['dia_vencimento'] . ($cartao['limite'] !== null ? ', limite ' . formatMoney($cartao['limite']) : ''));
        flash('success', 'Cartão salvo.');
        redirect('/cartoes/listar.php');
    }
}

$pageTitle = $id ? $cartao['nome'] : 'Novo cartão';
$pageBreadcrumb = 'Financeiro · Cartões de crédito';
$activeMenu = 'cartoes';
$pageAction = '<a href="/cartoes/listar.php" class="btn btn-outline-secondary">Cancelar</a><button type="submit" form="form-cartao" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>';
require __DIR__ . '/../../includes/layout_header.php';
?>

<?php if ($erros): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" id="form-cartao" style="max-width: 760px;">
    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-credit-card"></i> Cartão</div>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Nome *</label><input type="text" name="nome" class="form-control" required placeholder="Ex.: Inter Black" value="<?= e($cartao['nome']) ?>"></div>
            <div class="col-md-3"><label class="form-label">Bandeira</label><input type="text" name="bandeira" class="form-control" value="<?= e($cartao['bandeira']) ?>"></div>
            <div class="col-md-3"><label class="form-label">Limite (R$)</label><input type="text" name="limite" class="form-control" data-moeda inputmode="decimal" value="<?= $cartao['limite'] !== null ? formatDecimal($cartao['limite']) : '' ?>"></div>
            <div class="col-md-3"><label class="form-label">Dia do fechamento *</label><input type="number" name="dia_fechamento" class="form-control" min="1" max="31" required value="<?= e((string) $cartao['dia_fechamento']) ?>"></div>
            <div class="col-md-3"><label class="form-label">Dia do vencimento *</label><input type="number" name="dia_vencimento" class="form-control" min="1" max="31" required value="<?= e((string) $cartao['dia_vencimento']) ?>"></div>
            <div class="col-md-6"><label class="form-label">Conta que paga a fatura</label><select name="conta_pagamento_id" class="form-select"><?= opcoesContas($pdo, idOuNull($cartao['conta_pagamento_id']), '—') ?></select></div>
            <div class="col-12 form-text">Compra feita no dia do fechamento ou depois entra na fatura seguinte.</div>
            <div class="col-12"><div class="form-check"><input type="checkbox" class="form-check-input" name="ativo" id="ativo" <?= $cartao['ativo'] ? 'checked' : '' ?>><label class="form-check-label" for="ativo">Cartão ativo</label></div></div>
        </div>
    </div>
</form>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
