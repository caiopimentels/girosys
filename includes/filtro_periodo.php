<?php
// Filtro por período reutilizável (calendário duplo + atalhos) — o mesmo do
// sistema_keyla. Espera $dataInicio e $dataFim ('Y-m-d' ou '' = sem limite)
// e deve ficar DENTRO de um <form method="get">; envia os campos "de" e "ate"
// (ou os nomes em $periodoCampos, quando há mais de um filtro na página).
// Opcional: $periodoRotulo (ex.: "Vencimento") mostrado no botão.
[$campoDe, $campoAte] = $periodoCampos ?? ['de', 'ate'];
$labelPeriodo = ($dataInicio || $dataFim)
    ? ($dataInicio ? formatDate($dataInicio) : '...') . ' até ' . ($dataFim ? formatDate($dataFim) : '...')
    : 'Todo o período';
if (!empty($periodoRotulo)) {
    $labelPeriodo = $periodoRotulo . ': ' . $labelPeriodo;
}
?>
<div class="dropdown filtro-periodo">
    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle filtro-controle" data-bs-toggle="dropdown" data-bs-auto-close="outside">
        <i class="bi bi-calendar3"></i> <?= e($labelPeriodo) ?>
    </button>
    <div class="dropdown-menu p-3 periodo-painel">
        <div class="periodo-body">
            <div class="periodo-calendarios">
                <?php foreach (['inicio' => 'Início do período', 'fim' => 'Fim do período'] as $lado => $tituloLado): ?>
                <div class="periodo-calendario" data-lado="<?= $lado ?>">
                    <div class="periodo-titulo-lado"><?= $tituloLado ?></div>
                    <div class="periodo-nav">
                        <button type="button" class="btn btn-sm btn-link p-0 periodo-nav-prev"><i class="bi bi-chevron-left"></i></button>
                        <span class="periodo-nav-titulo"></span>
                        <button type="button" class="btn btn-sm btn-link p-0 periodo-nav-next"><i class="bi bi-chevron-right"></i></button>
                    </div>
                    <table class="periodo-grid">
                        <thead><tr><th>D</th><th>S</th><th>T</th><th>Q</th><th>Q</th><th>S</th><th>S</th></tr></thead>
                        <tbody></tbody>
                    </table>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="periodo-presets">
                <button type="button" data-preset="hoje">Hoje</button>
                <button type="button" data-preset="semana">Esta semana</button>
                <button type="button" data-preset="mes">Este mês (até hoje)</button>
                <button type="button" data-preset="mes_inteiro">Mês inteiro</button>
                <button type="button" data-preset="mes_passado">Mês passado</button>
                <button type="button" data-preset="proximos_30">Próximos 30 dias</button>
                <button type="button" data-preset="proximo_mes">Próximo mês</button>
                <button type="button" data-preset="tudo">Tudo</button>
                <button type="button" data-preset="customizado" class="ativo">Período customizado</button>
            </div>
        </div>
        <div class="d-flex justify-content-end gap-2 mt-2 pt-2 border-top">
            <button type="button" class="btn btn-sm btn-brand periodo-filtrar">Filtrar</button>
        </div>
    </div>
    <input type="hidden" name="<?= e($campoDe) ?>" class="periodo-hidden-inicio" value="<?= e($dataInicio) ?>">
    <input type="hidden" name="<?= e($campoAte) ?>" class="periodo-hidden-fim" value="<?= e($dataFim) ?>">
</div>
