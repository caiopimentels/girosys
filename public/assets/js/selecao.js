// Seleção de linhas das listas para o painel lateral: .selecao-todos marca
// todas as .selecao-item; o painel mostra quantas e a soma de cada
// [data-soma-selecao="x"] a partir do data-x de cada caixinha marcada.
(function () {
    const itens = () => [...document.querySelectorAll('.selecao-item')];
    const todos = document.querySelector('.selecao-todos');
    const reais = (v) => v.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });

    function atualizar() {
        const marcados = itens().filter((c) => c.checked);
        const info = document.getElementById('info-selecionadas');
        if (info) info.textContent = marcados.length;
        document.querySelectorAll('[data-soma-selecao]').forEach((el) => {
            const chave = el.dataset.somaSelecao;
            el.textContent = reais(marcados.reduce((s, c) => s + (parseFloat(c.dataset[chave]) || 0), 0));
        });
        itens().forEach((c) => c.closest('tr')?.classList.toggle('table-active', c.checked));
        if (todos) {
            todos.checked = marcados.length > 0 && marcados.length === itens().length;
            todos.indeterminate = marcados.length > 0 && marcados.length < itens().length;
        }
    }

    todos?.addEventListener('change', () => {
        itens().forEach((c) => (c.checked = todos.checked));
        atualizar();
    });
    document.addEventListener('change', (ev) => { if (ev.target.classList?.contains('selecao-item')) atualizar(); });
    // A linha abre o detalhe ao clicar; a caixinha não.
    document.addEventListener('click', (ev) => { if (ev.target.classList?.contains('selecao-item')) ev.stopPropagation(); }, true);
    document.addEventListener('selecao-mudou', atualizar);
    atualizar();
})();
