<?php
/**
 * ProductSEO helper for managing custom SEO tables.
 */
class ProductSEO {
    private $db;
    private $prefix;

    public function __construct($db, $prefix) {
        $this->db = $db;
        $this->prefix = $prefix;
    }

    /**
     * Create required tables if they do not exist.
     */
    public function create_tables() {
        $this->db->query("CREATE TABLE IF NOT EXISTS {$this->prefix}msw_products_seo (
            product_id BIGINT PRIMARY KEY,
            product_name VARCHAR(255),
            category_id BIGINT,
            impressions INT DEFAULT 0,
            clicks INT DEFAULT 0,
            ctr FLOAT DEFAULT 0,
            avg_position FLOAT DEFAULT 0,
            indexed_status ENUM('indexed','noindex','blocked','canonical_error') DEFAULT 'noindex',
            last_updated DATETIME
        )");

        $this->db->query("CREATE TABLE IF NOT EXISTS {$this->prefix}msw_product_keywords (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            product_id BIGINT,
            keyword VARCHAR(255),
            impressions INT DEFAULT 0,
            clicks INT DEFAULT 0,
            ctr FLOAT DEFAULT 0,
            avg_position FLOAT DEFAULT 0,
            last_updated DATETIME,
            KEY prod_idx (product_id)
        )");

        $this->db->query("CREATE TABLE IF NOT EXISTS {$this->prefix}msw_product_trends (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            product_id BIGINT,
            date DATE,
            impressions INT DEFAULT 0,
            clicks INT DEFAULT 0,
            ctr FLOAT DEFAULT 0,
            avg_position FLOAT DEFAULT 0,
            KEY prod_idx (product_id)
        )");
    }

    /**
     * Insert placeholders for all WooCommerce products.
     */
    public function seed_products() {
        if (!function_exists('wc_get_products')) {
            return;
        }
        $products = wc_get_products(['limit' => -1]);
        foreach ($products as $product) {
            $id = $product->get_id();
            $name = $product->get_name();
            $cats = $product->get_category_ids();
            $cat = !empty($cats) ? array_shift($cats) : 0;
            $stmt = $this->db->prepare("REPLACE INTO {$this->prefix}msw_products_seo (product_id,product_name,category_id,indexed_status,last_updated) VALUES (?,?,?,?,NOW())");
            $status = 'noindex';
            $stmt->bind_param('isis', $id, $name, $cat, $status);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Update metrics for a set of products.
     * Here we accept arrays of metrics from GSC and merge them.
     */
    public function update_metrics($metrics) {
        foreach ($metrics as $row) {
            $stmt = $this->db->prepare("UPDATE {$this->prefix}msw_products_seo SET impressions=?,clicks=?,ctr=?,avg_position=?,indexed_status=?,last_updated=NOW() WHERE product_id=?");
            $stmt->bind_param('iiddsi', $row['impressions'], $row['clicks'], $row['ctr'], $row['avg_position'], $row['status'], $row['product_id']);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Get dashboard summary counts.
     */
    public function summary() {
        $summary = [
            'total' => 0,
            'indexed' => 0,
            'unindexed' => 0,
            'last_update' => null
        ];
        $res = $this->db->query("SELECT COUNT(*) total, SUM(indexed_status='indexed') indexed, SUM(indexed_status!='indexed') unindexed, MAX(last_updated) last_update FROM {$this->prefix}msw_products_seo");
        if ($res) {
            $row = $res->fetch_assoc();
            $summary = $row;
        }
        return $summary;
    }
}
?>
