<?php

require_once __DIR__ . "/../includes/bootstrap.php";

if (
    !isset($_SESSION["id_usuario"]) ||
    ($_SESSION["tipo_usuario"] ?? "") !== "customer"
) {
    erro_pagina("Acesso restrito", "Esta área é apenas para clientes.", 403, "../users/login.php", "Entrar");
}

require_once "../config/database.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: my_orders.php");
    exit;
}

csrf_exigir();

$customer_id = (int) $_SESSION["id_usuario"];
$order_id = filter_var($_POST["order_id"] ?? null, FILTER_VALIDATE_INT);

if (!$order_id || $order_id < 1) {
    erro_pagina("Dados inválidos", "Encomenda inválida.", 400, "my_orders.php", "Minhas encomendas");
}

$conn->begin_transaction();

try {

    // Confere se a encomenda é do cliente e ainda está pendente (e trava a linha).
    $stmt = $conn->prepare(
        "SELECT id_order
         FROM orders
         WHERE id_order = ? AND customer_id = ? AND status = 'PENDING'
         FOR UPDATE"
    );

    if (!$stmt) {
        throw new Exception("prepare pedido: " . $conn->error);
    }

    $stmt->bind_param("ii", $order_id, $customer_id);
    $stmt->execute();

    $existe = $stmt->get_result()->num_rows === 1;
    $stmt->close();

    if (!$existe) {
        throw new RuntimeException("Encomenda não encontrada ou não pode mais ser cancelada.");
    }

    // Devolve os itens ao estoque.
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

    // Marca como cancelada.
    $stmt = $conn->prepare(
        "UPDATE orders
         SET status = 'CANCELLED'
         WHERE id_order = ? AND customer_id = ? AND status = 'PENDING'"
    );

    if (!$stmt) {
        throw new Exception("prepare status: " . $conn->error);
    }

    $stmt->bind_param("ii", $order_id, $customer_id);

    if (!$stmt->execute() || $stmt->affected_rows !== 1) {
        $stmt->close();
        throw new RuntimeException("Não foi possível cancelar a encomenda.");
    }

    $stmt->close();

    $conn->commit();

    header("Location: my_orders.php");
    exit;

} catch (Throwable $e) {

    $conn->rollback();

    if ($e instanceof RuntimeException) {
        erro_pagina("Não foi possível cancelar", $e->getMessage(), 409, "my_orders.php", "Minhas encomendas");
    }

    error_log("ClickFeira cancel.php: " . $e->getMessage());

    erro_pagina("Algo deu errado", "Tente novamente em instantes.", 500, "my_orders.php", "Minhas encomendas");
}
