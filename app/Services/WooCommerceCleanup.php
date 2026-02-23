<?php

namespace App\Services;

use App\Models\MigrationLog;

class WooCommerceCleanup
{
    public function __construct(
        protected WooCommerceClient $woocommerce,
        protected ?int $migrationId = null
    ) {}

    /**
     * Clean all WooCommerce data before migration
     */
    public function cleanAll(): array
    {
        $results = [
            'products' => $this->deleteAllProducts(),
            'categories' => $this->deleteAllCategories(),
            'customers' => $this->deleteAllCustomers(),
            'orders' => $this->deleteAllOrders(),
            'coupons' => $this->deleteAllCoupons(),
            'reviews' => $this->deleteAllReviews(),
        ];

        $this->log('info', 'WooCommerce cleanup completed', null, 'cleanup');

        return $results;
    }

    /**
     * Delete all products
     */
    protected function deleteAllProducts(): array
    {
        $deleted = 0;
        $failed = 0;

        try {
            $page = 1;
            do {
                $products = $this->woocommerce->get('products', [
                    'per_page' => 100,
                    'page' => $page,
                ]);

                foreach ($products as $product) {
                    try {
                        $this->woocommerce->delete("products/{$product['id']}", ['force' => true]);
                        $deleted++;
                    } catch (\Exception $e) {
                        $failed++;
                        $this->log('warning', "Failed to delete product {$product['id']}: {$e->getMessage()}", null, 'cleanup');
                    }
                }

                $page++;
            } while (count($products) === 100);

            $this->log('info', "Deleted {$deleted} products", null, 'cleanup');
        } catch (\Exception $e) {
            $this->log('error', "Product cleanup failed: {$e->getMessage()}", null, 'cleanup');
        }

        return ['deleted' => $deleted, 'failed' => $failed];
    }

    /**
     * Delete all categories
     */
    protected function deleteAllCategories(): array
    {
        $deleted = 0;
        $failed = 0;

        try {
            $categories = $this->woocommerce->get('products/categories', ['per_page' => 100]);

            foreach ($categories as $category) {
                // Skip default "Uncategorized" category
                if ($category['slug'] === 'uncategorized') {
                    continue;
                }

                try {
                    $this->woocommerce->delete("products/categories/{$category['id']}", ['force' => true]);
                    $deleted++;
                } catch (\Exception $e) {
                    $failed++;
                    $this->log('warning', "Failed to delete category {$category['id']}: {$e->getMessage()}", null, 'cleanup');
                }
            }

            $this->log('info', "Deleted {$deleted} categories", null, 'cleanup');
        } catch (\Exception $e) {
            $this->log('error', "Category cleanup failed: {$e->getMessage()}", null, 'cleanup');
        }

        return ['deleted' => $deleted, 'failed' => $failed];
    }

    /**
     * Delete all customers
     */
    protected function deleteAllCustomers(): array
    {
        $deleted = 0;
        $failed = 0;

        try {
            $page = 1;
            do {
                $customers = $this->woocommerce->get('customers', [
                    'per_page' => 100,
                    'page' => $page,
                ]);

                foreach ($customers as $customer) {
                    try {
                        $this->woocommerce->delete("customers/{$customer['id']}", ['force' => true, 'reassign' => 0]);
                        $deleted++;
                    } catch (\Exception $e) {
                        $failed++;
                        $this->log('warning', "Failed to delete customer {$customer['id']}: {$e->getMessage()}", null, 'cleanup');
                    }
                }

                $page++;
            } while (count($customers) === 100);

            $this->log('info', "Deleted {$deleted} customers", null, 'cleanup');
        } catch (\Exception $e) {
            $this->log('error', "Customer cleanup failed: {$e->getMessage()}", null, 'cleanup');
        }

        return ['deleted' => $deleted, 'failed' => $failed];
    }

    /**
     * Delete all orders
     */
    protected function deleteAllOrders(): array
    {
        $deleted = 0;
        $failed = 0;

        try {
            $page = 1;
            do {
                $orders = $this->woocommerce->get('orders', [
                    'per_page' => 100,
                    'page' => $page,
                ]);

                foreach ($orders as $order) {
                    try {
                        $this->woocommerce->delete("orders/{$order['id']}", ['force' => true]);
                        $deleted++;
                    } catch (\Exception $e) {
                        $failed++;
                        $this->log('warning', "Failed to delete order {$order['id']}: {$e->getMessage()}", null, 'cleanup');
                    }
                }

                $page++;
            } while (count($orders) === 100);

            $this->log('info', "Deleted {$deleted} orders", null, 'cleanup');
        } catch (\Exception $e) {
            $this->log('error', "Order cleanup failed: {$e->getMessage()}", null, 'cleanup');
        }

        return ['deleted' => $deleted, 'failed' => $failed];
    }

    /**
     * Delete all coupons
     */
    protected function deleteAllCoupons(): array
    {
        $deleted = 0;
        $failed = 0;

        try {
            $coupons = $this->woocommerce->get('coupons', ['per_page' => 100]);

            foreach ($coupons as $coupon) {
                try {
                    $this->woocommerce->delete("coupons/{$coupon['id']}", ['force' => true]);
                    $deleted++;
                } catch (\Exception $e) {
                    $failed++;
                    $this->log('warning', "Failed to delete coupon {$coupon['id']}: {$e->getMessage()}", null, 'cleanup');
                }
            }

            $this->log('info', "Deleted {$deleted} coupons", null, 'cleanup');
        } catch (\Exception $e) {
            $this->log('error', "Coupon cleanup failed: {$e->getMessage()}", null, 'cleanup');
        }

        return ['deleted' => $deleted, 'failed' => $failed];
    }

    /**
     * Delete all reviews
     */
    protected function deleteAllReviews(): array
    {
        $deleted = 0;
        $failed = 0;

        try {
            $page = 1;
            do {
                $reviews = $this->woocommerce->get('products/reviews', [
                    'per_page' => 100,
                    'page' => $page,
                ]);

                foreach ($reviews as $review) {
                    try {
                        $this->woocommerce->delete("products/reviews/{$review['id']}", ['force' => true]);
                        $deleted++;
                    } catch (\Exception $e) {
                        $failed++;
                        $this->log('warning', "Failed to delete review {$review['id']}: {$e->getMessage()}", null, 'cleanup');
                    }
                }

                $page++;
            } while (count($reviews) === 100);

            $this->log('info', "Deleted {$deleted} reviews", null, 'cleanup');
        } catch (\Exception $e) {
            $this->log('error', "Review cleanup failed: {$e->getMessage()}", null, 'cleanup');
        }

        return ['deleted' => $deleted, 'failed' => $failed];
    }

    /**
     * Log cleanup activity
     */
    protected function log(string $level, string $message, ?string $shopwareId = null, ?string $entityType = null): void
    {
        if (! $this->migrationId) {
            return;
        }

        MigrationLog::create([
            'migration_id' => $this->migrationId,
            'entity_type' => $entityType,
            'shopware_id' => $shopwareId,
            'level' => $level,
            'message' => $message,
            'created_at' => now(),
        ]);
    }
}
