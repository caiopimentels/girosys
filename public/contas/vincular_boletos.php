<?php
// Boletos emitidos fora do sistema (app/internet banking do Inter): busca no
// banco os do período que ainda não estão em nenhum título, sugere o título
// em aberto que bate (valor, vencimento, pagador ou nosso número) e liga os
// marcados — a partir daí baixam sozinhos como os emitidos pelo sistema.
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAdmin();

$pdo = getDB();
$contas = array_values(array_filter(contasInter($pdo), fn ($c) => !pendenciasInter($c)));
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? ($contas[0]['id'] ?? 0));
$conta = contaBancaria($pdo, $id);
if (!$conta || !contaTemInter($conta) || pendenciasInter($conta)) {
    flash('danger', 'Configure a API do banco de uma conta do Inter primeiro.');
    redirect('/configuracoes/contas.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ligados = [];
    $erros = [];
    foreach ((array) ($_POST['vinculo'] ?? []) as $codigo => $tituloId) {
        $tituloId = (int) $tituloId;
        if (!$tituloId || empty($_POST['marcado'][$codigo])) {
            continue;
        }
        $cobranca = json_decode((string) ($_POST['cobranca'][$codigo] ?? ''), true);
        $stmt = $pdo->prepare('SELECT r.*, e.nome AS emitente_nome FROM contas_receber r LEFT JOIN pessoas e ON e.id = r.emitente_id WHERE r.id = ?');
        $stmt->execute([$tituloId]);
        $titulo = $stmt->fetch();
        if (!$titulo || !is_array($cobranca) || ($cobranca['codigo'] ?? '') !== $codigo) {
            continue;
        }
        try {
            interContextoTitulo($tituloId, true);
            $situacao = ligarCobrancaAoTitulo($pdo, $titulo, $conta, $cobranca);
            $ligados[] = '#' . $tituloId . ' ' . ($titulo['emitente_nome'] ?? '') . ' ' . formatMoney($titulo['valor']) . ' (' . rotuloSituacaoBoleto($situacao) . ')';
        } catch (InvalidArgumentException | RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $erros[] = '#' . $tituloId . ': ' . $e->getMessage();
        }
    }
    if ($ligados) {
        registrarAtividade('boletos', 'vinculo_boleto', count($ligados) . ' boleto(s) do ' . $conta['nome'] . ' emitidos fora ligados: ' . implode('; ', $ligados));
        flash('success', count($ligados) . ' boleto(s) ligado(s) aos títulos. Agora baixam sozinhos quando forem pagos.');
    }
    if ($erros) {
        flash('danger', 'Não ligados: ' . implode(' · ', $erros));
    }
    if (!$ligados && !$erros) {
        flash('warning', 'Nenhum boleto marcado com título escolhido.');
    }
    redirect('/contas/vincular_boletos.php?' . http_build_query(['id' => $id, 'de' => $_POST['de'] ?? '', 'ate' => $_POST['ate'] ?? '', 'buscar' => 1]));
}

[$de, $ate] = periodoFiltro(date('Y-m-d'), date('Y-m-d', strtotime('+12 months')));
$de = $de ?: date('Y-m-d');
$ate = $ate ?: date('Y-m-d', strtotime($de . ' +12 months'));
$cobrancas = null;
$titulos = [];
$erroBusca = null;
if (isset($_GET['buscar'])) {
    try {
        [$cobrancas, $titulos] = sugerirVinculosInter($pdo, $conta, $de, $ate);
    } catch (Throwable $e) {
        $erroBusca = $e->getMessage();
    }
}

$pageTitle = $conta['nome'];
$pageBreadcrumb = 'Administração · Contas bancárias · Vincular boletos emitidos fora';
$activeMenu = 'configuracoes';
$pageAction = '<a href="/contas/conferir_boletos.php?id=' . $id . '" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Conferir boletos</a>';
require __DIR__ . '/../../includes/layout_header.php';
$abaAtiva = 'contas';
require __DIR__ . '/../../includes/abas_configuracoes.php';
$contaAbaId = $id;
$contaAba = 'conferir';
require __DIR__ . '/../../includes/abas_conta_bancaria.php';
?>

<p class="text-muted small" style="max-width: 900px;">
    Boletos que você emitiu direto no Inter (app ou internet banking) e que ainda não estão ligados a nenhum título. Para cada um, o sistema sugere o título em aberto
    que bate no <strong>valor + vencimento + pagador</strong> (ou no nosso número, se você anotou no campo Nº do título). Confira, ajuste se precisar e clique em <strong>Ligar marcados</strong>.
</p>

<div class="filtros">
    <form method="get">
        <input type="hidden" name="buscar" value="1">
        <select name="id" class="form-select form-select-sm" style="width: 200px;">
            <?php foreach ($contas as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $id ? 'selected' : '' ?>><?= e($c['nome']) ?></option><?php endforeach; ?>
        </select>
        <?php $dataInicio = $de; $dataFim = $ate; $periodoRotulo = 'Vencimento'; require __DIR__ . '/../../includes/filtro_periodo.php'; ?>
        <button class="btn btn-sm btn-brand" type="submit"><i class="bi bi-search"></i> Buscar no Inter</button>
    </form>
</div>

<?php if ($erroBusca): ?>
    <div class="alert alert-danger">Não foi possível buscar no Inter: <?= e($erroBusca) ?></div>
<?php elseif ($cobrancas === null): ?>
    <div class="alert alert-light border small">Escolha a conta e o período de vencimento e clique em <strong>Buscar no Inter</strong>.</div>
<?php elseif (!$cobrancas): ?>
    <div class="alert alert-light border small">Nenhum boleto do Inter nesse período fora do sistema — todos já estão ligados a títulos.</div>
<?php else: ?>
<form method="post">
    <input type="hidden" name="id" value="<?= (int) $id ?>"><input type="hidden" name="de" value="<?= e($de) ?>"><input type="hidden" name="ate" value="<?= e($ate) ?>">
    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead><tr><th style="width: 32px;"><input type="checkbox" class="form-check-input" title="Marcar todos com sugestão" onclick="document.querySelectorAll('.marcar-vinculo').forEach((c) => { if (c.closest('tr').querySelector('select').value) c.checked = this.checked; });"></th>
                    <th>Vencimento</th><th>Pagador no Inter</th><th>Nosso nº</th><th>Situação</th><th class="text-end">Valor</th><th style="min-width: 320px;">Título do sistema</th></tr></thead>
                <tbody>
                <?php foreach ($cobrancas as $c): $sug = $c['sugestao']; ?>
                    <tr>
                        <td><input type="checkbox" class="form-check-input marcar-vinculo" name="marcado[<?= e($c['codigo']) ?>]" value="1" <?= $sug ? 'checked' : '' ?>>
                            <input type="hidden" name="cobranca[<?= e($c['codigo']) ?>]" value="<?= e(json_encode(array_diff_key($c, ['sugestao' => 1]), JSON_UNESCAPED_UNICODE)) ?>"></td>
                        <td class="text-num"><?= formatDate($c['vencimento']) ?></td>
                        <td><?= e($c['pagador_nome'] ?: '-') ?><div class="small text-muted"><?= e($c['pagador_documento'] ? documentoFormatado($c['pagador_documento']) : '') ?></div></td>
                        <td class="small font-monospace"><?= e($c['nosso_numero'] ?: '-') ?></td>
                        <td><?= e(rotuloSituacaoBoleto($c['situacao'])) ?></td>
                        <td class="text-end text-num"><?= formatMoney($c['valor']) ?></td>
                        <td>
                            <select name="vinculo[<?= e($c['codigo']) ?>]" class="form-select form-select-sm" onchange="this.closest('tr').querySelector('.marcar-vinculo').checked = this.value !== '';">
                                <option value="">— não ligar —</option>
                                <?php foreach ($titulos as $t): if (abs((float) $t['valor'] - $c['valor']) > max(1, $c['valor'] * 0.2) && (!$sug || (int) $sug['id'] !== (int) $t['id'])) continue; ?>
                                    <option value="<?= (int) $t['id'] ?>" <?= $sug && (int) $sug['id'] === (int) $t['id'] ? 'selected' : '' ?>>#<?= (int) $t['id'] ?> · <?= formatDate($t['data_vencimento']) ?> · <?= formatMoney($t['valor']) ?> · <?= e($t['pagador_nome'] ?? 'sem pagador') ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!$sug): ?><div class="small text-muted">Nenhum título bateu sozinho — escolha na lista, se for de algum.</div><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-transparent d-flex align-items-center gap-2">
            <span class="small text-muted"><?= count($cobrancas) ?> boleto(s) fora do sistema · <?= count(array_filter(array_column($cobrancas, 'sugestao'))) ?> com título sugerido</span>
            <button class="btn btn-brand ms-auto"><i class="bi bi-link-45deg"></i> Ligar marcados</button>
        </div>
    </div>
</form>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
