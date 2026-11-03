<?php
require_once __DIR__ . '/../includes/bootstrap.php';
exigirLogin();

$tema = ($_POST['tema'] ?? '') === 'escuro' ? 'escuro' : 'claro';
getDB()->prepare('UPDATE usuarios SET tema = ? WHERE id = ?')->execute([$tema, usuarioAtualId()]);
$_SESSION['user_tema'] = $tema;
responderJson(['sucesso' => true]);
