<?php
// Configurações › Contas bancárias: cadastro das contas e a integração de
// cada uma com o banco (API, webhook, auditoria, conferência).
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAdmin();

$pdo = getDB();
$contas = $pdo->query(
    "SELECT c.*,
        (SELECT COUNT(*) FROM contas_receber r WHERE r.boleto_conta_id = c.id AND r.boleto_codigo IS NOT NULL AND r.status IN ('pendente', 'devolvido')
            AND (r.boleto_situacao IS NULL OR r.boleto_situacao NOT IN ('CANCELADO', 'EXPIRADO'))) AS boletos_abertos,
        (SELECT MAX(w.recebido_em) FROM webhook_eventos w WHERE w.conta_id = c.id AND w.aceito = 1) AS ultimo_aviso,
        (SELECT COUNT(*) FROM banco_chamadas b WHERE b.conta_bancaria_id = c.id AND b.sucesso = 0 AND b.criado_em >= NOW() - INTERVAL 7 DAY) AS erros_semana
     FROM contas_bancarias c ORDER BY c.ativo DESC, c.nome"
)->fetchAll();

$pageTitle = 'Configurações';
$pageBreadcrumb = 'Administração';
$activeMenu = 'configuracoes';
$pageAction = '<a href="/contas/form.php" class="btn btn-brand"><i class="bi bi-plus-lg"></i> Nova conta</a>';
require __DIR__ . '/../../includes/layout_header.php';
$abaAtiva = 'contas';
require __DIR__ . '/../../includes/abas_configuracoes.php';
?>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><th>Conta</th><th>Banco</th><th>Integração</th><th>Webhook</th><th class="text-center">Boletos em aberto</th><th>Último aviso do banco</th><th></th></tr></thead>
            <tbody>
            <?php if (!$contas): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">Nenhuma conta cadastrada.</td></tr>
            <?php endif; ?>
            <?php foreach ($contas as $c): $inter = contaTemInter($c); $falta = $inter ? pendenciasInter($c) : []; ?>
                <tr class="<?= $c['ativo'] ? '' : 'opacity-50' ?>">
                    <td><span class="d-inline-block rounded-circle me-1" style="width: 10px; height: 10px; background: <?= e($c['cor']) ?>;"></span>
                        <a href="/contas/form.php?id=<?= (int) $c['id'] ?>" class="fw-semibold"><?= e($c['nome']) ?></a><?= $c['ativo'] ? '' : ' <span class="badge text-bg-secondary">inativa</span>' ?></td>
                    <td><?= e($c['banco'] ?: '-') ?></td>
                    <td>
                        <?php if (!$inter): ?>
                            <span class="text-muted">Nenhuma</span>
                        <?php elseif ($falta): ?>
                            <span class="badge text-bg-warning">Inter · incompleta</span>
                        <?php else: ?>
                            <span class="badge text-bg-success">Inter · <?= $c['inter_ambiente'] === 'sandbox' ? 'sandbox' : 'produção' ?></span>
                        <?php endif; ?>
                        <?php if ($c['erros_semana']): ?><a href="/contas/auditoria.php?id=<?= (int) $c['id'] ?>&resultado=erro" class="small text-danger d-block"><?= (int) $c['erros_semana'] ?> erro(s) nos últimos 7 dias</a><?php endif; ?>
                    </td>
                    <td class="small"><?= $inter ? ($c['webhook_registrado_em'] ? '<i class="bi bi-check-circle valor-positivo"></i> registrado' : '<span class="text-warning">não registrado</span>') : '-' ?></td>
                    <td class="text-center"><?= $inter ? (int) $c['boletos_abertos'] : '-' ?></td>
                    <td class="small text-num"><?= $c['ultimo_aviso'] ? formatDateTime($c['ultimo_aviso']) : '-' ?></td>
                    <td class="text-end text-nowrap">
                        <a href="/contas/form.php?id=<?= (int) $c['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Dados da conta"><i class="bi bi-pencil"></i></a>
                        <a href="/contas/inter.php?id=<?= (int) $c['id'] ?>" class="btn btn-sm btn-outline-secondary" title="API do banco"><i class="bi bi-shield-lock"></i></a>
                        <?php if ($inter): ?>
                            <a href="/contas/webhook.php?id=<?= (int) $c['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Webhook de pagamento"><i class="bi bi-broadcast"></i></a>
                            <a href="/contas/auditoria.php?id=<?= (int) $c['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Auditoria do banco"><i class="bi bi-journal-text"></i></a>
                            <a href="/contas/conferir_boletos.php?id=<?= (int) $c['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Conferir boletos"><i class="bi bi-arrow-repeat"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
