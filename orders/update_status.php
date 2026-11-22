<?php

require_once __DIR__ . "/../includes/bootstrap.php";

if (
    !isset($_SESSION["id_usuario"]) ||
    ($_SESSION["tipo_usuario"] ?? "") !== "seller"
) {
    erro_pagina("Acesso restrito", "Esta área é apenas para vendedores.", 403, "../users/login.php", "Entrar");
}

require_once "../config/database.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: list.php");
    exit;
}

csrf_exigir();

$seller_id = (int) $_SESSION["id_usuario"];
$order_id = filter_var($_POST["order_id"] ?? null, FILTER_VALIDATE_INT);
$novo_status = (string) ($_POST["status"] ?? "");

// Transições permitidas: status atual => novos status possíveis.
$transicoes = [
    "PENDING" => ["ACCEPTED", "REJECTED", "CANCELLED"],
    "ACCEPTED" => ["COMPLETED"]
];

if (!$order_id || $order_id < 1) {
    erro_pagina("Dados inválidos", "Encomenda inválida.", 400, "list.php", "Encomendas recebidas");
}

$conn->begin_transaction();

try {

    // Trava a encomenda do vendedor para ninguém alterá-la ao mesmo tempo.
    $stmt = $conn->prepare(
        "SELECT status
         FROM orders
         WHERE id_order = ? AND seller_id = ?
         FOR UPDATE"
    );

    if (!$stmt) {
        throw new Exception("prepare pedido: " . $conn->error);
    }

    $stmt->bind_param("ii", $order_id, $seller_id);
    $stmt->execute();

    $encomenda = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$encomenda) {
        throw new RuntimeException("Encomenda não encontrada.");
    }

    $status_atual = $encomenda["status"];

    if (
        !isset($transicoes[$status_atual]) ||
        !in_array($novo_status, $transicoes[$status_atual], true)
    ) {
        throw new RuntimeException("Esta encomenda não pode mais mudar para esse status.");
    }

    // Recusada ou cancelada: os produtos voltam para o estoque.
    if ($novo_status === "REJECTED" || $novo_status === "CANCELLED") {

        $stmt = $conn->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");

        if (!$stmt) {
            throw new Exception("prepare itens: " . $conn->error);
        }

        $stmt->bind_param("i", $order_id);
        $stmt->execute();

        $itens = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $stmt_estoque = $conn->prepare("UPDATE products SET quantity = quantity + ? WHERE id_product = ?");

        if (!$stmt_estoque) {
            throw new Exception("prepare estoque: " . $conn->error);
        }

        foreach ($itens as $item) {

            $quantidade = (int) $item["quantity"];
            $produto_id = (int) $item["product_id"];

            $stmt_estoque->bind_param("ii", $quantidade, $produto_id);

            if (!$stmt_estoque->execute()) {
                throw new Exception("estoque: " . $stmt_estoque->error);
            }
        }

        $stmt_estoque->close();
    }

    $stmt = $conn->prepare(
        "UPDATE orders
         SET status = ?
         WHERE id_order = ? AND seller_id = ? AND status = ?"
    );

    if (!$stmt) {
        throw new Exception("prepare status: " . $conn->error);
    }

    $stmt->bind_param("siis", $novo_status, $order_id, $seller_id, $status_atual);

    if (!$stmt->execute() || $stmt->affected_rows !== 1) {
        $stmt->close();
        throw new RuntimeException("A encomenda não pôde ser atualizada.");
    }

    $stmt->close();

    $conn->commit();

    header("Location: list.php");
    exit;

} catch (Throwable $e) {

    $conn->rollback();

    if ($e instanceof RuntimeException) {
        erro_pagina("Não foi possível atualizar", $e->getMessage(), 409, "list.php", "Encomendas recebidas");
    }

    error_log("ClickFeira update_status.php: " . $e->getMessage());

    erro_pagina("Algo deu errado", "Tente novamente em instantes.", 500, "list.php", "Encomendas recebidas");
}
