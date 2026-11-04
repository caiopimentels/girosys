<?php
// Lançar compra no cartão (?cartao=) ou ver/excluir uma compra (?id=).
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

$pdo = getDB();
$cartoes = $pdo->query('SELECT * FROM cartoes WHERE ativo = 1 OR id IN (SELECT cartao_id FROM cartao_compras WHERE id = ' . (int) ($_GET['id'] ?? 0) . ') ORDER BY nome')->fetchAll();
$cartoesPorId = array_column($cartoes, null, 'id');
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$erros = [];
$travada = false;

// Editar (?id=): carrega a compra e as parcelas; o formulário é o mesmo do lançamento.
if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM cartao_compras WHERE id = ?');
    $stmt->execute([$id]);
    $compra = $stmt->fetch();
    if (!$compra) {
        redirect('/cartoes/listar.php');
    }
    $stmt = $pdo->prepare('SELECT p.*, f.competencia, f.data_vencimento, f.status, f.id AS fatura_id FROM cartao_parcelas p JOIN cartao_faturas f ON f.id = p.fatura_id WHERE p.compra_id = ? ORDER BY p.numero');
    $stmt->execute([$id]);
    $parcelas = $stmt->fetchAll();
    $travada = compraTemParcelaPaga($pdo, $id);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $_POST = [
            'cartao_id' => $compra['cartao_id'], 'data_compra' => $compra['data_compra'], 'descricao' => $compra['descricao'],
            'valor_total' => formatDecimal($compra['valor_total']), 'parcelas' => $compra['parcelas'],
            'primeira_fatura' => $parcelas[0]['competencia'] ?? '', 'categoria_id' => $compra['categoria_id'],
        ];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir' && $id) {
    try {
        excluirCompraCartao($pdo, $id);
        registrarAtividade('cartoes', 'exclusao', 'Compra excluída do ' . ($cartoesPorId[$compra['cartao_id']]['nome'] ?? 'cartão') . ': ' . $compra['descricao'] . ' · ' . $compra['parcelas'] . 'x · total ' . formatMoney($compra['valor_total']) . ' em ' . formatDate($compra['data_compra']));
        flash('success', 'Compra excluída das faturas.');
        redirecionarAposAcao('/cartoes/listar.php');
    } catch (InvalidArgumentException $e) {
        flash('danger', $e->getMessage());
        redirecionarAposAcao('/cartoes/compra.php?id=' . $id);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cartao = $cartoesPorId[(int) ($_POST['cartao_id'] ?? ($compra['cartao_id'] ?? 0))] ?? null;
    $descricao = input('descricao');
    $data = dataInput('data_compra') ?? ($travada ? $compra['data_compra'] : null);
    $total = $travada ? (float) $compra['valor_total'] : decimalInput('valor_total');
    $n = $travada ? (int) $compra['parcelas'] : (int) input('parcelas', '1');
    if (!$cartao) {
        $erros[] = 'Escolha o cartão.';
    }
    if ($descricao === '' || !$data || $total <= 0) {
        $erros[] = 'Informe descrição, data e valor.';
    }
    if ($n < 1 || $n > 48) {
        $erros[] = 'Parcelas de 1 a 48.';
    }
    if (!$erros && $id) {
        editarCompraCartao($pdo, $id, $cartao, $descricao, idOuNull($_POST['categoria_id'] ?? null), $data, $total, $n, input('primeira_fatura'));
        registrarAtividade('cartoes', 'edicao', 'Compra #' . $id . ' corrigida: ' . descreverAlteracoes(
            ['cartao' => ($cartoesPorId[$compra['cartao_id']]['nome'] ?? 'cartão'), 'descricao' => $compra['descricao'], 'data' => formatDate($compra['data_compra']), 'total' => formatMoney($compra['valor_total']), 'parcelas' => $compra['parcelas'] . 'x', 'categoria' => $compra['categoria_id']],
            ['cartao' => $cartao['nome'], 'descricao' => $descricao, 'data' => formatDate($data), 'total' => formatMoney($total), 'parcelas' => $n . 'x', 'categoria' => idOuNull($_POST['categoria_id'] ?? null)],
            ['cartao' => 'Cartão', 'descricao' => 'Descrição', 'data' => 'Data', 'total' => 'Total', 'parcelas' => 'Parcelas', 'categoria' => 'Categoria (id)']));
        flash('success', $travada ? 'Descrição e categoria alteradas (a compra tem parcela em fatura paga).' : "Compra corrigida: {$n}x de " . formatMoney($total / $n) . ' no ' . $cartao['nome'] . '.');
        redirect('/cartoes/listar.php');
    }
    if (!$erros) {
        lancarCompraCartao($pdo, $cartao, $descricao, idOuNull($_POST['categoria_id'] ?? null), $data, $total, $n, input('primeira_fatura'));
        registrarAtividade('cartoes', 'criacao', 'Compra no ' . $cartao['nome'] . ': ' . $descricao . ' · ' . $n . 'x de ' . formatMoney($total / $n) . ' (total ' . formatMoney($total) . ') em ' . formatDate($data));
        flash('success', "Compra lançada em {$n}x de " . formatMoney($total / $n) . ' no ' . $cartao['nome'] . '.');
        redirect(isset($_POST['salvar_outra']) ? '/cartoes/compra.php?cartao=' . $cartao['id'] : '/cartoes/listar.php');
    }
}

$pageTitle = $id ? 'Editar compra · ' . $compra['descricao'] : 'Lançar compra no cartão';
$pageBreadcrumb = 'Financeiro · Cartões de crédito';
$activeMenu = 'cartoes';
$pageAction = '<a href="/cartoes/listar.php" class="btn btn-outline-secondary">Cancelar</a>'
    . ($id
        ? '<form method="post" class="d-inline" onsubmit="return confirm(\'Excluir a compra e todas as parcelas?\');"><input type="hidden" name="acao" value="excluir"><button class="btn btn-outline-danger"><i class="bi bi-trash"></i> Excluir compra</button></form>'
        : '<button type="submit" form="form-compra" name="salvar_outra" value="1" class="btn btn-outline-secondary">Salvar e lançar outra</button>')
    . '<button type="submit" form="form-compra" class="btn btn-brand"><i class="bi bi-check-lg"></i> ' . ($id ? 'Salvar correção' : 'Salvar') . '</button>';
require __DIR__ . '/../../includes/layout_header.php';
?>

<?php if ($erros): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if (!$cartoes): ?>
    <div class="alert alert-warning">Cadastre um cartão primeiro. <a href="/cartoes/form.php">Novo cartão</a></div>
<?php else: ?>
<div class="row g-4">
    <div class="col-lg-7">
        <form method="post" id="form-compra">
            <?php $trava = $travada ? 'disabled' : ''; ?>
            <?php if ($travada): ?><div class="alert alert-light border small">Esta compra tem parcela em fatura já paga: dá para mudar só a descrição e a categoria. Para refazer as parcelas, estorne o pagamento da fatura antes.</div><?php endif; ?>
            <div class="secao">
                <div class="secao-titulo"><i class="bi bi-bag-plus"></i> Compra</div>
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label">Cartão *</label>
                        <select name="cartao_id" id="cartao_id" class="form-select" required <?= $trava ?>>
                            <?php foreach ($cartoes as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) ($_POST['cartao_id'] ?? $_GET['cartao'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nome']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6"><label class="form-label">Data da compra *</label><input type="date" name="data_compra" id="data_compra" class="form-control" required <?= $trava ?> value="<?= e($_POST['data_compra'] ?? date('Y-m-d')) ?>"></div>
                    <div class="col-12"><label class="form-label">Descrição *</label><input type="text" name="descricao" class="form-control" required maxlength="190" autofocus value="<?= e($_POST['descricao'] ?? '') ?>"></div>
                    <div class="col-md-4"><label class="form-label">Valor total (R$) *</label><input type="text" name="valor_total" id="valor_total" class="form-control" data-moeda inputmode="decimal" required <?= $trava ?> value="<?= e($_POST['valor_total'] ?? '') ?>"></div>
                    <div class="col-md-4"><label class="form-label">Parcelas *</label>
                        <select name="parcelas" id="parcelas" class="form-select" <?= $trava ?>>
                            <?php for ($i = 1; $i <= 24; $i++): ?><option value="<?= $i ?>" <?= (int) ($_POST['parcelas'] ?? 1) === $i ? 'selected' : '' ?>><?= $i ?>x</option><?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-4"><label class="form-label">1ª parcela na fatura de</label><input type="month" name="primeira_fatura" id="primeira_fatura" class="form-control" <?= $trava ?> value="<?= e($_POST['primeira_fatura'] ?? '') ?>"><div class="form-text" id="primeira-auto"></div></div>
                    <div class="col-md-6"><label class="form-label">Categoria</label><select name="categoria_id" class="form-select"><?= opcoesCategorias($pdo, 'despesa', idOuNull($_POST['categoria_id'] ?? null) ?? (int) $pdo->query("SELECT id FROM categorias WHERE nome = 'Cartão de crédito'")->fetchColumn()) ?></select></div>
                </div>
            </div>
        </form>
    </div>
    <div class="col-lg-5">
        <div class="secao-titulo"><i class="bi bi-calendar3"></i> Parcelas</div>
        <div class="card"><table class="table table-sm mb-0"><thead><tr><th>Parcela</th><th>Fatura</th><th class="text-end">Valor</th></tr></thead><tbody id="previa"></tbody></table></div>
        <?php if ($id): ?>
        <div class="secao-titulo mt-4"><i class="bi bi-receipt"></i> Parcelas lançadas hoje</div>
        <div class="card"><table class="table table-sm mb-0">
            <thead><tr><th>Parcela</th><th>Fatura</th><th class="text-end">Valor</th><th>Situação</th></tr></thead>
            <tbody>
            <?php foreach ($parcelas as $p): ?>
                <tr><td><?= (int) $p['numero'] ?>/<?= (int) $compra['parcelas'] ?></td><td><?= nomeMes($p['competencia']) ?></td><td class="text-end text-num"><?= formatMoney($p['valor']) ?></td><td><?= badgeStatus($p['status']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php if (!$travada): ?><div class="form-text">Ao salvar, as parcelas são refeitas conforme a prévia acima.</div><?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<script>
// moeda.js carrega no rodapé: monta a prévia depois da página pronta.
document.addEventListener('DOMContentLoaded', function () {
    const cartoes = <?= json_encode(array_map(fn ($c) => ['id' => (int) $c['id'], 'fech' => (int) $c['dia_fechamento'], 'venc' => (int) $c['dia_vencimento']], $cartoes)) ?>;
    const meses = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
    const $ = (id) => document.getElementById(id);
    // Mesma regra de competenciaDaCompra() no PHP.
    function competencia(cartao, data) {
        let [a, m, d] = data.split('-').map(Number);
        m -= 1;
        if (d >= cartao.fech) m += 1;
        if (cartao.venc <= cartao.fech) m += 1;
        return new Date(Date.UTC(a, m, 1));
    }
    function atualizar() {
        const cartao = cartoes.find((c) => String(c.id) === $('cartao_id').value);
        const data = $('data_compra').value;
        const total = Moeda.ler($('valor_total').value);
        const n = Number($('parcelas').value);
        if (!cartao || !data) return;
        const auto = competencia(cartao, data);
        $('primeira-auto').textContent = 'Automático: ' + meses[auto.getUTCMonth()] + '/' + auto.getUTCFullYear();
        let inicio = auto;
        if ($('primeira_fatura').value) { const [a, m] = $('primeira_fatura').value.split('-').map(Number); inicio = new Date(Date.UTC(a, m - 1, 1)); }
        const base = Math.floor(total / n * 100) / 100;
        let html = '';
        for (let i = 0; i < n; i++) {
            const d = new Date(Date.UTC(inicio.getUTCFullYear(), inicio.getUTCMonth() + i, 1));
            const v = i === 0 ? total - base * (n - 1) : base;
            html += `<tr><td>${i + 1}/${n}</td><td>${meses[d.getUTCMonth()]}/${d.getUTCFullYear()}</td><td class="text-end">${total ? Moeda.reais(v) : '-'}</td></tr>`;
        }
        $('previa').innerHTML = html;
    }
    ['cartao_id', 'data_compra', 'valor_total', 'parcelas', 'primeira_fatura'].forEach((id) => { $(id).addEventListener('input', atualizar); $(id).addEventListener('change', atualizar); });
    atualizar();
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
