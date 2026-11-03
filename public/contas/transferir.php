<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

$pdo = getDB();
$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $origem = (int) ($_POST['origem'] ?? 0);
    $destino = (int) ($_POST['destino'] ?? 0);
    $data = dataInput('data');
    $valor = decimalInput('valor');
    $obs = input('descricao');

    if (!$origem || !$destino || $origem === $destino) {
        $erros[] = 'Escolha duas contas diferentes.';
    }
    if (!$data || $valor <= 0) {
        $erros[] = 'Informe data e valor.';
    }

    if (!$erros) {
        $nomes = $pdo->query('SELECT id, nome FROM contas_bancarias')->fetchAll(PDO::FETCH_KEY_PAIR);
        $pdo->beginTransaction();
        // As duas pontas compartilham o origem_id (o id da saída) para excluir juntas.
        $saidaId = lancarMovimentacao($pdo, $origem, $data, 'saida', $valor, 'Transferência para ' . $nomes[$destino] . ($obs ? ' · ' . $obs : ''), 'transferencia');
        $pdo->prepare('UPDATE movimentacoes SET origem_id = id WHERE id = ?')->execute([$saidaId]);
        lancarMovimentacao($pdo, $destino, $data, 'entrada', $valor, 'Transferência de ' . $nomes[$origem] . ($obs ? ' · ' . $obs : ''), 'transferencia', $saidaId);
        $pdo->commit();
        flash('success', 'Transferência de ' . formatMoney($valor) . ' registrada.');
        redirect('/contas/listar.php');
    }
}

$pageTitle = 'Transferência entre contas';
$pageBreadcrumb = 'Contas bancárias';
$activeMenu = 'transferir';
$pageAction = '<a href="/contas/listar.php" class="btn btn-outline-secondary">Cancelar</a>'
    . '<button type="submit" form="form-transf" class="btn btn-brand"><i class="bi bi-arrow-left-right"></i> Transferir</button>';
require __DIR__ . '/../../includes/layout_header.php';
?>

<?php if ($erros): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" id="form-transf" style="max-width: 760px;">
    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-arrow-left-right"></i> Dados da transferência</div>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Sai de</label><select name="origem" class="form-select" required><?= opcoesContas($pdo, idOuNull($_POST['origem'] ?? null), 'Escolha...') ?></select></div>
            <div class="col-md-6"><label class="form-label">Entra em</label><select name="destino" class="form-select" required><?= opcoesContas($pdo, idOuNull($_POST['destino'] ?? null), 'Escolha...') ?></select></div>
            <div class="col-md-4"><label class="form-label">Data</label><input type="date" name="data" class="form-control" required value="<?= e($_POST['data'] ?? date('Y-m-d')) ?>"></div>
            <div class="col-md-4"><label class="form-label">Valor (R$)</label><input type="text" name="valor" class="form-control" data-moeda inputmode="decimal" required value="<?= e($_POST['valor'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Observação</label><input type="text" name="descricao" class="form-control" value="<?= e($_POST['descricao'] ?? '') ?>"></div>
        </div>
    </div>
</form>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
