<?php
require_once __DIR__ . '/../includes/bootstrap.php';
exigirLogin();
// Sem o Início liberado: vai para a primeira tela que o perfil pode ver.
if (!usuarioTemPermissao('ver_dashboard')) {
    foreach (['operacoes' => '/operacoes/listar.php', 'receber' => '/receber/listar.php', 'pagar' => '/pagar/listar.php', 'contas' => '/contas/listar.php',
        'repasses' => '/adiantamentos/listar.php', 'cartoes' => '/cartoes/listar.php', 'pessoas' => '/pessoas/listar.php?papel=cliente', 'movimentacao' => '/financeiro/movimentacao.php'] as $permissao => $url) {
        if (usuarioTemPermissao($permissao)) {
            redirect($url);
        }
    }
    redirect('/sem_acesso.php');
}

$pdo = getDB();
$hoje = date('Y-m-d');
$em7 = date('Y-m-d', strtotime('+7 days'));
$em30 = date('Y-m-d', strtotime('+30 days'));

$contas = contasComSaldo($pdo);
$saldoGlobal = array_sum(array_map(fn ($c) => (float) $c['saldo'], $contas));

$receber = $pdo->query(
    "SELECT
        COALESCE(SUM(CASE WHEN status = 'pendente' THEN valor END), 0) AS pendente,
        COALESCE(SUM(CASE WHEN status = 'pendente' AND data_vencimento < CURDATE() THEN valor END), 0) AS vencido,
        COALESCE(SUM(CASE WHEN status = 'devolvido' THEN valor END), 0) AS devolvido,
        COALESCE(SUM(CASE WHEN status = 'pendente' AND data_vencimento BETWEEN CURDATE() AND CURDATE() + INTERVAL 30 DAY THEN valor END), 0) AS prox30
     FROM contas_receber"
)->fetch();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(valor - COALESCE(valor_pago, 0)), 0) FROM contas_pagar WHERE status = 'pendente' AND data_vencimento <= ?");
$stmt->execute([$em30]);
$pagar30 = (float) $stmt->fetchColumn();
$stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(falta), 0) FROM (SELECT SUM(p.valor) - COALESCE(f.valor_pago, 0) AS falta FROM cartao_faturas f JOIN cartao_parcelas p ON p.fatura_id = f.id
     WHERE f.status = 'aberta' AND f.data_vencimento <= ? GROUP BY f.id) x"
);
$stmt->execute([$em30]);
$faturas30 = (float) $stmt->fetchColumn();

$adiantadoAberto = totalAdiantadoEmAberto($pdo);

// Patrimônio: o que tenho em conta + o que me devem (repasses e títulos) − o que devo (despesas e faturas em aberto).
$pagarAberto = (float) $pdo->query("SELECT COALESCE(SUM(valor - COALESCE(valor_pago, 0)), 0) FROM contas_pagar WHERE status = 'pendente'")->fetchColumn();
$faturasAberto = (float) $pdo->query(
    "SELECT COALESCE(SUM(falta), 0) FROM (SELECT SUM(p.valor) - COALESCE(f.valor_pago, 0) AS falta FROM cartao_faturas f JOIN cartao_parcelas p ON p.fatura_id = f.id
     WHERE f.status = 'aberta' GROUP BY f.id) x"
)->fetchColumn();
$receberAberto = (float) $receber['pendente'] + (float) $receber['devolvido'];
$patrimonio = round($saldoGlobal + $adiantadoAberto + $receberAberto - $pagarAberto - $faturasAberto, 2);
$lucroMes = (float) $pdo->query(
    "SELECT COALESCE(SUM(total_juros + juros_emprestimo), 0) FROM operacoes WHERE data_base BETWEEN DATE_FORMAT(CURDATE(), '%Y-%m-01') AND LAST_DAY(CURDATE())"
)->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT r.*, e.nome AS emitente_nome, c.nome AS conta_nome FROM contas_receber r
     LEFT JOIN pessoas e ON e.id = r.emitente_id LEFT JOIN contas_bancarias c ON c.id = r.conta_destino_id
     WHERE (r.status = 'pendente' AND r.data_vencimento <= ?) OR r.status = 'devolvido'
     ORDER BY r.status = 'devolvido' DESC, r.data_vencimento LIMIT 12"
);
$stmt->execute([$em7]);
$proxReceber = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT 'conta' AS origem, cp.id, cp.descricao, cp.data_vencimento, cp.valor - COALESCE(cp.valor_pago, 0) AS valor FROM contas_pagar cp WHERE cp.status = 'pendente' AND cp.data_vencimento <= ?
     UNION ALL
     SELECT 'fatura', f.id, CONCAT('Fatura ', c.nome), f.data_vencimento, SUM(p.valor) - COALESCE(f.valor_pago, 0) FROM cartao_faturas f JOIN cartoes c ON c.id = f.cartao_id JOIN cartao_parcelas p ON p.fatura_id = f.id
     WHERE f.status = 'aberta' AND f.data_vencimento <= ? GROUP BY f.id
     ORDER BY data_vencimento LIMIT 12"
);
$stmt->execute([$em7, $em7]);
$proxPagar = $stmt->fetchAll();

$compromissos = compromissosCartao($pdo, 6);

// Crescimento: ganho mês a mês (mesma conta do relatório de rentabilidade) e projeção pela tendência.
$serieGanhos = serieGanhosMensais($pdo, 12);
$projecao = projecaoGanhos($serieGanhos, 6);
$ganho12 = array_sum(array_column($serieGanhos, 'total'));
$ganhoProj = array_sum(array_column($projecao['meses'], 'total'));

$pageTitle = 'Olá, ' . explode(' ', trim(usuarioAtualNome()))[0];
$pageBreadcrumb = 'Início';
$activeMenu = 'dashboard';
require __DIR__ . '/../includes/layout_header.php';
?>

<div class="atalhos">
    <a href="/operacoes/form.php" class="atalho"><i class="bi bi-calculator"></i> Nova operação de desconto</a>
    <a href="/adiantamentos/form.php" class="atalho"><i class="bi bi-cash-coin"></i> Repasse a cliente</a>
    <a href="/pagar/form.php" class="atalho"><i class="bi bi-arrow-up-circle"></i> Lançar despesa</a>
    <a href="/cartoes/compra.php" class="atalho"><i class="bi bi-credit-card"></i> Compra no cartão</a>
    <a href="/contas/transferir.php" class="atalho"><i class="bi bi-arrow-left-right"></i> Transferir</a>
</div>

<div class="secao">
    <div class="secao-titulo"><i class="bi bi-graph-up"></i> Resumo</div>
    <div class="card mb-3 border-success"><div class="card-body d-flex flex-wrap align-items-center gap-4">
        <div>
            <div class="kpi-rotulo">Patrimônio total</div>
            <div class="kpi-valor <?= $patrimonio < 0 ? 'valor-negativo' : 'valor-positivo' ?>" style="font-size: 2rem;"><?= formatMoney($patrimonio) ?></div>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-3 small text-num">
            <div><div class="text-muted">Em conta</div><div class="fw-semibold"><?= formatMoney($saldoGlobal) ?></div></div>
            <div class="text-muted fs-5">+</div>
            <div><div class="text-muted">Repasses a clientes</div><div class="fw-semibold"><?= formatMoney($adiantadoAberto) ?></div></div>
            <div class="text-muted fs-5">+</div>
            <div><div class="text-muted">A receber (títulos)</div><div class="fw-semibold"><?= formatMoney($receberAberto) ?></div></div>
            <div class="text-muted fs-5">−</div>
            <div title="Contas a pagar em aberto (inclui pagamentos agendados a clientes) e faturas de cartão em aberto"><div class="text-muted">Despesas em aberto</div><div class="fw-semibold valor-negativo"><?= formatMoney($pagarAberto + $faturasAberto) ?></div>
                <div class="text-muted" style="font-size: .75rem;">contas <?= formatMoney($pagarAberto) ?> · cartões <?= formatMoney($faturasAberto) ?></div></div>
        </div>
    </div></div>
    <div class="row g-3">
        <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
            <div class="kpi-rotulo">Saldo global</div>
            <div class="kpi-valor <?= $saldoGlobal < 0 ? 'valor-negativo' : '' ?>"><?= formatMoney($saldoGlobal) ?></div>
            <div class="small text-muted"><?= count($contas) ?> conta(s) · <a href="/contas/listar.php">ver contas</a></div>
        </div></div></div>
        <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
            <div class="kpi-rotulo">A receber (títulos)</div>
            <div class="kpi-valor valor-positivo"><?= formatMoney($receber['pendente']) ?></div>
            <div class="small text-muted">Próx. 30 dias: <?= formatMoney($receber['prox30']) ?></div>
        </div></div></div>
        <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
            <div class="kpi-rotulo">Vencidos + devolvidos</div>
            <div class="kpi-valor <?= $receber['vencido'] + $receber['devolvido'] > 0 ? 'valor-negativo' : '' ?>"><?= formatMoney($receber['vencido'] + $receber['devolvido']) ?></div>
            <div class="small text-muted">Devolvidos: <?= formatMoney($receber['devolvido']) ?></div>
        </div></div></div>
        <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
            <div class="kpi-rotulo">A pagar em 30 dias</div>
            <div class="kpi-valor valor-negativo"><?= formatMoney($pagar30 + $faturas30) ?></div>
            <div class="small text-muted">Contas <?= formatMoney($pagar30) ?> · cartões <?= formatMoney($faturas30) ?></div>
        </div></div></div>
        <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
            <div class="kpi-rotulo">Repasses a clientes (em aberto)</div>
            <div class="kpi-valor"><?= formatMoney($adiantadoAberto) ?></div>
            <div class="small text-muted"><a href="/adiantamentos/listar.php">ver por cliente</a></div>
        </div></div></div>
        <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
            <div class="kpi-rotulo">Lucro das operações no mês</div>
            <div class="kpi-valor valor-positivo"><?= formatMoney($lucroMes) ?></div>
            <div class="small text-muted"><a href="/operacoes/listar.php">ver operações</a></div>
        </div></div></div>
        <div class="col-12 col-lg-6"><div class="card h-100"><div class="card-body">
            <div class="kpi-rotulo mb-2">Saldo por conta</div>
            <div class="d-flex flex-wrap gap-3">
                <?php if (!$contas): ?><a href="/contas/form.php">Cadastrar a primeira conta</a><?php endif; ?>
                <?php foreach ($contas as $c): ?>
                    <a href="/contas/extrato.php?conta=<?= (int) $c['id'] ?>" class="text-reset text-decoration-none">
                        <div class="small text-muted"><span class="ponto-status" style="background: <?= e($c['cor']) ?>"></span> <?= e($c['nome']) ?></div>
                        <div class="fw-semibold text-num <?= $c['saldo'] < 0 ? 'valor-negativo' : '' ?>"><?= formatMoney($c['saldo']) ?></div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div></div></div>
    </div>
</div>

<div class="secao">
    <div class="secao-titulo"><i class="bi bi-bar-chart-line"></i> Crescimento: ganho por mês
        <a href="/relatorios/rentabilidade.php" class="btn btn-sm btn-outline-secondary ms-auto">Rentabilidade</a></div>
    <div class="card"><div class="card-body">
        <div class="d-flex flex-wrap gap-4 mb-2 small">
            <div><div class="text-muted">Ganho nos últimos 12 meses</div><div class="fw-semibold fs-5 text-num"><?= formatMoney($ganho12) ?></div></div>
            <div><div class="text-muted">Projeção dos próximos 6 meses</div><div class="fw-semibold fs-5 text-num"><?= formatMoney($ganhoProj) ?></div></div>
            <div><div class="text-muted">Tendência</div><div class="fw-semibold fs-5 text-num"><?= $projecao['base_meses'] >= 3 ? ($projecao['inclinacao'] >= 0 ? '+' : '−') . formatMoney(abs($projecao['inclinacao'])) . ' <span class="small fw-normal text-muted">por mês</span>' : '<span class="small fw-normal text-muted">poucos meses de dados</span>' ?></div></div>
            <div class="ms-auto d-flex align-items-end gap-3 text-muted">
                <span><span class="legenda-barra"></span> Realizado</span>
                <span><span class="legenda-barra" style="opacity: .55"></span> Mês atual (até hoje)</span>
                <span><span class="legenda-barra projetada"></span> Projeção</span>
            </div>
        </div>
        <div id="grafico-crescimento" class="grafico-crescimento" role="img" aria-label="Ganho por mês nos últimos 12 meses e projeção dos próximos 6"></div>
        <details class="small mt-2"><summary class="text-muted">Ver em tabela</summary>
            <table class="table table-sm mt-2 mb-0"><thead><tr><th>Mês</th><th class="text-end">Ganho</th><th class="text-end">Operações</th><th class="text-end">Títulos descontados</th></tr></thead><tbody>
            <?php foreach ($serieGanhos as $m): ?><tr><td><?= e(nomeMes($m['mes'])) ?><?= $m['parcial'] ? ' <span class="text-muted">(até hoje)</span>' : '' ?></td><td class="text-end text-num"><?= formatMoney($m['total']) ?></td><td class="text-end"><?= $m['operacoes'] ?: '-' ?></td><td class="text-end text-num"><?= $m['bruto'] > 0 ? formatMoney($m['bruto']) : '-' ?></td></tr><?php endforeach; ?>
            <?php foreach ($projecao['meses'] as $m): ?><tr class="text-muted"><td><?= e(nomeMes($m['mes'])) ?> (projeção)</td><td class="text-end text-num"><?= formatMoney($m['total']) ?></td><td></td><td></td></tr><?php endforeach; ?>
            </tbody></table></details>
        <div class="form-text mt-1">Ganho = deságio + juros dos repasses + juros/multa por atraso + renegociações + arredondamentos. Projeção pela tendência dos últimos <?= max(1, $projecao['base_meses']) ?> mês(es) fechados (o mês atual ainda está em andamento); é uma estimativa.</div>
    </div></div>
</div>
<style>
    .grafico-crescimento { position: relative; height: 240px; }
    .grafico-crescimento svg { width: 100%; height: 100%; display: block; overflow: visible; }
    .grafico-crescimento .barra, .grafico-crescimento text, .grafico-crescimento line { pointer-events: none; }
    .grafico-crescimento .alvo { cursor: default; outline: none; }
    .grafico-crescimento .barra { fill: var(--accent); }
    .grafico-crescimento .barra.parcial { fill: var(--accent); opacity: .55; }
    .grafico-crescimento .barra.projetada { fill: var(--accent-soft); stroke: var(--accent); stroke-width: 1.5; stroke-dasharray: 4 3; }
    .grafico-crescimento .alvo:hover + .barra, .grafico-crescimento .alvo:focus + .barra { filter: brightness(1.12); }
    .grafico-crescimento .grade { stroke: var(--border); stroke-width: 1; }
    .grafico-crescimento .eixo { fill: var(--text-muted); font-size: 11px; }
    .grafico-crescimento .rotulo { fill: var(--text); font-size: 11px; font-weight: 600; }
    .grafico-crescimento .divisa { stroke: var(--text-muted); stroke-width: 1; stroke-dasharray: 2 3; }
    .grafico-dica { position: absolute; pointer-events: none; background: var(--surface, #fff); border: 1px solid var(--border); border-radius: 8px; padding: .4rem .6rem; font-size: .8rem; box-shadow: 0 4px 14px rgba(0,0,0,.08); white-space: nowrap; transform: translate(-50%, -100%); z-index: 5; }
    .grafico-dica strong { display: block; font-size: .95rem; }
    .legenda-barra { display: inline-block; width: 12px; height: 10px; border-radius: 2px; background: var(--accent); vertical-align: middle; }
    .legenda-barra.projetada { background: var(--accent-soft); border: 1.5px dashed var(--accent); }
</style>
<script>
(function () {
    const real = <?= json_encode(array_map(fn ($m) => ['mes' => $m['mes'], 'nome' => nomeMes($m['mes']), 'valor' => $m['total'], 'ops' => $m['operacoes'], 'parcial' => $m['parcial']], $serieGanhos), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;
    const proj = <?= json_encode(array_map(fn ($m) => ['mes' => $m['mes'], 'nome' => nomeMes($m['mes']), 'valor' => $m['total'], 'projetada' => true], $projecao['meses']), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;
    const dados = [...real, ...proj];
    const box = document.getElementById('grafico-crescimento');
    const reais = (v) => v.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    const curto = (v) => v === 0 ? 'R$ 0' : v >= 1000 ? 'R$ ' + (v / 1000).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + ' mil' : reais(v);
    const mesCurto = (m) => ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'][Number(m.slice(5)) - 1] + '/' + m.slice(2, 4);
    const NS = 'http://www.w3.org/2000/svg';
    const el = (tag, attrs, pai) => { const n = document.createElementNS(NS, tag); Object.entries(attrs).forEach(([k, v]) => n.setAttribute(k, v)); pai.appendChild(n); return n; };

    function desenhar() {
        box.innerHTML = '';
        const W = box.clientWidth, H = box.clientHeight, esq = 64, dir = 8, topo = 18, base = 26;
        const svg = el('svg', { viewBox: `0 0 ${W} ${H}` }, box);
        const max = Math.max(1, ...dados.map((d) => d.valor));
        // Escala "redonda" para a grade.
        const passo = Math.pow(10, Math.floor(Math.log10(max))) * ([1, 2, 2.5, 5, 10].find((f) => f * Math.pow(10, Math.floor(Math.log10(max))) * 4 >= max) || 10);
        const teto = Math.ceil(max / passo) * passo;
        const y = (v) => topo + (H - topo - base) * (1 - v / teto);
        for (let v = 0; v <= teto + 0.001; v += passo) {
            el('line', { class: 'grade', x1: esq, x2: W - dir, y1: y(v), y2: y(v) }, svg);
            el('text', { class: 'eixo', x: esq - 8, y: y(v) + 4, 'text-anchor': 'end' }, svg).textContent = curto(v);
        }
        const larg = (W - esq - dir) / dados.length;
        const bw = Math.max(6, Math.min(36, larg * 0.62));
        const dica = document.createElement('div'); dica.className = 'grafico-dica'; dica.hidden = true; box.appendChild(dica);
        let maiorReal = real.reduce((a, d, i) => (d.valor > real[a].valor ? i : a), 0);
        dados.forEach((d, i) => {
            const cx = esq + larg * i + larg / 2;
            const h = Math.max(d.valor > 0 ? 2 : 0, y(0) - y(d.valor));
            // Alvo transparente da altura toda: o mouse não precisa acertar a barra.
            const alvo = el('rect', { class: 'alvo', x: cx - larg / 2, y: topo, width: larg, height: H - topo - base, fill: 'transparent', tabindex: 0 }, svg);
            el('path', { class: 'barra' + (d.projetada ? ' projetada' : d.parcial ? ' parcial' : ''),
                d: h > 0 ? `M${cx - bw / 2},${y(0)} V${y(0) - h + 4} Q${cx - bw / 2},${y(0) - h} ${cx - bw / 2 + 4},${y(0) - h} H${cx + bw / 2 - 4} Q${cx + bw / 2},${y(0) - h} ${cx + bw / 2},${y(0) - h + 4} V${y(0)} Z` : '' }, svg);
            if (i % (larg < 34 ? 2 : 1) === 0 || i === dados.length - 1) el('text', { class: 'eixo', x: cx, y: H - 8, 'text-anchor': 'middle' }, svg).textContent = mesCurto(d.mes);
            // Rótulo só no maior mês realizado e no último projetado.
            if ((!d.projetada && i === maiorReal && d.valor > 0) || i === dados.length - 1) el('text', { class: 'rotulo', x: cx, y: y(d.valor) - 6, 'text-anchor': 'middle' }, svg).textContent = curto(d.valor);
            const mostrar = () => {
                dica.replaceChildren();
                const v = document.createElement('strong'); v.textContent = reais(d.valor); dica.appendChild(v);
                dica.appendChild(document.createTextNode(d.nome + (d.projetada ? ' · projeção' : d.parcial ? ' · até hoje' : '') + (d.ops ? ' · ' + d.ops + ' operação(ões)' : '')));
                dica.hidden = false; dica.style.left = cx + 'px'; dica.style.top = (y(d.valor) - 8) + 'px';
            };
            alvo.addEventListener('pointerenter', mostrar); alvo.addEventListener('focus', mostrar);
            alvo.addEventListener('pointerleave', () => (dica.hidden = true)); alvo.addEventListener('blur', () => (dica.hidden = true));
        });
        // Separa o realizado da projeção.
        const xs = esq + larg * real.length;
        el('line', { class: 'divisa', x1: xs, x2: xs, y1: topo - 6, y2: y(0) }, svg);
        el('text', { class: 'eixo', x: xs + 6, y: topo - 6 }, svg).textContent = 'projeção';
        el('line', { class: 'grade', x1: esq, x2: W - dir, y1: y(0), y2: y(0), style: 'stroke: var(--text-muted)' }, svg);
    }
    desenhar();
    let t; window.addEventListener('resize', () => { clearTimeout(t); t = setTimeout(desenhar, 150); });
})();
</script>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-arrow-down-circle"></i> A receber (até 7 dias, vencidos e devolvidos)
                <a href="/receber/listar.php" class="btn btn-sm btn-outline-secondary ms-auto">Ver todos</a></div>
            <div class="card"><div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th>Vencimento</th><th>Pagador</th><th>Conta</th><th class="text-end">Valor</th></tr></thead>
                    <tbody>
                    <?php if (!$proxReceber): ?><tr><td colspan="4" class="text-center text-muted py-3">Nada para os próximos dias.</td></tr><?php endif; ?>
                    <?php foreach ($proxReceber as $r): $st = statusTelaReceber($r); ?>
                        <tr class="cursor-pointer" data-detalhe="/receber/baixar.php?id=<?= (int) $r['id'] ?>">
                            <td class="text-num <?= $st !== 'pendente' ? 'valor-negativo' : '' ?>"><?= formatDate($r['data_vencimento']) ?><?= $st === 'devolvido' ? ' ' . badgeStatus('devolvido') : '' ?></td>
                            <td><?= tipoTituloLabel($r['tipo']) ?> · <?= e($r['emitente_nome'] ?? $r['descricao']) ?></td>
                            <td><?= e($r['conta_nome'] ?? '—') ?></td>
                            <td class="text-end text-num"><?= formatMoney($r['valor']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div></div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-arrow-up-circle"></i> A pagar (até 7 dias e vencidas)
                <a href="/pagar/listar.php" class="btn btn-sm btn-outline-secondary ms-auto">Ver todas</a></div>
            <div class="card"><div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th>Vencimento</th><th>Descrição</th><th class="text-end">Valor</th></tr></thead>
                    <tbody>
                    <?php if (!$proxPagar): ?><tr><td colspan="3" class="text-center text-muted py-3">Nada a pagar nos próximos dias.</td></tr><?php endif; ?>
                    <?php foreach ($proxPagar as $p): ?>
                        <tr class="cursor-pointer" data-detalhe="<?= $p['origem'] === 'fatura' ? '/cartoes/fatura.php?id=' : '/pagar/baixar.php?id=' ?><?= (int) $p['id'] ?>">
                            <td class="text-num <?= $p['data_vencimento'] < $hoje ? 'valor-negativo fw-semibold' : '' ?>"><?= formatDate($p['data_vencimento']) ?></td>
                            <td><?= $p['origem'] === 'fatura' ? '<i class="bi bi-credit-card"></i> ' : '' ?><?= e($p['descricao']) ?></td>
                            <td class="text-end text-num"><?= formatMoney($p['valor']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div></div>
        </div>
        <?php if ($compromissos): ?>
        <div class="secao">
            <div class="secao-titulo"><i class="bi bi-credit-card"></i> Cartões: faturas em aberto
                <a href="/cartoes/listar.php" class="btn btn-sm btn-outline-secondary ms-auto">Detalhar</a></div>
            <div class="card"><div class="card-body d-flex flex-wrap gap-4">
                <?php foreach ($compromissos as $mes => $valor): ?>
                    <div><div class="small text-muted <?= $mes < date('Y-m') ? 'valor-negativo' : '' ?>"><?= nomeMes($mes) ?></div><div class="fw-semibold text-num"><?= formatMoney($valor) ?></div></div>
                <?php endforeach; ?>
            </div></div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
