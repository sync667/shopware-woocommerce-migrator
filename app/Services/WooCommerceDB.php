<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\MySqlConnector;
use Illuminate\Database\MySqlConnection;

class WooCommerceDB
{
    protected array $config;

    protected ?Connection $connection = null;

    protected ?SSHTunnel $sshTunnel = null;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public static function fromMigration(\App\Models\MigrationRun $migration): static
    {
        $woo = $migration->woocommerceSettings();
        $config = [
            'db_host' => $woo['db_host'] ?? null,
            'db_port' => $woo['db_port'] ?? 3306,
            'db_database' => $woo['db_database'] ?? null,
            'db_username' => $woo['db_username'] ?? null,
            'db_password' => $woo['db_password'] ?? null,
            'table_prefix' => $woo['table_prefix'] ?? 'wp_',
            'ssh' => $woo['db_ssh'] ?? null,
        ];

        return new static($config);
    }

    public function isConfigured(): bool
    {
        return ! empty($this->config['db_host'])
            && ! empty($this->config['db_database'])
            && ! empty($this->config['db_username']);
    }

    public function connection(): Connection
    {
        if ($this->connection === null) {
            $host = $this->config['db_host'] ?? '127.0.0.1';
            $port = (int) ($this->config['db_port'] ?? 3306);

            if (! empty($this->config['ssh'])) {
                $this->sshTunnel = new SSHTunnel($this->config['ssh']);
                $port = $this->sshTunnel->connect($host, $port);
                $host = '127.0.0.1';
            }

            $connector = new MySqlConnector;
            $pdo = $connector->connect([
                'host' => $host,
                'port' => $port,
                'database' => $this->config['db_database'] ?? '',
                'username' => $this->config['db_username'] ?? '',
                'password' => $this->config['db_password'] ?? '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
            ]);
            $this->connection = new MySqlConnection($pdo, $this->config['db_database'] ?? '');
        }

        return $this->connection;
    }

    public function prefix(): string
    {
        $prefix = (string) ($this->config['table_prefix'] ?? 'wp_');

        return preg_match('/^[a-zA-Z0-9_]+$/', $prefix) ? $prefix : 'wp_';
    }

    public function table(string $name): string
    {
        return $this->prefix().$name;
    }

    public function select(string $query, array $bindings = []): array
    {
        return $this->connection()->select($query, $bindings);
    }

    public function statement(string $query, array $bindings = []): bool
    {
        return $this->connection()->statement($query, $bindings);
    }

    public function affecting(string $query, array $bindings = []): int
    {
        return $this->connection()->affectingStatement($query, $bindings);
    }

    public function ping(): bool
    {
        try {
            $this->select('SELECT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Bulk INSERT … ON DUPLICATE KEY into wp_postmeta. Chunks at 1000 to keep
     * MAX_ALLOWED_PACKET safe.
     *
     * @param  array<int, scalar|null>  $valuesByPostId
     */
    public function upsertPostMeta(string $metaKey, array $valuesByPostId): int
    {
        if ($valuesByPostId === []) {
            return 0;
        }

        $table = $this->table('postmeta');
        $affected = 0;

        foreach (array_chunk($valuesByPostId, 1000, true) as $chunk) {
            $placeholders = [];
            $params = [];
            foreach ($chunk as $postId => $value) {
                $placeholders[] = '(?, ?, ?)';
                $params[] = (int) $postId;
                $params[] = $metaKey;
                $params[] = (string) ($value ?? '');
            }

            $sql = "INSERT INTO {$table} (post_id, meta_key, meta_value) VALUES "
                .implode(',', $placeholders)
                .' ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)';

            $affected += $this->affecting($sql, $params);
        }

        return $affected;
    }

    /**
     * Authoritative replace: DELETE every row matching (post_id, meta_key) then
     * INSERT the fresh value. wp_postmeta has no UNIQUE on (post_id, meta_key),
     * so upsertPostMeta's ON DUPLICATE KEY UPDATE doesn't fire on the natural
     * key — re-running the migration would just append duplicates.
     *
     * Both statements per chunk live in one transaction so a crash mid-batch
     * either keeps the OLD row intact or has the NEW row in place, never a
     * deleted-but-not-yet-inserted gap.
     *
     * @param  array<int, scalar|null>  $valuesByPostId
     */
    public function replacePostMeta(string $metaKey, array $valuesByPostId): int
    {
        if ($valuesByPostId === []) {
            return 0;
        }

        $table = $this->table('postmeta');
        $written = 0;
        $conn = $this->connection();

        foreach (array_chunk($valuesByPostId, 500, true) as $chunk) {
            $conn->transaction(function () use ($table, $metaKey, $chunk, &$written) {
                $postIds = array_keys($chunk);
                $deletePlaceholders = implode(',', array_fill(0, count($postIds), '?'));
                $this->affecting(
                    "DELETE FROM {$table} WHERE meta_key = ? AND post_id IN ({$deletePlaceholders})",
                    array_merge([$metaKey], $postIds)
                );

                $insertPlaceholders = [];
                $insertParams = [];
                foreach ($chunk as $postId => $value) {
                    $insertPlaceholders[] = '(?, ?, ?)';
                    $insertParams[] = (int) $postId;
                    $insertParams[] = $metaKey;
                    $insertParams[] = (string) ($value ?? '');
                }
                $written += $this->affecting(
                    "INSERT INTO {$table} (post_id, meta_key, meta_value) VALUES ".implode(',', $insertPlaceholders),
                    $insertParams
                );
            });
        }

        return $written;
    }

    /** @param  array<int, scalar|null>  $valuesByTermId */
    public function upsertTermMeta(string $metaKey, array $valuesByTermId): int
    {
        if ($valuesByTermId === []) {
            return 0;
        }

        $table = $this->table('termmeta');
        $affected = 0;

        foreach (array_chunk($valuesByTermId, 1000, true) as $chunk) {
            $placeholders = [];
            $params = [];
            foreach ($chunk as $termId => $value) {
                $placeholders[] = '(?, ?, ?)';
                $params[] = (int) $termId;
                $params[] = $metaKey;
                $params[] = (string) ($value ?? '');
            }

            $sql = "INSERT INTO {$table} (term_id, meta_key, meta_value) VALUES "
                .implode(',', $placeholders)
                .' ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)';

            $affected += $this->affecting($sql, $params);
        }

        return $affected;
    }

    /**
     * WC REST rounds post_date to seconds; this preserves Shopware's exact
     * timestamps (useful on order audit trails).
     *
     * @param  array<int, string>  $datesByPostId  'Y-m-d H:i:s'
     */
    public function setExactPostDates(array $datesByPostId): int
    {
        if ($datesByPostId === []) {
            return 0;
        }

        $table = $this->table('posts');
        $affected = 0;

        foreach ($datesByPostId as $postId => $date) {
            $affected += $this->affecting(
                "UPDATE {$table}
                 SET post_date = ?, post_date_gmt = ?, post_modified = ?, post_modified_gmt = ?
                 WHERE ID = ?",
                [$date, $date, $date, $date, (int) $postId]
            );
        }

        return $affected;
    }

    /** Bump wp_posts.AUTO_INCREMENT past the migrated id range. */
    public function bumpPostsAutoIncrementTo(int $minimum): bool
    {
        $table = $this->table('posts');

        // MySQL 8 caches information_schema stats for ~24h. ANALYZE forces a refresh
        // so the "is it already high enough?" check below isn't fooled by stale data.
        $this->statement("ANALYZE TABLE {$table}");

        $current = (int) ($this->select(
            'SELECT AUTO_INCREMENT v FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$this->config['db_database'] ?? '', $table]
        )[0]->v ?? 0);

        if ($current >= $minimum) {
            return false;
        }

        $this->statement("ALTER TABLE {$table} AUTO_INCREMENT = {$minimum}");

        return true;
    }

    /** True when WC HPOS (custom orders table) is enabled — renumber must touch wp_wc_orders too. */
    public function isHposEnabled(): bool
    {
        try {
            $row = $this->select(
                'SELECT option_value v FROM '.$this->table('options').' WHERE option_name = ?',
                ['woocommerce_custom_orders_table_enabled']
            );

            return ($row[0]->v ?? 'no') === 'yes';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Posts in [min, max] whose type is NOT in $allowedTypes — the pre-flight
     * collision report for the renumber pass.
     *
     * @param  string[]  $allowedTypes
     * @return array<int, object>
     */
    public function findPostIdCollisions(int $min, int $max, array $allowedTypes = ['shop_order']): array
    {
        $placeholders = implode(',', array_fill(0, count($allowedTypes), '?'));

        return $this->select(
            'SELECT ID, post_type, post_title FROM '.$this->table('posts').'
             WHERE ID BETWEEN ? AND ?
               AND post_type NOT IN ('.$placeholders.')
             ORDER BY ID',
            array_merge([$min, $max], $allowedTypes)
        );
    }

    /**
     * Renumber a single order by updating every FK reference in one transaction.
     * Touches wp_posts (the order + child refunds via post_parent), wp_postmeta,
     * wp_woocommerce_order_items, wp_comments, wp_term_relationships, plus the
     * HPOS tables when enabled. Throws on collision or zero-row updates so the
     * caller can roll back / leave the auto-id.
     */
    public function renumberOrder(int $fromId, int $toId): void
    {
        if ($fromId === $toId) {
            return;
        }

        $conn = $this->connection();
        $hpos = $this->isHposEnabled();

        $conn->transaction(function () use ($fromId, $toId, $hpos) {
            $collision = $this->select(
                'SELECT ID FROM '.$this->table('posts').' WHERE ID = ?',
                [$toId]
            );
            if ($collision !== []) {
                throw new \RuntimeException("Target post.ID {$toId} is already in use");
            }

            $moved = $this->affecting(
                'UPDATE '.$this->table('posts').' SET ID = ? WHERE ID = ?',
                [$toId, $fromId]
            );
            if ($moved !== 1) {
                throw new \RuntimeException("wp_posts.ID renumber {$fromId}→{$toId} updated {$moved} rows (expected 1)");
            }

            $this->affecting(
                'UPDATE '.$this->table('postmeta').' SET post_id = ? WHERE post_id = ?',
                [$toId, $fromId]
            );
            $this->affecting(
                'UPDATE '.$this->table('woocommerce_order_items').' SET order_id = ? WHERE order_id = ?',
                [$toId, $fromId]
            );
            $this->affecting(
                'UPDATE '.$this->table('comments').' SET comment_post_ID = ? WHERE comment_post_ID = ?',
                [$toId, $fromId]
            );
            $this->affecting(
                'UPDATE '.$this->table('term_relationships').' SET object_id = ? WHERE object_id = ?',
                [$toId, $fromId]
            );
            // Refunds are shop_order_refund posts whose post_parent = original order.
            $this->affecting(
                'UPDATE '.$this->table('posts').' SET post_parent = ? WHERE post_parent = ?',
                [$toId, $fromId]
            );

            if ($hpos) {
                $this->affecting('UPDATE '.$this->table('wc_orders').' SET id = ? WHERE id = ?', [$toId, $fromId]);
                $this->affecting('UPDATE '.$this->table('wc_orders').' SET parent_order_id = ? WHERE parent_order_id = ?', [$toId, $fromId]);
                $this->affecting('UPDATE '.$this->table('wc_order_addresses').' SET order_id = ? WHERE order_id = ?', [$toId, $fromId]);
                $this->affecting('UPDATE '.$this->table('wc_order_operational_data').' SET order_id = ? WHERE order_id = ?', [$toId, $fromId]);
                $this->affecting('UPDATE '.$this->table('wc_order_stats').' SET order_id = ? WHERE order_id = ?', [$toId, $fromId]);
                $this->affecting('UPDATE '.$this->table('wc_order_product_lookup').' SET order_id = ? WHERE order_id = ?', [$toId, $fromId]);
            }
        });
    }

    /**
     * Bulk INSERT order notes into wp_comments. Each row: {order_id, content, transition_at}.
     * HPOS-safe — WC keeps notes in wp_comments even with HPOS enabled.
     *
     * @param  array<int, array{order_id:int, content:string, transition_at:string}>  $rows
     */
    public function bulkInsertOrderNotes(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $table = $this->table('comments');
        $affected = 0;

        foreach (array_chunk($rows, 500) as $chunk) {
            $placeholders = [];
            $params = [];
            foreach ($chunk as $row) {
                $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?)';
                array_push(
                    $params,
                    (int) $row['order_id'],
                    'order_note',
                    (string) $row['content'],
                    'Migration',
                    '',
                    (string) $row['transition_at'],
                    (string) $row['transition_at'],
                    1,
                );
            }

            $sql = "INSERT INTO {$table}
                (comment_post_ID, comment_type, comment_content, comment_author, comment_author_email,
                 comment_date, comment_date_gmt, comment_approved)
                VALUES ".implode(',', $placeholders);

            $affected += $this->affecting($sql, $params);
        }

        return $affected;
    }

    /** MAX(_order_number) we've stamped on migrated orders. Drives the AUTO_INCREMENT bump. */
    public function highestStampedOrderNumber(): ?int
    {
        $row = $this->select(
            'SELECT MAX(CAST(meta_value AS UNSIGNED)) AS v
             FROM '.$this->table('postmeta').'
             WHERE meta_key = ? AND meta_value REGEXP ?',
            ['_order_number', '^[0-9]+$']
        );

        $v = $row[0]->v ?? null;

        return $v === null ? null : (int) $v;
    }

    public function disconnect(): void
    {
        if ($this->connection !== null) {
            $this->connection->setPdo(null)->setReadPdo(null);
            $this->connection = null;
        }

        if ($this->sshTunnel) {
            $this->sshTunnel->disconnect();
            $this->sshTunnel = null;
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
