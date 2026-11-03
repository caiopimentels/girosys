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

define('APP_NAME', 'Meu Sistema');
define('COR_PRIMARIA', '#16a34a');
define('COR_SECUNDARIA', '#15803d');

// Minutos sem usar o sistema até deslogar sozinho.
define('SESSAO_TIMEOUT_MINUTOS', 60);

// Título recebido até X dias depois do vencimento ainda conta como "em dia"
// no histórico do emitente (prazo normal de compensação do cheque).
define('TOLERANCIA_COMPENSACAO_DIAS', 3);

define('BASE_PATH', dirname(__DIR__));
