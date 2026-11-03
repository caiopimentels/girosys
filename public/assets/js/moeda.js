// Valores em reais nos campos com data-moeda: o usuário digita "2000" ou
// "2.000,50" e, ao sair do campo, fica "2.000,50". O servidor aceita os dois
// formatos (paraDecimal() no PHP).
window.Moeda = {
    // "2.000,50" | "2000.5" | "2000" → 2000.5
    ler(texto) {
        texto = String(texto || '').trim().replace(/[^\d,.\-]/g, '');
        if (texto === '') return 0;
        if (texto.includes(',')) texto = texto.replace(/\./g, '').replace(',', '.');
        const n = parseFloat(texto);
        return isNaN(n) ? 0 : n;
    },
    formatar(n, casas = 2) {
        return Number(n || 0).toLocaleString('pt-BR', { minimumFractionDigits: casas, maximumFractionDigits: casas });
    },
    reais(n) {
        return 'R$ ' + Moeda.formatar(n);
    },
};

document.addEventListener('focusout', (ev) => {
    const campo = ev.target;
    if (campo.matches && campo.matches('[data-moeda]') && campo.value.trim() !== '') {
        campo.value = Moeda.formatar(Moeda.ler(campo.value), Number(campo.dataset.casas || 2));
    }
});
