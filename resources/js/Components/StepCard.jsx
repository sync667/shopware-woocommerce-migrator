import ProgressBar from './ProgressBar';
import {
    Factory,
    Percent,
    FolderTree,
    Package,
    Package2,
    Users,
    ShoppingCart,
    Ticket,
    Star,
    Truck,
    CreditCard,
    Link,
    FileText,
} from 'lucide-react';

const ICONS = {
    manufacturer: Factory,
    tax: Percent,
    category: FolderTree,
    product: Package,
    variation: Package2,
    customer: Users,
    order: ShoppingCart,
    coupon: Ticket,
    review: Star,
    shipping_method: Truck,
    payment_method: CreditCard,
    seo_url: Link,
    cms_page: FileText,
};

const LABELS = {
    manufacturer: 'Manufacturers',
    tax: 'Taxes',
    category: 'Categories',
    product: 'Products',
    variation: 'Variations',
    customer: 'Customers',
    order: 'Orders',
    coupon: 'Coupons',
    review: 'Reviews',
    shipping_method: 'Shipping Methods',
    payment_method: 'Payment Methods',
    seo_url: 'SEO URLs',
    cms_page: 'CMS Pages',
};

export default function StepCard({ entityType, counts = {}, isActive = false }) {
    const Icon = ICONS[entityType] || Package;
    const success = counts.success || 0;
    const failed = counts.failed || 0;
    const pending = counts.pending || 0;
    const running = counts.running || 0;
    const skipped = counts.skipped || 0;
    const total = success + failed + pending + running + skipped;
    const label = LABELS[entityType] || entityType.charAt(0).toUpperCase() + entityType.slice(1) + 's';

    return (
        <div
            className={`rounded-lg border p-4 shadow-sm transition-all ${
                isActive
                    ? 'border-blue-400 bg-blue-50 ring-1 ring-blue-200'
                    : 'border-gray-200 bg-white'
            }`}
        >
            <div className="mb-3 flex items-center justify-between">
                <div className="flex items-center gap-2">
                    <Icon
                        className={`h-5 w-5 ${isActive ? 'animate-pulse text-blue-600' : 'text-gray-500'}`}
                    />
                    <h3 className="font-medium text-gray-900">{label}</h3>
                </div>
                {total > 0 && (
                    <span className="text-xs text-gray-400">{total}</span>
                )}
            </div>
            <ProgressBar value={success + skipped} max={total || 1} className="mb-2" />
            <div className="flex flex-wrap justify-between gap-1 text-xs text-gray-500">
                <span className="text-green-600">{success} OK</span>
                {failed > 0 && <span className="text-red-600">{failed} failed</span>}
                {skipped > 0 && <span className="text-yellow-600">{skipped} skipped</span>}
                {running > 0 && (
                    <span className="text-blue-600">{running} running</span>
                )}
                {pending > 0 && <span>{pending} pending</span>}
            </div>
        </div>
    );
}
