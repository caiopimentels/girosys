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
    registrarAtividade('repasses', 'edicao', 'Repasse #' . $id . ' de ' . $repasse['cliente_nome'] . ' (' . formatMoney($repasse['valor']) . '): ' . descreverAlteracoes(
        ['descricao' => $repasse['descricao'], 'pagador' => $repasse['pagador_nome']], ['descricao' => input('descricao') ?: null, 'pagador' => input('pagador_nome') ?: null], ['descricao' => 'Descrição', 'pagador' => 'Pagador']));
    flash('success', 'Descrição e pagador do repasse alterados.');
    redirect('/adiantamentos/listar.php');
}
if (!$id && !empty($_GET['cliente'])) {
    $stmt = $pdo->prepare('SELECT nome FROM pessoas WHERE id = ?');
    $stmt->execute([(int) $_GET['cliente']]);
    $clienteNome = (string) $stmt->fetchColumn();
}

$parcelas = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clienteNome = input('cliente_nome');
    $data = dataInput('data');
    $valor = decimalInput('valor');
    // Novo repasse: várias parcelas (boletos com datas e valores diferentes) — cada uma vira um repasse.
    foreach ((array) ($_POST['parcelas'] ?? []) as $p) {
        $pData = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($p['data'] ?? '')) ? $p['data'] : '';
        $pValor = paraDecimal($p['valor'] ?? '');
        if ($pData || $pValor > 0) {
            $parcelas[] = ['data' => $pData, 'valor' => $pValor, 'descricao' => trim((string) ($p['descricao'] ?? ''))];
        }
    }
    // "sem" = lançamento antigo: o repasse fica em aberto, mas não mexe no saldo da conta.
    $semConta = ($_POST['conta_id'] ?? '') === 'sem';
    $contaId = $semConta ? null : idOuNull($_POST['conta_id'] ?? null);
    $descricao = input('descricao');
    $pagadorNome = input('pagador_nome');

    if ($clienteNome === '') {
        $erros[] = 'Informe o cliente.';
    }
    if ($id && (!$data || $valor <= 0)) {
        $erros[] = 'Informe data e valor.';
    }
    if (!$id && !$parcelas) {
        $erros[] = 'Informe a data e o valor de pelo menos uma parcela.';
    }
    foreach ($parcelas as $n => $p) {
        if (!$p['data'] || $p['valor'] <= 0) {
            $erros[] = 'Parcela ' . ($n + 1) . ': informe data e valor.';
        }
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
        registrarAtividade('repasses', 'edicao', 'Repasse #' . $id . ' alterado: ' . descreverAlteracoes(
            ['cliente' => $repasse['cliente_nome'], 'data' => formatDate($repasse['data']), 'valor' => formatMoney($repasse['valor']), 'conta' => $repasse['conta_id'] ?: 'sem', 'descricao' => $repasse['descricao'], 'pagador' => $repasse['pagador_nome']],
            ['cliente' => formatarNomePessoa($clienteNome), 'data' => formatDate($data), 'valor' => formatMoney($valor), 'conta' => $contaId ?: 'sem', 'descricao' => $descricao ?: null, 'pagador' => $pagadorNome ?: null],
            ['cliente' => 'Cliente', 'data' => 'Data', 'valor' => 'Valor', 'conta' => 'Conta (id)', 'descricao' => 'Descrição', 'pagador' => 'Pagador']));
        $pdo->commit();
        flash('success', 'Repasse alterado.');
        redirect('/adiantamentos/listar.php');
    }
    if (!$erros) {
        $pdo->beginTransaction();
        $clienteId = obterOuCriarPessoa($pdo, $clienteNome, input('cliente_documento'), 'cliente');
        $pagadorId = obterOuCriarPessoa($pdo, $pagadorNome, '', 'pagador');
        $marcarPagador = $pdo->prepare('UPDATE adiantamentos SET pagador_id = ? WHERE id = ?');
        foreach ($parcelas as $p) {
            // Descrição da parcela, se tiver; senão a geral.
            $novoId = registrarAdiantamento($pdo, $clienteId, $p['data'], $p['valor'], $contaId, $p['descricao'] ?: $descricao);
            $marcarPagador->execute([$pagadorId, $novoId]);
        }
        $soma = array_sum(array_column($parcelas, 'valor'));
        registrarAtividade('repasses', 'criacao', (count($parcelas) > 1 ? count($parcelas) . ' repasses' : 'Repasse') . ' a ' . formatarNomePessoa($clienteNome) . ': ' . implode(', ', array_map(fn ($p) => formatMoney($p['valor']) . ' em ' . formatDate($p['data']), $parcelas))
            . ' · total ' . formatMoney($soma) . ($semConta ? ' · sem movimentar conta' : '') . ($pagadorNome ? ' · pagador ' . $pagadorNome : ''));
        $pdo->commit();
        flash('success', (count($parcelas) > 1 ? count($parcelas) . ' repasses registrados, total ' : 'Repasse de ') . formatMoney($soma) . '. Quando chegarem os títulos, marque-os na operação de desconto.');
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
<?php if ($id): ?>
            <div class="col-md-3"><label class="form-label">Data *</label><input type="date" name="data" class="form-control" required <?= $soDescricao ? 'disabled' : '' ?> value="<?= e($_POST['data'] ?? date('Y-m-d')) ?>"></div>
<?php endif; ?>
            <div class="col-md-6"><label class="form-label">Pagador <span class="text-muted small">(opcional)</span></label><input type="text" name="pagador_nome" class="form-control" data-busca-pessoa="pagador" autocomplete="off-busca" value="<?= e($_POST['pagador_nome'] ?? '') ?>"></div>
            <div class="col-md-6"></div>
<?php if ($id): ?>
            <div class="col-md-3"><label class="form-label">Valor (R$) *</label><input type="text" name="valor" class="form-control" data-moeda inputmode="decimal" required <?= $soDescricao ? 'disabled' : '' ?> value="<?= e($_POST['valor'] ?? '') ?>" <?= $clienteNome ? 'autofocus' : '' ?>></div>
<?php endif; ?>
            <div class="col-md-4"><label class="form-label">Saiu da conta *</label><select name="conta_id" id="conta_saida" class="form-select" required <?= $soDescricao ? 'disabled' : '' ?>><?= opcoesContaSaida($pdo, (string) ($_POST['conta_id'] ?? '')) ?></select>
                <div class="form-text" id="conta-saida-ajuda"></div></div>
            <div class="col-md-5"><label class="form-label">Descrição</label><input type="text" name="descricao" class="form-control" maxlength="200" placeholder="Ex.: Pix para pagar fornecedor" value="<?= e($_POST['descricao'] ?? '') ?>"></div>
        </div>
        <?php if (!$id): ?>
        <?php // Várias parcelas (2/3 boletos com datas e valores diferentes): cada linha vira um repasse (assets/js/parcelas.js). ?>
        <div class="secao-titulo mt-4"><i class="bi bi-list-ol"></i> Parcelas</div>
        <div class="card">
            <table class="table table-sm mb-0 align-middle tabela-parcelas">
                <thead><tr><th style="width: 40px;">#</th><th style="width: 180px;">Data</th><th style="width: 180px;">Valor (R$)</th><th>Descrição da parcela <span class="text-muted small">(opcional — senão usa a de cima)</span></th><th style="width: 50px;"></th></tr></thead>
                <tbody>
                <?php foreach ($parcelas ?: [['data' => date('Y-m-d'), 'valor' => 0, 'descricao' => '']] as $i => $p): ?>
                    <tr>
                        <td class="text-muted small numero-parcela"></td>
                        <td><input type="date" name="parcelas[<?= $i ?>][data]" class="form-control form-control-sm" data-campo="data" value="<?= e($p['data']) ?>"></td>
                        <td><input type="text" name="parcelas[<?= $i ?>][valor]" class="form-control form-control-sm text-end" data-campo="valor" data-parcela-valor data-moeda inputmode="decimal" value="<?= $p['valor'] > 0 ? formatDecimal($p['valor']) : '' ?>"></td>
                        <td><input type="text" name="parcelas[<?= $i ?>][descricao]" class="form-control form-control-sm" data-campo="descricao" maxlength="200" value="<?= e($p['descricao']) ?>"></td>
                        <td class="text-end"><button type="button" class="btn btn-sm btn-link text-danger remover-parcela" title="Tirar"><i class="bi bi-x-lg"></i></button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr>
                    <td colspan="2"><button type="button" class="btn btn-sm btn-outline-secondary" data-add-parcela><i class="bi bi-plus-lg"></i> Adicionar parcela</button></td>
                    <td class="text-end fw-semibold text-num" data-total-parcelas>R$ 0,00</td>
                    <td class="small text-muted"><span data-qtd-parcelas>1</span> parcela(s) · Enter no valor cria a próxima</td><td></td>
                </tr></tfoot>
                <template>
                    <tr>
                        <td class="text-muted small numero-parcela"></td>
                        <td><input type="date" name="parcelas[__i][data]" class="form-control form-control-sm" data-campo="data"></td>
                        <td><input type="text" name="parcelas[__i][valor]" class="form-control form-control-sm text-end" data-campo="valor" data-parcela-valor data-moeda inputmode="decimal"></td>
                        <td><input type="text" name="parcelas[__i][descricao]" class="form-control form-control-sm" data-campo="descricao" maxlength="200"></td>
                        <td class="text-end"><button type="button" class="btn btn-sm btn-link text-danger remover-parcela" title="Tirar"><i class="bi bi-x-lg"></i></button></td>
                    </tr>
                </template>
            </table>
        </div>
        <?php endif; ?>
    </div>
</form>
<?= buscaPessoas($pdo) ?>
<script>
// Lançamento antigo: sem conta não mexe em saldo; com conta e data antes do
// saldo inicial dela, avisa que o saldo de hoje vai mudar.
(function () {
    const conta = document.getElementById('conta_saida');
    const ajuda = document.getElementById('conta-saida-ajuda');
    // Data do repasse (edição) ou a mais antiga das parcelas (novo).
    const dataMaisAntiga = () => [...document.querySelectorAll('[name="data"], [name$="[data]"]')].map((c) => c.value).filter(Boolean).sort()[0] || '';
    const br = (iso) => iso.split('-').reverse().join('/');
    function avisar() {
        const op = conta.options[conta.selectedIndex];
        ajuda.className = 'form-text';
        if (conta.value === 'sem') {
            ajuda.textContent = 'Fica em aberto no nome do cliente e corrige pela taxa desde a data, mas não sai de nenhuma conta.';
        } else if (op && op.dataset.inicio && dataMaisAntiga() && dataMaisAntiga() < op.dataset.inicio) {
            ajuda.className = 'form-text text-danger';
            ajuda.textContent = 'Esta conta começou com saldo em ' + br(op.dataset.inicio) + ': lançar uma saída antes disso muda o saldo de hoje. Para lançamento antigo, use "Não movimentar conta".';
        } else {
            ajuda.textContent = '';
        }
    }
    conta.addEventListener('change', avisar);
    document.addEventListener('change', (ev) => { if (ev.target.type === 'date') avisar(); });
    avisar();
})();
</script>
<script>
// Mostra o limite de crédito do cliente e avisa se este valor passa dele.
(function () {
    const campo = document.getElementById('cliente_nome');
    // Valor do repasse (edição) ou a soma das parcelas (novo).
    const totalValor = () => [...document.querySelectorAll('[name="valor"], [data-parcela-valor]')].reduce((s, c) => s + Moeda.ler(c.value), 0);
    const caixa = document.getElementById('cliente-limite');
    let cliente = null;
    function mostrar() {
        if (!cliente || cliente.limite_credito === null) {
            caixa.innerHTML = cliente ? 'Sem limite de crédito no cadastro · em uso ' + Moeda.reais(cliente.credito_em_uso) : '';
            return;
        }
        const depois = cliente.credito_em_uso + totalValor();
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
    document.addEventListener('input', (ev) => { if (ev.target.matches('[name="valor"], [data-parcela-valor]')) mostrar(); });
    document.addEventListener('parcelas-mudou', mostrar);
    if (campo.value.trim()) consultar();
})();
</script>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
