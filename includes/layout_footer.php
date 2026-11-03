<?php if (!empty($modoModal)): ?>
</div>
<?php return; endif; ?>
    <div class="pagina-rodape">
        <?= e(nomeSistema()) ?> · <?= date('Y') ?>
    </div>
</main>

<?php // Modal de detalhe: as listas abrem a conta aqui em vez de trocar de tela (assets/js/modal-detalhe.js). ?>
<div class="modal fade" id="modal-detalhe" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <div class="small text-muted modal-detalhe-subtitulo"></div>
                    <h5 class="modal-title modal-detalhe-titulo">Carregando...</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body modal-detalhe-corpo"></div>
            <div class="modal-footer py-2 justify-content-between">
                <a href="#" class="small modal-detalhe-tela-cheia" target="_blank"><i class="bi bi-box-arrow-up-right"></i> Abrir em tela cheia</a>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(assetUrl('/assets/js/csrf.js')) ?>"></script>
<script src="<?= e(assetUrl('/assets/js/mascaras.js')) ?>"></script>
<script src="<?= e(assetUrl('/assets/js/moeda.js')) ?>"></script>
<script src="<?= e(assetUrl('/assets/js/filtro-periodo.js')) ?>"></script>
<script src="<?= e(assetUrl('/assets/js/modal-detalhe.js')) ?>"></script>
<script src="<?= e(assetUrl('/assets/js/selecao.js')) ?>"></script>
<script src="<?= e(assetUrl('/assets/js/parcelas.js')) ?>"></script>
<script>
(function () {
    let restante = <?= (int) sessaoSegundosRestantes() ?>;
    const elValor = document.getElementById('sessao-timer-valor');
    const elBadge = document.getElementById('topnav-sessao-timer');

    function renderizar() {
        if (!elValor) return;
        elValor.textContent = String(Math.floor(restante / 60)).padStart(2, '0') + ':' + String(restante % 60).padStart(2, '0');
        if (elBadge) elBadge.classList.toggle('topnav-sessao-alerta', restante <= 60);
    }

    setInterval(function () {
        restante = Math.max(0, restante - 1);
        renderizar();
        // Quem confirma se a sessão expirou é o exigirLogin() do PHP.
        if (restante === 0) location.reload();
    }, 1000);

    renderizar();
})();

// Troca o tema (claro/escuro) e salva no usuário.
function alternarTema() {
    const novo = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'claro' : 'escuro';
    fetch('/tema.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: 'tema=' + novo,
    }).then((r) => r.json()).then((d) => { if (d && d.sucesso) location.reload(); }).catch(() => {});
}
</script>
</body>
</html>
