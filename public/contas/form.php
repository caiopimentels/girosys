<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

$pdo = getDB();
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$conta = ['nome' => '', 'banco' => '', 'saldo_inicial' => 0, 'data_saldo_inicial' => date('Y-m-d'), 'cor' => '#16a34a', 'ativo' => 1];

if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM contas_bancarias WHERE id = ?');
    $stmt->execute([$id]);
    $conta = $stmt->fetch();
    if (!$conta) {
        flash('danger', 'Conta não encontrada.');
        redirect('/contas/listar.php');
    }
}

$erros = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conta = [
        'nome' => input('nome'),
        'banco' => input('banco'),
        'saldo_inicial' => decimalInput('saldo_inicial'),
        'data_saldo_inicial' => dataInput('data_saldo_inicial') ?? date('Y-m-d'),
        'cor' => preg_match('/^#[0-9a-fA-F]{6}$/', input('cor')) ? input('cor') : '#16a34a',
        'ativo' => isset($_POST['ativo']) ? 1 : 0,
    ];
    if ($conta['nome'] === '') {
        $erros[] = 'Informe o nome da conta.';
    }
    if (!$erros) {
        $valores = [$conta['nome'], $conta['banco'] ?: null, $conta['saldo_inicial'], $conta['data_saldo_inicial'], $conta['cor'], $conta['ativo']];
        if ($id) {
            $pdo->prepare('UPDATE contas_bancarias SET nome = ?, banco = ?, saldo_inicial = ?, data_saldo_inicial = ?, cor = ?, ativo = ? WHERE id = ?')
                ->execute([...$valores, $id]);
        } else {
            $pdo->prepare('INSERT INTO contas_bancarias (nome, banco, saldo_inicial, data_saldo_inicial, cor, ativo) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute($valores);
        }
        registrarAtividade('contas_bancarias', $id ? 'edicao' : 'criacao', ($id ? 'Conta alterada: ' : 'Conta criada: ') . $conta['nome'] . ' · saldo inicial ' . formatMoney($conta['saldo_inicial']) . ' em ' . formatDate($conta['data_saldo_inicial']) . (!$conta['ativo'] ? ' · inativa' : ''));
        flash('success', 'Conta salva.');
        redirect(isset($_POST['salvar_outra']) ? '/contas/form.php' : '/contas/listar.php');
    }
}

$pageTitle = $id ? $conta['nome'] : 'Nova conta bancária';
$pageBreadcrumb = 'Contas bancárias';
$activeMenu = 'contas';
$pageAction = '<a href="/contas/listar.php" class="btn btn-outline-secondary">Cancelar</a>'
    . (!$id ? '<button type="submit" form="form-conta" name="salvar_outra" value="1" class="btn btn-outline-secondary">Salvar e cadastrar outra</button>' : '')
    . '<button type="submit" form="form-conta" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>';
require __DIR__ . '/../../includes/layout_header.php';
if ($id) {
    $contaAbaId = $id;
    $contaAba = 'dados';
    require __DIR__ . '/../../includes/abas_conta_bancaria.php';
}
?>

<?php if (isset($_GET['primeira'])): ?>
    <div class="alert alert-success">Acesso criado! Comece cadastrando suas contas (Banco Inter, Mercado Pago, Bradesco...) com o saldo de hoje.</div>
<?php endif; ?>
<?php if ($erros): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" id="form-conta" style="max-width: 760px;">
    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-bank"></i> Dados da conta</div>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Nome da conta *</label><input type="text" name="nome" class="form-control" required autofocus placeholder="Ex.: Banco Inter" value="<?= e($conta['nome']) ?>"></div>
            <div class="col-md-4"><label class="form-label">Banco / detalhes</label><input type="text" name="banco" class="form-control" placeholder="Ag. / conta" value="<?= e($conta['banco']) ?>"></div>
            <div class="col-md-2"><label class="form-label">Cor</label><input type="color" name="cor" class="form-control form-control-color w-100" value="<?= e($conta['cor']) ?>"></div>
            <div class="col-md-4"><label class="form-label">Saldo inicial (R$)</label><input type="text" name="saldo_inicial" class="form-control" data-moeda inputmode="decimal" value="<?= formatDecimal($conta['saldo_inicial']) ?>"></div>
            <div class="col-md-4"><label class="form-label">Saldo inicial em</label><input type="date" name="data_saldo_inicial" class="form-control" value="<?= e($conta['data_saldo_inicial']) ?>"></div>
            <div class="col-md-4 d-flex align-items-end">
                <div class="form-check mb-2"><input type="checkbox" name="ativo" class="form-check-input" id="ativo" <?= $conta['ativo'] ? 'checked' : '' ?>><label class="form-check-label" for="ativo">Conta ativa</label></div>
            </div>
            <div class="col-12 form-text">O saldo da conta é o saldo inicial + entradas − saídas lançadas no sistema a partir daí.</div>
        </div>
    </div>
</form>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
