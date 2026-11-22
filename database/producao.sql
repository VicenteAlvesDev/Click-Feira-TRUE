-- ClickFeira Assaré - ajustes de banco para PRODUÇÃO
-- Rode UMA vez no phpMyAdmin (aba SQL) com o banco do ClickFeira selecionado.
-- Se algum comando avisar "Duplicate key name" / "already exists", é só ignorar: o item já existe.

-- 1) E-mail único (impede duas contas com o mesmo e-mail).
--    Se der erro de duplicado, existem contas repetidas: apague as duplicatas e rode de novo.
ALTER TABLE users ADD UNIQUE INDEX uq_users_email (email);

-- 2) Índices que aceleram as telas mais usadas.
ALTER TABLE products ADD INDEX idx_products_category (category_id);
ALTER TABLE products ADD INDEX idx_products_user_id (user_id, id_product);
ALTER TABLE orders   ADD INDEX idx_orders_seller_status (seller_id, status);
ALTER TABLE orders   ADD INDEX idx_orders_seller_date (seller_id, order_date);
ALTER TABLE orders   ADD INDEX idx_orders_customer_date (customer_id, order_date);
ALTER TABLE order_items ADD INDEX idx_items_order (order_id);
ALTER TABLE order_items ADD INDEX idx_items_product (product_id);

-- 3) Controle de tentativas de login (o sistema também cria sozinho, mas já deixe pronto).
CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    email_hash CHAR(64) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_ip (ip, attempted_at),
    INDEX idx_login_email (email_hash, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4) USUÁRIO DO BANCO SÓ PARA O SITE (não use o root em produção).
--    Troque a senha abaixo e depois coloque os mesmos dados em config/config.php.
--    Substitua NOME_DO_BANCO pelo nome real do banco.
--
-- CREATE USER 'clickfeira'@'localhost' IDENTIFIED BY 'TROQUE_POR_UMA_SENHA_FORTE';
-- GRANT SELECT, INSERT, UPDATE, DELETE, CREATE ON NOME_DO_BANCO.* TO 'clickfeira'@'localhost';
-- FLUSH PRIVILEGES;
