<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAdmin();

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['excluir'])) {
    $alvo = (int) $_POST['excluir'];
    $stmt = $pdo->prepare('SELECT p.*, (SELECT COUNT(*) FROM usuarios u WHERE u.perfil_id = p.id) AS usuarios FROM perfis p WHERE p.id = ?');
    $stmt->execute([$alvo]);
    $p = $stmt->fetch();
    if (!$p) {
        flash('danger', 'Perfil não encontrado.');
    } elseif ($p['usuarios'] > 0 || $alvo === 1) {
        flash('danger', 'Este perfil tem usuários (ou é o Administrador principal): troque o perfil deles antes de excluir.');
    } else {
        $pdo->prepare('DELETE FROM perfis WHERE id = ?')->execute([$alvo]);
        registrarAtividade('perfis', 'exclusao', 'Perfil excluído: ' . $p['nome']);
        flash('success', 'Perfil ' . $p['nome'] . ' excluído.');
    }
    redirect('/perfis/listar.php');
}

$perfis = $pdo->query(
    'SELECT p.*, (SELECT COUNT(*) FROM usuarios u WHERE u.perfil_id = p.id) AS usuarios,
        (SELECT COUNT(*) FROM perfil_permissoes pp WHERE pp.perfil_id = p.id) AS permissoes
     FROM perfis p ORDER BY p.administrador DESC, p.nome'
)->fetchAll();
$totalPermissoes = (int) $pdo->query('SELECT COUNT(*) FROM permissoes')->fetchColumn();

$pageTitle = 'Perfis de acesso';
$pageBreadcrumb = 'Administração';
$activeMenu = 'perfis';
$pageAction = '<a href="/perfis/form.php" class="btn btn-brand"><i class="bi bi-plus-lg"></i> Novo perfil</a>';
require __DIR__ . '/../../includes/layout_header.php';
?>

<p class="text-muted" style="max-width: 900px;">O perfil diz o que o usuário vê e faz no sistema. <strong>Administrador</strong> tem acesso a tudo, inclusive Usuários, Perfis, Configurações e a integração com o banco.</p>

<div class="card" style="max-width: 900px;">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><th>Perfil</th><th>Acesso</th><th class="text-center">Usuários</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($perfis as $p): ?>
                <tr>
                    <td class="fw-semibold"><?= e($p['nome']) ?></td>
                    <td><?= $p['administrador'] ? '<span class="badge text-bg-success">Administrador · tudo</span>' : (int) $p['permissoes'] . ' de ' . $totalPermissoes . ' permissões' ?></td>
                    <td class="text-center"><?= (int) $p['usuarios'] ?></td>
                    <td class="text-end text-nowrap">
                        <a href="/perfis/form.php?id=<?= (int) $p['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Editar"><i class="bi bi-pencil"></i></a>
                        <?php if (!$p['usuarios'] && (int) $p['id'] !== 1): ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('Excluir o perfil <?= e($p['nome']) ?>?');"><button name="excluir" value="<?= (int) $p['id'] ?>" class="btn btn-sm btn-outline-danger" title="Excluir"><i class="bi bi-trash"></i></button></form>
                        <?php else: ?>
                            <span class="d-inline-block" tabindex="0" title="<?= (int) $p['id'] === 1 ? 'Perfil principal: não pode ser excluído' : 'Tem usuários: troque o perfil deles antes' ?>"><button type="button" class="btn btn-sm btn-outline-danger" disabled><i class="bi bi-trash"></i></button></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
