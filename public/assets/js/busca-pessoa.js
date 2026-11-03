// Lista suspensa de pessoas (mesmo visual da busca do sistema_keyla) em todo
// campo com data-busca-pessoa — inclusive os criados depois, como as linhas
// de títulos da operação. Abre ao clicar, filtra ao digitar (sem acento, CPF
// pelos dígitos) e, ao escolher, dispara "change" no campo. Nome que não está
// na lista vale como cadastro novo (o servidor cadastra ao gravar).
(function () {
    // Pode chegar de novo dentro do modal de detalhe: uma vez basta (a lista
    // de pessoas é lida de window.PESSOAS na hora de buscar).
    if (window.buscaPessoaCarregada) return;
    window.buscaPessoaCarregada = true;
    // Cada campo busca no cadastro do seu papel: data-busca-pessoa="cliente|pagador|fornecedor".
    const pessoasDo = (campo) => (window.PESSOAS || {})[campo.dataset.buscaPessoa || 'cliente'] || [];
    const nomesPapel = { cliente: 'cliente', pagador: 'pagador', fornecedor: 'fornecedor' };
    const normalizar = (t) => String(t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
    const escapar = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    // O Chrome ignora autocomplete="off" em campos de nome e abre o
    // preenchimento automático dele por cima desta lista; um valor que ele
    // não conhece desliga isso de verdade.
    const desligarAutofill = (c) => c.setAttribute('autocomplete', 'off-busca');
    document.querySelectorAll('[data-busca-pessoa]').forEach(desligarAutofill);

    const lista = document.createElement('div');
    lista.className = 'typeahead-lista d-none';
    lista.style.right = 'auto';
    lista.style.zIndex = 2000; // acima dos modais do Bootstrap (1055), p.ex. editar título no modal de detalhe
    document.body.appendChild(lista);

    let campoAtual = null;
    let achados = [];
    let marcado = -1;

    function posicionar() {
        const r = campoAtual.getBoundingClientRect();
        lista.style.top = (r.bottom + window.scrollY + 4) + 'px';
        lista.style.left = (r.left + window.scrollX) + 'px';
        lista.style.width = Math.max(r.width, 280) + 'px';
    }

    function renderizar() {
        const pessoas = pessoasDo(campoAtual);
        const papel = nomesPapel[campoAtual.dataset.buscaPessoa] || 'cliente';
        const termo = normalizar(campoAtual.value);
        const digitos = campoAtual.value.replace(/\D/g, '');
        achados = pessoas.filter((p) => !termo
            || normalizar(p.nome).includes(termo)
            || (digitos.length >= 3 && p.documento.replace(/\D/g, '').includes(digitos))).slice(0, 12);
        marcado = -1;

        let html = achados.map((p, i) => `<button type="button" class="typeahead-item" data-i="${i}">
            <span class="typeahead-nome">${escapar(p.nome)}</span>
            <span class="typeahead-meta">${[p.documento, p.cidade, p.taxa !== null ? 'taxa ' + String(p.taxa).replace('.', ',') + '%' : ''].filter(Boolean).map(escapar).join(' · ') || '&nbsp;'}</span>
        </button>`).join('');
        if (termo && !achados.length) {
            html += `<div class="typeahead-item typeahead-novo"><i class="bi bi-person-plus"></i> "${escapar(campoAtual.value.trim())}" será cadastrado como ${papel} novo ao gravar</div>`;
        } else if (!achados.length && !pessoas.length) {
            html = `<div class="typeahead-vazio">Nenhum ${papel} cadastrado ainda — digite o nome para cadastrar.</div>`;
        }
        lista.innerHTML = html;
        posicionar();
        lista.classList.remove('d-none');
    }

    function fechar() {
        lista.classList.add('d-none');
        campoAtual = null;
    }

    function escolher(i) {
        const p = achados[i];
        const campo = campoAtual;
        if (!p || !campo) return;
        campo.value = p.nome;
        fechar();
        campo.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function destacar(i) {
        const itens = lista.querySelectorAll('button.typeahead-item');
        if (!itens.length) return;
        marcado = (i + itens.length) % itens.length;
        itens.forEach((b, k) => b.style.background = k === marcado ? 'var(--accent-soft)' : '');
        itens[marcado].scrollIntoView({ block: 'nearest' });
    }

    document.addEventListener('focusin', (ev) => {
        if (ev.target.matches && ev.target.matches('[data-busca-pessoa]')) {
            campoAtual = ev.target;
            desligarAutofill(campoAtual);
            renderizar();
        }
    });
    // Digitar sempre pesquisa, mesmo se o campo ganhou o foco por código
    // (ex.: ao abrir os detalhes do título) e a lista tinha sido fechada.
    document.addEventListener('input', (ev) => {
        if (ev.target.matches && ev.target.matches('[data-busca-pessoa]')) {
            campoAtual = ev.target;
            renderizar();
        }
    });
    document.addEventListener('click', (ev) => {
        if (ev.target.matches && ev.target.matches('[data-busca-pessoa]')) {
            if (lista.classList.contains('d-none')) { campoAtual = ev.target; renderizar(); }
            return;
        }
        // Clique que só deu foco no campo por código (ex.: abrir os detalhes do título) não fecha a lista.
        if (!lista.contains(ev.target) && document.activeElement !== campoAtual) fechar();
    });
    lista.addEventListener('mousedown', (ev) => ev.preventDefault()); // não tira o foco do campo
    lista.addEventListener('click', (ev) => {
        const b = ev.target.closest('button.typeahead-item');
        if (b) escolher(Number(b.dataset.i));
    });
    // Captura: roda antes do "Enter não envia" da tela de operação.
    document.addEventListener('keydown', (ev) => {
        if (!campoAtual || ev.target !== campoAtual || lista.classList.contains('d-none')) return;
        if (ev.key === 'ArrowDown') { ev.preventDefault(); destacar(marcado + 1); }
        else if (ev.key === 'ArrowUp') { ev.preventDefault(); destacar(marcado - 1); }
        else if (ev.key === 'Escape') { fechar(); }
        else if (ev.key === 'Enter' && marcado >= 0) { ev.preventDefault(); ev.stopPropagation(); escolher(marcado); }
        else if (ev.key === 'Tab') { fechar(); }
    }, true);
    window.addEventListener('resize', () => campoAtual && posicionar());
    document.addEventListener('scroll', () => campoAtual && !lista.classList.contains('d-none') && posicionar(), true);
})();
