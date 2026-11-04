<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
exigirAdmin();

$pdo = getDB();
$config = buscarConfiguracoes($pdo);

$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nomeSistema = trim($_POST['nome_sistema'] ?? '');
    $corPrimaria = trim($_POST['cor_primaria'] ?? '#16a34a');
    $corSecundaria = trim($_POST['cor_secundaria'] ?? '#15803d');

    // Salva os valores enviados pelos controles da tela.
    $logoPosX = max(0, min(100, (int) ($_POST['logo_pos_x'] ?? 50)));
    $logoPosY = max(0, min(100, (int) ($_POST['logo_pos_y'] ?? 50)));
    $logoZoom = max(0.50, min(3.00, (float) ($_POST['logo_zoom'] ?? 1)));

    $toleranciaCompensacao = max(0, min(30, (int) ($_POST['tolerancia_compensacao_dias'] ?? 3)));
    $sessaoTimeoutMinutos = max(1, (int) ($_POST['sessao_timeout_minutos'] ?? 30));

    $smtpHost = trim($_POST['smtp_host'] ?? '');
    $smtpPorta = max(1, (int) ($_POST['smtp_porta'] ?? 587));
    $smtpSeguranca = in_array($_POST['smtp_seguranca'] ?? '', ['tls', 'ssl', 'none'], true) ? $_POST['smtp_seguranca'] : 'tls';
    $smtpUsuario = trim($_POST['smtp_usuario'] ?? '');
    $smtpRemetenteNome = trim($_POST['smtp_remetente_nome'] ?? '');
    $smtpRemetenteEmail = trim($_POST['smtp_remetente_email'] ?? '');
    $emailNotificacaoErros = trim($_POST['email_notificacao_erros'] ?? '');
    // Senha em branco mantém a atual — evita ter que redigitar a senha do
    // SMTP toda vez que só quer salvar outra configuração da tela.
    // No banco a senha fica criptografada (includes/cripto.php).
    $smtpSenha = ($_POST['smtp_senha'] ?? '') !== '' ? criptografar($_POST['smtp_senha']) : ($config['smtp_senha'] ?? null);

    if ($nomeSistema === '') {
        $nomeSistema = 'GiroSys';
    }

    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $corPrimaria)) {
        $corPrimaria = '#16a34a';
    }

    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $corSecundaria)) {
        $corSecundaria = '#15803d';
    }

    $logoSistema = $config['logo_sistema'] ?? null;

    if (
        isset($_FILES['logo_sistema']) &&
        $_FILES['logo_sistema']['error'] !== UPLOAD_ERR_NO_FILE
    ) {
        if ($_FILES['logo_sistema']['error'] !== UPLOAD_ERR_OK) {
            flash('danger', 'Não foi possível enviar a logo.');
            redirect('/configuracoes/form.php');
        }

        if ($_FILES['logo_sistema']['size'] > 2 * 1024 * 1024) {
            flash('danger', 'A logo deve ter no máximo 2 MB.');
            redirect('/configuracoes/form.php');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($_FILES['logo_sistema']['tmp_name']);

        $extensoes = [
            'image/png'  => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
        ];

        if (!isset($extensoes[$mime])) {
            flash('danger', 'Formato de imagem não permitido.');
            redirect('/configuracoes/form.php');
        }

        // public/assets/img fica fora do git: no servidor precisa existir e
        // ser gravável pelo usuário do Apache/PHP.
        $pasta = dirname(__DIR__) . '/assets/img';
        if ($problema = problemaPastaUpload($pasta)) {
            flash('danger', 'Não foi possível salvar a logo. ' . $problema);
            redirect('/configuracoes/form.php');
        }

        $nomeArquivo =
            'logo-sistema-' . time() . '.' . $extensoes[$mime];

        $destino = $pasta . '/' . $nomeArquivo;

        if (!move_uploaded_file(
            $_FILES['logo_sistema']['tmp_name'],
            $destino
        )) {
            flash('danger', 'Não foi possível salvar a logo.');
            redirect('/configuracoes/form.php');
        }

        $logoSistema = '/assets/img/' . $nomeArquivo;
    }

    $rotulosConfig = [
        'nome_sistema' => 'Nome do sistema',
        'logo_sistema' => 'Logo do sistema',
        'cor_primaria' => 'Cor principal',
        'cor_secundaria' => 'Cor de destaque',
        'tolerancia_compensacao_dias' => 'Tolerância de compensação (dias)',
        'sessao_timeout_minutos' => 'Tempo de sessão (min)',
        'smtp_host' => 'Servidor SMTP',
        'smtp_porta' => 'Porta SMTP',
        'smtp_seguranca' => 'Segurança SMTP',
        'smtp_usuario' => 'Usuário SMTP',
        'smtp_senha' => 'Senha SMTP',
        'smtp_remetente_nome' => 'Nome do remetente',
        'smtp_remetente_email' => 'E-mail do remetente',
        'email_notificacao_erros' => 'E-mail para avisos de erro',
    ];

    $configAntes = [
        'nome_sistema' => $config['nome_sistema'] ?? '',
        'logo_sistema' => $config['logo_sistema'] ?? '',
        'cor_primaria' => $config['cor_primaria'] ?? '',
        'cor_secundaria' => $config['cor_secundaria'] ?? '',
        'tolerancia_compensacao_dias' => (int) ($config['tolerancia_compensacao_dias'] ?? 3),
        'sessao_timeout_minutos' => (int) ($config['sessao_timeout_minutos'] ?? 30),
        'smtp_host' => $config['smtp_host'] ?? '',
        'smtp_porta' => (int) ($config['smtp_porta'] ?? 587),
        'smtp_seguranca' => $config['smtp_seguranca'] ?? 'tls',
        'smtp_usuario' => $config['smtp_usuario'] ?? '',
        'smtp_senha' => $config['smtp_senha'] ?? '',
        'smtp_remetente_nome' => $config['smtp_remetente_nome'] ?? '',
        'smtp_remetente_email' => $config['smtp_remetente_email'] ?? '',
        'email_notificacao_erros' => $config['email_notificacao_erros'] ?? '',
    ];

    $configDepois = [
        'nome_sistema' => $nomeSistema,
        'logo_sistema' => $logoSistema,
        'cor_primaria' => $corPrimaria,
        'cor_secundaria' => $corSecundaria,
        'tolerancia_compensacao_dias' => $toleranciaCompensacao,
        'sessao_timeout_minutos' => $sessaoTimeoutMinutos,
        'smtp_host' => $smtpHost,
        'smtp_porta' => $smtpPorta,
        'smtp_seguranca' => $smtpSeguranca,
        'smtp_usuario' => $smtpUsuario,
        'smtp_senha' => $smtpSenha,
        'smtp_remetente_nome' => $smtpRemetenteNome,
        'smtp_remetente_email' => $smtpRemetenteEmail,
        'email_notificacao_erros' => $emailNotificacaoErros,
    ];

    $stmt = $pdo->prepare(
        'UPDATE configuracoes
         SET nome_sistema = ?,
             logo_sistema = ?,
             cor_primaria = ?,
             cor_secundaria = ?,
             logo_pos_x = ?,
             logo_pos_y = ?,
             logo_zoom = ?,
             tolerancia_compensacao_dias = ?,
             sessao_timeout_minutos = ?,
             smtp_host = ?,
             smtp_porta = ?,
             smtp_seguranca = ?,
             smtp_usuario = ?,
             smtp_senha = ?,
             smtp_remetente_nome = ?,
             smtp_remetente_email = ?,
             email_notificacao_erros = ?
         WHERE id = 1'
    );

    $stmt->execute([
        $nomeSistema,
        $logoSistema,
        $corPrimaria,
        $corSecundaria,
        $logoPosX,
        $logoPosY,
        $logoZoom,
        $toleranciaCompensacao,
        $sessaoTimeoutMinutos,
        $smtpHost ?: null,
        $smtpPorta,
        $smtpSeguranca,
        $smtpUsuario ?: null,
        $smtpSenha,
        $smtpRemetenteNome ?: null,
        $smtpRemetenteEmail ?: null,
        $emailNotificacaoErros ?: null,
    ]);

    registrarAtividade('configuracoes', 'edicao', descreverAlteracoes($configAntes, $configDepois, $rotulosConfig, ['smtp_senha']));

    flash('success', 'Configurações atualizadas com sucesso.');
    redirect('/configuracoes/form.php');
}

$pageTitle = 'Configurações';
$pageBreadcrumb = 'Administração';
$pageAction = '<button type="submit" form="form-principal" class="btn btn-brand"><i class="bi bi-check-lg"></i> Salvar</button>';
$activeMenu = 'configuracoes';
require __DIR__ . '/../../includes/layout_header.php';
$abaAtiva = 'sistema';
require __DIR__ . '/../../includes/abas_configuracoes.php';
?>

<style>
.logo-editor {
    display: grid;
    grid-template-columns: 140px 1fr;
    gap: 24px;
    align-items: center;
    width: 100%;
    margin-top: 4px;
    padding: 20px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--bg);
}

.logo-preview {
    width: 120px;
    height: 120px;
    position: relative;
    overflow: hidden;
    justify-self: center;
    border: 3px solid var(--accent-soft);
    border-radius: 50%;
    background: #fff;
}

.logo-preview img {
    position: absolute;

    left: 0;
    top: 0;

    width: 100%;
    height: 100%;

    display: block;
    object-fit: contain;
    object-position: center;

    transform: scale(1);
    transform-origin: center;

    transition:
        left 0.15s ease,
        top 0.15s ease,
        transform 0.15s ease;
}

.logo-controles {
    display: grid;
    gap: 12px;
}

.logo-controle-titulo {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 4px;
}

.logo-controle-titulo .form-label {
    margin-bottom: 0;
}

.logo-valor {
    min-width: 92px;
    padding: 3px 8px;
    border-radius: 999px;
    color: var(--accent-dark);
    background: var(--accent-soft);
    font-size: 12px;
    font-weight: 700;
    text-align: center;
    white-space: nowrap;
}

@media (max-width: 576px) {
    .logo-editor {
        grid-template-columns: 1fr;
    }
}
</style>

<?php if ($erros): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($erros as $erro): ?><li><?= e($erro) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="secao" style="max-width: 700px;">
    <div class="secao-titulo">
        <i class="bi bi-palette"></i> Personalização do Sistema
    </div>

    <form method="post" enctype="multipart/form-data" id="form-principal">
        <div class="row g-3">

            <div class="col-12">
                <label class="form-label">Nome do sistema</label>

                <input
                    type="text"
                    name="nome_sistema"
                    class="form-control"
                    maxlength="100"
                    value="<?= e($config['nome_sistema'] ?? 'GiroSys') ?>"
                    required
                >
            </div>

            <div class="col-12">
                <label class="form-label">Logo do sistema</label>

                <?php if (!empty($config['logo_sistema'])): ?>
                    <div class="mb-3">
                    
                    </div>
                <?php endif; ?>

                <input
                    type="file"
                    name="logo_sistema"
                    id="logo_sistema"
                    class="form-control"
                    accept=".png,.jpg,.jpeg,.webp"
                >

                <div class="form-text">
                    Utilize PNG, JPG ou WebP com até 2 MB.
                </div>
            </div>

            <div class="logo-editor">
                <div class="logo-preview">
                    <?php if (empty($config['logo_sistema'])): ?><span id="logo-preview-letra" class="d-flex h-100 align-items-center justify-content-center fs-1 fw-semibold" style="color: var(--accent);"><?= e(mb_strtoupper(mb_substr(nomeSistema(), 0, 1))) ?></span><?php endif; ?>
                    <img
                        id="logo-preview-img"
                        src="<?= e($config['logo_sistema'] ?? '') ?>"
                        <?= empty($config['logo_sistema']) ? 'hidden' : '' ?>
                        alt="Pré-visualização da logo"
                    >
                </div>

                <div class="logo-controles">
                    <div>
                        <div class="logo-controle-titulo">
                            <label class="form-label" for="logo_pos_x">Posição horizontal</label>
                            <span class="logo-valor" id="logo_pos_x_valor">Centro 0%</span>
                        </div>

                        <input
                            type="range"
                            name="logo_pos_x"
                            id="logo_pos_x"
                            class="form-range"
                            min="0"
                            max="100"
                            step="1"
                            value="<?= (int) ($config['logo_pos_x'] ?? 50) ?>"
                        >
                    </div>

                    <div>
                        <div class="logo-controle-titulo">
                            <label class="form-label" for="logo_pos_y">Posição vertical</label>
                            <span class="logo-valor" id="logo_pos_y_valor">Centro 0%</span>
                        </div>

                        <input
                            type="range"
                            name="logo_pos_y"
                            id="logo_pos_y"
                            class="form-range"
                            min="0"
                            max="100"
                            step="1"
                            value="<?= (int) ($config['logo_pos_y'] ?? 50) ?>"
                        >
                    </div>

                    <div>
                        <div class="logo-controle-titulo">
                            <label class="form-label" for="logo_zoom">Tamanho da logo</label>
                            <span class="logo-valor" id="logo_zoom_valor">100%</span>
                        </div>

                        <input
                            type="range"
                            name="logo_zoom"
                            id="logo_zoom"
                            class="form-range"
                            min="0.50"
                            max="3"
                            step="0.05"
                            value="<?= e($config['logo_zoom'] ?? '1.00') ?>"
                        >
                    </div>

                    <button
                        type="button"
                        class="btn btn-sm btn-outline-secondary"
                        id="restaurar-logo"
                    >
                        Restaurar posição
                    </button>
                </div>
            </div>


            <div class="col-md-6">
                <label class="form-label">Cor principal</label>

                <div class="input-group">
                    <input
                        type="color"
                        name="cor_primaria"
                        class="form-control form-control-color"
                        value="<?= e($config['cor_primaria'] ?? '#16a34a') ?>"
                    >

                    <input
                        type="text"
                        class="form-control"
                        value="<?= e($config['cor_primaria'] ?? '#16a34a') ?>"
                        readonly
                    >
                </div>
            </div>

            <div class="col-md-6">
                <label class="form-label">Cor de destaque</label>

                <div class="input-group">
                    <input
                        type="color"
                        name="cor_secundaria"
                        class="form-control form-control-color"
                        value="<?= e($config['cor_secundaria'] ?? '#15803d') ?>"
                    >

                    <input
                        type="text"
                        class="form-control"
                        value="<?= e($config['cor_secundaria'] ?? '#15803d') ?>"
                        readonly
                    >
                </div>
            </div>
                
            <div class="col-12 mt-4">
                <div class="secao-titulo mb-2">
                    <i class="bi bi-calculator"></i>
                    Operação
                </div>

                <div class="col-md-6">
                    <label class="form-label">Tolerância de compensação (dias)</label>
                    <input
                        type="number"
                        name="tolerancia_compensacao_dias"
                        class="form-control"
                        min="0"
                        max="30"
                        value="<?= (int) ($config['tolerancia_compensacao_dias'] ?? 3) ?>"
                    >
                    <div class="form-text">Título recebido até esses dias depois do vencimento ainda conta como "em dia" no histórico do emitente (prazo normal de compensação do cheque).</div>
                </div>
            </div>

            <div class="col-12 mt-4">
                <div class="secao-titulo mb-2">
                    <i class="bi bi-shield-lock"></i>
                    Segurança
                </div>

                <div class="col-md-6">
                    <label class="form-label">
                        Tempo de sessão (minutos de inatividade até deslogar)
                    </label>

                    <input
                        type="number"
                        name="sessao_timeout_minutos"
                        class="form-control"
                        min="1"
                        value="<?= (int) ($config['sessao_timeout_minutos'] ?? 30) ?>"
                    >
                    <div class="form-text">Um contador no topo do sistema mostra quanto tempo falta para deslogar automaticamente.</div>
                </div>
            </div>

            <div class="col-12 mt-4">
                <div class="secao-titulo mb-2">
                    <i class="bi bi-envelope"></i>
                    E-mail (SMTP)
                </div>
                <div class="form-text mb-2">Usado para enviar o e-mail de "esqueci minha senha". Configure com os dados de uma conta de e-mail (Gmail, Outlook, etc.) ou de um serviço de envio (SendGrid, Mailgun...).</div>

                <div class="col-md-8">
                    <label class="form-label">Servidor SMTP (host)</label>
                    <input type="text" name="smtp_host" class="form-control" placeholder="Ex: smtp.gmail.com" value="<?= e($config['smtp_host'] ?? '') ?>">
                </div>

                <div class="col-md-4 mt-3 mt-md-0">
                    <label class="form-label">Porta</label>
                    <input type="number" name="smtp_porta" class="form-control" value="<?= (int) ($config['smtp_porta'] ?? 587) ?>">
                </div>

                <div class="col-md-4 mt-3">
                    <label class="form-label">Segurança</label>
                    <select name="smtp_seguranca" class="form-select">
                        <option value="tls" <?= ($config['smtp_seguranca'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>STARTTLS (porta 587, mais comum)</option>
                        <option value="ssl" <?= ($config['smtp_seguranca'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL (porta 465)</option>
                    </select>
                </div>

                <div class="col-md-4 mt-3">
                    <label class="form-label">Usuário</label>
                    <input type="text" name="smtp_usuario" class="form-control" placeholder="seu@email.com" value="<?= e($config['smtp_usuario'] ?? '') ?>">
                </div>

                <div class="col-md-4 mt-3">
                    <label class="form-label">Senha</label>
                    <input type="password" name="smtp_senha" class="form-control" autocomplete="new-password"
                           placeholder="<?= !empty($config['smtp_senha']) ? 'Deixe em branco para manter' : '' ?>">
                    <div class="form-text">No Gmail, use uma "senha de app", não a senha normal da conta.</div>
                </div>

                <div class="col-md-6 mt-3">
                    <label class="form-label">Nome do remetente</label>
                    <input type="text" name="smtp_remetente_nome" class="form-control" placeholder="Ex: GiroSys" value="<?= e($config['smtp_remetente_nome'] ?? '') ?>">
                </div>

                <div class="col-md-6 mt-3">
                    <label class="form-label">E-mail do remetente</label>
                    <input type="email" name="smtp_remetente_email" class="form-control" placeholder="Se vazio, usa o campo Usuário" value="<?= e($config['smtp_remetente_email'] ?? '') ?>">
                </div>

                <div class="col-12 mt-3">
                    <div class="input-group" style="max-width: 420px;">
                        <input type="email" id="email-teste-smtp" class="form-control" placeholder="e-mail para receber o teste">
                        <button type="button" class="btn btn-outline-secondary" id="btn-testar-smtp">
                            <i class="bi bi-send"></i> Testar envio
                        </button>
                    </div>
                    <div id="resultado-teste-smtp" class="form-text"></div>
                </div>

                <div class="col-md-6 mt-3">
                    <label class="form-label">E-mail para avisos de erro do sistema</label>
                    <input type="email" name="email_notificacao_erros" class="form-control" placeholder="Se vazio, usa o e-mail do remetente acima" value="<?= e($config['email_notificacao_erros'] ?? '') ?>">
                    <div class="form-text">Quando o sistema tem uma falha inesperada (não uma mensagem normal de validação), manda um e-mail pra este endereço.</div>
                </div>

                <div class="col-md-6 mt-3 d-flex align-items-end">
                    <div class="w-100">
                        <button type="button" class="btn btn-outline-secondary" id="btn-simular-erro"><i class="bi bi-bug"></i> Simular um erro e enviar o aviso</button>
                        <div id="resultado-simular-erro" class="form-text"></div>
                        <div class="form-text">Usa a configuração já <strong>salva</strong> — salve a tela antes de testar se acabou de mudar algo aqui.</div>
                    </div>
                </div>
            </div>

            <div class="col-12 mt-4">
                <button type="submit" class="btn btn-brand">
                    <i class="bi bi-check-lg"></i>
                    Salvar configurações
                </button>
            </div>

        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const arquivo = document.getElementById('logo_sistema');
    const imagem = document.getElementById('logo-preview-img');
    const posX = document.getElementById('logo_pos_x');
    const posY = document.getElementById('logo_pos_y');
    const zoom = document.getElementById('logo_zoom');
    const restaurar = document.getElementById('restaurar-logo');
    const valorX = document.getElementById('logo_pos_x_valor');
    const valorY = document.getElementById('logo_pos_y_valor');
    const valorZoom = document.getElementById('logo_zoom_valor');

    if (!imagem || !posX || !posY || !zoom) {
        return;
    }

    function atualizarLogo() {
        const deslocamentoX =
            (Number(posX.value) - 50) * 0.9;

        const deslocamentoY =
            (Number(posY.value) - 50) * 0.9;

        imagem.style.left = deslocamentoX + '%';
        imagem.style.top = deslocamentoY + '%';
        imagem.style.transform =
            'scale(' + Number(zoom.value) + ')';

        if (valorX) {
            const percentualX = Math.round(Math.abs(deslocamentoX));
            valorX.textContent = deslocamentoX < 0
                ? 'Esquerda ' + percentualX + '%'
                : deslocamentoX > 0
                    ? 'Direita ' + percentualX + '%'
                    : 'Centro 0%';
        }

        if (valorY) {
            const percentualY = Math.round(Math.abs(deslocamentoY));
            valorY.textContent = deslocamentoY < 0
                ? 'Cima ' + percentualY + '%'
                : deslocamentoY > 0
                    ? 'Baixo ' + percentualY + '%'
                    : 'Centro 0%';
        }

        if (valorZoom) {
            valorZoom.textContent = Math.round(Number(zoom.value) * 100) + '%';
        }
    }

    if (arquivo) {
        arquivo.addEventListener('change', function () {
            const imagemSelecionada = this.files && this.files[0];

            if (!imagemSelecionada) {
                return;
            }

            const leitor = new FileReader();
            leitor.onload = function (evento) {
                imagem.src = evento.target.result;
                imagem.hidden = false;
                const letra = document.getElementById('logo-preview-letra');
                if (letra) letra.remove();
                atualizarLogo();
            };
            leitor.readAsDataURL(imagemSelecionada);
        });
    }

    posX.addEventListener('input', atualizarLogo);
    posY.addEventListener('input', atualizarLogo);
    zoom.addEventListener('input', atualizarLogo);

    if (restaurar) {
        restaurar.addEventListener('click', function () {
            posX.value = 50;
            posY.value = 50;
            zoom.value = 1;
            atualizarLogo();
        });
    }

    atualizarLogo();
});

document.getElementById('btn-testar-smtp').addEventListener('click', function () {
    const botao = this;
    const resultado = document.getElementById('resultado-teste-smtp');
    const emailTeste = document.getElementById('email-teste-smtp').value.trim();

    if (!emailTeste) {
        resultado.textContent = 'Informe um e-mail para receber o teste.';
        resultado.className = 'form-text text-danger';
        return;
    }

    const form = document.getElementById('form-principal');
    const dados = new FormData(form);
    dados.append('email_teste', emailTeste);

    botao.disabled = true;
    botao.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Enviando...';
    resultado.textContent = '';

    fetch('/configuracoes/testar_smtp.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: dados,
    })
        .then((r) => r.json())
        .then((data) => {
            resultado.textContent = data.mensagem;
            resultado.className = 'form-text ' + (data.sucesso ? 'text-success' : 'text-danger');
        })
        .catch(() => {
            resultado.textContent = 'Erro de rede ao testar o envio.';
            resultado.className = 'form-text text-danger';
        })
        .finally(() => {
            botao.disabled = false;
            botao.innerHTML = '<i class="bi bi-send"></i> Testar envio';
        });
});

document.getElementById('btn-simular-erro').addEventListener('click', function () {
    const botao = this;
    const resultado = document.getElementById('resultado-simular-erro');

    botao.disabled = true;
    botao.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Enviando...';
    resultado.textContent = '';

    fetch('/configuracoes/simular_erro.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
        .then((r) => r.json())
        .then((data) => {
            resultado.textContent = data.mensagem;
            resultado.className = 'form-text ' + (data.sucesso ? 'text-success' : 'text-danger');
        })
        .catch(() => {
            resultado.textContent = 'Erro de rede ao simular o erro.';
            resultado.className = 'form-text text-danger';
        })
        .finally(() => {
            botao.disabled = false;
            botao.innerHTML = '<i class="bi bi-bug"></i> Simular um erro e enviar o aviso';
        });
});
</script>

<?php require __DIR__ . '/../../includes/layout_footer.php'; ?>
