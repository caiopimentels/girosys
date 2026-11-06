<?php
// Importa a planilha de boletos do banco (relatório do Inter, .xlsx) como se
// fosse um arquivo de retorno: mostra cada boleto ao lado do título do
// sistema e, ao confirmar, dá a baixa nos pagos (valor e data do banco) e
// guarda o nosso número nos que estão em aberto.
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirPermissaoAcao('baixar_receber', 'Seu perfil não pode dar baixa em recebimentos.');

$pdo = getDB();
$contas = $pdo->query('SELECT id, nome, integracao FROM contas_bancarias WHERE ativo = 1 ORDER BY (integracao = \'inter\') DESC, nome')->fetchAll();
$token = preg_replace('/[^a-f0-9]/', '', (string) ($_GET['t'] ?? $_POST['t'] ?? ''));
$sessao = $token !== '' ? ($_SESSION['importacao_banco'][$token] ?? null) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'enviar') {
    try {
        $arquivo = $_FILES['arquivo'] ?? null;
        if (!$arquivo || $arquivo['error'] !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Escolha a planilha (.xlsx) exportada do banco.');
        }
        if ($arquivo['size'] > 10 * 1024 * 1024) {
            throw new InvalidArgumentException('Planilha maior que 10 MB.');
        }
        $contaId = (int) ($_POST['conta_id'] ?? 0);
        if (!in_array($contaId, array_map('intval', array_column($contas, 'id')), true)) {
            throw new InvalidArgumentException('Escolha a conta em que o dinheiro dos boletos entrou.');
        }
        $boletos = interpretarPlanilhaBoletos(lerPlanilha($arquivo['tmp_name'], $arquivo['name']));
        if (!$boletos) {
            throw new InvalidArgumentException('A planilha não tem nenhum boleto.');
        }
        $hash = hash_file('sha256', $arquivo['tmp_name']);
        $token = bin2hex(random_bytes(8));
        // Só o último envio fica guardado na sessão.
        $_SESSION['importacao_banco'] = [$token => ['arquivo' => $arquivo['name'], 'hash' => $hash, 'conta_id' => $contaId, 'boletos' => $boletos]];
        redirect('/receber/importar_banco.php?t=' . $token);
    } catch (InvalidArgumentException | RuntimeException $e) {
        flash('danger', $e->getMessage());
        redirect('/receber/importar_banco.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'confirmar') {
    if (!$sessao) {
        flash('danger', 'A importação expirou. Envie a planilha de novo.');
        redirect('/receber/importar_banco.php');
    }
    $escolhas = [];
    foreach ((array) ($_POST['marcado'] ?? []) as $i => $sim) {
        $escolhas[(int) $i] = ['titulo_id' => (int) ($_POST['titulo'][$i] ?? 0), 'acao' => (string) ($_POST['acao_linha'][$i] ?? '')];
    }
    $pdo->beginTransaction();
    try {
        [$baixados, $total, $numeros, $avisos, $detalhes] = aplicarImportacaoBoletos($pdo, $sessao['boletos'], $escolhas, (int) $sessao['conta_id']);
        $pdo->prepare('INSERT INTO importacoes_banco (conta_id, arquivo, hash, linhas, baixados, total_baixado, numeros_gravados, usuario_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$sessao['conta_id'], mb_substr($sessao['arquivo'], 0, 255), $sessao['hash'], count($sessao['boletos']), $baixados, $total, $numeros, usuarioAtualId()]);
        $nomeConta = array_column($contas, 'nome', 'id')[$sessao['conta_id']] ?? '';
        registrarAtividade('contas_receber', 'baixa', 'Planilha do banco "' . $sessao['arquivo'] . '" (' . $nomeConta . '): ' . $baixados . ' baixa(s), total ' . formatMoney($total)
            . ', ' . $numeros . ' nosso(s) número(s) gravado(s)' . ($detalhes ? ' · ' . implode('; ', $detalhes) : ''));
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash('danger', 'Nada foi gravado: ' . $e->getMessage());
        redirect('/receber/importar_banco.php?t=' . $token);
    }
    unset($_SESSION['importacao_banco'][$token]);
    flash('success', "Importação concluída: {$baixados} boleto(s) baixado(s) no {$nomeConta}, total " . formatMoney($total) . ($numeros ? " · {$numeros} nosso(s) número(s) gravado(s) nos títulos" : '') . '.');
    foreach ($avisos as $aviso) {
        flash('warning', $aviso);
    }
    redirect('/receber/importar_banco.php');
}

// Prévia: casa cada boleto com um título (de novo a cada abertura, pega o que mudou).
$boletos = $sessao ? casarBoletosPlanilha($pdo, $sessao['boletos']) : null;
$jaImportado = null;
if ($sessao) {
    $stmt = $pdo->prepare('SELECT i.*, u.nome AS usuario_nome FROM importacoes_banco i LEFT JOIN usuarios u ON u.id = i.usuario_id WHERE i.hash = ? ORDER BY i.id DESC LIMIT 1');
    $stmt->execute([$sessao['hash']]);
    $jaImportado = $stmt->fetch();
    // Títulos em aberto para escolher à mão quando não casou.
    $stmt = $pdo->query("SELECT r.id, r.valor, r.data_vencimento, r.boleto_nosso_numero, e.nome AS pagador_nome FROM contas_receber r LEFT JOIN pessoas e ON e.id = r.emitente_id
        WHERE r.status IN ('pendente', 'devolvido') ORDER BY r.data_vencimento, r.id");
    $abertos = $stmt->fetchAll();
}
$rotulosAcao = [
    'baixar' => ['success', 'Dar baixa'],
    'gravar_numero' => ['info', 'Gravar nosso número'],
    'ja_baixado' => ['secondary', 'Já baixado'],
    'nada' => ['light', 'Nada a fazer'],
    'sem_titulo' => ['warning', 'Sem título'],
];
$ultimas = $pdo->query('SELECT i.*, c.nome AS conta_nome, u.nome AS usuario_nome FROM importacoes_banco i LEFT JOIN contas_bancarias c ON c.id = i.conta_id LEFT JOIN usuarios u ON u.id = i.usuario_id ORDER BY i.id DESC LIMIT 10')->fetchAll();

$pageTitle = 'Importar planilha do banco';
$pageBreadcrumb = 'Financeiro · Contas a receber';
$activeMenu = 'receber';
$pageAction = '<a href="/receber/listar.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Contas a receber</a>';
require __DIR__ . '/../../includes/layout_header.php';
?>

<?php if (!$sessao): ?>
<div class="secao" style="max-width: 900px;">
    <div class="secao-titulo"><i class="bi bi-file-earmark-spreadsheet"></i> Planilha de boletos</div>
    <p class="small text-muted">
        No Internet Banking do Inter, exporte o relatório de boletos (colunas CLIENTE, IDENTIFICADOR, VENCIMENTO, VALOR, STATUS, DATA RECEBIMENTO, VALOR RECEBIDO) em <strong>.xlsx</strong>.
        O sistema acha o título de cada boleto pelo <strong>nosso número</strong> (o IDENTIFICADOR) ou, se o título ainda não tiver, pelo valor + vencimento + pagador.
        Você confere antes de gravar: os <strong>pagos</strong> recebem a baixa com o valor e a data do banco, e nos <strong>em aberto</strong> o nosso número fica gravado no título.
    </p>
    <form method="post" enctype="multipart/form-data" class="row g-3 align-items-end">
        <input type="hidden" name="acao" value="enviar">
        <div class="col-md-6"><label class="form-label">Planilha (.xlsx ou .csv)</label><input type="file" name="arquivo" class="form-control" accept=".xlsx,.csv" required></div>
        <div class="col-md-4"><label class="form-label">O dinheiro entrou na conta</label>
            <select name="conta_id" class="form-select" required><?php foreach ($contas as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['nome']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><button class="btn btn-brand w-100"><i class="bi bi-upload"></i> Ler planilha</button></div>
    </form>
</div>

<?php if ($ultimas): ?>
<div class="secao" style="max-width: 900px;">
    <div class="secao-titulo"><i class="bi bi-clock-history"></i> Últimas importações</div>
    <div class="card"><table class="table table-sm mb-0">
        <thead><tr><th>Quando</th><th>Arquivo</th><th>Conta</th><th class="text-center">Boletos</th><th class="text-center">Baixas</th><th class="text-end">Total baixado</th><th>Por</th></tr></thead>
        <tbody><?php foreach ($ultimas as $u): ?>
            <tr><td class="text-num small"><?= formatDateTime($u['criado_em']) ?></td><td class="small"><?= e($u['arquivo']) ?></td><td class="small"><?= e($u['conta_nome'] ?? '-') ?></td>
                <td class="text-center"><?= (int) $u['linhas'] ?></td><td class="text-center"><?= (int) $u['baixados'] ?></td><td class="text-end text-num"><?= formatMoney($u['total_baixado']) ?></td><td class="small"><?= e($u['usuario_nome'] ?? '-') ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
</div>
<?php endif; ?>

<?php else: ?>
    <?php
    $contagem = array_count_values(array_column($boletos, 'acao'));
    $totalBaixar = array_sum(array_map(fn ($b) => $b['acao'] === 'baixar' ? ($b['valor_pago'] ?: $b['valor']) : 0, $boletos));
    $nomeConta = array_column($contas, 'nome', 'id')[$sessao['conta_id']] ?? '';
    ?>
    <?php if ($jaImportado): ?>
        <div class="alert alert-warning small">Esta mesma planilha já foi importada em <?= formatDateTime($jaImportado['criado_em']) ?> por <?= e($jaImportado['usuario_nome'] ?? '-') ?> (<?= (int) $jaImportado['baixados'] ?> baixa(s)). Os títulos já baixados aparecem como "Já baixado" e não baixam de novo.</div>
    <?php endif; ?>
    <div class="d-flex flex-wrap gap-2 mb-3 small">
        <span class="badge text-bg-light border"><?= e($sessao['arquivo']) ?> · <?= count($boletos) ?> boleto(s) · entra no <?= e($nomeConta) ?></span>
        <?php foreach ($rotulosAcao as $k => [$cor, $rotulo]): if (empty($contagem[$k])) continue; ?>
            <button type="button" class="badge text-bg-<?= $cor ?> border-0 filtro-acao" data-acao="<?= $k ?>" title="Mostrar só estes"><?= $rotulo ?>: <?= (int) $contagem[$k] ?></button>
        <?php endforeach; ?>
        <button type="button" class="badge text-bg-dark border-0 filtro-acao" data-acao="">Mostrar todos</button>
        <a href="/receber/importar_banco.php" class="ms-auto">Enviar outra planilha</a>
    </div>

    <form method="post" id="form-importacao">
        <input type="hidden" name="acao" value="confirmar"><input type="hidden" name="t" value="<?= e($token) ?>">
        <div class="card">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead><tr><th style="width: 32px;"><input type="checkbox" class="form-check-input" id="marcar-todos" checked title="Marcar/desmarcar os que têm o que fazer"></th>
                        <th>Pagador no banco</th><th>Nosso nº</th><th>Vencimento</th><th class="text-end">Valor</th><th>No banco</th><th>Pago</th><th style="min-width: 300px;">Título do sistema</th><th>O que fazer</th></tr></thead>
                    <tbody>
                    <?php foreach ($boletos as $i => $b): $t = $b['titulo']; [$cor, $rotulo] = $rotulosAcao[$b['acao']]; $marcavel = in_array($b['acao'], ['baixar', 'gravar_numero', 'sem_titulo'], true); ?>
                        <tr data-acao="<?= $b['acao'] ?>">
                            <td><?php if ($marcavel): ?><input type="checkbox" class="form-check-input marcar-linha" name="marcado[<?= $i ?>]" value="1" <?= $b['acao'] !== 'sem_titulo' ? 'checked' : '' ?>><?php endif; ?>
                                <input type="hidden" name="acao_linha[<?= $i ?>]" value="<?= $b['situacao'] === 'pago' ? 'baixar' : 'gravar_numero' ?>"></td>
                            <td class="small"><?= e($b['pagador']) ?></td>
                            <td class="small font-monospace"><?= e($b['nosso_numero']) ?></td>
                            <td class="text-num small"><?= $b['vencimento'] ? formatDate($b['vencimento']) : '-' ?></td>
                            <td class="text-end text-num"><?= formatMoney($b['valor']) ?></td>
                            <td class="small"><?= e($b['situacao_banco']) ?></td>
                            <td class="small text-num"><?= $b['situacao'] === 'pago' ? formatMoney($b['valor_pago'] ?: $b['valor']) . ' em ' . ($b['data_pagamento'] ? formatDate($b['data_pagamento']) : '?') : '-' ?></td>
                            <td class="small">
                                <?php if ($t): ?>
                                    <input type="hidden" name="titulo[<?= $i ?>]" value="<?= (int) $t['id'] ?>">
                                    <a href="/receber/baixar.php?id=<?= (int) $t['id'] ?>" target="_blank">#<?= (int) $t['id'] ?></a> <?= e($t['pagador_nome'] ?? '-') ?> · <?= formatMoney($t['valor']) ?> · vence <?= formatDate($t['data_vencimento']) ?>
                                    <div class="text-muted"><?= badgeStatus(statusTelaReceber($t)) ?> achado pelo <?= e($b['casou_por']) ?></div>
                                    <?php if ($b['acao'] === 'baixar' && abs(($b['valor_pago'] ?: $b['valor']) - (float) $t['valor']) > 0.004): ?>
                                        <div class="<?= ($b['valor_pago'] ?: $b['valor']) < (float) $t['valor'] ? 'text-danger' : 'valor-positivo' ?>">Pago <?= ($b['valor_pago'] ?: $b['valor']) < (float) $t['valor'] ? 'a menos' : 'a mais (juros/multa)' ?>: <?= formatMoney(($b['valor_pago'] ?: $b['valor']) - (float) $t['valor']) ?></div>
                                    <?php endif; ?>
                                <?php elseif ($b['acao'] === 'sem_titulo'): ?>
                                    <select name="titulo[<?= $i ?>]" class="form-select form-select-sm escolher-titulo">
                                        <option value="">— nenhum título bateu; escolha se for de algum —</option>
                                        <?php foreach ($abertos as $a): if ($a['boleto_nosso_numero'] || abs((float) $a['valor'] - $b['valor']) > max(1, $b['valor'] * 0.15)) continue; ?>
                                            <option value="<?= (int) $a['id'] ?>">#<?= (int) $a['id'] ?> · <?= formatDate($a['data_vencimento']) ?> · <?= formatMoney($a['valor']) ?> · <?= e($a['pagador_nome'] ?? 'sem pagador') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                            </td>
                            <td><span class="badge text-bg-<?= $cor ?>"><?= $b['acao'] === 'sem_titulo' && $b['situacao'] === 'pago' ? 'Pago, sem título' : $rotulo ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-transparent d-flex flex-wrap align-items-center gap-2">
                <span class="small text-muted"><?= (int) ($contagem['baixar'] ?? 0) ?> baixa(s) somando <?= formatMoney($totalBaixar) ?> no <?= e($nomeConta) ?> · <?= (int) ($contagem['gravar_numero'] ?? 0) ?> nosso(s) número(s) para gravar</span>
                <button class="btn btn-brand ms-auto" onclick="return confirm('Gravar as baixas e os nossos números marcados?');"><i class="bi bi-check2-all"></i> Confirmar importação</button>
            </div>
        </div>
    </form>
    <script>
    (function () {
        const form = document.getElementById('form-importacao');
        document.getElementById('marcar-todos').addEventListener('change', function () {
            form.querySelectorAll('.marcar-linha').forEach((c) => { if (c.closest('tr').dataset.acao !== 'sem_titulo' || c.closest('tr').querySelector('.escolher-titulo')?.value) c.checked = this.checked; });
        });
        form.querySelectorAll('.escolher-titulo').forEach((s) => s.addEventListener('change', () => { s.closest('tr').querySelector('.marcar-linha').checked = s.value !== ''; }));
        document.querySelectorAll('.filtro-acao').forEach((b) => b.addEventListener('click', () => {
            form.querySelectorAll('tbody tr').forEach((tr) => { tr.hidden = b.dataset.acao !== '' && tr.dataset.acao !== b.dataset.acao; });
        }));
    })();
    </script>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
