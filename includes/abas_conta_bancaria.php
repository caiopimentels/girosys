<?php
// Abas de uma conta bancária: dados, API do banco (boleto), webhook de
// pagamento, auditoria do que foi trocado com o banco e conferência dos
// boletos. Quem inclui define $contaAbaId e $contaAba ('dados', 'api',
// 'webhook', 'auditoria' ou 'conferir'). As abas do banco são do administrador.
?>
<ul class="nav nav-pills mb-3">
    <li class="nav-item"><a class="nav-link <?= $contaAba === 'dados' ? 'active' : '' ?>" href="/contas/form.php?id=<?= (int) $contaAbaId ?>"><i class="bi bi-bank"></i> Dados da conta</a></li>
    <?php if (usuarioEhAdmin()): ?>
    <li class="nav-item"><a class="nav-link <?= $contaAba === 'api' ? 'active' : '' ?>" href="/contas/inter.php?id=<?= (int) $contaAbaId ?>"><i class="bi bi-shield-lock"></i> API do banco</a></li>
    <li class="nav-item"><a class="nav-link <?= $contaAba === 'webhook' ? 'active' : '' ?>" href="/contas/webhook.php?id=<?= (int) $contaAbaId ?>"><i class="bi bi-broadcast"></i> Webhook de pagamento</a></li>
    <li class="nav-item"><a class="nav-link <?= $contaAba === 'auditoria' ? 'active' : '' ?>" href="/contas/auditoria.php?id=<?= (int) $contaAbaId ?>"><i class="bi bi-journal-text"></i> Auditoria do banco</a></li>
    <li class="nav-item"><a class="nav-link <?= $contaAba === 'conferir' ? 'active' : '' ?>" href="/contas/conferir_boletos.php?id=<?= (int) $contaAbaId ?>"><i class="bi bi-arrow-repeat"></i> Conferir boletos</a></li>
    <?php endif; ?>
</ul>
