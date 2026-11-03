// Widget de filtro de período: dois calendários (início/fim) + atalhos,
// no estilo do seletor de período do Bling. Mesmo do sistema_keyla, com
// atalhos para a frente (planejamento: contas a vencer, previsto).
(function () {
    const MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

    function pad(n) {
        return String(n).padStart(2, '0');
    }

    function paraISO(data) {
        if (!data) return '';
        return data.getFullYear() + '-' + pad(data.getMonth() + 1) + '-' + pad(data.getDate());
    }

    function deISO(iso) {
        if (!iso) return null;
        const [ano, mes, dia] = iso.split('-').map(Number);
        return new Date(ano, mes - 1, dia);
    }

    function mesmoDia(a, b) {
        return a && b && a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
    }

    function inicioDoMes(data) {
        return new Date(data.getFullYear(), data.getMonth(), 1);
    }

    function calcularPreset(preset) {
        const hoje = new Date();
        hoje.setHours(0, 0, 0, 0);
        let inicio = null;
        let fim = null;

        switch (preset) {
            case 'hoje':
                inicio = new Date(hoje);
                fim = new Date(hoje);
                break;
            case 'semana':
                inicio = new Date(hoje);
                inicio.setDate(hoje.getDate() - hoje.getDay());
                fim = new Date(hoje);
                break;
            case 'semana_passada':
                fim = new Date(hoje);
                fim.setDate(hoje.getDate() - hoje.getDay() - 1);
                inicio = new Date(fim);
                inicio.setDate(fim.getDate() - 6);
                break;
            case 'mes':
                inicio = new Date(hoje.getFullYear(), hoje.getMonth(), 1);
                fim = new Date(hoje);
                break;
            case 'mes_passado':
                inicio = new Date(hoje.getFullYear(), hoje.getMonth() - 1, 1);
                fim = new Date(hoje.getFullYear(), hoje.getMonth(), 0);
                break;
            case 'mes_inteiro':
                inicio = new Date(hoje.getFullYear(), hoje.getMonth(), 1);
                fim = new Date(hoje.getFullYear(), hoje.getMonth() + 1, 0);
                break;
            case 'proximos_30':
                inicio = new Date(hoje);
                fim = new Date(hoje);
                fim.setDate(hoje.getDate() + 30);
                break;
            case 'proximo_mes':
                inicio = new Date(hoje.getFullYear(), hoje.getMonth() + 1, 1);
                fim = new Date(hoje.getFullYear(), hoje.getMonth() + 2, 0);
                break;
            case 'tudo':
                inicio = null;
                fim = null;
                break;
        }

        return { inicio, fim };
    }

    const PRESETS_CONCRETOS = ['hoje', 'semana', 'semana_passada', 'mes', 'mes_passado', 'mes_inteiro', 'proximos_30', 'proximo_mes', 'tudo'];

    const NOMES_PRESET = {
        hoje: 'Hoje',
        semana: 'Esta semana',
        semana_passada: 'Semana passada',
        mes: 'Este mês',
        mes_passado: 'Mês passado',
        mes_inteiro: 'Mês inteiro',
        proximos_30: 'Próximos 30 dias',
        proximo_mes: 'Próximo mês',
        tudo: 'Todo o período',
    };

    function presetCorresponde(preset, inicio, fim) {
        if (preset === 'tudo') return !inicio && !fim;
        const calc = calcularPreset(preset);
        return mesmoDia(calc.inicio, inicio) && mesmoDia(calc.fim, fim);
    }

    function presetQueCorresponde(inicio, fim) {
        return PRESETS_CONCRETOS.find((preset) => presetCorresponde(preset, inicio, fim)) || null;
    }

    function iniciarWidget(container) {
        const estado = {
            inicio: deISO(container.querySelector('.periodo-hidden-inicio').value),
            fim: deISO(container.querySelector('.periodo-hidden-fim').value),
            customizadoForcado: false,
        };
        estado.mesInicio = inicioDoMes(estado.inicio || new Date());
        estado.mesFim = inicioDoMes(estado.fim || new Date());
        // Guarda qual atalho foi de fato clicado, para não depender só de
        // "as datas batem com esse cálculo" — em dias específicos (ex.:
        // domingo) "Esta semana" e "Hoje" calculam o mesmo intervalo, e sem
        // isso o rótulo/realce voltaria pro atalho errado.
        estado.presetSelecionado = presetQueCorresponde(estado.inicio, estado.fim);

        const calInicio = container.querySelector('[data-lado="inicio"]');
        const calFim = container.querySelector('[data-lado="fim"]');
        const painel = container.querySelector('.periodo-painel');

        // Fora do período customizado só existe um calendário na tela; um
        // clique nele troca o dia único em exibição em vez de abrir o modo
        // de intervalo (que só é aberto explicitamente pelo atalho abaixo).
        let modoAtualCustomizado = false;

        function renderizarGrade(calEl, mesRef) {
            calEl.querySelector('.periodo-nav-titulo').textContent = MESES[mesRef.getMonth()] + ' ' + mesRef.getFullYear();

            const primeiroDiaSemana = new Date(mesRef.getFullYear(), mesRef.getMonth(), 1).getDay();
            const inicioGrade = new Date(mesRef.getFullYear(), mesRef.getMonth(), 1 - primeiroDiaSemana);

            const tbody = calEl.querySelector('tbody');
            tbody.innerHTML = '';

            for (let semana = 0; semana < 6; semana++) {
                const tr = document.createElement('tr');
                for (let dia = 0; dia < 7; dia++) {
                    const data = new Date(inicioGrade);
                    data.setDate(inicioGrade.getDate() + semana * 7 + dia);

                    const td = document.createElement('td');
                    const span = document.createElement('span');
                    span.className = 'periodo-dia';
                    span.textContent = data.getDate();

                    if (data.getMonth() !== mesRef.getMonth()) {
                        span.classList.add('outro-mes');
                    }
                    if (mesmoDia(data, estado.inicio) || mesmoDia(data, estado.fim)) {
                        span.classList.add('selecionado');
                    } else if (estado.inicio && estado.fim && data > estado.inicio && data < estado.fim) {
                        span.classList.add('no-intervalo');
                    }

                    span.addEventListener('click', function () {
                        if (!modoAtualCustomizado) {
                            // Só um dia em exibição: o clique troca esse dia
                            // (início e fim iguais), sem abrir o período
                            // customizado sozinho.
                            estado.inicio = data;
                            estado.fim = data;
                            estado.mesInicio = inicioDoMes(data);
                            estado.presetSelecionado = null;
                        } else if (calEl === calInicio) {
                            estado.inicio = data;
                            estado.mesInicio = inicioDoMes(data);
                        } else {
                            estado.fim = data;
                            estado.mesFim = inicioDoMes(data);
                        }
                        renderizarTudo();
                    });

                    td.appendChild(span);
                    tr.appendChild(td);
                }
                tbody.appendChild(tr);
            }
        }

        // O modo customizado (dois calendários) só abre com um clique
        // explícito no atalho "Período customizado" — ou, ao reabrir o
        // filtro, quando o período salvo já é um intervalo de dias que não
        // corresponde a nenhum atalho (não dá pra representar isso em um
        // único calendário). Clicar em qualquer outro atalho, ou em um dia
        // do calendário único, mantém tudo no modo simples.
        function renderizarTudo() {
            const presetDetectado = presetQueCorresponde(estado.inicio, estado.fim);
            const presetParaExibir = estado.presetSelecionado !== null ? estado.presetSelecionado : presetDetectado;
            const intervaloCustomNaoRepresentavel = !presetDetectado && estado.inicio && estado.fim && !mesmoDia(estado.inicio, estado.fim);
            const modoCustomizado = estado.customizadoForcado || intervaloCustomNaoRepresentavel;
            modoAtualCustomizado = modoCustomizado;

            container.querySelectorAll('[data-preset]').forEach(function (botao) {
                const ativo = modoCustomizado
                    ? botao.dataset.preset === 'customizado'
                    : botao.dataset.preset === presetParaExibir;
                botao.classList.toggle('ativo', ativo);
            });

            painel.classList.toggle('modo-simples', !modoCustomizado);
            calFim.style.display = modoCustomizado ? '' : 'none';
            calInicio.querySelector('.periodo-titulo-lado').textContent = modoCustomizado
                ? 'Início do período'
                : (NOMES_PRESET[presetParaExibir] || 'Dia selecionado');

            renderizarGrade(calInicio, estado.mesInicio);
            if (modoCustomizado) {
                renderizarGrade(calFim, estado.mesFim);
            }
        }

        calInicio.querySelector('.periodo-nav-prev').addEventListener('click', function () {
            estado.mesInicio = new Date(estado.mesInicio.getFullYear(), estado.mesInicio.getMonth() - 1, 1);
            renderizarTudo();
        });
        calInicio.querySelector('.periodo-nav-next').addEventListener('click', function () {
            estado.mesInicio = new Date(estado.mesInicio.getFullYear(), estado.mesInicio.getMonth() + 1, 1);
            renderizarTudo();
        });
        calFim.querySelector('.periodo-nav-prev').addEventListener('click', function () {
            estado.mesFim = new Date(estado.mesFim.getFullYear(), estado.mesFim.getMonth() - 1, 1);
            renderizarTudo();
        });
        calFim.querySelector('.periodo-nav-next').addEventListener('click', function () {
            estado.mesFim = new Date(estado.mesFim.getFullYear(), estado.mesFim.getMonth() + 1, 1);
            renderizarTudo();
        });

        container.querySelectorAll('[data-preset]').forEach(function (botao) {
            botao.addEventListener('click', function () {
                if (botao.dataset.preset === 'customizado') {
                    estado.customizadoForcado = true;
                    estado.presetSelecionado = null;
                    renderizarTudo();
                    return;
                }
                const { inicio, fim } = calcularPreset(botao.dataset.preset);
                estado.customizadoForcado = false;
                estado.presetSelecionado = botao.dataset.preset;
                estado.inicio = inicio;
                estado.fim = fim;
                estado.mesInicio = inicioDoMes(inicio || new Date());
                estado.mesFim = inicioDoMes(fim || new Date());
                renderizarTudo();
            });
        });

        const btnFiltrar = container.querySelector('.periodo-filtrar');
        const form = container.closest('form');
        btnFiltrar.addEventListener('click', function () {
            container.querySelector('.periodo-hidden-inicio').value = paraISO(estado.inicio);
            container.querySelector('.periodo-hidden-fim').value = paraISO(estado.fim);
            form.submit();
        });

        renderizarTudo();
    }

    document.querySelectorAll('.filtro-periodo').forEach(iniciarWidget);
})();
