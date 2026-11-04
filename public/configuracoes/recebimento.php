<?php
// Tipos de recebimento (cheque, boleto... e os que eu criar): nome, ativo e
// como atualizar o título vencido de cada tipo.
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

$pdo = getDB();
$usos = $pdo->query('SELECT tipo, COUNT(*) FROM contas_receber GROUP BY tipo')->fetchAll(PDO::FETCH_KEY_PAIR);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Excluir: só tipo que nenhum título usa ("outro" é o tipo padrão e fica).
    if (isset($_POST['excluir'])) {
        $tipo = (string) $_POST['excluir'];
        if ($tipo === 'outro' || !empty($usos[$tipo])) {
            flash('danger', 'Este tipo já tem títulos (ou é o padrão "Outro"): desative em vez de excluir.');
        } else {
            $pdo->prepare('DELETE FROM tipos_recebimento WHERE tipo = ?')->execute([$tipo]);
            registrarAtividade('tipos_recebimento', 'exclusao', 'Tipo de recebimento excluído: ' . tipoTituloLabel($tipo));
            flash('success', 'Tipo excluído.');
        }
        redirect('/configuracoes/recebimento.php');
    }

    $upd = $pdo->prepare('UPDATE tipos_recebimento SET nome = ?, ativo = ?, modo = ?, multa_percent = ?, juros_mes = ? WHERE tipo = ?');
    $antesTipos = array_column($pdo->query('SELECT * FROM tipos_recebimento')->fetchAll(), null, 'tipo');
    $mudancas = [];
    foreach ((array) ($_POST['config'] ?? []) as $tipo => $c) {
        $nome = trim((string) ($c['nome'] ?? '')) ?: tipoTituloLabel($tipo);
        $modo = array_key_exists($c['modo'] ?? '', modosAtualizacao()) ? $c['modo'] : 'cliente';
        $multa = trim((string) ($c['multa'] ?? '')) === '' ? null : paraDecimal($c['multa']);
        $juros = trim((string) ($c['juros'] ?? '')) === '' ? null : paraDecimal($c['juros']);
        $ativo = $tipo === 'outro' || !empty($c['ativo']) ? 1 : 0;
        $upd->execute([$nome, $ativo, $modo, $multa, $juros, (string) $tipo]);
        $a = $antesTipos[$tipo] ?? [];
        $num = fn ($v) => $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float) $v, 4, ',', ''), '0'), ',');
        $mudou = descreverAlteracoes(
            ['nome' => $a['nome'] ?? '', 'ativo' => !empty($a['ativo']) ? 'sim' : 'não', 'modo' => modosAtualizacao()[$a['modo'] ?? ''] ?? '', 'multa' => $num($a['multa_percent'] ?? null), 'juros' => $num($a['juros_mes'] ?? null)],
            ['nome' => $nome, 'ativo' => $ativo ? 'sim' : 'não', 'modo' => modosAtualizacao()[$modo], 'multa' => $num($multa), 'juros' => $num($juros)],
            ['nome' => 'Nome', 'ativo' => 'Ativo', 'modo' => 'Atualizar por', 'multa' => 'Multa %', 'juros' => 'Juros % a.m.']);
        if ($mudou !== 'nenhum campo alterado') {
            $mudancas[] = $nome . ' (' . $mudou . ')';
        }
    }

    $novoNome = trim((string) ($_POST['novo']['nome'] ?? ''));
    if ($novoNome !== '') {
        // Chave a partir do nome ("Pix agendado" → pix_agendado), sem repetir.
        $base = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $novoNome))), '_') ?: 'tipo';
        $chave = substr($base, 0, 26);
        for ($i = 2; isset(tiposTitulo(true)[$chave]); $i++) {
            $chave = substr($base, 0, 26) . '_' . $i;
        }
        $n = $_POST['novo'];
        $modo = array_key_exists($n['modo'] ?? '', modosAtualizacao()) ? $n['modo'] : 'cliente';
        $pdo->prepare('INSERT INTO tipos_recebimento (tipo, nome, modo, multa_percent, juros_mes, ordem) VALUES (?, ?, ?, ?, ?, 50)')
            ->execute([$chave, $novoNome, $modo, trim((string) ($n['multa'] ?? '')) === '' ? null : paraDecimal($n['multa']),
                trim((string) ($n['juros'] ?? '')) === '' ? null : paraDecimal($n['juros'])]);
    }
    if ($mudancas) {
        registrarAtividade('tipos_recebimento', 'edicao', 'Tipos de recebimento: ' . implode('; ', $mudancas));
    }
    if ($novoNome !== '') {
        registrarAtividade('tipos_recebimento', 'criacao', 'Tipo de recebimento criado: ' . $novoNome);
    }
    flash('success', 'Tipos de recebimento salvos.' . ($novoNome !== '' ? ' "' . $novoNome . '" incluído.' : ''));
    redirect('/configuracoes/recebimento.php');
}

$tipos = $pdo->query('SELECT * FROM tipos_recebimento ORDER BY ordem, nome')->fetchAll();
$pct = fn ($v) => $v === null ? '' : rtrim(rtrim(formatDecimal($v, 4), '0'), ',');

$pageTitle = 'Tipos de recebimento';
$pageBreadcrumb = 'Cadastros auxiliares';
$activeMenu = 'config-recebimento';
$pageAction = '<button type="submit" form="form-config" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>';
require __DIR__ . '/../../includes/layout_header.php';
$abaAtiva = 'auxiliares';
require __DIR__ . '/../../includes/abas_configuracoes.php';
$abaAuxiliar = 'tipos';
require __DIR__ . '/../../includes/abas_cadastros_auxiliares.php';
?>

<p class="text-muted" style="max-width: 900px;">
    Os tipos aparecem na operação de desconto e no contas a receber. Para cada um, diga como atualizar o título que passou do vencimento:
    pela <strong>taxa do cliente</strong>, por <strong>juros e multa próprios</strong> do tipo, ou <strong>não atualizar</strong>.
    Os juros são simples, por dia de atraso com mês de 30 dias (a mesma conta do desconto); a multa é cobrada uma vez.
</p>

<form method="post" id="form-config" style="max-width: 1100px;">
    <div class="card">
        <table class="table mb-0 align-middle">
            <thead><tr><th>Tipo</th><th>Atualizar vencido por</th><th style="width: 120px;">Multa (%)</th><th style="width: 140px;">Juros (% a.m.)</th><th class="text-center" style="width: 80px;">Ativo</th><th class="text-center" style="width: 80px;">Títulos</th><th style="width: 50px;"></th></tr></thead>
            <tbody>
            <?php foreach ($tipos as $c): $tipo = $c['tipo']; $qtd = (int) ($usos[$tipo] ?? 0); ?>
                <tr class="linha-config <?= $c['ativo'] ? '' : 'opacity-50' ?>">
                    <td><input type="text" name="config[<?= e($tipo) ?>][nome]" class="form-control form-control-sm fw-semibold" maxlength="60" required value="<?= e($c['nome']) ?>"></td>
                    <td>
                        <select name="config[<?= e($tipo) ?>][modo]" class="form-select form-select-sm modo">
                            <?php foreach (modosAtualizacao() as $k => $v): ?><option value="<?= $k ?>" <?= $c['modo'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
                        </select>
                    </td>
                    <td><input type="text" name="config[<?= e($tipo) ?>][multa]" class="form-control form-control-sm proprio" inputmode="decimal" placeholder="Ex.: 2" value="<?= e($pct($c['multa_percent'])) ?>"></td>
                    <td><input type="text" name="config[<?= e($tipo) ?>][juros]" class="form-control form-control-sm proprio" inputmode="decimal" placeholder="Ex.: 1" value="<?= e($pct($c['juros_mes'])) ?>"></td>
                    <td class="text-center">
                        <div class="form-check form-switch d-inline-block m-0">
                            <input type="checkbox" class="form-check-input" name="config[<?= e($tipo) ?>][ativo]" value="1" <?= $c['ativo'] ? 'checked' : '' ?> <?= $tipo === 'outro' ? 'disabled title="O tipo padrão fica sempre ativo"' : '' ?>
                                   onchange="this.closest('tr').classList.toggle('opacity-50', !this.checked)">
                        </div>
                    </td>
                    <td class="text-center text-muted"><?= $qtd ?: '-' ?></td>
                    <td class="text-end">
                        <?php if ($qtd === 0 && $tipo !== 'outro'): ?>
                            <button type="submit" name="excluir" value="<?= e($tipo) ?>" class="btn btn-sm btn-outline-danger" formnovalidate title="Excluir" onclick="return confirm('Excluir o tipo <?= e($c['nome']) ?>?');"><i class="bi bi-trash"></i></button>
                        <?php else: ?>
                            <span class="d-inline-block" tabindex="0" title="<?= $tipo === 'outro' ? 'Tipo padrão: não pode ser excluído' : 'Tem títulos: desative em vez de excluir' ?>"><button type="button" class="btn btn-sm btn-outline-danger" disabled><i class="bi bi-trash"></i></button></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
                <tr class="linha-config table-light">
                    <td><input type="text" name="novo[nome]" class="form-control form-control-sm" maxlength="60" placeholder="+ Novo tipo (ex.: Pix agendado)"></td>
                    <td>
                        <select name="novo[modo]" class="form-select form-select-sm modo">
                            <?php foreach (modosAtualizacao() as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?>
                        </select>
                    </td>
                    <td><input type="text" name="novo[multa]" class="form-control form-control-sm proprio" inputmode="decimal" placeholder="Ex.: 2"></td>
                    <td><input type="text" name="novo[juros]" class="form-control form-control-sm proprio" inputmode="decimal" placeholder="Ex.: 1"></td>
                    <td colspan="3" class="small text-muted">entra ao salvar</td>
                </tr>
            </tbody>
        </table>
    </div>
    <div class="form-text mt-2">
        Tipo desativado não aparece para novos lançamentos, mas os títulos que já têm esse tipo continuam com ele.
        Só dá para excluir tipo que nenhum título usa.
    </div>
</form>
<script>
// Multa e juros só valem no modo "próprios" (readonly guarda os valores ao trocar).
document.querySelectorAll('.linha-config').forEach((linha) => {
    const modo = linha.querySelector('.modo');
    const aplicar = () => linha.querySelectorAll('.proprio').forEach((c) => { c.readOnly = modo.value !== 'proprio'; c.classList.toggle('opacity-50', c.readOnly); });
    modo.addEventListener('change', aplicar);
    aplicar();
});
</script>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
