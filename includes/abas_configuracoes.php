<?php
// Abas das telas de configuração. Espera $abaAtiva ('sistema', 'empresa',
// 'contas', 'auxiliares' ou 'auditoria'). Só aparecem para administrador
// (os cadastros auxiliares também abrem pelo menu Cadastros).
$abaAtiva = $abaAtiva ?? 'sistema';
if (!usuarioEhAdmin()) {
    return;
}
?>
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link <?= $abaAtiva === 'sistema' ? 'active' : '' ?>" href="/configuracoes/form.php">
            <i class="bi bi-sliders"></i> Sistema
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $abaAtiva === 'empresa' ? 'active' : '' ?>" href="/configuracoes/empresa.php">
            <i class="bi bi-building"></i> Empresa
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $abaAtiva === 'contas' ? 'active' : '' ?>" href="/configuracoes/contas.php">
            <i class="bi bi-bank"></i> Contas bancárias
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $abaAtiva === 'auxiliares' ? 'active' : '' ?>" href="/configuracoes/recebimento.php">
            <i class="bi bi-list-check"></i> Cadastros auxiliares
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $abaAtiva === 'auditoria' ? 'active' : '' ?>" href="/configuracoes/auditoria.php">
            <i class="bi bi-clock-history"></i> Auditoria
        </a>
    </li>
</ul>
