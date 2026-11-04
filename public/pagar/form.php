<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

$pdo = getDB();
$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $descricao = input('descricao');
    $valor = decimalInput('valor');
    $vencimento = dataInput('data_vencimento');
    $repeticao = input('repeticao', 'unica'); // unica | parcelada | mensal
    $quantidade = max(1, min(120, (int) input('quantidade', '1')));
    $contaId = idOuNull($_POST['conta_id'] ?? null);
    $categoriaId = idOuNull($_POST['categoria_id'] ?? null);
    $jaPaga = isset($_POST['ja_paga']);

    if ($descricao === '' || $valor <= 0 || !$vencimento) {
        $erros[] = 'Informe descrição, valor e vencimento.';
    }
    if ($jaPaga && !$contaId) {
        $erros[] = 'Para lançar como paga, escolha a conta de onde saiu.';
    }
    if (!$erros) {
        $pdo->beginTransaction();
        $pessoaId = obterOuCriarPessoa($pdo, input('pessoa_nome'), '', 'fornecedor');
        // Parcelada divide o valor; mensal repete o mesmo valor todo mês.
        $valores = match ($repeticao) {
            'parcelada' => dividirParcelas($valor, $quantidade),
            'mensal' => array_fill(0, $quantidade, $valor),
            default => [$valor],
        };
        $n = count($valores);
        $ins = $pdo->prepare('INSERT INTO contas_pagar (descricao, categoria_id, pessoa_id, valor, data_vencimento, conta_id, observacao) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($valores as $i => $v) {
            $ins->execute([$descricao . ($repeticao === 'parcelada' && $n > 1 ? ' (' . ($i + 1) . "/{$n})" : ''), $categoriaId, $pessoaId, $v, somarMeses($vencimento, $i), $contaId, input('observacao') ?: null]);
            if ($jaPaga && $i === 0) {
                $id = (int) $pdo->lastInsertId();
                pagarConta($pdo, ['id' => $id, 'descricao' => $descricao], $vencimento, $v, $contaId);
            }
        }
        registrarAtividade('contas_pagar', 'criacao', ($n > 1 ? "{$n} lançamentos ({$repeticao})" : 'Despesa lançada') . ': ' . $descricao . ' · ' . ($n > 1 ? $n . 'x · total ' . formatMoney(array_sum($valores)) : formatMoney($valor)) . ' · 1º vencimento ' . formatDate($vencimento)
            . (input('pessoa_nome') ? ' · ' . input('pessoa_nome') : '') . ($jaPaga ? ' · já paga' : ''));
        $pdo->commit();
        flash('success', $n > 1 ? "{$n} lançamentos criados." : 'Despesa lançada.');
        redirect(isset($_POST['salvar_outra']) ? '/pagar/form.php' : '/pagar/listar.php');
    }
}

$pageTitle = 'Lançar despesa';
$pageBreadcrumb = 'Financeiro · Contas a pagar';
$activeMenu = 'pagar';
$pageAction = '<a href="/pagar/listar.php" class="btn btn-outline-secondary">Cancelar</a>'
    . '<button type="submit" form="form-pagar" name="salvar_outra" value="1" class="btn btn-outline-secondary">Salvar e lançar outra</button>'
    . '<button type="submit" form="form-pagar" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>';
require __DIR__ . '/../../includes/layout_header.php';
?>

<?php if ($erros): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" id="form-pagar" style="max-width: 900px;">
    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-arrow-up-circle"></i> Despesa</div>
        <p class="small text-muted">Compras no cartão de crédito: lance em <a href="/cartoes/listar.php">Cartões de crédito</a>, que já divide nas faturas. Dinheiro passado a cliente: <a href="/adiantamentos/form.php">Adiantamentos</a>.</p>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Descrição *</label><input type="text" name="descricao" class="form-control" required maxlength="180" autofocus value="<?= e($_POST['descricao'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label">Fornecedor</label><input type="text" name="pessoa_nome" class="form-control" data-busca-pessoa="fornecedor" autocomplete="off-busca" value="<?= e($_POST['pessoa_nome'] ?? '') ?>"></div>
            <div class="col-md-3"><label class="form-label">Valor (R$) *</label><input type="text" name="valor" class="form-control" data-moeda inputmode="decimal" required value="<?= e($_POST['valor'] ?? '') ?>"></div>
            <div class="col-md-3"><label class="form-label">Vencimento *</label><input type="date" name="data_vencimento" class="form-control" required value="<?= e($_POST['data_vencimento'] ?? date('Y-m-d')) ?>"></div>
            <div class="col-md-3"><label class="form-label">Repetição</label>
                <select name="repeticao" class="form-select" id="repeticao">
                    <option value="unica">Uma vez</option>
                    <option value="parcelada" <?= ($_POST['repeticao'] ?? '') === 'parcelada' ? 'selected' : '' ?>>Parcelada (divide o valor)</option>
                    <option value="mensal" <?= ($_POST['repeticao'] ?? '') === 'mensal' ? 'selected' : '' ?>>Todo mês (mesmo valor)</option>
                </select>
            </div>
            <div class="col-md-3" id="bloco-qtd"><label class="form-label">Quantas vezes</label><input type="number" name="quantidade" class="form-control" min="1" max="120" value="<?= e($_POST['quantidade'] ?? '12') ?>"></div>
            <div class="col-md-4"><label class="form-label">Categoria</label><select name="categoria_id" class="form-select"><?= opcoesCategorias($pdo, 'despesa', idOuNull($_POST['categoria_id'] ?? null)) ?></select></div>
            <div class="col-md-4"><label class="form-label">Pagar pela conta</label><select name="conta_id" class="form-select"><?= opcoesContas($pdo, idOuNull($_POST['conta_id'] ?? null), '— definir ao pagar —') ?></select></div>
            <div class="col-md-4 d-flex align-items-end"><div class="form-check mb-2"><input type="checkbox" class="form-check-input" name="ja_paga" id="ja_paga" <?= isset($_POST['ja_paga']) ? 'checked' : '' ?>><label class="form-check-label" for="ja_paga">Já está paga (lança a saída na data do vencimento)</label></div></div>
            <div class="col-12"><label class="form-label">Observação</label><textarea name="observacao" class="form-control" rows="2"><?= e($_POST['observacao'] ?? '') ?></textarea></div>
        </div>
    </div>
</form>
<?= buscaPessoas($pdo) ?>
<script>
(function () {
    const rep = document.getElementById('repeticao');
    const qtd = document.getElementById('bloco-qtd');
    const atualizar = () => (qtd.style.display = rep.value === 'unica' ? 'none' : '');
    rep.addEventListener('change', atualizar);
    atualizar();
})();
</script>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
