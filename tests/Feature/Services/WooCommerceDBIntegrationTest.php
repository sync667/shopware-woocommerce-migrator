<?php

namespace Tests\Feature\Services;

use App\Services\WooCommerceDB;
use PHPUnit\Framework\TestCase;

/**
 * Integration test against the mysql-test container — bypasses Laravel's test DB
 * because we need a fresh WC-shaped schema (wp_posts + meta + items + comments
 * + term_relationships) with no migrator-app tables present.
 */
class WooCommerceDBIntegrationTest extends TestCase
{
    private static ?\PDO $pdo = null;

    private const DATABASE = 'wc_renumber_test';

    private static array $dbConfig = [
        'db_host' => 'mysql-test',
        'db_port' => 3306,
        'db_database' => self::DATABASE,
        'db_username' => 'root',
        'db_password' => 'root',
        'table_prefix' => 'wp_',
    ];

    public static function setUpBeforeClass(): void
    {
        try {
            self::$pdo = new \PDO(
                'mysql:host=mysql-test;port=3306;charset=utf8mb4',
                'root',
                'root',
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
            self::$pdo->exec('DROP DATABASE IF EXISTS '.self::DATABASE);
            self::$pdo->exec('CREATE DATABASE '.self::DATABASE.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            self::$pdo->exec('USE '.self::DATABASE);
            self::createSchema();
        } catch (\PDOException $e) {
            self::markTestSkippedAll('mysql-test unreachable: '.$e->getMessage());
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$pdo !== null) {
            self::$pdo->exec('DROP DATABASE IF EXISTS '.self::DATABASE);
            self::$pdo = null;
        }
    }

    private static function markTestSkippedAll(string $msg): void
    {
        throw new \PHPUnit\Framework\SkippedTestSuiteError($msg);
    }

    private static function createSchema(): void
    {
        self::$pdo->exec("
            CREATE TABLE wp_posts (
                ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                post_parent BIGINT UNSIGNED NOT NULL DEFAULT 0,
                post_type VARCHAR(20) NOT NULL DEFAULT 'post',
                post_status VARCHAR(20) NOT NULL DEFAULT 'publish',
                post_title TEXT,
                post_date DATETIME NULL,
                post_date_gmt DATETIME NULL,
                post_modified DATETIME NULL,
                post_modified_gmt DATETIME NULL,
                PRIMARY KEY (ID),
                KEY post_type (post_type),
                KEY post_parent (post_parent)
            ) ENGINE=InnoDB
        ");
        self::$pdo->exec('
            CREATE TABLE wp_postmeta (
                meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                meta_key VARCHAR(255),
                meta_value LONGTEXT,
                PRIMARY KEY (meta_id),
                KEY post_id (post_id),
                KEY meta_key_idx (meta_key(191))
            ) ENGINE=InnoDB
        ');
        self::$pdo->exec("
            CREATE TABLE wp_woocommerce_order_items (
                order_item_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                order_item_name TEXT NOT NULL,
                order_item_type VARCHAR(200) NOT NULL DEFAULT '',
                PRIMARY KEY (order_item_id),
                KEY order_id (order_id)
            ) ENGINE=InnoDB
        ");
        self::$pdo->exec("
            CREATE TABLE wp_comments (
                comment_ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                comment_post_ID BIGINT UNSIGNED NOT NULL DEFAULT 0,
                comment_content TEXT,
                comment_type VARCHAR(20) NOT NULL DEFAULT '',
                comment_author TINYTEXT NULL,
                comment_author_email VARCHAR(100) NOT NULL DEFAULT '',
                comment_date DATETIME NULL,
                comment_date_gmt DATETIME NULL,
                comment_approved VARCHAR(20) NOT NULL DEFAULT '1',
                PRIMARY KEY (comment_ID),
                KEY comment_post_ID (comment_post_ID)
            ) ENGINE=InnoDB
        ");
        self::$pdo->exec('
            CREATE TABLE wp_term_relationships (
                object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                term_taxonomy_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                term_order INT NOT NULL DEFAULT 0,
                PRIMARY KEY (object_id, term_taxonomy_id)
            ) ENGINE=InnoDB
        ');
        self::$pdo->exec("
            CREATE TABLE wp_options (
                option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                option_name VARCHAR(191) NOT NULL DEFAULT '',
                option_value LONGTEXT,
                autoload VARCHAR(20) NOT NULL DEFAULT 'yes',
                PRIMARY KEY (option_id),
                UNIQUE KEY option_name (option_name)
            ) ENGINE=InnoDB
        ");
        self::$pdo->exec('
            CREATE TABLE wp_termmeta (
                meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                term_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                meta_key VARCHAR(255),
                meta_value LONGTEXT,
                PRIMARY KEY (meta_id),
                KEY term_id (term_id)
            ) ENGINE=InnoDB
        ');
    }

    private function db(): WooCommerceDB
    {
        return new WooCommerceDB(self::$dbConfig);
    }

    private function seedOrder(int $orderId, string $shopwareOrderNumber, int $refundId = 0): void
    {
        $insert = function (string $sql, array $params) {
            $stmt = self::$pdo->prepare($sql);
            $stmt->execute($params);
        };

        $insert(
            "INSERT INTO wp_posts (ID, post_type, post_title) VALUES (?, 'shop_order', ?)",
            [$orderId, "Order #{$shopwareOrderNumber}"]
        );
        $insert(
            'INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (?, ?, ?)',
            [$orderId, '_order_number', $shopwareOrderNumber]
        );
        $insert(
            'INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (?, ?, ?)',
            [$orderId, '_billing_email', 'customer@example.com']
        );
        $insert(
            "INSERT INTO wp_woocommerce_order_items (order_id, order_item_name, order_item_type) VALUES (?, 'Test Product', 'line_item')",
            [$orderId]
        );
        $insert(
            "INSERT INTO wp_comments (comment_post_ID, comment_content, comment_type) VALUES (?, 'Note', 'order_note')",
            [$orderId]
        );
        $insert(
            'INSERT INTO wp_term_relationships (object_id, term_taxonomy_id) VALUES (?, 1)',
            [$orderId]
        );

        if ($refundId > 0) {
            $insert(
                "INSERT INTO wp_posts (ID, post_type, post_parent, post_title) VALUES (?, 'shop_order_refund', ?, ?)",
                [$refundId, $orderId, "Refund for {$shopwareOrderNumber}"]
            );
        }
    }

    private function seedNonOrderPost(int $id, string $type, string $title): void
    {
        $stmt = self::$pdo->prepare(
            'INSERT INTO wp_posts (ID, post_type, post_title) VALUES (?, ?, ?)'
        );
        $stmt->execute([$id, $type, $title]);
    }

    protected function setUp(): void
    {
        foreach (['wp_posts', 'wp_postmeta', 'wp_woocommerce_order_items', 'wp_comments', 'wp_term_relationships', 'wp_options', 'wp_termmeta'] as $t) {
            self::$pdo->exec("TRUNCATE TABLE {$t}");
        }
    }

    public function test_is_hpos_enabled_returns_false_when_option_missing(): void
    {
        $this->assertFalse($this->db()->isHposEnabled());
    }

    public function test_is_hpos_enabled_reads_option_value(): void
    {
        self::$pdo->exec("INSERT INTO wp_options (option_name, option_value) VALUES ('woocommerce_custom_orders_table_enabled', 'yes')");
        $this->assertTrue($this->db()->isHposEnabled());

        self::$pdo->exec("UPDATE wp_options SET option_value = 'no' WHERE option_name = 'woocommerce_custom_orders_table_enabled'");
        $this->assertFalse($this->db()->isHposEnabled());
    }

    public function test_find_post_id_collisions_reports_non_order_posts_in_range(): void
    {
        $this->seedOrder(25001, '10154');
        $this->seedNonOrderPost(15000, 'page', 'About Us');
        $this->seedNonOrderPost(20000, 'attachment', 'logo');
        $this->seedNonOrderPost(30000, 'page', 'Far');

        $conflicts = $this->db()->findPostIdCollisions(10154, 21034);

        $this->assertCount(2, $conflicts);
        $this->assertSame(15000, (int) $conflicts[0]->ID);
        $this->assertSame('page', $conflicts[0]->post_type);
        $this->assertSame(20000, (int) $conflicts[1]->ID);
    }

    public function test_renumber_order_moves_all_fk_references(): void
    {
        $this->seedOrder(25001, '21034', refundId: 25002);

        $this->db()->renumberOrder(25001, 21034);

        $this->assertSame(1, $this->countRows('wp_posts', 'ID = ?', [21034]));
        $this->assertSame(0, $this->countRows('wp_posts', 'ID = ?', [25001]));

        $refund = $this->fetchOne('SELECT post_parent FROM wp_posts WHERE ID = ?', [25002]);
        $this->assertSame('21034', (string) $refund->post_parent);

        $this->assertSame(2, $this->countRows('wp_postmeta', 'post_id = ?', [21034]));
        $this->assertSame(0, $this->countRows('wp_postmeta', 'post_id = ?', [25001]));
        $this->assertSame(1, $this->countRows('wp_woocommerce_order_items', 'order_id = ?', [21034]));
        $this->assertSame(0, $this->countRows('wp_woocommerce_order_items', 'order_id = ?', [25001]));
        $this->assertSame(1, $this->countRows('wp_comments', 'comment_post_ID = ?', [21034]));
        $this->assertSame(0, $this->countRows('wp_comments', 'comment_post_ID = ?', [25001]));
        $this->assertSame(1, $this->countRows('wp_term_relationships', 'object_id = ?', [21034]));
        $this->assertSame(0, $this->countRows('wp_term_relationships', 'object_id = ?', [25001]));
    }

    public function test_renumber_order_no_ops_when_from_equals_to(): void
    {
        $this->seedOrder(21034, '21034');

        $this->db()->renumberOrder(21034, 21034);

        $this->assertSame(1, $this->countRows('wp_posts', 'ID = ?', [21034]));
    }

    public function test_renumber_order_throws_and_rolls_back_on_target_collision(): void
    {
        $this->seedOrder(25001, '15000');
        $this->seedNonOrderPost(15000, 'page', 'Squatter');

        try {
            $this->db()->renumberOrder(25001, 15000);
            $this->fail('Expected RuntimeException for target collision');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already in use', $e->getMessage());
        }

        $this->assertSame(1, $this->countRows('wp_posts', "ID = ? AND post_type = 'shop_order'", [25001]));
        $this->assertSame(1, $this->countRows('wp_posts', "ID = ? AND post_type = 'page'", [15000]));
        $this->assertSame(2, $this->countRows('wp_postmeta', 'post_id = ?', [25001]));
    }

    public function test_renumber_order_touches_hpos_tables_when_enabled(): void
    {
        self::$pdo->exec('
            CREATE TABLE wp_wc_orders (
                id BIGINT UNSIGNED NOT NULL,
                parent_order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB
        ');
        foreach (['wc_order_addresses', 'wc_order_operational_data', 'wc_order_stats', 'wc_order_product_lookup'] as $t) {
            self::$pdo->exec("
                CREATE TABLE wp_{$t} (
                    row_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    order_id BIGINT UNSIGNED NOT NULL,
                    PRIMARY KEY (row_id),
                    KEY order_id (order_id)
                ) ENGINE=InnoDB
            ");
        }
        self::$pdo->exec("INSERT INTO wp_options (option_name, option_value) VALUES ('woocommerce_custom_orders_table_enabled', 'yes')");

        $this->seedOrder(25001, '21034');
        self::$pdo->exec('INSERT INTO wp_wc_orders (id) VALUES (25001)');
        foreach (['wc_order_addresses', 'wc_order_operational_data', 'wc_order_stats', 'wc_order_product_lookup'] as $t) {
            self::$pdo->exec("INSERT INTO wp_{$t} (order_id) VALUES (25001)");
        }

        $this->db()->renumberOrder(25001, 21034);

        $this->assertSame(1, $this->countRows('wp_wc_orders', 'id = ?', [21034]));
        $this->assertSame(0, $this->countRows('wp_wc_orders', 'id = ?', [25001]));
        foreach (['wc_order_addresses', 'wc_order_operational_data', 'wc_order_stats', 'wc_order_product_lookup'] as $t) {
            $this->assertSame(1, $this->countRows("wp_{$t}", 'order_id = ?', [21034]));
            $this->assertSame(0, $this->countRows("wp_{$t}", 'order_id = ?', [25001]));
        }

        self::$pdo->exec('DROP TABLE wp_wc_orders, wp_wc_order_addresses, wp_wc_order_operational_data, wp_wc_order_stats, wp_wc_order_product_lookup');
    }

    public function test_highest_stamped_order_number_returns_max(): void
    {
        $this->seedOrder(25001, '10154');
        $this->seedOrder(25002, '21034');
        $this->seedOrder(25003, '15500');
        self::$pdo->exec("INSERT INTO wp_posts (ID, post_type) VALUES (25004, 'shop_order')");
        self::$pdo->exec("INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (25004, '_order_number', 'SW-ABC')");

        $this->assertSame(21034, $this->db()->highestStampedOrderNumber());
    }

    public function test_bump_auto_increment_advances_when_below_target(): void
    {
        $this->seedOrder(100, '100');
        self::$pdo->exec('ALTER TABLE wp_posts AUTO_INCREMENT = 101');

        $bumped = $this->db()->bumpPostsAutoIncrementTo(50000);

        $this->assertTrue($bumped);

        // MySQL 8 caches information_schema.AUTO_INCREMENT for 24h — verify by
        // inserting a row and reading the assigned ID instead.
        self::$pdo->exec("INSERT INTO wp_posts (post_type) VALUES ('post')");
        $newId = (int) self::$pdo->lastInsertId();
        $this->assertGreaterThanOrEqual(50000, $newId);
    }

    public function test_bump_auto_increment_is_noop_when_already_above_target(): void
    {
        $this->seedOrder(100000, '100000');

        $bumped = $this->db()->bumpPostsAutoIncrementTo(50);

        $this->assertFalse($bumped);
    }

    public function test_bulk_insert_order_notes_writes_one_row_per_input(): void
    {
        $this->seedOrder(21034, '21034');

        $rows = [
            ['order_id' => 21034, 'content' => 'Shopware status: open → in_progress', 'transition_at' => '2024-01-01 10:00:00'],
            ['order_id' => 21034, 'content' => 'Shopware status: in_progress → completed', 'transition_at' => '2024-01-02 14:30:00'],
        ];

        $affected = $this->db()->bulkInsertOrderNotes($rows);

        $this->assertSame(2, $affected);
        // seedOrder() pre-inserts one "Note" comment as part of a baseline order, so
        // we scope the count to comment_author = Migration (what bulkInsert sets).
        $this->assertSame(2, $this->countRows('wp_comments', 'comment_post_ID = ? AND comment_author = ?', [21034, 'Migration']));

        $written = self::$pdo->query("SELECT comment_content, comment_date, comment_author FROM wp_comments WHERE comment_post_ID = 21034 AND comment_author = 'Migration' ORDER BY comment_date ASC")->fetchAll(\PDO::FETCH_OBJ);
        $this->assertSame('Shopware status: open → in_progress', $written[0]->comment_content);
        $this->assertSame('2024-01-01 10:00:00', $written[0]->comment_date);
        $this->assertSame('Migration', $written[0]->comment_author);
        $this->assertSame('Shopware status: in_progress → completed', $written[1]->comment_content);
    }

    public function test_bulk_insert_order_notes_short_circuits_on_empty_input(): void
    {
        $this->assertSame(0, $this->db()->bulkInsertOrderNotes([]));
    }

    public function test_replace_post_meta_writes_a_single_row(): void
    {
        $json = '[{"from":1,"to":5,"cost":100},{"from":6,"to":null,"cost":300}]';

        $written = $this->db()->replacePostMeta('_remizasklep_delivery_tiers', [99 => $json]);

        $this->assertSame(1, $written);
        $row = self::$pdo->query("SELECT meta_value FROM wp_postmeta WHERE post_id = 99 AND meta_key = '_remizasklep_delivery_tiers'")->fetch(\PDO::FETCH_OBJ);
        $this->assertSame($json, $row->meta_value);
    }

    public function test_replace_post_meta_drops_old_rows_before_inserting(): void
    {
        // The plugin contract notes wp_postmeta has no UNIQUE on (post_id, meta_key)
        // so re-running the migration without a delete would silently append a
        // second row and the WP API's get_post_meta($id, $key, true) would still
        // return the FIRST (stale) value. Verify both pre-existing rows are
        // removed and a single new row remains.
        self::$pdo->exec("INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (200, '_remizasklep_delivery_tiers', '[OLD-1]')");
        self::$pdo->exec("INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (200, '_remizasklep_delivery_tiers', '[OLD-2]')");
        $this->assertSame(2, $this->countRows('wp_postmeta', 'post_id = ? AND meta_key = ?', [200, '_remizasklep_delivery_tiers']));

        $written = $this->db()->replacePostMeta('_remizasklep_delivery_tiers', [200 => '[NEW]']);

        $this->assertSame(1, $written);
        $this->assertSame(1, $this->countRows('wp_postmeta', 'post_id = ? AND meta_key = ?', [200, '_remizasklep_delivery_tiers']));
        $row = self::$pdo->query("SELECT meta_value FROM wp_postmeta WHERE post_id = 200 AND meta_key = '_remizasklep_delivery_tiers'")->fetch(\PDO::FETCH_OBJ);
        $this->assertSame('[NEW]', $row->meta_value);
    }

    public function test_replace_post_meta_leaves_other_post_ids_untouched(): void
    {
        self::$pdo->exec("INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (300, '_remizasklep_delivery_tiers', 'untouched')");

        $this->db()->replacePostMeta('_remizasklep_delivery_tiers', [301 => '[NEW]']);

        $row = self::$pdo->query("SELECT meta_value FROM wp_postmeta WHERE post_id = 300 AND meta_key = '_remizasklep_delivery_tiers'")->fetch(\PDO::FETCH_OBJ);
        $this->assertSame('untouched', $row->meta_value);
    }

    public function test_replace_post_meta_short_circuits_on_empty_input(): void
    {
        $this->assertSame(0, $this->db()->replacePostMeta('_remizasklep_delivery_tiers', []));
    }

    private function countRows(string $table, string $where, array $params): int
    {
        $stmt = self::$pdo->prepare("SELECT COUNT(*) c FROM {$table} WHERE {$where}");
        $stmt->execute($params);

        return (int) $stmt->fetch(\PDO::FETCH_OBJ)->c;
    }

    private function fetchOne(string $sql, array $params): object
    {
        $stmt = self::$pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetch(\PDO::FETCH_OBJ);
    }
}
