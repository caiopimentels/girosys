<?php

// Criptografia de segredos guardados no banco (client_secret da API do
// banco, senha da chave do certificado, token de acesso). A chave fica num
// arquivo em storage/ — fora do public/ e fora do git —, então um dump do
// banco sozinho não expõe as credenciais. Perder esse arquivo significa
// redigitar os segredos (não há como recuperá-los).

function caminhoStorage(string $subcaminho = ''): string
{
    $base = BASE_PATH . '/storage';
    if (!is_dir($base)) {
        mkdir($base, 0700, true);
        // Defesa extra caso o servidor algum dia aponte o docroot para a
        // raiz do projeto em vez de public/.
        file_put_contents($base . '/.htaccess', "Require all denied\n");
    }
    return $subcaminho === '' ? $base : $base . '/' . ltrim($subcaminho, '/');
}

function chaveCriptografiaApp(): string
{
    static $chave = null;
    if ($chave !== null) {
        return $chave;
    }

    $arquivo = caminhoStorage('chave_app.key');
    if (!is_file($arquivo)) {
        file_put_contents($arquivo, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
        chmod($arquivo, 0600);
    }

    $chave = base64_decode(trim((string) file_get_contents($arquivo)), true);
    if ($chave === false || strlen($chave) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        throw new RuntimeException('Arquivo de chave de criptografia inválido em storage/chave_app.key.');
    }
    return $chave;
}

function criptografar(string $texto): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($nonce . sodium_crypto_secretbox($texto, $nonce, chaveCriptografiaApp()));
}

// Retorna null quando não consegue abrir (chave trocada ou dado corrompido)
// — quem chama trata como "segredo não cadastrado" e pede para redigitar.
function descriptografar(?string $cifrado): ?string
{
    if (!$cifrado) {
        return null;
    }
    $bruto = base64_decode($cifrado, true);
    if ($bruto === false || strlen($bruto) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        return null;
    }
    $nonce = substr($bruto, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $texto = sodium_crypto_secretbox_open(substr($bruto, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, chaveCriptografiaApp());
    return $texto === false ? null : $texto;
}
