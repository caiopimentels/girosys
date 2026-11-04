<?php
require_once __DIR__ . '/../includes/bootstrap.php';
exigirLogin();

$pdo = getDB();
$stmt = $pdo->prepare('SELECT * FROM usuarios WHERE id = ?');
$stmt->execute([usuarioAtualId()]);
$usuario = $stmt->fetch();
$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = formatarNomePessoa(input('nome'));
    $email = mb_strtolower(input('email'));
    $senhaAtual = input('senha_atual');
    $senhaNova = input('senha_nova');

    if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erros[] = 'Informe nome e um e-mail válido.';
    }
    if ($senhaNova !== '' && mb_strlen($senhaNova) < 8) {
        $erros[] = 'A senha nova precisa ter pelo menos 8 caracteres.';
    }
    if (!senhaDoUsuarioConfere($pdo, $senhaAtual)) {
        $erros[] = 'Senha atual incorreta (depois de 5 erros, aguarde 15 minutos).';
    }
    $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE email = ? AND id <> ?');
    $stmt->execute([$email, $usuario['id']]);
    if ($stmt->fetch()) {
        $erros[] = 'Já existe outro usuário com este e-mail.';
    }

    if (!$erros) {
        $pdo->prepare('UPDATE usuarios SET nome = ?, email = ?, senha = ? WHERE id = ?')->execute([
            $nome, $email, $senhaNova !== '' ? password_hash($senhaNova, PASSWORD_DEFAULT) : $usuario['senha'], $usuario['id'],
        ]);
        $_SESSION['user_nome'] = $nome;
        registrarAtividade('usuarios', $senhaNova !== '' ? 'senha' : 'edicao', 'Minha conta: ' . descreverAlteracoes(
            ['nome' => $usuario['nome'], 'email' => $usuario['email'], 'senha' => ''], ['nome' => $nome, 'email' => $email, 'senha' => $senhaNova !== '' ? 'x' : ''],
            ['nome' => 'Nome', 'email' => 'E-mail', 'senha' => 'Senha'], ['senha']));
        flash('success', 'Dados atualizados.');
        redirect('/minha_conta.php');
    }
    $usuario['nome'] = $nome;
    $usuario['email'] = $email;
}

$pageTitle = 'Minha conta';
$pageAction = '<button type="submit" form="form-conta" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>';
require __DIR__ . '/../includes/layout_header.php';
?>

<?php if ($erros): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" id="form-conta" style="max-width: 640px;">
    <div class="secao">
        <div class="secao-titulo"><i class="bi bi-person"></i> Dados de acesso</div>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Nome</label><input type="text" name="nome" class="form-control" required value="<?= e($usuario['nome']) ?>"></div>
            <div class="col-md-6"><label class="form-label">E-mail</label><input type="email" name="email" class="form-control" required value="<?= e($usuario['email']) ?>"></div>
            <div class="col-md-6"><label class="form-label">Senha nova <span class="text-muted small">(deixe vazio para manter)</span></label><input type="password" name="senha_nova" class="form-control" minlength="8" autocomplete="new-password"></div>
            <div class="col-md-6"><label class="form-label">Senha atual *</label><input type="password" name="senha_atual" class="form-control" required autocomplete="current-password"></div>
        </div>
    </div>
</form>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
