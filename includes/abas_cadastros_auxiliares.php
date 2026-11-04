<?php
// Abas de "Cadastros auxiliares" (dentro de Configurações). Espera
// $abaAuxiliar ('tipos' ou 'categorias').
?>
<ul class="nav nav-pills mb-3">
    <li class="nav-item"><a class="nav-link <?= $abaAuxiliar === 'tipos' ? 'active' : '' ?>" href="/configuracoes/recebimento.php"><i class="bi bi-upc-scan"></i> Tipos de recebimento</a></li>
    <li class="nav-item"><a class="nav-link <?= $abaAuxiliar === 'categorias' ? 'active' : '' ?>" href="/categorias/listar.php"><i class="bi bi-tags"></i> Categorias</a></li>
</ul>
