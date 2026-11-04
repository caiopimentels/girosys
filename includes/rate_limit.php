<?php

// Limita quantas vezes algo pode ser tentado (login, recuperação de senha)
// por identificador (ex.: e-mail) e por IP, dentro de uma janela de tempo —
// proteção simples contra força bruta e spam, sem depender de infraestrutura
// externa.

function enderecoIpAtual(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function registrarTentativaAcesso(PDO $pdo, string $tipo, string $identificador): void
{
    // Aproveita a própria escrita para limpar tentativas antigas — evita
    // precisar de um cron só para isso, o volume aqui é sempre pequeno.
    $pdo->exec("DELETE FROM tentativas_acesso WHERE criado_em < DATE_SUB(NOW(), INTERVAL 1 DAY)");

    $stmt = $pdo->prepare('INSERT INTO tentativas_acesso (tipo, identificador, ip) VALUES (?, ?, ?)');
    $stmt->execute([$tipo, mb_strtolower($identificador), enderecoIpAtual()]);
}

function limparTentativasAcesso(PDO $pdo, string $tipo, string $identificador): void
{
    $stmt = $pdo->prepare('DELETE FROM tentativas_acesso WHERE tipo = ? AND identificador = ?');
    $stmt->execute([$tipo, mb_strtolower($identificador)]);
}

// Retorna true se o identificador OU o IP atual já bateram no limite de
// tentativas dentro da janela — quem chamar deve bloquear a ação nesse caso.
function limiteDeTentativasExcedido(PDO $pdo, string $tipo, string $identificador, int $maxPorIdentificador, int $maxPorIp, int $janelaMinutos): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM tentativas_acesso
         WHERE tipo = ? AND identificador = ? AND criado_em >= DATE_SUB(NOW(), INTERVAL ? MINUTE)'
    );
    $stmt->execute([$tipo, mb_strtolower($identificador), $janelaMinutos]);
    if ((int) $stmt->fetchColumn() >= $maxPorIdentificador) {
        return true;
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM tentativas_acesso
         WHERE tipo = ? AND ip = ? AND criado_em >= DATE_SUB(NOW(), INTERVAL ? MINUTE)'
    );
    $stmt->execute([$tipo, enderecoIpAtual(), $janelaMinutos]);
    return (int) $stmt->fetchColumn() >= $maxPorIp;
}
