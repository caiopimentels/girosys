<?php
// Conta a receber avulsa (fora de operação de desconto): cliente e pagador,
// e uma ou várias parcelas com datas e valores diferentes (cada uma vira um título).
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

$pdo = getDB();
$erros = [];
$parcelas = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $descricao = input('descricao');
    $contaId = idOuNull($_POST['conta_destino_id'] ?? null);
    $categoriaId = idOuNull($_POST['categoria_id'] ?? null);
    $tipo = array_key_exists(input('tipo'), tiposTitulo()) ? input('tipo') : 'outro';
    foreach ((array) ($_POST['parcelas'] ?? []) as $p) {
        $venc = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($p['vencimento'] ?? '')) ? $p['vencimento'] : '';
        $valor = paraDecimal($p['valor'] ?? '');
        if ($venc || $valor > 0) {
            $parcelas[] = ['vencimento' => $venc, 'valor' => $valor, 'numero' => trim((string) ($p['numero'] ?? ''))];
        }
    }

    if ($descricao === '') {
        $erros[] = 'Informe a descrição.';
    }
    if (!$parcelas) {
        $erros[] = 'Informe o vencimento e o valor de pelo menos uma parcela.';
    }
    foreach ($parcelas as $n => $p) {
        if (!$p['vencimento'] || $p['valor'] <= 0) {
            $erros[] = 'Parcela ' . ($n + 1) . ': informe vencimento e valor.';
        }
    }
    if (!$erros) {
        $pdo->beginTransaction();
        $pagadorId = obterOuCriarPessoa($pdo, input('pagador_nome'), input('pagador_documento'), 'pagador');
        $clienteId = obterOuCriarPessoa($pdo, input('cliente_nome'), '', 'cliente');
        $ins = $pdo->prepare(
            'INSERT INTO contas_receber (tipo, emitente_id, cliente_id, descricao, categoria_id, numero_titulo, valor, data_vencimento, conta_destino_id, observacao)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $n = count($parcelas);
        foreach ($parcelas as $i => $p) {
            $ins->execute([$tipo, $pagadorId, $clienteId, $descricao . ($n > 1 ? ' (' . ($i + 1) . "/{$n})" : ''), $categoriaId, $p['numero'] ?: null,
                $p['valor'], $p['vencimento'], $contaId, input('observacao') ?: null]);
        }
        $pdo->commit();
        flash('success', ($n > 1 ? "{$n} parcelas lançadas, total " : 'Conta a receber lançada: ') . formatMoney(array_sum(array_column($parcelas, 'valor'))) . '.');
        redirect(isset($_POST['salvar_outra']) ? '/receber/form.php' : '/receber/listar.php');
    }
}

$pageTitle = 'Nova conta a receber';
$pageBreadcrumb = 'Financeiro · Contas a receber';
$activeMenu = 'receber';
$pageAction = '<a href="/receber/listar.php" class="btn btn-outline-secondary">Cancelar</a>'
    . '<button type="submit" form="form-receber" name="salvar_outra" value="1" class="btn btn-outline-secondary">Salvar e lançar outra</button>'
    . '<button type="submit" form="form-receber" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>';
require __DIR__ . '/../../includes/layout_header.php';
?>

<?php if ($erros): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" id="form-receber" style="max-width: 1000px;" autocomplete="off">
    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-arrow-down-circle"></i> Lançamento avulso</div>
        <p class="small text-muted">Para títulos descontados de clientes use <a href="/operacoes/form.php">Nova operação de desconto</a>, que já calcula os juros.</p>
        <div class="row g-3">
            <div class="col-md-12"><label class="form-label">Descrição *</label><input type="text" name="descricao" class="form-control" required maxlength="180" autofocus value="<?= e($_POST['descricao'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label">Cliente <span class="text-muted small">(opcional)</span></label><input type="text" name="cliente_nome" class="form-control" data-busca-pessoa="cliente" autocomplete="off-busca" value="<?= e($_POST['cliente_nome'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Pagador</label><input type="text" name="pagador_nome" class="form-control" data-busca-pessoa="pagador" autocomplete="off-busca" value="<?= e($_POST['pagador_nome'] ?? '') ?>"></div>
            <div class="col-md-2"><label class="form-label">CPF/CNPJ <span class="text-muted small">(se novo)</span></label><input type="text" name="pagador_documento" class="form-control" inputmode="numeric" value="<?= e($_POST['pagador_documento'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Tipo</label><select name="tipo" class="form-select"><?php foreach (tiposTitulo() as $k => $v): ?><option value="<?= e($k) ?>" <?= ($_POST['tipo'] ?? 'boleto') === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label">Cai na conta</label><select name="conta_destino_id" class="form-select"><?= opcoesContas($pdo, idOuNull($_POST['conta_destino_id'] ?? null), '— definir ao receber —') ?></select></div>
            <div class="col-md-4"><label class="form-label">Categoria</label><select name="categoria_id" class="form-select"><?= opcoesCategorias($pdo, 'receita', idOuNull($_POST['categoria_id'] ?? null)) ?></select></div>
        </div>
    </div>

    <?php // Uma linha por parcela (datas e valores podem ser diferentes) — assets/js/parcelas.js. ?>
    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-list-ol"></i> Parcelas
            <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" data-bs-toggle="collapse" data-bs-target="#dividir-igual"><i class="bi bi-distribute-vertical"></i> Dividir em parcelas iguais</button>
        </div>
        <div class="collapse mb-2" id="dividir-igual"><div class="card card-body py-2">
            <div class="row g-2 align-items-end">
                <div class="col-md-3"><label class="form-label small">Valor total (R$)</label><input type="text" class="form-control form-control-sm" id="div-total" data-moeda inputmode="decimal"></div>
                <div class="col-md-2"><label class="form-label small">Parcelas</label><input type="number" class="form-control form-control-sm" id="div-qtd" min="1" max="60" value="3"></div>
                <div class="col-md-2"><label class="form-label small">1º vencimento</label><input type="date" class="form-control form-control-sm" id="div-venc" value="<?= date('Y-m-d', strtotime('+30 days')) ?>"></div>
                <div class="col-md-2"><label class="form-label small">Intervalo</label><select class="form-select form-select-sm" id="div-intervalo">
                    <option value="mes">Mensal</option><option value="7">Semanal</option><option value="15">Quinzenal (15 dias)</option><option value="30">A cada 30 dias</option><option value="x">A cada X dias...</option></select></div>
                <div class="col-md-1 d-none" id="bloco-div-dias"><label class="form-label small">Dias</label><input type="number" class="form-control form-control-sm" id="div-dias" min="1" max="365" value="10"></div>
                <div class="col-md-1"><button type="button" class="btn btn-sm btn-brand w-100" id="div-gerar">Preencher</button></div>
            </div>
        </div></div>
        <div class="card">
            <table class="table table-sm mb-0 align-middle tabela-parcelas" id="parcelas-receber">
                <thead><tr><th style="width: 40px;">#</th><th style="width: 190px;">Vencimento</th><th style="width: 190px;">Valor (R$)</th><th>Nº do documento</th><th style="width: 50px;"></th></tr></thead>
                <tbody>
                <?php foreach ($parcelas ?: [['vencimento' => date('Y-m-d', strtotime('+30 days')), 'valor' => 0, 'numero' => '']] as $i => $p): ?>
                    <tr>
                        <td class="text-muted small numero-parcela"></td>
                        <td><input type="date" name="parcelas[<?= $i ?>][vencimento]" class="form-control form-control-sm" data-campo="vencimento" value="<?= e($p['vencimento']) ?>"></td>
                        <td><input type="text" name="parcelas[<?= $i ?>][valor]" class="form-control form-control-sm text-end" data-campo="valor" data-parcela-valor data-moeda inputmode="decimal" value="<?= $p['valor'] > 0 ? formatDecimal($p['valor']) : '' ?>"></td>
                        <td><input type="text" name="parcelas[<?= $i ?>][numero]" class="form-control form-control-sm" data-campo="numero" maxlength="60" value="<?= e($p['numero']) ?>"></td>
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
                        <td><input type="date" name="parcelas[__i][vencimento]" class="form-control form-control-sm" data-campo="vencimento"></td>
                        <td><input type="text" name="parcelas[__i][valor]" class="form-control form-control-sm text-end" data-campo="valor" data-parcela-valor data-moeda inputmode="decimal"></td>
                        <td><input type="text" name="parcelas[__i][numero]" class="form-control form-control-sm" data-campo="numero" maxlength="60"></td>
                        <td class="text-end"><button type="button" class="btn btn-sm btn-link text-danger remover-parcela" title="Tirar"><i class="bi bi-x-lg"></i></button></td>
                    </tr>
                </template>
            </table>
        </div>
    </div>

    <div class="secao">
        <label class="form-label">Observação</label>
        <textarea name="observacao" class="form-control" rows="2"><?= e($_POST['observacao'] ?? '') ?></textarea>
    </div>
</form>
<?= buscaPessoas($pdo) ?>
<script>
// "Dividir em parcelas iguais": troca as linhas por N parcelas do mesmo valor
// (os centavos que sobram vão na primeira), que depois dá para ajustar.
document.addEventListener('DOMContentLoaded', function () {
    const tabela = document.getElementById('parcelas-receber');
    // "A cada X dias": mostra o campo para digitar o intervalo.
    document.getElementById('div-intervalo').addEventListener('change', function () {
        document.getElementById('bloco-div-dias').classList.toggle('d-none', this.value !== 'x');
        if (this.value === 'x') document.getElementById('div-dias').focus();
    });
    document.getElementById('div-gerar').addEventListener('click', function () {
        const total = Moeda.ler(document.getElementById('div-total').value);
        const n = Math.max(1, Math.min(60, parseInt(document.getElementById('div-qtd').value, 10) || 1));
        const venc = document.getElementById('div-venc').value;
        const escolha = document.getElementById('div-intervalo').value;
        const intervalo = escolha === 'x' ? String(Math.max(1, parseInt(document.getElementById('div-dias').value, 10) || 1)) : escolha;
        if (!total || !venc) { alert('Informe o valor total e o 1º vencimento.'); return; }
        const base = Math.floor(total / n * 100) / 100;
        const [a, m, d] = venc.split('-').map(Number);
        tabela.parcelas.limpar();
        for (let i = 0; i < n; i++) {
            let data;
            if (intervalo === 'mes') {
                const fim = new Date(Date.UTC(a, m + i, 0)).getUTCDate();
                data = new Date(Date.UTC(a, m - 1 + i, Math.min(d, fim)));
            } else {
                data = new Date(Date.UTC(a, m - 1, d + Number(intervalo) * i));
            }
            tabela.parcelas.adicionar({ vencimento: data.toISOString().slice(0, 10), valor: Moeda.formatar(i === 0 ? total - base * (n - 1) : base) });
        }
        bootstrap.Collapse.getOrCreateInstance(document.getElementById('dividir-igual')).hide();
    });
});
</script>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
