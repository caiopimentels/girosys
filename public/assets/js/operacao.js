// Tela "Nova operação de desconto": monta as linhas e recalcula tudo ao
// vivo. A conta é a mesma de includes/desconto.php (que é a que vale ao gravar):
//   juros = valor × taxa% / 30 × dias   (juros simples, mês de 30 dias)
(function () {
    const { estado, contas, tipos } = window.OPERACAO;
    const $ = (sel, el = document) => el.querySelector(sel);
    const tabelaTitulos = $('#tabela-titulos');
    const corpoAdiant = $('#corpo-adiantamentos');
    const campoCliente = $('#cliente_nome');
    const campoTaxa = $('#taxa');
    let seq = 0;
    let clienteAtual = null; // resposta de /pessoas/info.php
    let taxaDigitada = estado.taxa !== '';
    let repassesCorrigidos = 0; // repasses marcados, corrigidos até a data base (para o cálculo de parcelas)
    // Despesas a deixar marcadas quando a lista do cliente chegar: id => valor usado.
    let marcarAoCarregar = new Map(estado.adiantamentos.map((a) => [String(a.id), a.valor_usado]));

    const arred = (n) => Math.round((n + Number.EPSILON) * 100) / 100;
    const reais = (n) => Moeda.reais(n);
    const escapar = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const ajax = { headers: { 'X-Requested-With': 'XMLHttpRequest' } };

    function diasEntre(de, ate) {
        if (!de || !ate) return 0;
        const [a1, m1, d1] = de.split('-').map(Number);
        const [a2, m2, d2] = ate.split('-').map(Number);
        return Math.round((Date.UTC(a2, m2 - 1, d2) - Date.UTC(a1, m1 - 1, d1)) / 86400000);
    }
    const juros = (valor, taxa, dias) => (dias > 0 ? arred(valor * taxa / 100 / 30 * dias) : 0);
    const taxaAtual = () => Moeda.ler(campoTaxa.value);

    function somarDias(data, dias) {
        if (!data) return '';
        const [a, m, d] = data.split('-').map(Number);
        return new Date(Date.UTC(a, m - 1, d + dias)).toISOString().slice(0, 10);
    }

    function opcoesConta(selecionada) {
        return '<option value="">—</option>' + contas.map((c) => `<option value="${c.id}" ${String(c.id) === String(selecionada) ? 'selected' : ''}>${escapar(c.nome)}</option>`).join('');
    }

    // ---------------- Títulos ----------------
    // Cada título é um <tbody>: a linha principal (tipo, vencimento, valor) e
    // a linha de detalhes (emitente, CPF, banco, nº, conta), que abre ao clicar.
    const titulos = () => [...tabelaTitulos.querySelectorAll('tbody.titulo')];
    const campo = (tb, nome) => $(`[data-campo="${nome}"]`, tb);

    function adicionarTitulo(t = {}, depoisDe = null) {
        const i = seq++;
        const ultimo = titulos().pop();
        const tipo = t.tipo || (ultimo ? campo(ultimo, 'tipo').value : 'cheque');
        const conta = t.conta_destino_id ?? ($('#conta_padrao_id').value || (ultimo ? campo(ultimo, 'conta_destino_id').value : ''));
        const tb = document.createElement('tbody');
        tb.className = 'titulo';
        tb.innerHTML = `
            <tr class="titulo-principal">
                <td><button type="button" class="btn btn-sm btn-link p-1 text-muted" data-acao="abrir" title="Mais dados"><i class="bi bi-chevron-right"></i></button></td>
                <td><select class="form-select" name="titulos[${i}][tipo]" data-campo="tipo">${Object.entries(tipos).map(([k, v]) => `<option value="${k}" ${k === tipo ? 'selected' : ''}>${v}</option>`).join('')}</select></td>
                <td><input type="date" class="form-control" name="titulos[${i}][vencimento]" data-campo="vencimento" value="${escapar(t.vencimento)}"></td>
                <td><div class="input-group"><span class="input-group-text">R$</span><input type="text" class="form-control text-end fw-semibold" name="titulos[${i}][valor]" data-campo="valor" data-moeda inputmode="decimal" placeholder="0,00" value="${t.valor ? Moeda.formatar(t.valor) : ''}"></div></td>
                <td class="titulo-resumo small"></td>
                <td class="calc text-end" data-saida="dias">-</td>
                <td class="calc text-end" data-saida="juros">-</td>
                <td class="calc text-end fw-semibold" data-saida="liquido">-</td>
                <td class="text-nowrap">
                    <button type="button" class="btn btn-sm btn-link p-1" data-acao="duplicar" title="Repetir com vencimento +30 dias"><i class="bi bi-copy"></i></button>
                    <button type="button" class="btn btn-sm btn-link p-1 text-danger" data-acao="remover" title="Remover"><i class="bi bi-x-lg"></i></button>
                </td>
            </tr>
            <tr class="titulo-detalhes d-none">
                <td></td>
                <td colspan="8">
                    <div class="row g-2">
                        <div class="col-md-4"><label class="form-label small mb-1">Pagador do título (emitente)</label>
                            <input type="text" class="form-control" name="titulos[${i}][emitente_nome]" data-campo="emitente_nome" data-busca-pessoa="pagador" autocomplete="off-busca" placeholder="em branco = cheque do próprio cliente" value="${escapar(t.emitente_nome)}">
                            <div class="emitente-info"></div></div>
                        <div class="col-md-2"><label class="form-label small mb-1">CPF/CNPJ</label><input type="text" class="form-control" name="titulos[${i}][emitente_documento]" data-campo="emitente_documento" inputmode="numeric" autocomplete="off-busca" value="${escapar(t.emitente_documento)}"></div>
                        <div class="col-md-2"><label class="form-label small mb-1">Banco</label><input type="text" class="form-control" name="titulos[${i}][banco_titulo]" data-campo="banco_titulo" value="${escapar(t.banco_titulo)}"></div>
                        <div class="col-md-1"><label class="form-label small mb-1">Nº</label><input type="text" class="form-control" name="titulos[${i}][numero_titulo]" data-campo="numero_titulo" value="${escapar(t.numero_titulo)}"></div>
                        <div class="col-md-3"><label class="form-label small mb-1">Cai na conta</label><select class="form-select" name="titulos[${i}][conta_destino_id]" data-campo="conta_destino_id">${opcoesConta(conta)}</select></div>
                        <div class="col-12"><input type="text" class="form-control" name="titulos[${i}][observacao]" data-campo="observacao" placeholder="Observação" value="${escapar(t.observacao)}"></div>
                    </div>
                </td>
            </tr>`;
        if (depoisDe) depoisDe.after(tb); else tabelaTitulos.appendChild(tb);
        if (t.emitente_nome) consultarEmitente(tb);
        atualizarResumoTitulo(tb);
        recalcular();
        return tb;
    }

    function abrirDetalhes(tb, abrir) {
        const det = $('.titulo-detalhes', tb);
        const vaiAbrir = abrir ?? det.classList.contains('d-none');
        det.classList.toggle('d-none', !vaiAbrir);
        $('[data-acao="abrir"] i', tb).className = 'bi ' + (vaiAbrir ? 'bi-chevron-down' : 'bi-chevron-right');
        if (vaiAbrir && abrir === undefined) campo(tb, 'emitente_nome').focus();
    }

    function atualizarResumoTitulo(tb) {
        const emitente = campo(tb, 'emitente_nome').value.trim();
        const sel = campo(tb, 'conta_destino_id');
        const conta = sel.value ? sel.options[sel.selectedIndex].text : '';
        const badge = tb.dataset.badge || '';
        const html = (emitente ? escapar(emitente) + ' ' + badge : '<span class="text-muted">cheque do próprio cliente</span>')
            + (conta ? ` <span class="text-muted">· ${escapar(conta)}</span>` : ' <span class="text-muted">· sem conta</span>');
        // Só redesenha se mudou: trocar o conteúdo no meio de um clique (o
        // "change" do valor dispara no mousedown) faz o Chrome perder o clique.
        const celula = $('.titulo-resumo', tb);
        if (celula.innerHTML !== html) celula.innerHTML = html;
    }

    tabelaTitulos.addEventListener('click', (ev) => {
        const tb = ev.target.closest('tbody.titulo');
        if (!tb) return;
        const botao = ev.target.closest('[data-acao]');
        if (botao) {
            if (botao.dataset.acao === 'abrir') abrirDetalhes(tb);
            else if (botao.dataset.acao === 'remover') { tb.remove(); if (!titulos().length) adicionarTitulo(); recalcular(); }
            else {
                const dados = {};
                tb.querySelectorAll('[data-campo]').forEach((c) => (dados[c.dataset.campo] = c.value));
                dados.valor = Moeda.ler(dados.valor);
                dados.vencimento = somarDias(dados.vencimento, 30);
                dados.numero_titulo = /^\d+$/.test(dados.numero_titulo) ? String(Number(dados.numero_titulo) + 1).padStart(dados.numero_titulo.length, '0') : '';
                adicionarTitulo(dados, tb);
            }
            return;
        }
        // Clique na linha principal (fora dos campos) abre/fecha os detalhes.
        if (ev.target.closest('.titulo-principal') && !ev.target.closest('input, select, .input-group')) abrirDetalhes(tb);
    });

    tabelaTitulos.addEventListener('input', recalcular);
    tabelaTitulos.addEventListener('change', (ev) => {
        const tb = ev.target.closest('tbody.titulo');
        if (tb && ev.target.matches('[data-campo="emitente_nome"], [data-campo="emitente_documento"]')) consultarEmitente(tb);
        if (tb) atualizarResumoTitulo(tb);
        recalcular();
    });
    // Enter no valor: vai para o próximo título (ou cria um).
    tabelaTitulos.addEventListener('keydown', (ev) => {
        if (ev.key !== 'Enter') return;
        ev.preventDefault();
        if (ev.target.dataset.campo === 'valor') {
            ev.target.dispatchEvent(new Event('focusout', { bubbles: true }));
            const tb = ev.target.closest('tbody.titulo');
            const proximo = titulos()[titulos().indexOf(tb) + 1] || adicionarTitulo();
            campo(proximo, 'vencimento').focus();
        }
    });

    function consultarEmitente(tb) {
        const nome = campo(tb, 'emitente_nome').value.trim();
        const documento = campo(tb, 'emitente_documento').value.trim();
        const info = $('.emitente-info', tb);
        tb.dataset.badge = '';
        if (!nome && !documento) { info.innerHTML = ''; atualizarResumoTitulo(tb); return; }
        fetch('/pessoas/info.php?' + new URLSearchParams({ nome, documento, papel: 'pagador' }), ajax)
            .then((r) => r.json())
            .then((d) => {
                if (!d.encontrado) {
                    info.innerHTML = '<span class="badge text-bg-light">Pagador novo — será cadastrado ao gravar</span>';
                } else {
                    info.innerHTML = `<a href="/pessoas/form.php?id=${d.id}&tab=emitente" target="_blank" title="${escapar(d.resumo)}">${d.badge}</a> <span class="text-muted">${escapar(d.resumo)}</span>`;
                    tb.dataset.badge = d.badge;
                    if (!campo(tb, 'emitente_documento').value && d.documento) campo(tb, 'emitente_documento').value = d.documento;
                }
                atualizarResumoTitulo(tb);
            })
            .catch(() => {});
    }

    // ---------------- Cliente ----------------
    function consultarCliente() {
        const nome = campoCliente.value.trim();
        const caixa = $('#cliente-info');
        clienteAtual = null;
        if (!nome) { caixa.innerHTML = ''; listarAdiantamentos(null); return; }
        fetch('/pessoas/info.php?' + new URLSearchParams({ nome, papel: 'cliente' }), ajax)
            .then((r) => r.json())
            .then((d) => {
                if (!d.encontrado) {
                    caixa.innerHTML = '<span class="badge text-bg-light">Cliente novo — será cadastrado ao gravar</span>';
                    $('#taxa-origem').textContent = '';
                    listarAdiantamentos(null);
                    return;
                }
                clienteAtual = d;
                caixa.innerHTML = `<a href="/pessoas/form.php?id=${d.id}&tab=cliente" target="_blank">ver cadastro</a>`
                    + (d.limite_credito !== null ? ` · limite ${reais(d.limite_credito)}, em uso ${reais(d.credito_em_uso)}` : ` · crédito em uso ${reais(d.credito_em_uso)}`);
                if (d.taxa_padrao !== null && (!taxaDigitada || campoTaxa.value === '')) {
                    campoTaxa.value = Moeda.formatar(d.taxa_padrao, 2).replace(/,00$/, ',0');
                    $('#taxa-origem').textContent = 'Taxa do cadastro do cliente';
                    taxaDigitada = false;
                } else if (d.taxa_padrao === null) {
                    $('#taxa-origem').textContent = 'Cliente sem taxa no cadastro — esta será gravada como a dele';
                }
                listarAdiantamentos(d);
            })
            .catch(() => {});
    }
    campoCliente.addEventListener('change', consultarCliente);
    campoTaxa.addEventListener('input', () => { taxaDigitada = true; $('#taxa-origem').textContent = 'Taxa só desta operação'; recalcular(); });
    ['#data_base', '#dias_compensacao'].forEach((s) => $(s).addEventListener('input', recalcular));

    // ---------------- Valores já passados (adiantamentos) ----------------
    // Lista todos os em aberto do cliente; só os marcados vão no formulário
    // (os campos dos desmarcados ficam desabilitados e não são enviados).
    function listarAdiantamentos(cliente) {
        // Guarda o que já estava marcado, para não perder ao recarregar a lista.
        corpoAdiant.querySelectorAll('tr[data-id]').forEach((tr) => {
            if ($('.marcar', tr).checked) marcarAoCarregar.set(tr.dataset.id, Moeda.ler($('[data-usado]', tr).value));
        });
        const itens = cliente ? cliente.adiantamentos : [];
        if (!cliente) {
            corpoAdiant.innerHTML = `<tr><td colspan="9" class="text-center text-muted py-3">${campoCliente.value.trim() ? 'Cliente novo: nenhum repasse ainda.' : 'Escolha o cliente para ver os repasses dele em aberto.'}</td></tr>`;
        } else if (!itens.length) {
            corpoAdiant.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-3">Nenhum repasse em aberto para este cliente. Lance em Repasses ou no "+" abaixo.</td></tr>';
        } else {
            corpoAdiant.innerHTML = '';
            itens.forEach((a) => {
                const i = seq++;
                const marcado = marcarAoCarregar.has(String(a.id));
                const usado = marcado ? marcarAoCarregar.get(String(a.id)) : a.em_aberto;
                const tr = document.createElement('tr');
                tr.dataset.id = a.id;
                tr.dataset.data = a.data;
                tr.dataset.emAberto = a.em_aberto;
                tr.className = 'cursor-pointer';
                tr.innerHTML = `
                    <td><input type="checkbox" class="form-check-input marcar" ${marcado ? 'checked' : ''}><input type="hidden" name="adiantamentos[${i}][id]" value="${a.id}" data-envia></td>
                    <td class="text-num">${escapar(a.data_br)}</td>
                    <td>${escapar(a.descricao) || '-'}</td>
                    <td>${escapar(a.conta) || '—'}</td>
                    <td class="text-end text-num">${reais(a.em_aberto)}</td>
                    <td><input type="text" class="form-control form-control-sm text-end" name="adiantamentos[${i}][valor_usado]" data-usado data-envia data-moeda inputmode="decimal" value="${Moeda.formatar(usado)}"></td>
                    <td class="calc text-end" data-saida="dias">-</td>
                    <td class="calc text-end" data-saida="juros">-</td>
                    <td class="calc text-end fw-semibold" data-saida="corrigido">-</td>`;
                corpoAdiant.appendChild(tr);
            });
        }
        marcarAoCarregar = new Map();
        recalcular();
    }
    corpoAdiant.addEventListener('click', (ev) => {
        const tr = ev.target.closest('tr[data-id]');
        if (!tr || ev.target.matches('input')) return;
        const c = $('.marcar', tr);
        c.checked = !c.checked;
        recalcular();
    });
    corpoAdiant.addEventListener('input', recalcular);
    corpoAdiant.addEventListener('change', recalcular);
    corpoAdiant.addEventListener('keydown', (ev) => { if (ev.key === 'Enter') ev.preventDefault(); });
    $('#btn-marcar-todos').addEventListener('click', () => {
        const caixas = [...corpoAdiant.querySelectorAll('.marcar')];
        const todas = caixas.length && caixas.every((c) => c.checked);
        caixas.forEach((c) => (c.checked = !todas));
        recalcular();
    });

    $('#na-salvar').addEventListener('click', () => {
        if (!campoCliente.value.trim()) { $('#na-erro').textContent = 'Informe o cliente primeiro.'; return; }
        const corpo = new URLSearchParams({
            cliente_nome: campoCliente.value.trim(),
            cliente_documento: $('[name="cliente_documento"]').value,
            data: $('#na-data').value,
            valor: $('#na-valor').value,
            conta_id: $('#na-conta').value,
            descricao: $('#na-descricao').value,
        });
        fetch('/adiantamentos/criar_rapido.php', { method: 'POST', headers: { ...ajax.headers, 'Content-Type': 'application/x-www-form-urlencoded' }, body: corpo.toString() })
            .then((r) => r.json())
            .then((d) => {
                if (!d.sucesso) { $('#na-erro').textContent = d.mensagem; return; }
                marcarAoCarregar.set(String(d.adiantamento.id), d.adiantamento.em_aberto);
                ['#na-valor', '#na-descricao'].forEach((s) => ($(s).value = ''));
                $('#na-erro').textContent = '';
                $('#novo-adiantamento').open = false;
                consultarCliente();
            })
            .catch(() => ($('#na-erro').textContent = 'Não foi possível lançar. Tente de novo.'));
    });

    // ---------------- Cálculo ----------------
    function recalcular() {
        const base = $('#data_base').value;
        const taxa = taxaAtual();
        const comp = Math.max(0, parseInt($('#dias_compensacao').value, 10) || 0);
        let bruto = 0, totJuros = 0, liquido = 0;

        titulos().forEach((tb) => {
            const valor = Moeda.ler(campo(tb, 'valor').value);
            const venc = campo(tb, 'vencimento').value;
            const saida = (k) => $(`[data-saida="${k}"]`, tb);
            if (!valor || !venc || !base) { ['dias', 'juros', 'liquido'].forEach((k) => (saida(k).textContent = '-')); return; }
            const dias = Math.max(0, diasEntre(base, venc) + comp);
            const j = juros(valor, taxa, dias);
            saida('dias').textContent = dias;
            saida('juros').textContent = Moeda.formatar(j);
            saida('liquido').textContent = Moeda.formatar(valor - j);
            bruto += valor; totJuros += j; liquido += arred(valor - j);
        });

        let adiant = 0, adiantJuros = 0, corrigido = 0;
        corpoAdiant.querySelectorAll('tr[data-id]').forEach((tr) => {
            const marcado = $('.marcar', tr).checked;
            tr.querySelectorAll('[data-envia]').forEach((c) => (c.disabled = !marcado));
            tr.classList.toggle('table-success', marcado);
            const usado = Moeda.ler($('[data-usado]', tr).value);
            $('[data-usado]', tr).classList.toggle('is-invalid', marcado && usado > Number(tr.dataset.emAberto) + 0.004);
            if (!marcado) { ['dias', 'juros', 'corrigido'].forEach((k) => ($(`[data-saida="${k}"]`, tr).textContent = '-')); return; }
            const dias = Math.max(0, diasEntre(tr.dataset.data, base));
            const j = juros(usado, taxa, dias);
            $('[data-saida="dias"]', tr).textContent = dias;
            $('[data-saida="juros"]', tr).textContent = Moeda.formatar(j);
            $('[data-saida="corrigido"]', tr).textContent = Moeda.formatar(usado + j);
            adiant += usado; adiantJuros += j; corrigido += arred(usado + j);
        });

        repassesCorrigidos = corrigido;
        const saldo = arred(liquido - corrigido);
        const set = (id, v) => ($('#' + id).textContent = reais(v));
        set('tot-bruto', bruto); set('tot-juros', totJuros); set('tot-liquido', liquido);
        set('tot-adiant', adiant); set('tot-adiant-juros', adiantJuros); set('tot-adiant-corrigido', corrigido);
        set('r-bruto', bruto); set('r-juros', totJuros); set('r-liquido', liquido);
        set('r-adiant', adiant); set('r-adiant-juros', adiantJuros); set('r-adiant-corrigido', corrigido);
        set('r-lucro', totJuros + adiantJuros);

        const elSaldo = $('#r-saldo');
        elSaldo.textContent = reais(Math.abs(saldo));
        elSaldo.className = 'saldo-final ' + (saldo < 0 ? 'valor-negativo' : '');
        if (saldo < 0) {
            $('#r-saldo-rotulo').textContent = 'Residual: cliente ainda deve';
            $('#r-saldo-ajuda').textContent = 'O líquido dos títulos não cobre os repasses.';
        } else if (adiant > 0) {
            $('#r-saldo-rotulo').textContent = saldo > 0 ? 'Diferença a pagar ao cliente' : 'Zerado';
            $('#r-saldo-ajuda').textContent = saldo > 0 ? 'Líquido dos títulos − repasses corrigidos.' : 'Os títulos cobrem exatamente os repasses.';
        } else {
            $('#r-saldo-rotulo').textContent = 'Pagar ao cliente';
            $('#r-saldo-ajuda').textContent = 'Desconto direto: o cliente recebe o líquido.';
        }
        $('#bloco-pagar').style.display = saldo > 0 ? '' : 'none';
        $('#conta_pagamento_id').disabled = !$('#pagar_diferenca').checked;
        $('#conta_pagamento_id').required = saldo > 0 && $('#pagar_diferenca').checked;
        $('#bloco-residual').style.display = saldo < 0 ? '' : 'none';
        $('#residual_vencimento').disabled = !$('#residual-receber').checked;

        // Limite de crédito: em aberto hoje − o que esta operação quita + títulos novos + residual.
        const limite = $('#bloco-limite');
        if (clienteAtual && clienteAtual.limite_credito !== null) {
            const depois = arred(clienteAtual.credito_em_uso - adiant + bruto + Math.max(0, -saldo));
            const disponivel = arred(clienteAtual.limite_credito - depois);
            limite.className = 'mt-2 small p-2 rounded ' + (disponivel < 0 ? 'bg-danger-subtle' : 'bg-body-tertiary');
            limite.innerHTML = `Limite ${reais(clienteAtual.limite_credito)} · depois desta operação em uso <strong>${reais(depois)}</strong>`
                + (disponivel < 0 ? `<div class="valor-negativo fw-semibold">Passa do limite em ${reais(-disponivel)}</div>` : `<div>Disponível: ${reais(disponivel)}</div>`);
        } else {
            limite.className = 'mt-2 small';
            limite.innerHTML = '';
        }
    }
    $('#pagar_diferenca').addEventListener('change', recalcular);
    document.querySelectorAll('[name="residual_modo"]').forEach((r) => r.addEventListener('change', recalcular));
    $('#btn-add-titulo').addEventListener('click', () => campo(adicionarTitulo(), 'vencimento').focus());

    // ---------------- Calcular parcelas ----------------
    // Valor de face igual em todas as parcelas, de modo que a soma dos
    // líquidos (valor − juros de cada prazo) dê o líquido desejado:
    //   valor = líquido ÷ Σ(1 − taxa/30 × dias da parcela)
    // arredondado para cima (centavos, R$ 1 ou R$ 10), nunca abaixo da meta.
    const painelParc = $('#painel-parcelas');
    const modalParc = new bootstrap.Modal($('#modal-parcelas'));
    const campoAlvo = $('#parc-alvo');
    let alvoDigitado = false;

    function somarMesesIso(data, meses) {
        const [a, m, d] = data.split('-').map(Number);
        const ultimo = new Date(Date.UTC(a, m - 1 + meses + 1, 0)).getUTCDate();
        return new Date(Date.UTC(a, m - 1 + meses, Math.min(d, ultimo))).toISOString().slice(0, 10);
    }

    function calcularParcelas() {
        const alvo = Moeda.ler(campoAlvo.value);
        const n = parseInt($('#parc-qtd').value, 10) || 0;
        if (n < 1 || n > 360) return { erro: 'Parcelas: de 1 a 360.' };
        const primeiro = $('#parc-venc').value;
        const base = $('#data_base').value;
        const taxa = taxaAtual();
        const comp = Math.max(0, parseInt($('#dias_compensacao').value, 10) || 0);
        const passo = Number($('#parc-arred').value);
        if (!alvo || !primeiro || !base) return { erro: 'Informe o líquido desejado e o 1º vencimento.' };

        const intervaloDias = Math.max(1, parseInt($('#parc-dias').value, 10) || 30);
        const vencs = [...Array(n)].map((_, i) => ($('#parc-intervalo').value === 'mes' ? somarMesesIso(primeiro, i) : somarDias(primeiro, intervaloDias * i)));
        const dias = vencs.map((v) => Math.max(0, diasEntre(base, v) + comp));
        const somaFatores = dias.reduce((s, d) => s + (1 - taxa / 100 / 30 * d), 0);
        if (somaFatores <= 0) return { erro: 'Com essa taxa e esses prazos os juros passam do valor do título.' };

        let valor = Math.ceil(arred(alvo / somaFatores) / passo - 1e-9) * passo;
        const liquidoDe = (v) => dias.reduce((s, d) => s + arred(v - juros(v, taxa, d)), 0);
        for (let i = 0; i < 1000 && liquidoDe(valor) < alvo - 0.004; i++) valor = arred(valor + passo); // centavos dos juros
        valor = arred(valor);
        const liquido = arred(liquidoDe(valor));
        return { n, valor, vencs, alvo, bruto: arred(valor * n), liquido, sobra: arred(liquido - alvo) };
    }

    const dataBr = (iso) => iso.split('-').reverse().join('/');

    function mostrarPrevia() {
        $('#bloco-parc-dias').style.display = $('#parc-intervalo').value === 'dias' ? '' : 'none';
        const r = calcularParcelas();
        $('#parc-vencs').textContent = r.erro ? '' : 'Vencimentos: ' + r.vencs.map(dataBr).join(', ');
        $('#parc-previa').innerHTML = r.erro ? `<span class="text-danger">${escapar(r.erro)}</span>`
            : `<strong>${r.n} × ${reais(r.valor)}</strong> = bruto ${reais(r.bruto)} · líquido <strong>${reais(r.liquido)}</strong>`
              + (r.sobra > 0.004 ? ` <span class="text-muted">(${reais(r.sobra)} acima do desejado, pelo arredondamento)</span>` : '');
        return r;
    }

    $('#btn-parcelas').addEventListener('click', () => {
        if (!alvoDigitado && repassesCorrigidos > 0) campoAlvo.value = Moeda.formatar(repassesCorrigidos);
        $('#parc-alvo-ajuda').textContent = repassesCorrigidos > 0 ? 'Repasses marcados, corrigidos até a data base: ' + reais(repassesCorrigidos) : 'Nenhum repasse marcado: digite o valor.';
        if (!$('#parc-venc').value && $('#data_base').value) $('#parc-venc').value = somarMesesIso($('#data_base').value, 1);
        mostrarPrevia();
        modalParc.show();
    });
    $('#modal-parcelas').addEventListener('shown.bs.modal', () => campoAlvo.focus());
    campoAlvo.addEventListener('input', () => (alvoDigitado = true));
    painelParc.addEventListener('input', mostrarPrevia);
    painelParc.addEventListener('change', mostrarPrevia);
    painelParc.addEventListener('keydown', (ev) => { if (ev.key === 'Enter') { ev.preventDefault(); campoAlvo.dispatchEvent(new Event('focusout', { bubbles: true })); $('#parc-gerar').click(); } });

    $('#parc-gerar').addEventListener('click', () => {
        const r = mostrarPrevia();
        if (r.erro) { (Moeda.ler(campoAlvo.value) ? $('#parc-venc') : campoAlvo).focus(); return; }
        const preenchidos = titulos().filter((tb) => Moeda.ler(campo(tb, 'valor').value) > 0);
        if (preenchidos.length && !confirm('Substituir os ' + preenchidos.length + ' título(s) já lançados pelas ' + r.n + ' parcelas?')) return;
        // Mantém pagador, banco e conta do primeiro título lançado (cheques do mesmo emitente).
        const modelo = titulos()[0];
        const copiar = modelo ? ['emitente_nome', 'emitente_documento', 'banco_titulo', 'conta_destino_id'].reduce((o, k) => (campo(modelo, k).value ? { ...o, [k]: campo(modelo, k).value } : o), {}) : {};
        titulos().forEach((tb) => tb.remove());
        r.vencs.forEach((venc) => adicionarTitulo({ ...copiar, tipo: $('#parc-tipo').value, vencimento: venc, valor: r.valor }));
        modalParc.hide();
        recalcular();
        $('#tabela-titulos').scrollIntoView({ behavior: 'smooth', block: 'center' });
    });

    // Enter nos campos de cima também não grava sem querer.
    $('#form-operacao').addEventListener('keydown', (ev) => {
        if (ev.key === 'Enter' && ev.target.tagName === 'INPUT' && ev.target.type !== 'submit') ev.preventDefault();
    });

    // Estado inicial (tela nova ou voltando com erro).
    (estado.titulos.length ? estado.titulos : [{}]).forEach((t) => adicionarTitulo(t));
    if (campoCliente.value.trim()) consultarCliente(); else listarAdiantamentos(null);
    recalcular();
})();
