// Protege todo POST do sistema contra CSRF sem precisar mexer em cada
// formulário/fetch espalhado pelas telas: lê o token da tag <meta
// name="csrf-token"> (gerada pelo bootstrap.php em toda página) e injeta
// sozinho em qualquer <form> (mesmo criado depois, via JS) e em todo
// fetch() (num header, cobrindo as chamadas AJAX). O bootstrap.php confere
// esse valor em todo POST e rejeita se não bater com o da sessão.
(function () {
    const meta = document.querySelector('meta[name="csrf-token"]');
    const token = meta ? meta.content : '';
    if (!token) {
        return;
    }

    function garantirCampoCsrf(form) {
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        if ((form.method || 'get').toLowerCase() !== 'post') {
            return;
        }
        let campo = form.querySelector('input[name="_csrf"]');
        if (!campo) {
            campo = document.createElement('input');
            campo.type = 'hidden';
            campo.name = '_csrf';
            form.appendChild(campo);
        }
        campo.value = token;
    }

    // Cobre o envio "de verdade" (clique no botão, Enter) — dispara antes
    // do navegador realmente mandar a requisição.
    document.addEventListener('submit', function (ev) {
        garantirCampoCsrf(ev.target);
    }, true);

    // form.submit() chamado direto pelo JS não dispara o evento "submit"
    // acima — cobre esse caso também (usado em telas que montam um form
    // temporário só pra imprimir relatório, por exemplo).
    const submitOriginal = HTMLFormElement.prototype.submit;
    HTMLFormElement.prototype.submit = function () {
        garantirCampoCsrf(this);
        return submitOriginal.apply(this, arguments);
    };

    // Toda chamada fetch() do sistema ganha o token num header — cobre as
    // dezenas de chamadas AJAX espalhadas pelas telas sem precisar editar
    // cada uma.
    const fetchOriginal = window.fetch;
    window.fetch = function (recurso, opcoes) {
        opcoes = opcoes || {};
        const metodo = (opcoes.method || 'GET').toUpperCase();
        if (metodo !== 'GET' && metodo !== 'HEAD') {
            const headers = new Headers(opcoes.headers || {});
            headers.set('X-CSRF-Token', token);
            opcoes = Object.assign({}, opcoes, { headers: headers });
        }
        return fetchOriginal.call(this, recurso, opcoes);
    };
})();
