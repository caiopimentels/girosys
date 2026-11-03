<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

$pdo = getDB();
$erros = [];
$clienteNome = '';
// Edição (?id=): enquanto não foi usado numa operação muda tudo e o extrato
// acompanha; depois de usado, só a descrição (o resto entrou na conta da operação).
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$repasse = null;
if ($id) {
    $stmt = $pdo->prepare('SELECT a.*, p.nome AS cliente_nome, pg.nome AS pagador_nome FROM adiantamentos a JOIN pessoas p ON p.id = a.cliente_id LEFT JOIN pessoas pg ON pg.id = a.pagador_id WHERE a.id = ?');
    $stmt->execute([$id]);
    $repasse = $stmt->fetch();
    if (!$repasse) {
        flash('danger', 'Repasse não encontrado.');
        redirect('/adiantamentos/listar.php');
    }
    $clienteNome = $repasse['cliente_nome'];
    $_POST += $_SERVER['REQUEST_METHOD'] === 'POST' ? [] : [
        'data' => $repasse['data'], 'valor' => formatDecimal($repasse['valor']), 'conta_id' => $repasse['conta_id'] ?: 'sem', 'descricao' => $repasse['descricao'], 'pagador_nome' => $repasse['pagador_nome'],
    ];
}
$soDescricao = $repasse && ((float) $repasse['valor_utilizado'] > 0 || $repasse['operacao_origem_id'] || $repasse['titulo_origem_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $soDescricao) {
    // Já usado: só descrição e pagador (informativos) mudam.
    $pdo->prepare('UPDATE adiantamentos SET descricao = ?, pagador_id = ? WHERE id = ?')
        ->execute([input('descricao') ?: null, obterOuCriarPessoa($pdo, input('pagador_nome'), '', 'pagador'), $id]);
    flash('success', 'Descrição e pagador do repasse alterados.');
    redirect('/adiantamentos/listar.php');
}
if (!$id && !empty($_GET['cliente'])) {
    $stmt = $pdo->prepare('SELECT nome FROM pessoas WHERE id = ?');
    $stmt->execute([(int) $_GET['cliente']]);
    $clienteNome = (string) $stmt->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clienteNome = input('cliente_nome');
    $data = dataInput('data');
    $valor = decimalInput('valor');
    // "sem" = lançamento antigo: o repasse fica em aberto, mas não mexe no saldo da conta.
    $semConta = ($_POST['conta_id'] ?? '') === 'sem';
    $contaId = $semConta ? null : idOuNull($_POST['conta_id'] ?? null);
    $descricao = input('descricao');
    $pagadorNome = input('pagador_nome');

    if ($clienteNome === '') {
        $erros[] = 'Informe o cliente.';
    }
    if (!$data || $valor <= 0) {
        $erros[] = 'Informe data e valor.';
    }
    if (!$contaId && !$semConta) {
        $erros[] = 'Escolha a conta de onde saiu o dinheiro (ou "Não movimentar conta", para lançamento antigo).';
    }
    if (!$erros && $id) {
        $pdo->beginTransaction();
        $clienteId = obterOuCriarPessoa($pdo, $clienteNome, input('cliente_documento'), 'cliente');
        $pdo->prepare('UPDATE adiantamentos SET cliente_id = ?, pagador_id = ?, data = ?, valor = ?, conta_id = ?, descricao = ? WHERE id = ?')
            ->execute([$clienteId, obterOuCriarPessoa($pdo, $pagadorNome, '', 'pagador'), $data, round($valor, 2), $contaId, $descricao ?: null, $id]);
        // Refaz a saída no extrato da conta com os dados novos.
        removerMovimentacoes($pdo, 'adiantamento', $id);
        if ($contaId) {
            lancarMovimentacao($pdo, $contaId, $data, 'saida', $valor, 'Repasse a ' . formatarNomePessoa($clienteNome) . ($descricao ? ' · ' . $descricao : ''), 'adiantamento', $id);
        }
        $pdo->commit();
        flash('success', 'Repasse alterado.');
        redirect('/adiantamentos/listar.php');
    }
    if (!$erros) {
        $pdo->beginTransaction();
        $clienteId = obterOuCriarPessoa($pdo, $clienteNome, input('cliente_documento'), 'cliente');
        $novoId = registrarAdiantamento($pdo, $clienteId, $data, $valor, $contaId, $descricao);
        $pdo->prepare('UPDATE adiantamentos SET pagador_id = ? WHERE id = ?')->execute([obterOuCriarPessoa($pdo, $pagadorNome, '', 'pagador'), $novoId]);
        $pdo->commit();
        flash('success', 'Repasse de ' . formatMoney($valor) . ' registrado. Quando chegarem os títulos, marque-o na operação de desconto.');
        redirect(isset($_POST['salvar_outro']) ? '/adiantamentos/form.php' : '/adiantamentos/listar.php');
    }
}

$pageTitle = $id ? 'Editar repasse a ' . $clienteNome : 'Novo repasse a cliente';
$pageBreadcrumb = 'Operações · Repasses a clientes';
$activeMenu = 'adiantamentos';
$pageAction = '<a href="/adiantamentos/listar.php" class="btn btn-outline-secondary">Cancelar</a>'
    . ($id ? '' : '<button type="submit" form="form-adiant" name="salvar_outro" value="1" class="btn btn-outline-secondary">Salvar e lançar outro</button>')
    . '<button type="submit" form="form-adiant" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>';
require __DIR__ . '/../../includes/layout_header.php';
?>

<?php if ($erros): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" id="form-adiant" style="max-width: 900px;">
    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-cash-coin"></i> Repasse ao cliente</div>
        <?php if ($soDescricao): ?><div class="alert alert-light border small">Este repasse já entrou numa operação de desconto: só a descrição e o pagador podem mudar. Para mudar valor ou data, exclua a operação antes.</div><?php endif; ?>
        <p class="small text-muted">O valor sai da conta escolhida agora e fica em aberto no nome do cliente. Na operação de desconto, ele é corrigido pela taxa desde esta data.</p>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Cliente *</label><input type="text" name="cliente_nome" id="cliente_nome" class="form-control" data-busca-pessoa="cliente" required autocomplete="off-busca" <?= $soDescricao ? 'disabled' : '' ?> value="<?= e($clienteNome) ?>" <?= $clienteNome ? '' : 'autofocus' ?>><div class="small mt-1" id="cliente-limite"></div></div>
            <div class="col-md-3"><label class="form-label">CPF/CNPJ <span class="text-muted small">(se novo)</span></label><input type="text" name="cliente_documento" class="form-control" inputmode="numeric" <?= $soDescricao ? 'disabled' : '' ?> value="<?= e($_POST['cliente_documento'] ?? '') ?>"></div>
            <div class="col-md-3"><label class="form-label">Data *</label><input type="date" name="data" class="form-control" required <?= $soDescricao ? 'disabled' : '' ?> value="<?= e($_POST['data'] ?? date('Y-m-d')) ?>"></div>
            <div class="col-md-6"><label class="form-label">Pagador <span class="text-muted small">(opcional)</span></label><input type="text" name="pagador_nome" class="form-control" data-busca-pessoa="pagador" autocomplete="off-busca" value="<?= e($_POST['pagador_nome'] ?? '') ?>"></div>
            <div class="col-md-6"></div>
            <div class="col-md-3"><label class="form-label">Valor (R$) *</label><input type="text" name="valor" class="form-control" data-moeda inputmode="decimal" required <?= $soDescricao ? 'disabled' : '' ?> value="<?= e($_POST['valor'] ?? '') ?>" <?= $clienteNome ? 'autofocus' : '' ?>></div>
            <div class="col-md-4"><label class="form-label">Saiu da conta *</label><select name="conta_id" id="conta_saida" class="form-select" required <?= $soDescricao ? 'disabled' : '' ?>><?= opcoesContaSaida($pdo, (string) ($_POST['conta_id'] ?? '')) ?></select>
                <div class="form-text" id="conta-saida-ajuda"></div></div>
            <div class="col-md-5"><label class="form-label">Descrição</label><input type="text" name="descricao" class="form-control" maxlength="200" placeholder="Ex.: Pix para pagar fornecedor" value="<?= e($_POST['descricao'] ?? '') ?>"></div>
        </div>
    </div>
</form>
<?= buscaPessoas($pdo) ?>
<script>
// Lançamento antigo: sem conta não mexe em saldo; com conta e data antes do
// saldo inicial dela, avisa que o saldo de hoje vai mudar.
(function () {
    const conta = document.getElementById('conta_saida');
    const data = document.querySelector('[name="data"]');
    const ajuda = document.getElementById('conta-saida-ajuda');
    const br = (iso) => iso.split('-').reverse().join('/');
    function avisar() {
        const op = conta.options[conta.selectedIndex];
        ajuda.className = 'form-text';
        if (conta.value === 'sem') {
            ajuda.textContent = 'Fica em aberto no nome do cliente e corrige pela taxa desde a data, mas não sai de nenhuma conta.';
        } else if (op && op.dataset.inicio && data.value && data.value < op.dataset.inicio) {
            ajuda.className = 'form-text text-danger';
            ajuda.textContent = 'Esta conta começou com saldo em ' + br(op.dataset.inicio) + ': lançar uma saída antes disso muda o saldo de hoje. Para lançamento antigo, use "Não movimentar conta".';
        } else {
            ajuda.textContent = '';
        }
    }
    conta.addEventListener('change', avisar);
    data.addEventListener('change', avisar);
    avisar();
})();
</script>
<script>
// Mostra o limite de crédito do cliente e avisa se este valor passa dele.
(function () {
    const campo = document.getElementById('cliente_nome');
    const valor = document.querySelector('[name="valor"]');
    const caixa = document.getElementById('cliente-limite');
    let cliente = null;
    function mostrar() {
        if (!cliente || cliente.limite_credito === null) {
            caixa.innerHTML = cliente ? 'Sem limite de crédito no cadastro · em uso ' + Moeda.reais(cliente.credito_em_uso) : '';
            return;
        }
        const depois = cliente.credito_em_uso + Moeda.ler(valor.value);
        const sobra = cliente.limite_credito - depois;
        caixa.innerHTML = 'Limite ' + Moeda.reais(cliente.limite_credito) + ' · em uso ' + Moeda.reais(cliente.credito_em_uso)
            + (sobra < 0 ? ' · <span class="valor-negativo fw-semibold">com este valor passa do limite em ' + Moeda.reais(-sobra) + '</span>'
                         : ' · disponível depois deste: <strong>' + Moeda.reais(sobra) + '</strong>');
    }
    function consultar() {
        cliente = null;
        if (!campo.value.trim()) { mostrar(); return; }
        fetch('/pessoas/info.php?' + new URLSearchParams({ nome: campo.value.trim(), papel: 'cliente' }), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then((r) => r.json()).then((d) => { cliente = d.encontrado ? d : null; mostrar(); }).catch(() => {});
    }
    campo.addEventListener('change', consultar);
    valor.addEventListener('input', mostrar);
    if (campo.value.trim()) consultar();
})();
</script>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
