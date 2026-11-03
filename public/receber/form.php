<?php
// Conta a receber avulsa (fora de operação de desconto): aluguel, reembolso...
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

$pdo = getDB();
$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $descricao = input('descricao');
    $valor = decimalInput('valor');
    $vencimento = dataInput('data_vencimento');
    $parcelas = max(1, min(60, (int) input('parcelas', '1')));
    $contaId = idOuNull($_POST['conta_destino_id'] ?? null);
    $categoriaId = idOuNull($_POST['categoria_id'] ?? null);
    $tipo = array_key_exists(input('tipo'), tiposTitulo()) ? input('tipo') : 'outro';

    if ($descricao === '' || $valor <= 0 || !$vencimento) {
        $erros[] = 'Informe descrição, valor e vencimento.';
    }
    if (!$erros) {
        $pdo->beginTransaction();
        $pessoaId = obterOuCriarPessoa($pdo, input('pessoa_nome'), '', 'pagador');
        $ins = $pdo->prepare(
            'INSERT INTO contas_receber (tipo, emitente_id, descricao, categoria_id, numero_titulo, valor, data_vencimento, conta_destino_id, observacao) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach (dividirParcelas($valor, $parcelas) as $i => $v) {
            $ins->execute([$tipo, $pessoaId, $descricao . ($parcelas > 1 ? ' (' . ($i + 1) . "/{$parcelas})" : ''), $categoriaId, input('numero_titulo') ?: null,
                $v, somarMeses($vencimento, $i), $contaId, input('observacao') ?: null]);
        }
        $pdo->commit();
        flash('success', $parcelas > 1 ? "{$parcelas} parcelas lançadas." : 'Conta a receber lançada.');
        redirect('/receber/listar.php');
    }
}

$pageTitle = 'Nova conta a receber';
$pageBreadcrumb = 'Financeiro · Contas a receber';
$activeMenu = 'receber';
$pageAction = '<a href="/receber/listar.php" class="btn btn-outline-secondary">Cancelar</a>'
    . '<button type="submit" form="form-receber" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>';
require __DIR__ . '/../../includes/layout_header.php';
?>

<?php if ($erros): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" id="form-receber" style="max-width: 900px;">
    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-arrow-down-circle"></i> Lançamento avulso</div>
        <p class="small text-muted">Para títulos de clientes use <a href="/operacoes/form.php">Nova operação de desconto</a>, que já calcula os juros.</p>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Descrição *</label><input type="text" name="descricao" class="form-control" required maxlength="190" value="<?= e($_POST['descricao'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label">Pagador</label><input type="text" name="pessoa_nome" class="form-control" data-busca-pessoa="pagador" autocomplete="off-busca" value="<?= e($_POST['pessoa_nome'] ?? '') ?>"></div>
            <div class="col-md-3"><label class="form-label">Tipo</label><select name="tipo" class="form-select"><?php foreach (tiposTitulo() as $k => $v): ?><option value="<?= $k ?>" <?= ($_POST['tipo'] ?? 'outro') === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label">Valor total (R$) *</label><input type="text" name="valor" class="form-control" data-moeda inputmode="decimal" required value="<?= e($_POST['valor'] ?? '') ?>"></div>
            <div class="col-md-3"><label class="form-label">1º vencimento *</label><input type="date" name="data_vencimento" class="form-control" required value="<?= e($_POST['data_vencimento'] ?? date('Y-m-d')) ?>"></div>
            <div class="col-md-3"><label class="form-label">Parcelas</label><input type="number" name="parcelas" class="form-control" min="1" max="60" value="<?= e($_POST['parcelas'] ?? '1') ?>"></div>
            <div class="col-md-4"><label class="form-label">Cai na conta</label><select name="conta_destino_id" class="form-select"><?= opcoesContas($pdo, idOuNull($_POST['conta_destino_id'] ?? null), '— definir ao receber —') ?></select></div>
            <div class="col-md-4"><label class="form-label">Categoria</label><select name="categoria_id" class="form-select"><?= opcoesCategorias($pdo, 'receita', idOuNull($_POST['categoria_id'] ?? null)) ?></select></div>
            <div class="col-md-4"><label class="form-label">Nº do documento</label><input type="text" name="numero_titulo" class="form-control" value="<?= e($_POST['numero_titulo'] ?? '') ?>"></div>
            <div class="col-12"><label class="form-label">Observação</label><textarea name="observacao" class="form-control" rows="2"><?= e($_POST['observacao'] ?? '') ?></textarea></div>
        </div>
    </div>
</form>
<?= buscaPessoas($pdo) ?>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
