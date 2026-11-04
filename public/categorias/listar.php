<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    if ($acao === 'salvar') {
        $nome = input('nome');
        $tipo = input('tipo') === 'receita' ? 'receita' : 'despesa';
        $id = idOuNull($_POST['id'] ?? null);
        if ($nome !== '') {
            if ($id) {
                $antes = $pdo->prepare('SELECT nome, tipo FROM categorias WHERE id = ?');
                $antes->execute([$id]);
                $antes = $antes->fetch() ?: [];
                $pdo->prepare('UPDATE categorias SET nome = ?, tipo = ? WHERE id = ?')->execute([$nome, $tipo, $id]);
                registrarAtividade('categorias', 'edicao', 'Categoria ' . $nome . ': ' . descreverAlteracoes($antes, ['nome' => $nome, 'tipo' => $tipo], ['nome' => 'Nome', 'tipo' => 'Tipo']));
            } else {
                $pdo->prepare('INSERT INTO categorias (nome, tipo) VALUES (?, ?)')->execute([$nome, $tipo]);
                registrarAtividade('categorias', 'criacao', 'Categoria criada: ' . $nome . ' (' . $tipo . ')');
            }
            flash('success', 'Categoria salva.');
        }
    } elseif ($acao === 'alternar') {
        $pdo->prepare('UPDATE categorias SET ativo = 1 - ativo WHERE id = ?')->execute([(int) $_POST['id']]);
        $cat = $pdo->prepare('SELECT nome, ativo FROM categorias WHERE id = ?');
        $cat->execute([(int) $_POST['id']]);
        if ($cat = $cat->fetch()) {
            registrarAtividade('categorias', 'edicao', 'Categoria ' . $cat['nome'] . ($cat['ativo'] ? ' ativada' : ' desativada'));
        }
    }
    redirect('/categorias/listar.php');
}

$categorias = $pdo->query(
    "SELECT c.*,
        (SELECT COUNT(*) FROM contas_pagar WHERE categoria_id = c.id) + (SELECT COUNT(*) FROM contas_receber WHERE categoria_id = c.id) + (SELECT COUNT(*) FROM cartao_compras WHERE categoria_id = c.id) AS usos
     FROM categorias c ORDER BY c.tipo, c.nome"
)->fetchAll();

$pageTitle = 'Categorias';
$pageBreadcrumb = 'Cadastros auxiliares';
$activeMenu = 'categorias';
require __DIR__ . '/../../includes/layout_header.php';
$abaAtiva = 'auxiliares';
require __DIR__ . '/../../includes/abas_configuracoes.php';
$abaAuxiliar = 'categorias';
require __DIR__ . '/../../includes/abas_cadastros_auxiliares.php';
?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card">
            <table class="table table-hover mb-0 align-middle">
                <thead><tr><th>Nome</th><th>Tipo</th><th class="text-center">Lançamentos</th><th>Situação</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($categorias as $c): ?>
                    <tr>
                        <td><?= e($c['nome']) ?></td>
                        <td><?= $c['tipo'] === 'receita' ? '<span class="badge text-bg-success">Receita</span>' : '<span class="badge text-bg-light">Despesa</span>' ?></td>
                        <td class="text-center"><?= (int) $c['usos'] ?></td>
                        <td><?= $c['ativo'] ? 'Ativa' : '<span class="text-muted">Inativa</span>' ?></td>
                        <td class="text-end text-nowrap">
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick='editar(<?= json_encode(['id' => (int) $c['id'], 'nome' => $c['nome'], 'tipo' => $c['tipo']], JSON_HEX_APOS | JSON_HEX_TAG) ?>)'><i class="bi bi-pencil"></i></button>
                            <form method="post" class="d-inline"><input type="hidden" name="acao" value="alternar"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="btn btn-sm btn-outline-secondary" title="Ativar/desativar"><i class="bi <?= $c['ativo'] ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i></button></form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card"><div class="card-body">
            <div class="secao-titulo mb-3" id="titulo-form"><i class="bi bi-plus-lg"></i> Nova categoria</div>
            <form method="post" id="form-categoria">
                <input type="hidden" name="acao" value="salvar"><input type="hidden" name="id" id="cat-id">
                <div class="mb-2"><label class="form-label">Nome</label><input type="text" name="nome" id="cat-nome" class="form-control" required maxlength="80"></div>
                <div class="mb-3"><label class="form-label">Tipo</label><select name="tipo" id="cat-tipo" class="form-select"><option value="despesa">Despesa</option><option value="receita">Receita</option></select></div>
                <button class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>
                <a href="/categorias/listar.php" class="btn btn-outline-secondary">Limpar</a>
            </form>
        </div></div>
    </div>
</div>
<script>
function editar(c) {
    document.getElementById('cat-id').value = c.id;
    document.getElementById('cat-nome').value = c.nome;
    document.getElementById('cat-tipo').value = c.tipo;
    document.getElementById('titulo-form').innerHTML = '<i class="bi bi-pencil"></i> Editar categoria';
    document.getElementById('cat-nome').focus();
}
</script>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
