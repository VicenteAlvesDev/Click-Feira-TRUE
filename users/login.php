<?php

require_once __DIR__ . "/../includes/bootstrap.php";
require_once "../config/database.php";

function escapar($valor) {
    return htmlspecialchars((string) ($valor ?? ""), ENT_QUOTES, "UTF-8");
}

// Quem já está logado vai direto para o início.
if (isset($_SESSION["id_usuario"])) {
    header("Location: ../index.php");
    exit;
}

$erro = "";
$email = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    csrf_exigir();

    $email = strtolower(trim((string) ($_POST["email"] ?? "")));
    $senha = (string) ($_POST["password"] ?? "");

    $ip = substr((string) ($_SERVER["REMOTE_ADDR"] ?? "0.0.0.0"), 0, 45);
    $hash_email = hash("sha256", $email);

    // Tabela de tentativas (criada automaticamente na primeira vez).
    if (empty($_SESSION["_tabela_tentativas"])) {

        $conn->query(
            "CREATE TABLE IF NOT EXISTS login_attempts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                ip VARCHAR(45) NOT NULL,
                email_hash CHAR(64) NOT NULL,
                attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_login_ip (ip, attempted_at),
                INDEX idx_login_email (email_hash, attempted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $_SESSION["_tabela_tentativas"] = true;
    }

    // Bloqueio temporário contra tentativas em massa (força bruta).
    $bloqueado = false;

    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(ip = ?), 0) AS por_ip,
                COALESCE(SUM(email_hash = ?), 0) AS por_email
         FROM login_attempts
         WHERE attempted_at > (NOW() - INTERVAL 15 MINUTE)"
    );

    if ($stmt) {

        $stmt->bind_param("ss", $ip, $hash_email);
        $stmt->execute();

        $contagem = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ((int) $contagem["por_ip"] >= 20 || (int) $contagem["por_email"] >= 6) {
            $bloqueado = true;
        }
    }

    if ($bloqueado) {

        $erro = "Muitas tentativas de acesso. Aguarde alguns minutos e tente novamente.";

    } elseif ($email === "" || $senha === "") {

        $erro = "Informe seu e-mail e sua senha.";

    } else {

        $stmt = $conn->prepare("SELECT id_user, password, user_type FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();

        $usuario = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($usuario && password_verify($senha, $usuario["password"])) {

            // Troca o ID da sessão ao entrar (evita fixação de sessão).
            session_regenerate_id(true);

            $_SESSION["id_usuario"] = (int) $usuario["id_user"];
            $_SESSION["tipo_usuario"] = $usuario["user_type"];
            $_SESSION["_csrf"] = bin2hex(random_bytes(32));

            // Atualiza o hash da senha se o algoritmo padrão do PHP evoluiu.
            if (password_needs_rehash($usuario["password"], PASSWORD_DEFAULT)) {

                $novo_hash = password_hash($senha, PASSWORD_DEFAULT);

                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id_user = ?");
                $stmt->bind_param("si", $novo_hash, $usuario["id_user"]);
                $stmt->execute();
                $stmt->close();
            }

            // Zera as falhas deste e-mail.
            $stmt = $conn->prepare("DELETE FROM login_attempts WHERE email_hash = ?");

            if ($stmt) {
                $stmt->bind_param("s", $hash_email);
                $stmt->execute();
                $stmt->close();
            }

            header("Location: ../index.php");
            exit;
        }

        // Registra a falha.
        $stmt = $conn->prepare("INSERT INTO login_attempts (ip, email_hash) VALUES (?, ?)");

        if ($stmt) {
            $stmt->bind_param("ss", $ip, $hash_email);
            $stmt->execute();
            $stmt->close();
        }

        // Limpeza ocasional de registros antigos.
        if (random_int(1, 50) === 1) {
            $conn->query("DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 2 DAY)");
        }

        $erro = "E-mail ou senha incorretos.";
    }
}

?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#174c32">
    <link rel="manifest" href="../manifest.json">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/img/favicon-32.png">
    <link rel="apple-touch-icon" href="../assets/img/apple-touch-icon.png">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="ClickFeira">
    <link rel="preload" as="image" href="../assets/img/banner-principal.webp" type="image/webp">

    <title>Entrar - ClickFeira Assaré</title>

    <link rel="preconnect" href="https://cdn-uicons.flaticon.com" crossorigin>
    <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/3.0.0/uicons-regular-rounded/css/uicons-regular-rounded.css">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;600;700&display=swap" rel="stylesheet">

    <style>

        :root {
            --verde: #79bf00;
            --verde-escuro: #174c32;
            --verde-claro: #ccff66;

            --amarelo: #f2c14e;
            --laranja: #e58b55;
            --azul-verde: #4f8f83;
            --creme: #fff3d9;

            --fundo: #f7f5ef;
            --branco: #ffffff;
            --texto: #29332d;
            --texto-suave: #747b72;
            --borda: #e8e4d9;
            --sombra: 0 4px 18px rgba(48, 55, 39, 0.07);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: "Kanit", Arial, sans-serif;
            background: var(--fundo);
            color: var(--texto);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        button,
        input {
            font: inherit;
        }

        h1, h2, h3 {
            font-family: Impact, Haettenschweiler, "Arial Narrow Bold", sans-serif;
            font-weight: 400;
            letter-spacing: 0.35px;
        }


        /* CABEÇALHO */

        .topo {
            background: var(--branco);
            border-bottom: 1px solid var(--borda);
        }

        .topo-principal {
            max-width: none;
            margin: auto;
            padding: 18px max(35px, calc((100% - 1280px) / 2));
            display: flex;
            align-items: center;
            gap: 35px;

            background:
                linear-gradient(
                    90deg,
                    rgba(6, 92, 0, 0.35),
                    rgba(6, 92, 0, 0.15)
                ),
                url("../assets/img/banner-principal.webp") center 48% / cover no-repeat;
        }

        .logo {
            display: flex;
            align-items: center;
            flex-shrink: 0;
        }

        .logo img {
            display: block;
            width: 205px;
            max-width: 100%;
            height: auto;
            object-fit: contain;
        }

        .pesquisa {
            flex: 1;
            display: flex;
            height: 46px;
            border: 1px solid var(--borda);
            border-radius: 9px;
            overflow: hidden;
            background: #fffdf8;
        }

        .pesquisa input {
            flex: 1;
            min-width: 0;
            border: none;
            outline: none;
            padding: 0 16px;
            background: transparent;
            font-family: Arial, sans-serif;
            font-size: 14px;
        }

        .pesquisa button {
            width: 55px;
            border: none;
            background: var(--verde);
            color: white;
            font-size: 19px;
            cursor: pointer;
        }

        .pesquisa button:hover {
            background: var(--verde-escuro);
        }

        .acoes-topo {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-shrink: 0;
        }

        .link-conta {
            font-size: 13px;
            color: white;
            font-weight: bold;
        }

        .botao-conta {
            padding: 11px 17px;
            border-radius: 8px;
            background: var(--amarelo);
            color: #49370d;
            font-size: 13px;
            font-weight: bold;
            box-shadow: 0 3px 9px rgba(242, 193, 78, 0.25);
            transition: 0.2s;
        }

        .botao-conta:hover {
            background: var(--creme);
        }


        /* NAVEGAÇÃO */

        .navegacao {
            border-top: 1px solid var(--borda);
        }

        .navegacao-conteudo {
            max-width: 1350px;
            margin: auto;
            padding: 0 35px;
            min-height: 48px;
            display: flex;
            align-items: center;
            gap: 30px;
        }

        .navegacao a {
            font-size: 13px;
            color: var(--texto-suave);
            font-weight: 600;
            transition: 0.2s;
        }

        .navegacao a:hover,
        .navegacao .ativo {
            color: var(--verde);
        }


        /* CONTEÚDO */

        .container {
            max-width: 1350px;
            width: 100%;
            flex: 1;
            margin: 35px auto;
            padding: 0 35px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .cartao-login {
            width: 100%;
            max-width: 1050px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            background: var(--branco);
            border: 1px solid var(--borda);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: var(--sombra);
        }


        /* PAINEL LATERAL */

        .painel {
            padding: 50px 42px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            color: white;
            background:
                linear-gradient(
                    rgba(23, 76, 50, 0.78),
                    rgba(23, 76, 50, 0.60)
                ),
                url("../assets/img/banner-principal.webp") center 48% / cover no-repeat;
        }

        .painel-etiqueta {
            display: inline-block;
            align-self: flex-start;
            background: rgba(204, 255, 102, 0.18);
            color: var(--verde-claro);
            border: 1px solid rgba(204, 255, 102, 0.28);
            padding: 7px 12px;
            border-radius: 20px;
            font-size: 12px;
            margin-bottom: 22px;
        }

        .painel h1 {
            font-size: 35px;
            line-height: 1.2;
            margin-bottom: 15px;
        }

        .painel p {
            color: rgba(255, 255, 255, 0.90);
            font-size: 15px;
            line-height: 1.6;
        }

        .painel-destaque {
            margin-top: 32px;
            padding-top: 22px;
            border-top: 1px solid rgba(255, 255, 255, 0.25);
            font-size: 14px;
            line-height: 1.7;
            color: rgba(255, 255, 255, 0.95);
        }


        /* FORMULÁRIO */

        .form-area {
            padding: 50px 46px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            min-width: 0;
        }

        .form-area h2 {
            font-size: 28px;
            margin-bottom: 8px;
        }

        .subtitulo {
            color: var(--texto-suave);
            font-size: 14px;
            margin-bottom: 28px;
        }

        .campo {
            margin-bottom: 19px;
            min-width: 0;
        }

        .campo label {
            display: block;
            font-size: 12px;
            font-weight: bold;
            color: var(--texto-suave);
            margin-bottom: 8px;
        }

        .campo input {
            width: 100%;
            height: 47px;
            padding: 0 14px;
            border: 1px solid var(--borda);
            border-radius: 7px;
            background: #fffdf8;
            color: var(--texto);
            font-size: 13px;
            outline: none;
            transition: 0.2s;
        }

        .campo input:focus {
            border-color: var(--verde);
            box-shadow: 0 0 0 3px rgba(121, 191, 0, 0.15);
        }

        .campo input::placeholder {
            color: #a1a69b;
        }

        .senha-container {
            position: relative;
        }

        .senha-container input {
            padding-right: 80px;
        }

        .mostrar-senha {
            position: absolute;
            right: 13px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: transparent;
            color: var(--verde-escuro);
            font-size: 12px;
            font-weight: bold;
            cursor: pointer;
        }

        .mostrar-senha:hover {
            color: var(--verde);
        }

        .erro {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            background: #fff0f0;
            color: #b00020;
            border: 1px solid #f3cccc;
            padding: 13px 15px;
            border-radius: 9px;
            font-size: 13px;
            line-height: 1.5;
            margin-bottom: 20px;
        }

        .erro i {
            margin-top: 1px;
        }

        .botao {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            width: 100%;
            height: 47px;
            border: none;
            border-radius: 7px;
            background: var(--verde);
            color: white;
            font-size: 14px;
            font-weight: bold;
            cursor: pointer;
            transition: 0.2s;
            margin-top: 8px;
        }

        .botao:hover {
            background: var(--verde-escuro);
            transform: translateY(-1px);
        }

        .cadastro {
            text-align: center;
            margin-top: 24px;
            color: var(--texto-suave);
            font-size: 13px;
        }

        .cadastro a {
            color: var(--verde-escuro);
            font-weight: bold;
        }

        .cadastro a:hover {
            color: var(--verde);
        }


        /* RODAPÉ */

        footer {
            margin-top: 0;
            padding: 30px 20px;
            background: var(--branco);
            border-top: 1px solid var(--borda);
            text-align: center;
            color: var(--texto-suave);
            font-size: 12px;
        }


        /* RESPONSIVIDADE */

        @media (max-width: 850px) {

            .topo-principal {
                flex-wrap: wrap;
                gap: 15px;
                padding: 17px 20px;
            }

            .pesquisa {
                order: 3;
                flex-basis: 100%;
            }

            .acoes-topo {
                margin-left: auto;
            }

            .navegacao-conteudo {
                padding: 15px 20px;
                flex-wrap: wrap;
                gap: 17px;
            }

            .container {
                padding: 0 20px;
            }

            .cartao-login {
                grid-template-columns: 1fr;
                max-width: 520px;
            }

            .painel {
                padding: 35px 30px;
            }

            .painel h1 {
                font-size: 28px;
            }

            .painel-destaque {
                display: none;
            }

            .form-area {
                padding: 36px 30px;
            }

        }

        @media (max-width: 550px) {

            .container {
                margin-top: 25px;
            }

            .painel {
                padding: 30px 23px;
            }

            .painel h1 {
                font-size: 27px;
            }

            .form-area {
                padding: 30px 22px;
            }

            .form-area h2 {
                font-size: 24px;
            }

            .navegacao-conteudo {
                gap: 13px;
            }

            .navegacao a {
                font-size: 12px;
            }

            .acoes-topo {
                gap: 10px;
            }

            .botao-conta {
                padding: 10px;
            }

            /* HEADER MOBILE */
            .topo-principal {
                display: grid;
                grid-template-columns: minmax(0, 1fr) auto;
                grid-template-areas:
                    "logo acoes"
                    "pesquisa pesquisa";
                align-items: center;
                gap: 14px;
                padding: 14px 16px;
            }

            .logo {
                grid-area: logo;
                min-width: 0;
            }

            .logo img {
                width: 155px;
                max-width: 100%;
            }

            .pesquisa {
                grid-area: pesquisa;
                order: 0;
                flex-basis: auto;
                width: 100%;
                height: 44px;
            }

            .pesquisa input {
                font-size: 13px;
                padding: 0 12px;
            }

            .pesquisa button {
                width: 46px;
            }

            .acoes-topo {
                grid-area: acoes;
                margin-left: 0;
                gap: 9px;
                justify-self: end;
            }

            .foto-conta {
                width: 40px;
                height: 40px;
            }

            .link-conta {
                font-size: 12px;
            }

            .botao-conta {
                padding: 9px 11px;
                font-size: 12px;
                white-space: nowrap;
            }

            /* NAVEGAÇÃO MOBILE */

            .navegacao-conteudo {
                display: flex;
                flex-wrap: nowrap;
                overflow-x: auto;
                overflow-y: hidden;
                gap: 21px;
                min-height: 48px;
                padding: 13px 16px;
                white-space: nowrap;
                scrollbar-width: none;
                -webkit-overflow-scrolling: touch;
            }

            .navegacao-conteudo::-webkit-scrollbar {
                display: none;
            }

            .navegacao a {
                flex-shrink: 0;
                font-size: 12px;
            }

        }

    </style>

</head>

<body>

    <!-- CABEÇALHO -->

    <header class="topo">

        <div class="topo-principal">

            <a href="../index.php" class="logo" aria-label="ClickFeira Assaré - Página inicial">
                <img src="../assets/img/logo-clickfeira.webp" alt="ClickFeira Assaré" width="205" height="51" decoding="async" fetchpriority="high">
            </a>

            <form action="../products/list.php" method="GET" class="pesquisa">

                <input
                    type="text"
                    name="pesquisa"
                    placeholder="O que você está procurando?"
                >

                <button type="submit" aria-label="Pesquisar">
                    <i class="fi fi-rr-search"></i>
                </button>

            </form>

            <div class="acoes-topo">

                <a href="login.php" class="link-conta">
                    Entrar
                </a>

                <a href="register.php" class="botao-conta">
                    Criar conta
                </a>

            </div>

        </div>

        <nav class="navegacao">

            <div class="navegacao-conteudo">

                <a href="../index.php">Início</a>
                <a href="../products/list.php">Todos os produtos</a>
                <a href="register.php">Criar conta</a>

            </div>

        </nav>

    </header>


    <main class="container">

        <div class="cartao-login">

            <!-- PAINEL LATERAL -->

            <section class="painel">

                <span class="painel-etiqueta">
                    SUA FEIRA, AGORA MAIS PERTO
                </span>

                <h1>Conectando você ao melhor da feira.</h1>

                <p>
                    Encontre produtos, conheça vendedores locais
                    e faça suas encomendas de maneira simples e prática.
                </p>

                <div class="painel-destaque">
                    Valorize os produtores locais e fortaleça
                    o comércio da nossa região.
                </div>

            </section>


            <!-- FORMULÁRIO -->

            <section class="form-area">

                <h2>Bem-vindo de volta!</h2>

                <p class="subtitulo">
                    Entre na sua conta para continuar.
                </p>

                <?php if ($erro !== ""): ?>

                    <div class="erro">
                        <i class="fi fi-rr-exclamation"></i>
                        <span><?= escapar($erro) ?></span>
                    </div>

                <?php endif; ?>

                <form method="POST">
<?= csrf_campo() ?>

                    <div class="campo">

                        <label for="email">E-mail</label>

                        <input
                            type="email"
                            id="email"
                            name="email"
                            placeholder="Digite seu e-mail"
                            value="<?= escapar($email) ?>"
                            autocomplete="email"
                            required
                        >

                    </div>

                    <div class="campo">

                        <label for="password">Senha</label>

                        <div class="senha-container">

                            <input
                                type="password"
                                id="password"
                                name="password"
                                placeholder="Digite sua senha"
                                autocomplete="current-password"
                                required
                            >

                            <button
                                type="button"
                                class="mostrar-senha"
                                id="botaoSenha"
                                onclick="alternarSenha()"
                            >
                                Mostrar
                            </button>

                        </div>

                    </div>

                    <button type="submit" class="botao">
                        <i class="fi fi-rr-enter"></i>
                        Entrar na minha conta
                    </button>

                </form>

                <div class="cadastro">
                    Ainda não tem uma conta?
                    <a href="register.php">Criar conta</a>
                </div>

            </section>

        </div>

    </main>


    <footer>

        <p>
            © <?= date("Y") ?> ClickFeira Assaré.
            Conectando vendedores e consumidores.
        </p>

    </footer>


    <script>

        function alternarSenha() {

            const campo = document.getElementById("password");
            const botao = document.getElementById("botaoSenha");

            if (campo.type === "password") {

                campo.type = "text";
                botao.textContent = "Ocultar";

            } else {

                campo.type = "password";
                botao.textContent = "Mostrar";

            }

        }

    </script>

    <script src="../assets/js/pwa.js" defer></script>

</body>

</html>