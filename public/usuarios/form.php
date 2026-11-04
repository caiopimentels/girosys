<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAdmin();

$pdo = getDB();
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$usuario = ['nome' => '', 'email' => '', 'perfil_id' => 0, 'ativo' => 1];
if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE id = ?');
    $stmt->execute([$id]);
    $usuario = $stmt->fetch();
    if (!$usuario) {
        flash('danger', 'Usuário não encontrado.');
        redirect('/usuarios/listar.php');
    }
}
$perfis = $pdo->query('SELECT id, nome, administrador FROM perfis ORDER BY administrador DESC, nome')->fetchAll();

$erros = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $antes = $usuario;
    $nome = formatarNomePessoa(input('nome'));
    $email = mb_strtolower(input('email'));
    $senha = (string) ($_POST['senha'] ?? '');
    $perfilId = (int) ($_POST['perfil_id'] ?? 0);
    $ativo = isset($_POST['ativo']) ? 1 : 0;
    $proprio = $id === usuarioAtualId();

    if ($nome === '') {
        $erros[] = 'O nome é obrigatório.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erros[] = 'Informe um e-mail válido.';
    }
    if (!in_array($perfilId, array_map('intval', array_column($perfis, 'id')), true)) {
        $erros[] = 'Selecione um perfil.';
    }
    if ((!$id && strlen($senha) < 8) || ($senha !== '' && strlen($senha) < 8)) {
        $erros[] = 'A senha precisa ter pelo menos 8 caracteres.';
    }
    // Não deixa o sistema sem administrador ativo (nem tirar o próprio acesso).
    $perfilAdmin = (bool) ($perfis[array_search($perfilId, array_map('intval', array_column($perfis, 'id')), true)]['administrador'] ?? false);
    if ($proprio && (!$ativo || !$perfilAdmin)) {
        $erros[] = 'Você não pode desativar o seu próprio usuário nem tirar o seu perfil de administrador.';
    }
    if (!$erros) {
        $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE email = ? AND id <> ?');
        $stmt->execute([$email, $id ?? 0]);
        if ($stmt->fetch()) {
            $erros[] = 'Já existe um usuário com este e-mail.';
        }
    }

    if (!$erros) {
        $nomePerfil = fn ($pid) => array_column($perfis, 'nome', 'id')[$pid] ?? '-';
        if ($id) {
            $pdo->prepare('UPDATE usuarios SET nome = ?, email = ?, perfil_id = ?, ativo = ?' . ($senha !== '' ? ', senha = ?' : '') . (!$ativo || $senha !== '' && !$proprio ? ', token_sessao = NULL' : '') . ' WHERE id = ?')
                ->execute(array_merge([$nome, $email, $perfilId, $ativo], $senha !== '' ? [password_hash($senha, PASSWORD_DEFAULT)] : [], [$id]));
            $mudou = descreverAlteracoes(
                ['nome' => $antes['nome'], 'email' => $antes['email'], 'perfil' => $nomePerfil($antes['perfil_id']), 'ativo' => $antes['ativo'] ? 'sim' : 'não', 'senha' => ''],
                ['nome' => $nome, 'email' => $email, 'perfil' => $nomePerfil($perfilId), 'ativo' => $ativo ? 'sim' : 'não', 'senha' => $senha !== '' ? 'x' : ''],
                ['nome' => 'Nome', 'email' => 'E-mail', 'perfil' => 'Perfil', 'ativo' => 'Ativo', 'senha' => 'Senha'], ['senha']
            );
            registrarAtividade('usuarios', 'edicao', 'Usuário ' . $nome . ': ' . $mudou);
        } else {
            $pdo->prepare('INSERT INTO usuarios (nome, email, senha, perfil_id, ativo) VALUES (?, ?, ?, ?, ?)')
                ->execute([$nome, $email, password_hash($senha, PASSWORD_DEFAULT), $perfilId, $ativo]);
            registrarAtividade('usuarios', 'criacao', 'Usuário criado: ' . $nome . ' (' . $email . ') · perfil ' . $nomePerfil($perfilId));
        }
        flash('success', 'Usuário salvo.');
        redirect('/usuarios/listar.php');
    }
    $usuario = ['nome' => $nome, 'email' => $email, 'perfil_id' => $perfilId, 'ativo' => $ativo];
}

$pageTitle = $id ? 'Editar usuário' : 'Novo usuário';
$pageBreadcrumb = 'Administração · Usuários';
$activeMenu = 'usuarios';
$pageAction = '<a href="/usuarios/listar.php" class="btn btn-outline-secondary">Cancelar</a>'
    . '<button type="submit" form="form-usuario" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>';
require __DIR__ . '/../../includes/layout_header.php';
?>

<?php if ($erros): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" id="form-usuario" autocomplete="off">
    <div class="secao" style="max-width: 700px;">
        <div class="secao-titulo"><i class="bi bi-person"></i> Dados de acesso</div>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Nome</label><input type="text" name="nome" class="form-control" required maxlength="120" value="<?= e($usuario['nome']) ?>"></div>
            <div class="col-md-6"><label class="form-label">E-mail (login)</label><input type="email" name="email" class="form-control" required maxlength="160" value="<?= e($usuario['email']) ?>"></div>
            <div class="col-md-6"><label class="form-label">Senha<?= $id ? ' <span class="text-muted small">(deixe em branco para manter)</span>' : '' ?></label>
                <input type="password" name="senha" class="form-control" autocomplete="new-password" minlength="8" <?= $id ? '' : 'required' ?>>
                <div class="form-text">Mínimo 8 caracteres.<?= $id ? ' Trocar a senha encerra a sessão aberta do usuário.' : '' ?></div></div>
            <div class="col-md-6"><label class="form-label">Perfil de acesso</label>
                <select name="perfil_id" class="form-select" required>
                    <option value="">Selecione...</option>
                    <?php foreach ($perfis as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= (int) $usuario['perfil_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['nome']) ?><?= $p['administrador'] ? ' (acesso a tudo)' : '' ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">O que cada perfil vê fica em <a href="/perfis/listar.php">Perfis de acesso</a>.</div></div>
            <div class="col-12"><label class="form-check"><input type="checkbox" class="form-check-input" name="ativo" <?= $usuario['ativo'] ? 'checked' : '' ?>> Ativo (pode entrar no sistema)</label></div>
        </div>
    </div>
</form>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
