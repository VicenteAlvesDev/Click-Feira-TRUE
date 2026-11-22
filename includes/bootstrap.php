<?php

/*
 * ClickFeira - bootstrap compartilhado por todas as páginas.
 *
 * Responsável por:
 *  - esconder erros do visitante (eles vão para o log do servidor);
 *  - iniciar a sessão com cookie seguro;
 *  - enviar cabeçalhos de segurança;
 *  - oferecer proteção CSRF e uma página de erro padrão.
 */

if (defined("CF_BOOTSTRAP")) {
    return;
}

define("CF_BOOTSTRAP", true);

// Erros nunca aparecem para o visitante. Para depurar localmente,
// defina a variável de ambiente CF_DEBUG=1.
error_reporting(E_ALL);
ini_set("log_errors", "1");
ini_set("display_errors", getenv("CF_DEBUG") === "1" ? "1" : "0");

$cf_https = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off")
    || (($_SERVER["HTTP_X_FORWARDED_PROTO"] ?? "") === "https")
    || (($_SERVER["SERVER_PORT"] ?? "") === "443");

define("CF_HTTPS", $cf_https);

// Sessão com cookie seguro.
if (session_status() === PHP_SESSION_NONE) {

    ini_set("session.use_strict_mode", "1");
    ini_set("session.use_only_cookies", "1");
    ini_set("session.use_trans_sid", "0");
    ini_set("session.gc_maxlifetime", "28800");

    session_set_cookie_params([
        "lifetime" => 0,
        "path" => "/",
        "secure" => CF_HTTPS,
        "httponly" => true,
        "samesite" => "Lax"
    ]);

    session_name("CLICKFEIRA");
    session_start();

    // Encerra sessões inativas por mais de 4 horas.
    $agora = time();

    if (isset($_SESSION["_ultimo_acesso"]) && ($agora - $_SESSION["_ultimo_acesso"]) > 14400) {
        $_SESSION = [];
        session_regenerate_id(true);
    }

    $_SESSION["_ultimo_acesso"] = $agora;
}

// Cabeçalhos de segurança.
if (!headers_sent()) {

    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: DENY");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()");

    header(
        "Content-Security-Policy: " .
        "default-src 'self'; " .
        "script-src 'self' 'unsafe-inline'; " .
        "style-src 'self' 'unsafe-inline' https://cdn-uicons.flaticon.com https://fonts.googleapis.com; " .
        "font-src 'self' data: https://cdn-uicons.flaticon.com https://fonts.gstatic.com; " .
        "img-src 'self' data: blob:; " .
        "connect-src 'self'; " .
        "manifest-src 'self'; " .
        "worker-src 'self'; " .
        "object-src 'none'; " .
        "base-uri 'self'; " .
        "form-action 'self'; " .
        "frame-ancestors 'none'"
    );

    if (CF_HTTPS) {
        header("Strict-Transport-Security: max-age=31536000");
    }

    // Páginas com dados do usuário não devem ficar em cache compartilhado.
    header("Cache-Control: private, no-cache");
}

// Proteção CSRF.

function csrf_token() {

    if (empty($_SESSION["_csrf"])) {
        $_SESSION["_csrf"] = bin2hex(random_bytes(32));
    }

    return $_SESSION["_csrf"];
}

function csrf_campo() {

    return '<input type="hidden" name="csrf_token" value="' .
        htmlspecialchars(csrf_token(), ENT_QUOTES, "UTF-8") . '">';
}

function csrf_valido() {

    $enviado = $_POST["csrf_token"] ?? "";

    if (!is_string($enviado) || $enviado === "" || empty($_SESSION["_csrf"])) {
        return false;
    }

    return hash_equals($_SESSION["_csrf"], $enviado);
}

function csrf_exigir() {

    if (!csrf_valido()) {
        erro_pagina(
            "Sessão expirada",
            "Não foi possível confirmar sua solicitação. Volte à página anterior, atualize-a e tente novamente.",
            419
        );
    }
}

// Página de erro padrão (visual do ClickFeira).

function erro_pagina($titulo, $mensagem, $codigo = 400, $link = null, $texto_link = "Voltar") {

    if (!headers_sent()) {
        http_response_code($codigo);
    }

    $titulo = htmlspecialchars((string) $titulo, ENT_QUOTES, "UTF-8");
    $mensagem = htmlspecialchars((string) $mensagem, ENT_QUOTES, "UTF-8");
    $texto_link = htmlspecialchars((string) $texto_link, ENT_QUOTES, "UTF-8");

    $href = $link !== null
        ? htmlspecialchars((string) $link, ENT_QUOTES, "UTF-8")
        : "javascript:history.back()";

    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">' .
        '<meta name="viewport" content="width=device-width, initial-scale=1.0">' .
        '<meta name="theme-color" content="#174c32">' .
        '<title>' . $titulo . ' - ClickFeira Assaré</title><style>' .
        '*{box-sizing:border-box;margin:0;padding:0}' .
        'body{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:25px;' .
        'background:#f7f5ef;color:#29332d;font-family:Arial,sans-serif;text-align:center}' .
        '.cartao{width:100%;max-width:440px;background:#fff;border:1px solid #e8e4d9;border-radius:16px;' .
        'padding:38px 28px;box-shadow:0 4px 18px rgba(48,55,39,.07)}' .
        'h1{font-size:24px;color:#174c32;margin-bottom:12px}' .
        'p{color:#747b72;font-size:15px;line-height:1.6;margin-bottom:26px}' .
        'a{display:inline-block;padding:13px 24px;border-radius:8px;background:#79bf00;color:#fff;' .
        'font-weight:bold;text-decoration:none;font-size:14px}a:hover{background:#174c32}' .
        '</style></head><body><main class="cartao"><h1>' . $titulo . '</h1><p>' . $mensagem . '</p>' .
        '<a href="' . $href . '">' . $texto_link . '</a></main></body></html>';

    exit;
}
