<?php
require_once __DIR__ . '/../includes/bootstrap.php';

// Ícone da aba do navegador: a logo das Configurações recortada em círculo,
// com a mesma posição e zoom do círculo do topo do sistema (logo_pos_x,
// logo_pos_y, logo_zoom). Desenha em 256 px e reduz para 64 px, para a borda
// redonda sair lisa. Sem logo, 404 (o navegador usa o padrão). Se o servidor
// não tem a extensão GD do PHP (ou ela não lê o formato), manda a própria
// logo, sem recorte — o cabeçalho X-Icone diz o motivo.
$config = buscarConfiguracoes(getDB());
$logo = (string) ($config['logo_sistema'] ?? '');
$publico = realpath(__DIR__);
$arquivo = $logo !== '' ? realpath($publico . '/' . ltrim(parse_url($logo, PHP_URL_PATH) ?? '', '/')) : false;
if (!$arquivo || !str_starts_with($arquivo, $publico . DIRECTORY_SEPARATOR) || !is_file($arquivo)) {
    header('X-Icone: logo-nao-encontrada');
    http_response_code(404);
    exit;
}

$mime = @getimagesize($arquivo)['mime'] ?? '';
$enviarLogoOriginal = function (string $motivo) use ($arquivo, $mime): void {
    header('X-Icone: ' . $motivo);
    header('Content-Type: ' . ($mime ?: 'image/png'));
    header('Cache-Control: public, max-age=86400');
    readfile($arquivo);
    exit;
};
if (!function_exists('imagecreatetruecolor')) {
    $enviarLogoOriginal('sem-gd');
}

$origem = match ($mime) {
    'image/png' => @imagecreatefrompng($arquivo),
    'image/jpeg' => @imagecreatefromjpeg($arquivo),
    'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($arquivo) : false,
    'image/gif' => @imagecreatefromgif($arquivo),
    default => false,
};
if (!$origem) {
    $enviarLogoOriginal('formato-nao-lido');
}

$tamanho = 256;
$larguraOrigem = imagesx($origem);
$alturaOrigem = imagesy($origem);

// Mesma conta do CSS do topo: a imagem cabe inteira no quadrado (contain),
// é ampliada pelo zoom a partir do centro e deslocada (pos - 50) * 0,9 %.
$zoom = max(0.5, min(3.0, (float) ($config['logo_zoom'] ?? 1)));
$deslocX = ((int) ($config['logo_pos_x'] ?? 50) - 50) * 0.9 / 100 * $tamanho;
$deslocY = ((int) ($config['logo_pos_y'] ?? 50) - 50) * 0.9 / 100 * $tamanho;
$escala = min($tamanho / $larguraOrigem, $tamanho / $alturaOrigem) * $zoom;
$largura = $larguraOrigem * $escala;
$altura = $alturaOrigem * $escala;
$x = ($tamanho - $largura) / 2 + $deslocX;
$y = ($tamanho - $altura) / 2 + $deslocY;

// Fundo branco (como no topo) com a logo por cima.
$quadro = imagecreatetruecolor($tamanho, $tamanho);
imagefill($quadro, 0, 0, imagecolorallocate($quadro, 255, 255, 255));
imagealphablending($quadro, true);
imagecopyresampled($quadro, $origem, (int) round($x), (int) round($y), 0, 0, (int) round($largura), (int) round($altura), $larguraOrigem, $alturaOrigem);

// Recorte redondo: fora do círculo fica transparente; na beirada, uma
// borda cinza clara como a do topo.
$icone = imagecreatetruecolor($tamanho, $tamanho);
imagealphablending($icone, false);
imagesavealpha($icone, true);
imagefill($icone, 0, 0, imagecolorallocatealpha($icone, 0, 0, 0, 127));
$raio = $tamanho / 2;
$borda = 6;
for ($py = 0; $py < $tamanho; $py++) {
    for ($px = 0; $px < $tamanho; $px++) {
        $distancia = hypot($px + 0.5 - $raio, $py + 0.5 - $raio);
        if ($distancia > $raio) {
            continue;
        }
        $cor = $distancia > $raio - $borda ? 0xDDE1E6 : imagecolorat($quadro, $px, $py);
        imagesetpixel($icone, $px, $py, $cor & 0xFFFFFF);
    }
}

$final = imagecreatetruecolor(64, 64);
imagealphablending($final, false);
imagesavealpha($final, true);
imagefill($final, 0, 0, imagecolorallocatealpha($final, 0, 0, 0, 127));
imagecopyresampled($final, $icone, 0, 0, 0, 0, 64, 64, $tamanho, $tamanho);

// O endereço já muda quando a logo ou o ajuste mudam (?v=...), então pode
// ficar guardado no navegador.
header('Content-Type: image/png');
header('Cache-Control: public, max-age=604800');
imagepng($final);
