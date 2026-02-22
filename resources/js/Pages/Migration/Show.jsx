import { useQuery } from '@tanstack/react-query';
import StepCard from '../../Components/StepCard';
import ProgressBar from '../../Components/ProgressBar';
import { ArrowLeft, Pause, Play, XCircle, FileText } from 'lucide-react';

const ENTITY_TYPES = [
    'manufacturer',
    'tax',
    'category',
    'product',
    'variation',
    'customer',
    'order',
    'coupon',
    'review',
];

export default function Show({ migrationId }) {
    const { data, isLoading } = useQuery({
        queryKey: ['migration-status', migrationId],
        queryFn: async () => {
            const res = await fetch(`/api/migrations/${migrationId}/status`);
            return res.json();
        },
        refetchInterval: 2000,
    });

    const migration = data?.migration || {};
    const counts = data?.counts || {};
    const recentErrors = data?.recent_errors || [];

    const isRunning = migration.status === 'running';
    const isPaused = migration.status === 'paused';

    const handlePause = async () => {
        await fetch(`/api/migrations/${migrationId}/pause`, { method: 'POST' });
    };

    const handleResume = async () => {
        await fetch(`/api/migrations/${migrationId}/resume`, { method: 'POST' });
    };

    const handleCancel = async () => {
        if (confirm('Are you sure you want to cancel this migration?')) {
            await fetch(`/api/migrations/${migrationId}/cancel`, { method: 'POST' });
        }
    };

    const totalSuccess = Object.values(counts).reduce(
        (sum, c) => sum + (c.success || 0),
        0,
    );
    const totalAll = Object.values(counts).reduce(
        (sum, c) =>
            sum + (c.success || 0) + (c.failed || 0) + (c.pending || 0) + (c.running || 0),
        0,
    );

    return (
        <div className="mx-auto max-w-6xl px-4 py-8">
            <div className="mb-6 flex items-center justify-between">
                <div className="flex items-center gap-3">
                    <a href="/" className="text-gray-400 hover:text-gray-600">
                        <ArrowLeft className="h-5 w-5" />
                    </a>
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900">
                            {migration.name || 'Migration'}
                        </h1>
                        <p className="text-sm text-gray-500">
                            Status:{' '}
                            <span className="font-medium">{migration.status || '...'}</span>
                            {migration.is_dry_run && (
                                <span className="ml-2 text-purple-600">(Dry Run)</span>
                            )}
                        </p>
                    </div>
                </div>
                <div className="flex gap-2">
                    <a
                        href={`/migrations/${migrationId}/logs`}
                        className="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"
                    >
                        <FileText className="h-4 w-4" />
                        Logs
                    </a>
                    {isRunning && (
                        <button
                            onClick={handlePause}
                            className="inline-flex items-center gap-1.5 rounded-lg border border-yellow-300 bg-yellow-50 px-3 py-2 text-sm text-yellow-700 hover:bg-yellow-100"
                        >
                            <Pause className="h-4 w-4" />
                            Pause
                        </button>
                    )}
                    {isPaused && (
                        <button
                            onClick={handleResume}
                            className="inline-flex items-center gap-1.5 rounded-lg border border-green-300 bg-green-50 px-3 py-2 text-sm text-green-700 hover:bg-green-100"
                        >
                            <Play className="h-4 w-4" />
                            Resume
                        </button>
                    )}
                    {(isRunning || isPaused) && (
                        <button
                            onClick={handleCancel}
                            className="inline-flex items-center gap-1.5 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700 hover:bg-red-100"
                        >
                            <XCircle className="h-4 w-4" />
                            Cancel
                        </button>
                    )}
                </div>
            </div>

            {/* Overall progress */}
            <div className="mb-6 rounded-lg border border-gray-200 bg-white p-4">
                <div className="mb-2 flex justify-between text-sm">
                    <span className="text-gray-600">Overall Progress</span>
                    <span className="font-medium">
                        {totalSuccess} / {totalAll || '...'}
                    </span>
                </div>
                <ProgressBar value={totalSuccess} max={totalAll || 1} />
            </div>

            {/* Per-entity cards */}
            <div className="mb-6 grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4">
                {ENTITY_TYPES.map((type) => (
                    <StepCard key={type} entityType={type} counts={counts[type] || {}} />
                ))}
            </div>

            {/* Recent errors */}
            {recentErrors.length > 0 && (
                <div className="rounded-lg border border-red-200 bg-white">
                    <div className="border-b border-red-100 px-4 py-3">
                        <h3 className="font-medium text-red-800">Recent Errors</h3>
                    </div>
                    <div className="divide-y divide-red-50">
                        {recentErrors.map((err, i) => (
                            <div key={i} className="px-4 py-3 text-sm">
                                <span className="mr-2 font-medium text-red-700">
                                    [{err.entity_type}]
                                </span>
                                <span className="text-gray-700">{err.message}</span>
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}
