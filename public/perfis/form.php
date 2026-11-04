<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAdmin();

$pdo = getDB();
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$perfil = ['nome' => '', 'administrador' => 0];
$marcadas = [];
if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM perfis WHERE id = ?');
    $stmt->execute([$id]);
    $perfil = $stmt->fetch();
    if (!$perfil) {
        flash('danger', 'Perfil não encontrado.');
        redirect('/perfis/listar.php');
    }
    $stmt = $pdo->prepare('SELECT permissao_id FROM perfil_permissoes WHERE perfil_id = ?');
    $stmt->execute([$id]);
    $marcadas = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}
$permissoes = $pdo->query('SELECT * FROM permissoes ORDER BY ordem, id')->fetchAll();
$grupos = [];
foreach ($permissoes as $perm) {
    $grupos[$perm['grupo']][] = $perm;
}

$erros = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = trim(input('nome'));
    // O perfil principal (1) é sempre administrador: garante que alguém administra.
    $admin = $id === 1 ? 1 : (isset($_POST['administrador']) ? 1 : 0);
    $novas = array_values(array_intersect(array_map('intval', (array) ($_POST['permissoes'] ?? [])), array_map('intval', array_column($permissoes, 'id'))));
    if ($nome === '') {
        $erros[] = 'Informe o nome do perfil.';
    }
    if (!$erros) {
        $pdo->beginTransaction();
        if ($id) {
            $pdo->prepare('UPDATE perfis SET nome = ?, administrador = ? WHERE id = ?')->execute([$nome, $admin, $id]);
        } else {
            $pdo->prepare('INSERT INTO perfis (nome, administrador) VALUES (?, ?)')->execute([$nome, $admin]);
            $id = (int) $pdo->lastInsertId();
        }
        $pdo->prepare('DELETE FROM perfil_permissoes WHERE perfil_id = ?')->execute([$id]);
        $ins = $pdo->prepare('INSERT INTO perfil_permissoes (perfil_id, permissao_id) VALUES (?, ?)');
        foreach ($novas as $pid) {
            $ins->execute([$id, $pid]);
        }
        // Quem está logado com este perfil passa a ver as mudanças no próximo login.
        $pdo->commit();

        $rotulo = array_column($permissoes, 'descricao', 'id');
        $ganhou = array_map(fn ($p) => $rotulo[$p], array_diff($novas, $marcadas));
        $perdeu = array_map(fn ($p) => $rotulo[$p], array_diff($marcadas, $novas));
        registrarAtividade('perfis', $perfil['nome'] === '' ? 'criacao' : 'edicao', 'Perfil ' . $nome
            . ($perfil['nome'] !== '' && $perfil['nome'] !== $nome ? ' (antes "' . $perfil['nome'] . '")' : '')
            . ((int) $perfil['administrador'] !== $admin ? ' · administrador: ' . ($admin ? 'sim' : 'não') : '')
            . ($ganhou ? ' · liberou: ' . implode(', ', $ganhou) : '') . ($perdeu ? ' · tirou: ' . implode(', ', $perdeu) : ''));
        flash('success', 'Perfil salvo. Quem usa este perfil vê as mudanças no próximo login.');
        redirect('/perfis/listar.php');
    }
    $perfil = ['nome' => $nome, 'administrador' => $admin];
    $marcadas = $novas;
}

$pageTitle = $id ? 'Editar perfil' : 'Novo perfil';
$pageBreadcrumb = 'Administração · Perfis de acesso';
$activeMenu = 'perfis';
$pageAction = '<a href="/perfis/listar.php" class="btn btn-outline-secondary">Cancelar</a>'
    . '<button type="submit" form="form-perfil" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>';
require __DIR__ . '/../../includes/layout_header.php';
?>

<?php if ($erros): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" id="form-perfil">
    <div class="secao" style="max-width: 800px;">
        <div class="secao-titulo"><i class="bi bi-person-badge"></i> Perfil</div>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Nome</label><input type="text" name="nome" class="form-control" required maxlength="100" placeholder="Ex.: Financeiro, Operador" value="<?= e($perfil['nome']) ?>"></div>
            <div class="col-md-6 d-flex align-items-end">
                <label class="form-check mb-2"><input type="checkbox" class="form-check-input" name="administrador" id="perfil-admin" <?= $perfil['administrador'] ? 'checked' : '' ?> <?= $id === 1 ? 'disabled' : '' ?>>
                    Administrador (acesso a tudo)<?= $id === 1 ? ' <span class="text-muted small">— perfil principal, sempre administrador</span>' : '' ?></label>
            </div>
        </div>
    </div>

    <div class="secao" style="max-width: 800px;" id="bloco-permissoes">
        <div class="secao-titulo"><i class="bi bi-ui-checks"></i> O que este perfil pode fazer</div>
        <div class="form-text mb-3">Usuários, Perfis, Configurações e a integração com o banco são só do administrador.</div>
        <?php foreach ($grupos as $grupo => $itens): ?>
            <div class="mb-3">
                <div class="fw-semibold small text-uppercase text-muted mb-1"><?= e($grupo) ?></div>
                <?php foreach ($itens as $perm): ?>
                    <label class="form-check"><input type="checkbox" class="form-check-input" name="permissoes[]" value="<?= (int) $perm['id'] ?>" <?= in_array((int) $perm['id'], $marcadas, true) ? 'checked' : '' ?>> <?= e($perm['descricao']) ?></label>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>
</form>
<script>
// Administrador já tem tudo: as permissões ficam apagadas.
(function () {
    const admin = document.getElementById('perfil-admin');
    const bloco = document.getElementById('bloco-permissoes');
    const aplicar = () => { bloco.classList.toggle('opacity-50', admin.checked); bloco.querySelectorAll('input').forEach((c) => { c.disabled = admin.checked; }); };
    admin.addEventListener('change', aplicar);
    aplicar();
})();
</script>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
