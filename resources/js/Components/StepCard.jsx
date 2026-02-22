import ProgressBar from './ProgressBar';
import {
    Factory,
    Percent,
    FolderTree,
    Package,
    Users,
    ShoppingCart,
    Ticket,
    Star,
} from 'lucide-react';

const ICONS = {
    manufacturer: Factory,
    tax: Percent,
    category: FolderTree,
    product: Package,
    customer: Users,
    order: ShoppingCart,
    coupon: Ticket,
    review: Star,
};

export default function StepCard({ entityType, counts = {} }) {
    const Icon = ICONS[entityType] || Package;
    const success = counts.success || 0;
    const failed = counts.failed || 0;
    const pending = counts.pending || 0;
    const running = counts.running || 0;
    const total = success + failed + pending + running;
    const label = entityType.charAt(0).toUpperCase() + entityType.slice(1) + 's';

    return (
        <div className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <div className="mb-3 flex items-center gap-2">
                <Icon className="h-5 w-5 text-gray-500" />
                <h3 className="font-medium text-gray-900">{label}</h3>
            </div>
            <ProgressBar value={success} max={total || 1} className="mb-2" />
            <div className="flex justify-between text-xs text-gray-500">
                <span className="text-green-600">{success} OK</span>
                {failed > 0 && <span className="text-red-600">{failed} failed</span>}
                {(pending + running) > 0 && <span>{pending + running} pending</span>}
            </div>
        </div>
    );
}
