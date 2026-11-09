<?php
// Atualiza títulos de contas a receber pela planilha da listagem (botão
// "Planilha"), devolvida com a coluna Nosso número preenchida. Mostra o que
// muda antes de gravar; as demais colunas da planilha são ignoradas.
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

$pdo = getDB();
$token = preg_replace('/[^a-f0-9]/', '', (string) ($_GET['t'] ?? $_POST['t'] ?? ''));
$sessao = $token !== '' ? ($_SESSION['atualizar_planilha'][$token] ?? null) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'enviar') {
    try {
        $arquivo = $_FILES['arquivo'] ?? null;
        if (!$arquivo || $arquivo['error'] !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Escolha a planilha (.xlsx ou .csv).');
        }
        if ($arquivo['size'] > 10 * 1024 * 1024) {
            throw new InvalidArgumentException('Planilha maior que 10 MB.');
        }
        [$valores, $erros] = lerPlanilhaNossoNumero(lerPlanilha($arquivo['tmp_name'], $arquivo['name']));
        if (!$valores && !$erros) {
            throw new InvalidArgumentException('A planilha não tem nenhuma linha com ID.');
        }
        $token = bin2hex(random_bytes(8));
        $_SESSION['atualizar_planilha'] = [$token => ['arquivo' => $arquivo['name'], 'valores' => $valores, 'erros' => $erros]];
        redirect('/receber/atualizar_planilha.php?t=' . $token);
    } catch (InvalidArgumentException | RuntimeException $e) {
        flash('danger', $e->getMessage());
        redirect('/receber/atualizar_planilha.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'confirmar') {
    if (!$sessao) {
        flash('danger', 'A atualização expirou. Envie a planilha de novo.');
        redirect('/receber/atualizar_planilha.php');
    }
    $marcados = array_map('intval', array_keys((array) ($_POST['marcado'] ?? [])));
    $pdo->beginTransaction();
    try {
        // Confere de novo na hora de gravar (pode ter mudado desde a prévia).
        $gravados = [];
        $upd = $pdo->prepare('UPDATE contas_receber SET boleto_nosso_numero = ? WHERE id = ?');
        foreach (analisarNossoNumeroPlanilha($pdo, $sessao['valores']) as $l) {
            if ($l['acao'] === 'gravar' && in_array($l['id'], $marcados, true)) {
                $upd->execute([$l['novo'], $l['id']]);
                $gravados[] = '#' . $l['id'] . ': ' . ($l['atual'] ?: 'vazio') . ' → ' . $l['novo'];
            }
        }
        if ($gravados) {
            registrarAtividade('contas_receber', 'edicao', 'Nosso número pela planilha "' . $sessao['arquivo'] . '": ' . count($gravados) . ' título(s) · ' . implode('; ', $gravados));
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash('danger', 'Nada foi gravado: ' . $e->getMessage());
        redirect('/receber/atualizar_planilha.php?t=' . $token);
    }
    unset($_SESSION['atualizar_planilha'][$token]);
    flash($gravados ? 'success' : 'warning', $gravados ? count($gravados) . ' nosso(s) número(s) gravado(s).' : 'Nada foi gravado.');
    redirect('/receber/listar.php');
}

$linhas = $sessao ? analisarNossoNumeroPlanilha($pdo, $sessao['valores']) : null;
$rotulos = [
    'gravar' => ['success', 'Gravar'],
    'igual' => ['light', 'Já está igual'],
    'vazio' => ['light', 'Em branco (não mexe)'],
    'registrado' => ['secondary', 'Boleto do sistema'],
    'duplicado' => ['danger', 'Duplicado'],
    'invalido' => ['danger', 'Inválido'],
    'sem_titulo' => ['warning', 'ID não existe'],
];

$pageTitle = 'Atualizar pela planilha';
$pageBreadcrumb = 'Financeiro · Contas a receber';
$activeMenu = 'receber';
$pageAction = '<a href="/receber/listar.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Contas a receber</a>';
require __DIR__ . '/../../includes/layout_header.php';
?>

<?php if (!$sessao): ?>
<div class="secao" style="max-width: 900px;">
    <div class="secao-titulo"><i class="bi bi-upload"></i> Nosso número pela planilha</div>
    <ol class="small text-muted">
        <li>Em Contas a receber, filtre (ou marque) os títulos e clique em <strong>Planilha</strong>.</li>
        <li>Abra no Excel e preencha a coluna <strong>Nosso número</strong>. Não mexa na coluna <strong>ID</strong>; as outras colunas são ignoradas.</li>
        <li>Formate a coluna Nosso número como <strong>Texto</strong> antes de digitar (senão o Excel corta zeros à esquerda ou vira 1,23E+11). Salvar em <strong>.xlsx</strong> é o mais seguro.</li>
        <li>Envie aqui: você confere o que muda antes de gravar. Linha em branco não apaga o número que já existe.</li>
    </ol>
    <form method="post" enctype="multipart/form-data" class="row g-3 align-items-end">
        <input type="hidden" name="acao" value="enviar">
        <div class="col-md-8"><label class="form-label">Planilha (.xlsx ou .csv)</label><input type="file" name="arquivo" class="form-control" accept=".xlsx,.csv" required></div>
        <div class="col-md-4"><button class="btn btn-brand w-100"><i class="bi bi-upload"></i> Ler planilha</button></div>
    </form>
</div>

<?php else: $contagem = array_count_values(array_column($linhas, 'acao')); ?>
    <?php foreach ($sessao['erros'] as $erro): ?><div class="alert alert-warning small py-2 mb-2"><?= e($erro) ?></div><?php endforeach; ?>
    <div class="d-flex flex-wrap gap-2 mb-3 small">
        <span class="badge text-bg-light border"><?= e($sessao['arquivo']) ?> · <?= count($linhas) ?> linha(s)</span>
        <?php foreach ($rotulos as $k => [$cor, $rotulo]): if (empty($contagem[$k])) continue; ?>
            <button type="button" class="badge text-bg-<?= $cor ?> border filtro-acao" data-acao="<?= $k ?>" title="Mostrar só estes"><?= $rotulo ?>: <?= (int) $contagem[$k] ?></button>
        <?php endforeach; ?>
        <button type="button" class="badge text-bg-dark border-0 filtro-acao" data-acao="">Mostrar todos</button>
        <a href="/receber/atualizar_planilha.php" class="ms-auto">Enviar outra planilha</a>
    </div>

    <form method="post" id="form-atualizar">
        <input type="hidden" name="acao" value="confirmar"><input type="hidden" name="t" value="<?= e($token) ?>">
        <div class="card">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead><tr><th style="width: 32px;"><input type="checkbox" class="form-check-input" id="marcar-todos" checked></th>
                        <th>Título</th><th>Pagador</th><th>Vencimento</th><th class="text-end">Valor</th><th>Nosso nº atual</th><th>Na planilha</th><th>O que fazer</th></tr></thead>
                    <tbody>
                    <?php foreach ($linhas as $l): $t = $l['titulo']; [$cor, $rotulo] = $rotulos[$l['acao']]; ?>
                        <tr data-acao="<?= $l['acao'] ?>">
                            <td><?php if ($l['acao'] === 'gravar'): ?><input type="checkbox" class="form-check-input marcar-linha" name="marcado[<?= (int) $l['id'] ?>]" value="1" checked><?php endif; ?></td>
                            <td><?php if ($t): ?><a href="/receber/baixar.php?id=<?= (int) $l['id'] ?>" target="_blank">#<?= (int) $l['id'] ?></a> <span class="text-muted small"><?= e(tipoTituloLabel($t['tipo'])) ?></span><?php else: ?>#<?= (int) $l['id'] ?><?php endif; ?></td>
                            <td class="small"><?= e($t['pagador_nome'] ?? '-') ?></td>
                            <td class="text-num small"><?= $t ? formatDate($t['data_vencimento']) : '-' ?></td>
                            <td class="text-end text-num"><?= $t ? formatMoney($t['valor']) : '-' ?></td>
                            <td class="small font-monospace"><?= e($l['atual'] ?: '-') ?></td>
                            <td class="small font-monospace <?= $l['acao'] === 'gravar' ? 'fw-semibold' : '' ?>"><?= e($l['novo'] ?: '-') ?></td>
                            <td><span class="badge text-bg-<?= $cor ?> border"><?= $rotulo ?></span><?php if ($l['motivo']): ?><div class="small text-muted"><?= e($l['motivo']) ?></div><?php endif; ?>
                                <?php if ($l['acao'] === 'gravar' && $l['atual']): ?><div class="small text-danger">substitui o número atual</div><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-transparent d-flex flex-wrap align-items-center gap-2">
                <span class="small text-muted"><?= (int) ($contagem['gravar'] ?? 0) ?> nosso(s) número(s) para gravar</span>
                <button class="btn btn-brand ms-auto" <?= empty($contagem['gravar']) ? 'disabled' : '' ?> onclick="return confirm('Gravar os nossos números marcados?');"><i class="bi bi-check2-all"></i> Gravar</button>
            </div>
        </div>
    </form>
    <script>
    (function () {
        const form = document.getElementById('form-atualizar');
        document.getElementById('marcar-todos').addEventListener('change', function () {
            form.querySelectorAll('.marcar-linha').forEach((c) => { c.checked = this.checked; });
        });
        document.querySelectorAll('.filtro-acao').forEach((b) => b.addEventListener('click', () => {
            form.querySelectorAll('tbody tr').forEach((tr) => { tr.hidden = b.dataset.acao !== '' && tr.dataset.acao !== b.dataset.acao; });
        }));
    })();
    </script>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
