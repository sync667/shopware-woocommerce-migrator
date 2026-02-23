import StepCard from '../Components/StepCard';
import { FlaskConical, Settings, LogOut } from 'lucide-react';
import { logout } from '../utils/auth';

const ENTITY_TYPES = [
    'manufacturer',
    'tax',
    'category',
    'product',
    'customer',
    'order',
    'coupon',
    'review',
    'shipping_method',
    'payment_method',
    'seo_url',
];

const STATUS_COLORS = {
    pending: 'bg-gray-100 text-gray-700',
    running: 'bg-blue-100 text-blue-700',
    completed: 'bg-green-100 text-green-700',
    failed: 'bg-red-100 text-red-700',
    paused: 'bg-yellow-100 text-yellow-700',
    dry_run: 'bg-purple-100 text-purple-700',
};

export default function Dashboard({ migrations = [] }) {
    return (
        <div className="mx-auto max-w-6xl px-4 py-8">
            <div className="mb-8 flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">
                        Shopware → WooCommerce Migration
                    </h1>
                    <p className="mt-1 text-sm text-gray-500">
                        Manage and monitor your migration runs
                    </p>
                </div>
                <div className="flex items-center gap-3">
                    <a
                        href="/settings"
                        className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                    >
                        <Settings className="h-4 w-4" />
                        New Migration
                    </a>
                    <button
                        onClick={logout}
                        className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                        title="Logout"
                    >
                        <LogOut className="h-4 w-4" />
                        Logout
                    </button>
                </div>
            </div>

            {/* Entity type cards (overview) */}
            <div className="mb-8 grid grid-cols-2 gap-4 md:grid-cols-4">
                {ENTITY_TYPES.map((type) => (
                    <StepCard key={type} entityType={type} counts={{}} />
                ))}
            </div>

            {/* Recent migration runs */}
            <div className="rounded-lg border border-gray-200 bg-white shadow-sm">
                <div className="border-b border-gray-200 px-6 py-4">
                    <h2 className="text-lg font-medium text-gray-900">Migration Runs</h2>
                </div>
                <div className="divide-y divide-gray-100">
                    {migrations.length === 0 && (
                        <div className="px-6 py-12 text-center text-gray-400">
                            No migration runs yet. Configure a new migration to get started.
                        </div>
                    )}
                    {migrations.map((m) => (
                        <a
                            key={m.id}
                            href={`/migrations/${m.id}`}
                            className="flex items-center justify-between px-6 py-4 hover:bg-gray-50"
                        >
                            <div>
                                <span className="font-medium text-gray-900">{m.name}</span>
                                {m.is_dry_run && (
                                    <span className="ml-2 inline-flex items-center gap-1 rounded bg-purple-100 px-2 py-0.5 text-xs text-purple-700">
                                        <FlaskConical className="h-3 w-3" />
                                        Dry Run
                                    </span>
                                )}
                            </div>
                            <div className="flex items-center gap-4">
                                <span
                                    className={`rounded-full px-3 py-1 text-xs font-medium ${STATUS_COLORS[m.status] || STATUS_COLORS.pending}`}
                                >
                                    {m.status}
                                </span>
                                <span className="text-xs text-gray-400">
                                    {new Date(m.created_at).toLocaleString()}
                                </span>
                            </div>
                        </a>
                    ))}
                </div>
            </div>
        </div>
    );
}
