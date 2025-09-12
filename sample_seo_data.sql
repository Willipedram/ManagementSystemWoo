INSERT INTO msw_products_seo (product_id, product_name, category_id, impressions, clicks, ctr, avg_position, indexed_status, last_updated) VALUES
(1, 'Sample Product A', 10, 100, 10, 0.1, 5, 'indexed', NOW()),
(2, 'Sample Product B', 20, 50, 5, 0.1, 8, 'indexed', NOW());

INSERT INTO msw_product_keywords (product_id, keyword, impressions, clicks, ctr, avg_position, last_updated) VALUES
(1, 'sample keyword a1', 40, 4, 0.1, 6, NOW()),
(1, 'sample keyword a2', 60, 6, 0.1, 4, NOW()),
(2, 'sample keyword b1', 50, 5, 0.1, 8, NOW());

INSERT INTO msw_product_trends (product_id, date, impressions, clicks, ctr, avg_position) VALUES
(1, '2024-01-01', 100, 10, 0.1, 5),
(1, '2024-01-02', 120, 12, 0.1, 4),
(2, '2024-01-01', 50, 5, 0.1, 8);
