<?php
// Integração da conta com o Banco Inter: credenciais, certificado, teste de
// conexão, registro do webhook e os últimos avisos recebidos.
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

$pdo = getDB();
$id = (int) ($_GET['id'] ?? 0);
$conta = contaBancaria($pdo, $id);
if (!$conta) {
    flash('danger', 'Conta não encontrada.');
    redirect('/contas/listar.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? 'salvar';
    try {
        if ($acao === 'salvar') {
            $ativa = isset($_POST['ativa']);
            $segredo = input('client_secret');
            $pdo->prepare('UPDATE contas_bancarias SET integracao = ?, inter_ambiente = ?, inter_client_id = ?, inter_conta_corrente = ?, inter_token = NULL' . ($segredo !== '' ? ', inter_client_secret = ?' : '') . ' WHERE id = ?')
                ->execute(array_merge([$ativa ? 'inter' : 'nenhuma', input('ambiente') === 'sandbox' ? 'sandbox' : 'producao', input('client_id') ?: null, input('conta_corrente') ?: null],
                    $segredo !== '' ? [criptografar($segredo)] : [], [$id]));
            salvarCertificadoInter($pdo, $id, $_FILES['certificado'] ?? [], $_FILES['chave'] ?? []);
            flash('success', 'Integração salva.' . (($falta = pendenciasInter(contaBancaria($pdo, $id))) ? ' Ainda falta: ' . implode(', ', $falta) . '.' : ' Agora teste a conexão.'));
        } elseif ($acao === 'testar') {
            interToken($pdo, $conta, true);
            flash('success', 'Conexão com o Banco Inter funcionando (token obtido com o certificado).');
        } elseif ($acao === 'webhook') {
            $url = registrarWebhookInter($pdo, $conta);
            flash('success', 'Webhook registrado no Inter: ' . $url);
        }
    } catch (Throwable $e) {
        flash('danger', $e->getMessage());
    }
    redirect('/contas/inter.php?id=' . $id);
}

$falta = pendenciasInter($conta);
$eventos = $pdo->prepare('SELECT * FROM webhook_eventos WHERE conta_id = ? ORDER BY id DESC LIMIT 20');
$eventos->execute([$id]);
$eventos = $eventos->fetchAll();
$urlWebhook = contaTemInter($conta) ? urlWebhookInter($pdo, $conta) : '';

$pageTitle = 'Integração Banco Inter · ' . $conta['nome'];
$pageBreadcrumb = 'Contas bancárias';
$activeMenu = 'contas';
$pageAction = '<a href="/contas/listar.php" class="btn btn-outline-secondary">Voltar</a>'
    . '<button type="submit" form="form-inter" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>';
require __DIR__ . '/../../includes/layout_header.php';
?>

<div style="max-width: 900px;">
    <div class="alert <?= contaTemInter($conta) && !$falta ? 'alert-success' : 'alert-light border' ?> small">
        <?php if (!contaTemInter($conta)): ?>
            Integração desligada. Para emitir boleto (com Pix) por esta conta, ative e preencha os dados abaixo.
        <?php elseif ($falta): ?>
            Falta para emitir boleto: <strong><?= e(implode(', ', $falta)) ?></strong>.
        <?php else: ?>
            <i class="bi bi-check-circle"></i> Pronta para emitir boletos (<?= $conta['inter_ambiente'] === 'sandbox' ? 'sandbox — teste' : 'produção' ?>).
            <?= $conta['webhook_registrado_em'] ? 'Webhook registrado em ' . formatDateTime($conta['webhook_registrado_em']) . '.' : 'Falta registrar o webhook para a baixa automática.' ?>
        <?php endif; ?>
    </div>

    <form method="post" id="form-inter" enctype="multipart/form-data" autocomplete="off">
        <input type="hidden" name="acao" value="salvar">
        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-key"></i> Credenciais</div>
            <p class="small text-muted">No Internet Banking do Inter: <strong>Soluções para sua empresa › Nova integração</strong>, marque a API de <strong>Cobrança (boleto com Pix)</strong> com permissão de leitura e escrita, e baixe a chave (.key) e o certificado (.crt).</p>
            <div class="row g-3">
                <div class="col-12"><label class="form-check"><input type="checkbox" class="form-check-input" name="ativa" <?= contaTemInter($conta) ? 'checked' : '' ?>> Emitir boletos pelo Banco Inter nesta conta</label></div>
                <div class="col-md-4"><label class="form-label">Ambiente</label>
                    <select name="ambiente" class="form-select"><option value="producao" <?= $conta['inter_ambiente'] === 'producao' ? 'selected' : '' ?>>Produção</option><option value="sandbox" <?= $conta['inter_ambiente'] === 'sandbox' ? 'selected' : '' ?>>Sandbox (teste)</option></select></div>
                <div class="col-md-8"><label class="form-label">Client ID</label><input type="text" name="client_id" class="form-control" value="<?= e($conta['inter_client_id']) ?>"></div>
                <div class="col-md-8"><label class="form-label">Client Secret</label><input type="password" name="client_secret" class="form-control" autocomplete="new-password" placeholder="<?= $conta['inter_client_secret'] ? '•••••••• guardado (deixe vazio para manter)' : '' ?>"></div>
                <div class="col-md-4"><label class="form-label">Conta corrente <span class="text-muted small">(se tiver mais de uma)</span></label><input type="text" name="conta_corrente" class="form-control" inputmode="numeric" value="<?= e($conta['inter_conta_corrente']) ?>"></div>
            </div>
        </div>
        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-shield-lock"></i> Certificado</div>
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label">Certificado (.crt)</label><input type="file" name="certificado" class="form-control" accept=".crt,.pem">
                    <div class="form-text"><?= $conta['inter_certificado'] && is_file($conta['inter_certificado']) ? '<i class="bi bi-check-circle valor-positivo"></i> enviado' : 'não enviado' ?></div></div>
                <div class="col-md-6"><label class="form-label">Chave (.key)</label><input type="file" name="chave" class="form-control" accept=".key,.pem">
                    <div class="form-text"><?= $conta['inter_chave'] && is_file($conta['inter_chave']) ? '<i class="bi bi-check-circle valor-positivo"></i> enviada' : 'não enviada' ?></div></div>
                <div class="col-12 form-text">Os arquivos ficam em <code>storage/</code>, fora da pasta pública; o Client Secret fica criptografado.</div>
            </div>
        </div>
    </form>

    <?php if (contaTemInter($conta) && !$falta): ?>
    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-plug"></i> Conexão e baixa automática</div>
        <div class="d-flex flex-wrap gap-2 mb-2">
            <form method="post"><input type="hidden" name="acao" value="testar"><button class="btn btn-outline-secondary"><i class="bi bi-plug"></i> Testar conexão</button></form>
            <form method="post"><input type="hidden" name="acao" value="webhook"><button class="btn btn-outline-secondary"><i class="bi bi-broadcast"></i> <?= $conta['webhook_registrado_em'] ? 'Registrar webhook de novo' : 'Registrar webhook' ?></button></form>
        </div>
        <div class="small text-muted">Endereço que o Inter vai chamar quando um boleto for pago: <code><?= e($urlWebhook) ?></code></div>
        <div class="small text-muted mt-1">Se algum aviso não chegar, a rotina <code>scripts/conferir_boletos.php</code> (cron, a cada hora) confere os boletos em aberto e dá a baixa.</div>
    </div>
    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-list-ul"></i> Últimos avisos do Inter</div>
        <div class="card"><table class="table table-sm mb-0">
            <thead><tr><th>Recebido em</th><th>Cobrança</th><th>Situação</th><th>Resultado</th></tr></thead>
            <tbody>
            <?php if (!$eventos): ?><tr><td colspan="4" class="text-center text-muted py-3">Nenhum aviso recebido ainda.</td></tr><?php endif; ?>
            <?php foreach ($eventos as $ev): ?>
                <tr><td class="text-num"><?= formatDateTime($ev['recebido_em']) ?></td><td class="small"><?= e($ev['codigo'] ?? '-') ?></td><td><?= e(rotuloSituacaoBoleto($ev['situacao'])) ?></td>
                    <td class="small <?= $ev['processado'] ? 'valor-positivo' : 'text-muted' ?>"><?= e($ev['resultado'] ?? '-') ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
