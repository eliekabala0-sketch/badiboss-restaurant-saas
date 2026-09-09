-- Classification additive : aucun produit ni historique existant n'est supprimé.
-- Les produits existants restent des plats jusqu'à leur reclassement explicite.
SET @menu_product_type_sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'menu_items' AND COLUMN_NAME = 'product_type') = 0,
    'ALTER TABLE menu_items ADD COLUMN product_type ENUM("ARTICLE", "PLAT") NOT NULL DEFAULT "PLAT" AFTER price',
    'SELECT 1'
);
PREPARE menu_product_type_stmt FROM @menu_product_type_sql;
EXECUTE menu_product_type_stmt;
DEALLOCATE PREPARE menu_product_type_stmt;
