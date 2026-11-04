<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAdmin();

$pdo = getDB();
$config = buscarConfiguracoes($pdo);

$campos = [
    'empresa_razao_social' => 'Razão social',
    'empresa_nome_fantasia' => 'Nome fantasia',
    'empresa_cnpj' => 'CNPJ',
    'empresa_email' => 'E-mail',
    'empresa_telefone' => 'Telefone',
    'empresa_cep' => 'CEP',
    'empresa_logradouro' => 'Logradouro',
    'empresa_numero' => 'Número',
    'empresa_complemento' => 'Complemento',
    'empresa_bairro' => 'Bairro',
    'empresa_cidade' => 'Cidade',
    'empresa_uf' => 'UF',
];

$erros = [];
$dados = [];
foreach ($campos as $campo => $rotulo) {
    $dados[$campo] = $config[$campo] ?? '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($campos as $campo => $rotulo) {
        $dados[$campo] = input($campo);
    }
    $dados['empresa_uf'] = strtoupper($dados['empresa_uf']);

    if ($dados['empresa_cnpj'] !== '' && !validarCnpj($dados['empresa_cnpj'])) {
        $erros[] = 'O CNPJ informado é inválido.';
    }
    if ($dados['empresa_uf'] !== '' && !preg_match('/^[A-Z]{2}$/', $dados['empresa_uf'])) {
        $erros[] = 'A UF deve ter 2 letras.';
    }
    if ($dados['empresa_cep'] !== '' && strlen(apenasDigitos($dados['empresa_cep'])) !== 8) {
        $erros[] = 'O CEP deve ter 8 dígitos.';
    }

    if (!$erros) {
        $sets = implode(', ', array_map(fn ($c) => "{$c} = ?", array_keys($campos)));
        $stmt = $pdo->prepare("UPDATE configuracoes SET {$sets} WHERE id = 1");
        $stmt->execute(array_map(fn ($v) => $v !== '' ? $v : null, array_values($dados)));

        $antes = [];
        foreach ($campos as $campo => $rotulo) {
            $antes[$campo] = $config[$campo] ?? '';
        }
        registrarAtividade('configuracoes', 'edicao', 'Dados da empresa: ' . (descreverAlteracoes($antes, $dados, $campos) ?: 'sem alterações'));

        flash('success', 'Dados da empresa salvos.');
        redirect('/configuracoes/empresa.php');
    }
}

$pageTitle = 'Configurações';
$pageBreadcrumb = 'Administração';
$activeMenu = 'configuracoes';
$pageAction = '<button type="submit" form="form-empresa" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>';
require __DIR__ . '/../../includes/layout_header.php';
$abaAtiva = 'empresa';
require __DIR__ . '/../../includes/abas_configuracoes.php';
?>

<?php if ($erros): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<p class="text-muted">Dados da empresa. Aparecem no cabeçalho dos relatórios impressos e precisam bater com o cadastro da empresa no banco (beneficiário dos boletos).</p>

<form method="post" id="form-empresa">
<div class="secao" style="max-width: 900px;">
    <div class="secao-titulo"><i class="bi bi-building"></i> Identificação</div>
    <div class="row g-3">
        <div class="col-md-8">
            <label class="form-label">Razão social</label>
            <input type="text" name="empresa_razao_social" class="form-control" maxlength="150" value="<?= e($dados['empresa_razao_social']) ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label">CNPJ</label>
            <div class="input-group">
                <input type="text" name="empresa_cnpj" id="empresa-cnpj" class="form-control" inputmode="numeric" value="<?= e($dados['empresa_cnpj']) ?>">
                <button type="button" class="btn btn-outline-secondary" id="buscar-cnpj" title="Buscar os dados na Receita e preencher"><i class="bi bi-search"></i> Buscar</button>
            </div>
            <div class="form-text" id="cnpj-status"></div>
        </div>
        <div class="col-md-12">
            <label class="form-label">Nome fantasia</label>
            <input type="text" name="empresa_nome_fantasia" class="form-control" maxlength="150" value="<?= e($dados['empresa_nome_fantasia']) ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label">E-mail</label>
            <input type="email" name="empresa_email" class="form-control" value="<?= e($dados['empresa_email']) ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label">Telefone</label>
            <input type="text" name="empresa_telefone" class="form-control" value="<?= e($dados['empresa_telefone']) ?>">
        </div>
    </div>
</div>

<div class="secao" style="max-width: 900px;">
    <div class="secao-titulo"><i class="bi bi-geo-alt"></i> Endereço</div>
    <div class="row g-3">
        <div class="col-md-3">
            <label class="form-label">CEP</label>
            <input type="text" name="empresa_cep" class="form-control" value="<?= e($dados['empresa_cep']) ?>">
        </div>
        <div class="col-md-7">
            <label class="form-label">Logradouro</label>
            <input type="text" name="empresa_logradouro" class="form-control" value="<?= e($dados['empresa_logradouro']) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label">Número</label>
            <input type="text" name="empresa_numero" class="form-control" value="<?= e($dados['empresa_numero']) ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label">Complemento</label>
            <input type="text" name="empresa_complemento" class="form-control" value="<?= e($dados['empresa_complemento']) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label">Bairro</label>
            <input type="text" name="empresa_bairro" class="form-control" value="<?= e($dados['empresa_bairro']) ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label">Cidade</label>
            <input type="text" name="empresa_cidade" class="form-control" value="<?= e($dados['empresa_cidade']) ?>">
        </div>
        <div class="col-md-1">
            <label class="form-label">UF</label>
            <input type="text" name="empresa_uf" class="form-control text-uppercase" maxlength="2" value="<?= e($dados['empresa_uf']) ?>">
        </div>
    </div>
</div>
</form>

<script>
// Busca de CNPJ (BrasilAPI, dados públicos da Receita): preenche e sobrescreve.
document.getElementById('buscar-cnpj').addEventListener('click', function () {
    const c = document.getElementById('empresa-cnpj').value.replace(/\D/g, '');
    const status = document.getElementById('cnpj-status');
    if (c.length !== 14) { status.className = 'form-text text-danger'; status.textContent = 'Digite os 14 dígitos do CNPJ.'; return; }
    const titulo = (t) => String(t || '').toLowerCase().replace(/(^|\s)(\S)/g, (m, e, l) => e + l.toUpperCase()).replace(/ (De|Da|Do|Dos|Das|E) /g, (m) => m.toLowerCase());
    status.className = 'form-text'; status.textContent = 'Buscando na Receita...';
    fetch('https://brasilapi.com.br/api/cnpj/v1/' + c)
        .then((r) => { if (r.status === 404) throw new Error('nao'); if (!r.ok) throw new Error('erro'); return r.json(); })
        .then((d) => {
            const por = (nome, valor) => { const campo = document.querySelector('[name="' + nome + '"]'); if (campo && valor) campo.value = valor; };
            por('empresa_razao_social', d.razao_social);
            por('empresa_nome_fantasia', titulo(d.nome_fantasia));
            const cep = String(d.cep || '').replace(/\D/g, '');
            if (cep.length === 8) por('empresa_cep', cep.slice(0, 5) + '-' + cep.slice(5));
            por('empresa_logradouro', titulo([d.descricao_tipo_de_logradouro, d.logradouro].filter(Boolean).join(' ')));
            por('empresa_numero', d.numero === 'SN' ? 'S/N' : d.numero);
            por('empresa_complemento', titulo(d.complemento));
            por('empresa_bairro', titulo(d.bairro));
            por('empresa_cidade', titulo(d.municipio));
            por('empresa_uf', d.uf);
            por('empresa_telefone', d.ddd_telefone_1);
            por('empresa_email', d.email ? d.email.toLowerCase() : '');
            status.className = 'form-text valor-positivo'; status.textContent = 'Receita: ' + (d.descricao_situacao_cadastral || 'situação não informada') + ' · dados preenchidos (confira e salve)';
        })
        .catch((e) => { status.className = 'form-text text-danger'; status.textContent = e.message === 'nao' ? 'CNPJ não encontrado na Receita.' : 'Não foi possível consultar agora; preencha à mão.'; });
});
</script>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
