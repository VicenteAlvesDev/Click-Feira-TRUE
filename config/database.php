<?php

/*
 * Conexão com o banco de dados.
 *
 * As credenciais ficam em config/config.php (fora do Git) ou em variáveis
 * de ambiente (DB_HOST, DB_NAME, DB_USER, DB_PASS). Nunca coloque senhas
 * neste arquivo.
 */

$cf_config = [];

if (is_file(__DIR__ . "/config.php")) {
    $cf_config = require __DIR__ . "/config.php";
}

$host = getenv("DB_HOST") ?: ($cf_config["host"] ?? "localhost");
$dbname = getenv("DB_NAME") ?: ($cf_config["dbname"] ?? "mydb");
$username = getenv("DB_USER") ?: ($cf_config["username"] ?? "root");
$password = getenv("DB_PASS") ?: ($cf_config["password"] ?? "");

// Falhas retornam false (comportamento tradicional) em vez de exceções.
mysqli_report(MYSQLI_REPORT_OFF);

$conn = @new mysqli($host, $username, $password, $dbname);

if ($conn->connect_errno) {

    // O detalhe técnico vai só para o log; o visitante vê uma mensagem genérica.
    error_log("ClickFeira: falha na conexão com o banco (" . $conn->connect_errno . "): " . $conn->connect_error);

    http_response_code(503);
    header("Retry-After: 60");

    exit("O ClickFeira está temporariamente indisponível. Tente novamente em instantes.");
}

$conn->set_charset("utf8mb4");

unset($cf_config);
