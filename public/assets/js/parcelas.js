// Tabela de parcelas (várias datas e valores no mesmo lançamento): tabela
// .tabela-parcelas com um <template> da linha (use __i no name), botão
// [data-add-parcela] para incluir linha, .remover-parcela em cada linha e
// [data-total-parcelas] mostrando a soma dos [data-parcela-valor].
// Avisa mudanças com o evento "parcelas-mudou" (no documento).
(function () {
    document.querySelectorAll('.tabela-parcelas').forEach((tabela) => {
        const corpo = tabela.querySelector('tbody');
        const modelo = tabela.querySelector('template');
        let seq = corpo.querySelectorAll('tr').length + 1000;

        function total() {
            const soma = [...corpo.querySelectorAll('[data-parcela-valor]')].reduce((s, c) => s + Moeda.ler(c.value), 0);
            tabela.querySelectorAll('[data-total-parcelas]').forEach((el) => (el.textContent = Moeda.reais(soma)));
            tabela.querySelectorAll('[data-qtd-parcelas]').forEach((el) => (el.textContent = corpo.querySelectorAll('tr').length));
            document.dispatchEvent(new CustomEvent('parcelas-mudou', { detail: { soma } }));
        }

        // Linha nova já vem com a data um mês depois da última (o usuário ajusta).
        function adicionar(valores = {}) {
            corpo.insertAdjacentHTML('beforeend', modelo.innerHTML.replaceAll('__i', seq++));
            const linha = corpo.lastElementChild;
            const ultimaData = [...corpo.querySelectorAll('input[type="date"]')].slice(-2, -1)[0];
            const data = linha.querySelector('input[type="date"]');
            if (data && !valores.data && ultimaData && ultimaData.value) {
                const [a, m, d] = ultimaData.value.split('-').map(Number);
                const fim = new Date(Date.UTC(a, m + 1, 0)).getUTCDate();
                data.value = new Date(Date.UTC(a, m, Math.min(d, fim))).toISOString().slice(0, 10);
            }
            Object.entries(valores).forEach(([campo, v]) => { const c = linha.querySelector(`[data-campo="${campo}"]`); if (c) c.value = v; });
            total();
            return linha;
        }

        tabela.querySelector('[data-add-parcela]')?.addEventListener('click', () => {
            const linha = adicionar();
            (linha.querySelector('[data-parcela-valor]') || linha.querySelector('input')).focus();
        });
        corpo.addEventListener('click', (ev) => {
            if (!ev.target.closest('.remover-parcela')) return;
            if (corpo.querySelectorAll('tr').length > 1) ev.target.closest('tr').remove();
            total();
        });
        corpo.addEventListener('input', total);
        corpo.addEventListener('focusout', () => setTimeout(total));
        // Enter no último valor cria a próxima parcela.
        corpo.addEventListener('keydown', (ev) => {
            if (ev.key !== 'Enter' || !ev.target.matches('input')) return;
            ev.preventDefault();
            if (ev.target.matches('[data-parcela-valor]') && ev.target.closest('tr') === corpo.lastElementChild) adicionar().querySelector('input').focus();
        });

        tabela.parcelas = { adicionar, total, limpar: () => (corpo.innerHTML = '') };
        if (!corpo.querySelector('tr')) adicionar();
        total();
    });
})();
