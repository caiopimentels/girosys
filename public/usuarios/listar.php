<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAdmin();

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['excluir'])) {
    $alvo = (int) $_POST['excluir'];
    $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE id = ?');
    $stmt->execute([$alvo]);
    $u = $stmt->fetch();
    if (!$u) {
        flash('danger', 'Usuário não encontrado.');
    } elseif ($alvo === usuarioAtualId()) {
        flash('danger', 'Você não pode excluir o seu próprio usuário.');
    } else {
        // Quem já lançou algo fica (as operações apontam para ele): desativa.
        $usado = (int) $pdo->query('SELECT COUNT(*) FROM operacoes WHERE usuario_id = ' . $alvo)->fetchColumn();
        if ($usado) {
            $pdo->prepare('UPDATE usuarios SET ativo = 0, token_sessao = NULL WHERE id = ?')->execute([$alvo]);
            registrarAtividade('usuarios', 'edicao', 'Usuário desativado (tem lançamentos): ' . $u['nome'] . ' (' . $u['email'] . ')');
            flash('success', $u['nome'] . ' tem operações lançadas: foi desativado em vez de excluído.');
        } else {
            $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$alvo]);
            registrarAtividade('usuarios', 'exclusao', 'Usuário excluído: ' . $u['nome'] . ' (' . $u['email'] . ')');
            flash('success', 'Usuário ' . $u['nome'] . ' excluído.');
        }
    }
    redirect('/usuarios/listar.php');
}

$usuarios = $pdo->query(
    "SELECT u.*, p.nome AS perfil_nome, p.administrador,
        (SELECT MAX(criado_em) FROM log_atividades l WHERE l.usuario_id = u.id AND l.operacao = 'login') AS ultimo_login
     FROM usuarios u JOIN perfis p ON p.id = u.perfil_id ORDER BY u.ativo DESC, u.nome"
)->fetchAll();

$pageTitle = 'Usuários';
$pageBreadcrumb = 'Administração';
$activeMenu = 'usuarios';
$pageAction = '<a href="/usuarios/form.php" class="btn btn-brand"><i class="bi bi-plus-lg"></i> Novo usuário</a>';
require __DIR__ . '/../../includes/layout_header.php';
?>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Último login</th><th>Situação</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($usuarios as $u): ?>
                <tr class="<?= $u['ativo'] ? '' : 'opacity-50' ?>">
                    <td class="fw-semibold"><?= e($u['nome']) ?><?= (int) $u['id'] === usuarioAtualId() ? ' <span class="badge text-bg-light border">você</span>' : '' ?></td>
                    <td><?= e($u['email']) ?></td>
                    <td><?= e($u['perfil_nome']) ?><?= $u['administrador'] ? ' <i class="bi bi-shield-check text-success" title="Administrador: acesso a tudo"></i>' : '' ?></td>
                    <td class="small text-num"><?= $u['ultimo_login'] ? formatDateTime($u['ultimo_login']) : '-' ?></td>
                    <td><span class="badge <?= $u['ativo'] ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $u['ativo'] ? 'Ativo' : 'Inativo' ?></span></td>
                    <td class="text-end text-nowrap">
                        <a href="/usuarios/form.php?id=<?= (int) $u['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Editar"><i class="bi bi-pencil"></i></a>
                        <a href="/configuracoes/auditoria.php?usuario_id=<?= (int) $u['id'] ?>" class="btn btn-sm btn-outline-secondary" title="O que este usuário fez"><i class="bi bi-clock-history"></i></a>
                        <?php if ((int) $u['id'] !== usuarioAtualId()): ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('Excluir o usuário <?= e($u['nome']) ?>?');"><button name="excluir" value="<?= (int) $u['id'] ?>" class="btn btn-sm btn-outline-danger" title="Excluir"><i class="bi bi-trash"></i></button></form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
