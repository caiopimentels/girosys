<?php
// Coluna lateral das listas (mesmo visual do sistema_keyla): totais da página
// e dos itens marcados. Quem inclui define:
//   $informacoes = [['rótulo', 'valor já formatado', 'classe extra'], ...]
//   $somasSelecao = ['valor' => 'Valor', 'atualizado' => 'Atualizado hoje', ...]
//       — cada chave soma o data-<chave> das caixinhas .selecao-item marcadas.
//   $acoesLaterais = [['rótulo', 'bi-ícone', 'js ao clicar'], ...] (opcional)
// A seleção fica em /assets/js/selecao.js.
$somasSelecao = $somasSelecao ?? ['valor' => 'Valor'];
$acoesLaterais = $acoesLaterais ?? [];
?>
<aside class="pedidos-lateral">
    <?php if ($acoesLaterais): ?>
    <div class="lateral-bloco">
        <div class="lateral-titulo">Ações</div>
        <?php foreach ($acoesLaterais as [$rotulo, $icone, $js]): ?>
            <button type="button" class="lateral-acao" onclick="<?= e($js) ?>"><i class="bi <?= e($icone) ?>"></i> <?= e($rotulo) ?></button>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="lateral-bloco">
        <div class="lateral-titulo">Informações</div>
        <?php foreach ($informacoes as [$rotulo, $valor, $classe]): ?>
            <div class="lateral-info">
                <span class="lateral-info-rotulo"><?= e($rotulo) ?></span>
                <span class="lateral-info-valor <?= e($classe) ?>"><?= e((string) $valor) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="lateral-bloco">
        <div class="lateral-titulo">Selecionados</div>
        <div class="lateral-info">
            <span class="lateral-info-rotulo">Quantidade</span>
            <span class="lateral-info-valor destaque" id="info-selecionadas">0</span>
        </div>
        <?php foreach ($somasSelecao as $chave => $rotulo): ?>
            <div class="lateral-info">
                <span class="lateral-info-rotulo"><?= e($rotulo) ?></span>
                <span class="lateral-info-valor" data-soma-selecao="<?= e($chave) ?>">R$ 0,00</span>
            </div>
        <?php endforeach; ?>
        <button type="button" class="lateral-acao mt-1" onclick="document.querySelectorAll('.selecao-item').forEach((c) => (c.checked = false)); document.dispatchEvent(new Event('selecao-mudou'));"><i class="bi bi-x-circle"></i> Limpar seleção</button>
    </div>
</aside>
