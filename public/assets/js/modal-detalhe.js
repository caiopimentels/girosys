// Modal de detalhe das listas: linha com data-detalhe="/pagar/baixar.php?id=1"
// abre a página no modal (?modal=1 devolve só o miolo) em vez de trocar de
// tela. Os formulários de dentro mandam "voltar" (esta lista) e "reabrir"
// (o detalhe): depois de agir, a lista recarrega atualizada; se deu erro,
// o modal reabre sozinho. Ctrl/⌘ + clique abre a página inteira em outra aba.
(function () {
    const el = document.getElementById('modal-detalhe');
    if (!el) return;
    const modal = new bootstrap.Modal(el);
    const corpo = el.querySelector('.modal-detalhe-corpo');

    function urlDaLista() {
        const u = new URL(location.href);
        u.searchParams.delete('reabrir');
        return u.pathname + u.search;
    }

    function rodarScripts(container) {
        container.querySelectorAll('script').forEach((velho) => {
            const novo = document.createElement('script');
            [...velho.attributes].forEach((a) => novo.setAttribute(a.name, a.value));
            novo.textContent = velho.textContent;
            velho.replaceWith(novo);
        });
    }

    window.abrirDetalhe = function (url) {
        el.querySelector('.modal-detalhe-titulo').textContent = 'Carregando...';
        el.querySelector('.modal-detalhe-subtitulo').textContent = '';
        el.querySelector('.modal-detalhe-tela-cheia').href = url;
        corpo.innerHTML = '<div class="text-center text-muted py-5"><div class="spinner-border spinner-border-sm"></div></div>';
        modal.show();
        fetch(url + (url.includes('?') ? '&' : '?') + 'modal=1')
            .then((r) => {
                if (r.redirected && r.url.includes('/login.php')) { location.reload(); throw new Error('sessão'); }
                return r.text();
            })
            .then((html) => {
                corpo.innerHTML = html;
                const conteudo = corpo.querySelector('.modal-conteudo');
                if (conteudo) {
                    el.querySelector('.modal-detalhe-titulo').textContent = conteudo.dataset.titulo || '';
                    el.querySelector('.modal-detalhe-subtitulo').textContent = conteudo.dataset.subtitulo || '';
                }
                corpo.querySelectorAll('form').forEach((form) => {
                    if ((form.method || 'get').toLowerCase() !== 'post') return;
                    if (!form.getAttribute('action')) form.setAttribute('action', url);
                    [['voltar', urlDaLista()], ['reabrir', url]].forEach(([nome, valor]) => {
                        const campo = document.createElement('input');
                        campo.type = 'hidden';
                        campo.name = nome;
                        campo.value = valor;
                        form.appendChild(campo);
                    });
                });
                rodarScripts(corpo);
                const primeiro = corpo.querySelector('[autofocus]');
                if (primeiro) primeiro.focus();
            })
            .catch((e) => {
                if (e.message !== 'sessão') corpo.innerHTML = '<div class="alert alert-danger">Não foi possível abrir. <a href="' + url + '">Abrir a página</a></div>';
            });
    };

    document.addEventListener('click', (ev) => {
        const linha = ev.target.closest('[data-detalhe]');
        if (!linha || ev.target.closest('a, button, input, select, form, label')) return;
        ev.preventDefault();
        if (ev.metaKey || ev.ctrlKey) {
            window.open(linha.dataset.detalhe, '_blank');
            return;
        }
        abrirDetalhe(linha.dataset.detalhe);
    });

    // Voltou de uma ação com erro: reabre o modal onde estava.
    const reabrir = new URLSearchParams(location.search).get('reabrir');
    if (reabrir && reabrir.startsWith('/')) {
        history.replaceState(null, '', urlDaLista());
        abrirDetalhe(reabrir);
    }
})();
