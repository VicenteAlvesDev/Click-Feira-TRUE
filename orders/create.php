<?php

require_once __DIR__ . "/../includes/bootstrap.php";

if (
    !isset($_SESSION["id_usuario"]) ||
    ($_SESSION["tipo_usuario"] ?? "") !== "customer"
) {
    erro_pagina(
        "Acesso restrito",
        "Entre com uma conta de cliente para fazer encomendas.",
        403,
        "../users/login.php",
        "Entrar"
    );
}

require_once "../config/database.php";

// Esta página só recebe o formulário de encomenda.
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../products/list.php");
    exit;
}

csrf_exigir();

$customer_id = (int) $_SESSION["id_usuario"];

$product_id = filter_var($_POST["product_id"] ?? null, FILTER_VALIDATE_INT);
$quantity = filter_var($_POST["quantity"] ?? null, FILTER_VALIDATE_INT);

if (!$product_id || $product_id < 1 || !$quantity || $quantity < 1 || $quantity > 100000) {
    erro_pagina("Dados inválidos", "Informe uma quantidade válida para o produto.", 400);
}

$conn->begin_transaction();

try {

    // 1. Trava a linha do produto para ninguém comprar o mesmo estoque ao mesmo tempo.
    $stmt = $conn->prepare(
        "SELECT price, user_id, quantity
         FROM products
         WHERE id_product = ?
         FOR UPDATE"
    );

    if (!$stmt) {
        throw new Exception("prepare produto: " . $conn->error);
    }

    $stmt->bind_param("i", $product_id);
    $stmt->execute();

    $produto = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$produto) {
        throw new RuntimeException("Produto não encontrado.");
    }

    if ($quantity > (int) $produto["quantity"]) {
        throw new RuntimeException("A quantidade solicitada é maior que o estoque disponível.");
    }

    $unit_price = $produto["price"];
    $seller_id = (int) $produto["user_id"];

    // 2. Baixa o estoque (e confere que realmente baixou).
    $stmt = $conn->prepare(
        "UPDATE products
         SET quantity = quantity - ?
         WHERE id_product = ? AND quantity >= ?"
    );

    if (!$stmt) {
        throw new Exception("prepare estoque: " . $conn->error);
    }

    $stmt->bind_param("iii", $quantity, $product_id, $quantity);

    if (!$stmt->execute() || $stmt->affected_rows !== 1) {
        $stmt->close();
        throw new RuntimeException("Este produto acabou de ficar sem estoque suficiente.");
    }

    $stmt->close();

    // 3. Cria a encomenda.
    $stmt = $conn->prepare(
        "INSERT INTO orders (customer_id, seller_id, order_date, status)
         VALUES (?, ?, NOW(), 'PENDING')"
    );

    if (!$stmt) {
        throw new Exception("prepare pedido: " . $conn->error);
    }

    $stmt->bind_param("ii", $customer_id, $seller_id);

    if (!$stmt->execute()) {
        throw new Exception("insert pedido: " . $stmt->error);
    }

    $order_id = $conn->insert_id;
    $stmt->close();

    // 4. Adiciona o item com o preço do momento.
    $stmt = $conn->prepare(
        "INSERT INTO order_items (order_id, product_id, quantity, unit_price)
         VALUES (?, ?, ?, ?)"
    );

    if (!$stmt) {
        throw new Exception("prepare item: " . $conn->error);
    }

    $stmt->bind_param("iiid", $order_id, $product_id, $quantity, $unit_price);

    if (!$stmt->execute()) {
        throw new Exception("insert item: " . $stmt->error);
    }

    $stmt->close();

    $conn->commit();

    header("Location: my_orders.php");
    exit;

} catch (Throwable $e) {

    $conn->rollback();

    if ($e instanceof RuntimeException) {
        erro_pagina("Não foi possível encomendar", $e->getMessage(), 409, "../products/list.php", "Voltar aos produtos");
    }

    error_log("ClickFeira create.php: " . $e->getMessage());

    erro_pagina(
        "Algo deu errado",
        "Não foi possível registrar sua encomenda agora. Tente novamente em instantes.",
        500,
        "../products/list.php",
        "Voltar aos produtos"
    );
}
