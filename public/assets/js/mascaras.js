// Máscaras de CPF e telefone nos campos com data-mascara="cpf" ou
// "telefone". O servidor guarda só os números; aqui é só para a tela.
// Funciona também nos campos criados depois (ex.: "Outros telefones").
(function () {
    const formatos = {
        cpf(d) {
            d = d.slice(0, 11);
            return d.replace(/^(\d{3})(\d)/, '$1.$2').replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3').replace(/\.(\d{3})(\d{1,2})$/, '.$1-$2');
        },
        telefone(d) {
            d = d.slice(0, 11);
            if (d.length <= 2) return d;
            if (d.length <= 6) return '(' + d.slice(0, 2) + ') ' + d.slice(2);
            if (d.length <= 10) return '(' + d.slice(0, 2) + ') ' + d.slice(2, 6) + '-' + d.slice(6);
            return '(' + d.slice(0, 2) + ') ' + d.slice(2, 7) + '-' + d.slice(7);
        },
    };
    function aplicar(campo) {
        const formatar = formatos[campo.dataset.mascara];
        if (formatar && campo.value) campo.value = formatar(campo.value.replace(/\D/g, ''));
    }
    document.addEventListener('input', (ev) => {
        if (ev.target.matches && ev.target.matches('[data-mascara]')) aplicar(ev.target);
    });
    document.querySelectorAll('[data-mascara]').forEach(aplicar);
})();
