<?php
// Confere no Inter os boletos em aberto (caso algum aviso do webhook não tenha
// chegado) e dá baixa nos que foram pagos. Rodar pelo cron, ex. a cada hora:
//   0 * * * * php /caminho/meu_sistema/scripts/conferir_boletos.php
require_once __DIR__ . '/../includes/bootstrap_webhook.php';

$pdo = getDB();
[$conferidos, $pagos, $erros] = conferirBoletosInter($pdo);
foreach ($erros as $id => $mensagem) {
    fwrite(STDERR, "Título #{$id}: {$mensagem}\n");
}
echo date('Y-m-d H:i') . " · {$conferidos} boleto(s) conferido(s), {$pagos} pago(s) baixado(s)" . ($erros ? ', ' . count($erros) . ' com erro' : '') . ".\n";
