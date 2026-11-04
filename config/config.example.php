<?php

// Copie este arquivo para config.php e ajuste os dados do banco.
// O config.php não vai para o git (ver .gitignore).

// Horário do Brasil — sem isto o PHP usa UTC e "hoje" fica até 3h adiantado,
// mudando a contagem de dias do desconto perto da meia-noite.
date_default_timezone_set('America/Sao_Paulo');

define('DB_HOST', 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'meu_sistema');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Nome usado só se Configurações › Sistema estiver vazio. Nome, logo, cores,
// tempo de sessão, tolerância de compensação e SMTP ficam no banco
// (tabela configuracoes, tela Configurações).
define('APP_NAME', 'GiroSys');

// Endereço público do sistema (com https), usado no webhook do Banco Inter.
// Vazio = usa o endereço pelo qual o sistema foi acessado.
define('URL_SISTEMA', getenv('URL_SISTEMA') ?: '');
// Só para testes locais: aponta a API do Inter para um simulador.
define('INTER_URL_BASE', getenv('INTER_URL_BASE') ?: '');

define('BASE_PATH', dirname(__DIR__));
