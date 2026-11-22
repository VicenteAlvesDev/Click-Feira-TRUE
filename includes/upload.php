<?php

/*
 * Upload seguro de imagens.
 *
 * Funciona de dois jeitos:
 *  1. Se a extensão GD do PHP estiver ativa: a imagem é redimensionada e
 *     regravada (fica mais leve e sem metadados).
 *  2. Se a GD NÃO estiver ativa (ou falhar): a imagem é validada e salva
 *     como foi enviada, exatamente como o sistema fazia antes.
 *
 * Nos dois casos o nome do arquivo é aleatório e só JPG, PNG e WEBP passam.
 *
 * Retorno: ["ok" => bool, "arquivo" => nome salvo|null, "erro" => mensagem|null]
 */

// Salva o arquivo enviado sem processar (validação já feita pelo chamador).
function guardar_imagem_original(string $temporario, string $pasta, string $mime) {

    $extensoes = [
        "image/jpeg" => "jpg",
        "image/png" => "png",
        "image/webp" => "webp"
    ];

    if (!isset($extensoes[$mime])) {
        return null;
    }

    $nome = bin2hex(random_bytes(16)) . "." . $extensoes[$mime];
    $caminho = rtrim($pasta, "/\\") . DIRECTORY_SEPARATOR . $nome;

    if (!@move_uploaded_file($temporario, $caminho)) {
        error_log("ClickFeira: não foi possível mover o upload para " . $caminho);
        return null;
    }

    @chmod($caminho, 0644);

    return $nome;
}

function salvar_imagem_upload(array $arquivo, string $pasta, int $lado_maximo = 1200, int $qualidade = 82) {

    $falha = function ($mensagem) {
        return ["ok" => false, "arquivo" => null, "erro" => $mensagem];
    };

    $codigo = $arquivo["error"] ?? UPLOAD_ERR_NO_FILE;

    if ($codigo === UPLOAD_ERR_INI_SIZE || $codigo === UPLOAD_ERR_FORM_SIZE) {
        return $falha("A imagem deve ter no máximo 2 MB.");
    }

    if ($codigo !== UPLOAD_ERR_OK) {
        return $falha("Erro ao enviar a imagem.");
    }

    $temporario = $arquivo["tmp_name"] ?? "";

    if ($temporario === "" || !is_uploaded_file($temporario)) {
        return $falha("Erro ao enviar a imagem.");
    }

    if (($arquivo["size"] ?? 0) > 2 * 1024 * 1024) {
        return $falha("A imagem deve ter no máximo 2 MB.");
    }

    $info = @getimagesize($temporario);

    if ($info === false) {
        return $falha("Formato inválido. Use JPG, PNG ou WEBP.");
    }

    $largura = (int) $info[0];
    $altura = (int) $info[1];
    $mime = $info["mime"] ?? "";

    if (!in_array($mime, ["image/jpeg", "image/png", "image/webp"], true)) {
        return $falha("Formato inválido. Use JPG, PNG ou WEBP.");
    }

    if ($largura < 1 || $altura < 1 || ($largura * $altura) > 16000000) {
        return $falha("A imagem é grande demais. Reduza a resolução e tente novamente.");
    }

    // Sem GD: salva como foi enviada (comportamento anterior).
    $tem_gd = extension_loaded("gd") && function_exists("imagecreatetruecolor");

    if ($tem_gd) {
        $otimizada = otimizar_imagem_gd($temporario, $mime, $largura, $altura, $pasta, $lado_maximo, $qualidade);

        if ($otimizada !== null) {
            return ["ok" => true, "arquivo" => $otimizada, "erro" => null];
        }
    }

    $nome = guardar_imagem_original($temporario, $pasta, $mime);

    if ($nome === null) {
        return $falha("Não foi possível salvar a imagem.");
    }

    return ["ok" => true, "arquivo" => $nome, "erro" => null];
}

// Redimensiona e regrava com a GD. Retorna o nome do arquivo ou null se algo falhar.
function otimizar_imagem_gd(string $temporario, string $mime, int $largura, int $altura, string $pasta, int $lado_maximo, int $qualidade) {

    switch ($mime) {
        case "image/jpeg":
            $origem = function_exists("imagecreatefromjpeg") ? @imagecreatefromjpeg($temporario) : false;
            break;

        case "image/png":
            $origem = function_exists("imagecreatefrompng") ? @imagecreatefrompng($temporario) : false;
            break;

        case "image/webp":
            $origem = function_exists("imagecreatefromwebp") ? @imagecreatefromwebp($temporario) : false;
            break;

        default:
            return null;
    }

    if (!$origem) {
        return null;
    }

    // Corrige a rotação de fotos de celular (EXIF).
    if ($mime === "image/jpeg" && function_exists("exif_read_data")) {

        $exif = @exif_read_data($temporario);
        $orientacao = (int) ($exif["Orientation"] ?? 1);

        $angulos = [3 => 180, 6 => -90, 8 => 90];

        if (isset($angulos[$orientacao])) {

            $girada = @imagerotate($origem, $angulos[$orientacao], 0);

            if ($girada) {
                $origem = $girada;
                $largura = imagesx($origem);
                $altura = imagesy($origem);
            }
        }
    }

    $escala = min(1, $lado_maximo / max($largura, $altura));
    $nova_largura = max(1, (int) round($largura * $escala));
    $nova_altura = max(1, (int) round($altura * $escala));

    $destino_img = imagecreatetruecolor($nova_largura, $nova_altura);

    if (!$destino_img) {
        return null;
    }

    $usa_webp = function_exists("imagewebp");

    if ($usa_webp) {
        imagealphablending($destino_img, false);
        imagesavealpha($destino_img, true);
        imagefill($destino_img, 0, 0, imagecolorallocatealpha($destino_img, 255, 255, 255, 127));
    } else {
        imagefill($destino_img, 0, 0, imagecolorallocate($destino_img, 255, 255, 255));
    }

    imagecopyresampled($destino_img, $origem, 0, 0, 0, 0, $nova_largura, $nova_altura, $largura, $altura);

    $extensao = $usa_webp ? "webp" : "jpg";
    $nome = bin2hex(random_bytes(16)) . "." . $extensao;
    $caminho = rtrim($pasta, "/\\") . DIRECTORY_SEPARATOR . $nome;

    $salvou = $usa_webp
        ? @imagewebp($destino_img, $caminho, $qualidade)
        : @imagejpeg($destino_img, $caminho, $qualidade);

    if (!$salvou) {
        @unlink($caminho);
        return null;
    }

    @chmod($caminho, 0644);

    return $nome;
}

// Remove com segurança um arquivo de upload antigo (apenas dentro da pasta).
function remover_upload($nome_arquivo, string $pasta) {

    if (empty($nome_arquivo)) {
        return;
    }

    $caminho = rtrim($pasta, "/\\") . DIRECTORY_SEPARATOR . basename((string) $nome_arquivo);

    if (is_file($caminho)) {
        @unlink($caminho);
    }
}