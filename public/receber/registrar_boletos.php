<?php
// Registra no banco o boleto dos títulos marcados no contas a receber (ou de
// um só, pelo botão da linha). Só tipos que registram boleto; os que já têm
// boleto ou nosso número ficam como estão.
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirLogin();
exigirPermissaoAcao('emitir_boleto', 'Seu perfil não pode emitir boletos.');

$voltar = str_starts_with((string) input('voltar'), '/receber/') ? input('voltar') : '/receber/listar.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect($voltar);
}
$pdo = getDB();
$ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])))));
// Cada título vai ao banco: limita o lote para a tela não esperar demais.
const LIMITE_LOTE_BOLETOS = 50;
if (!$ids) {
    flash('danger', 'Marque ao menos um título.');
    redirect($voltar);
}
if (count($ids) > LIMITE_LOTE_BOLETOS) {
    flash('danger', 'Marque no máximo ' . LIMITE_LOTE_BOLETOS . ' títulos por vez.');
    redirect($voltar);
}

try {
    [$registrados, $pulados, $falhas] = registrarBoletosLote($pdo, $ids, idOuNull($_POST['conta_id'] ?? null));
} catch (InvalidArgumentException $e) {
    flash('danger', $e->getMessage());
    redirect($voltar);
}
if (count($ids) > 1) {
    registrarAtividade('boletos', 'emissao_boleto', 'Registro de boletos em lote: ' . count($registrados) . ' registrado(s), ' . count($pulados) . ' pulado(s), ' . count($falhas) . ' com erro');
}
$lista = fn (array $itens) => implode(' · ', array_slice($itens, 0, 5)) . (count($itens) > 5 ? ' · e mais ' . (count($itens) - 5) : '');
if ($registrados) {
    flash('success', count($registrados) . ' boleto(s) registrado(s) no Inter.' . (count($registrados) === 1 && count($ids) === 1 ? ' ' . $registrados[0] : ''));
}
if ($pulados) {
    flash('warning', 'Não precisaram de boleto: ' . $lista($pulados));
}
if ($falhas) {
    flash('danger', 'Não registrados: ' . $lista($falhas));
}
redirect($voltar);
