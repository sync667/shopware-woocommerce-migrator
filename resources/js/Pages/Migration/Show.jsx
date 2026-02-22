import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import StepCard from '../../Components/StepCard';
import ProgressBar from '../../Components/ProgressBar';
import {
    ArrowLeft,
    Pause,
    Play,
    XCircle,
    FileText,
    Clock,
    Timer,
    Activity,
    AlertTriangle,
    CheckCircle2,
    XOctagon,
} from 'lucide-react';

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

function formatDuration(seconds) {
    if (seconds == null) return '—';
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;
    if (h > 0) return `${h}h ${m}m ${s}s`;
    if (m > 0) return `${m}m ${s}s`;
    return `${s}s`;
}

function StatusBadge({ status }) {
    const colors = {
        pending: 'bg-gray-100 text-gray-700',
        running: 'bg-blue-100 text-blue-700',
        completed: 'bg-green-100 text-green-700',
        failed: 'bg-red-100 text-red-700',
        paused: 'bg-yellow-100 text-yellow-700',
        dry_run: 'bg-purple-100 text-purple-700',
    };

    return (
        <span
            className={`inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-medium ${colors[status] || colors.pending}`}
        >
            {status === 'running' && <Activity className="h-3 w-3 animate-pulse" />}
            {status === 'completed' && <CheckCircle2 className="h-3 w-3" />}
            {status === 'failed' && <XOctagon className="h-3 w-3" />}
            {status === 'paused' && <Pause className="h-3 w-3" />}
            {status}
        </span>
    );
}

export default function Show({ migrationId }) {
    const [showWarnings, setShowWarnings] = useState(false);

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
    const summary = data?.summary || {};
    const timing = data?.timing || {};
    const currentStep = data?.current_step;
    const lastActivity = data?.last_activity;
    const recentErrors = data?.recent_errors || [];
    const recentWarnings = data?.recent_warnings || [];

    const isRunning = migration.status === 'running';
    const isPaused = migration.status === 'paused';
    const isFinished = migration.status === 'completed' || migration.status === 'failed';

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

    return (
        <div className="mx-auto max-w-6xl px-4 py-8">
            {/* Header */}
            <div className="mb-6 flex items-center justify-between">
                <div className="flex items-center gap-3">
                    <a href="/" className="text-gray-400 hover:text-gray-600">
                        <ArrowLeft className="h-5 w-5" />
                    </a>
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900">
                            {migration.name || 'Migration'}
                        </h1>
                        <div className="mt-1 flex items-center gap-3">
                            <StatusBadge status={migration.status || 'pending'} />
                            {migration.is_dry_run && (
                                <span className="rounded bg-purple-100 px-2 py-0.5 text-xs text-purple-700">
                                    Dry Run
                                </span>
                            )}
                            {currentStep && isRunning && (
                                <span className="flex items-center gap-1 text-xs text-blue-600">
                                    <Activity className="h-3 w-3 animate-pulse" />
                                    Processing: {currentStep}s
                                </span>
                            )}
                        </div>
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

            {/* Timing & Summary Stats */}
            <div className="mb-6 grid grid-cols-2 gap-4 md:grid-cols-4">
                <div className="rounded-lg border border-gray-200 bg-white p-4">
                    <div className="mb-1 flex items-center gap-1.5 text-xs text-gray-500">
                        <Clock className="h-3.5 w-3.5" />
                        Elapsed
                    </div>
                    <div className="text-lg font-semibold text-gray-900">
                        {formatDuration(timing.elapsed_seconds)}
                    </div>
                </div>
                <div className="rounded-lg border border-gray-200 bg-white p-4">
                    <div className="mb-1 flex items-center gap-1.5 text-xs text-gray-500">
                        <Timer className="h-3.5 w-3.5" />
                        ETA
                    </div>
                    <div className="text-lg font-semibold text-gray-900">
                        {isFinished ? 'Done' : formatDuration(timing.eta_seconds)}
                    </div>
                </div>
                <div className="rounded-lg border border-gray-200 bg-white p-4">
                    <div className="mb-1 flex items-center gap-1.5 text-xs text-green-600">
                        <CheckCircle2 className="h-3.5 w-3.5" />
                        Success
                    </div>
                    <div className="text-lg font-semibold text-green-700">
                        {summary.success || 0}
                        <span className="ml-1 text-sm font-normal text-gray-400">
                            / {summary.total || 0}
                        </span>
                    </div>
                </div>
                <div className="rounded-lg border border-gray-200 bg-white p-4">
                    <div className="mb-1 flex items-center gap-1.5 text-xs text-red-600">
                        <XOctagon className="h-3.5 w-3.5" />
                        Failed
                    </div>
                    <div className="text-lg font-semibold text-red-700">
                        {summary.failed || 0}
                        {(summary.skipped || 0) > 0 && (
                            <span className="ml-1 text-sm font-normal text-yellow-600">
                                +{summary.skipped} skipped
                            </span>
                        )}
                    </div>
                </div>
            </div>

            {/* Overall Progress */}
            <div className="mb-6 rounded-lg border border-gray-200 bg-white p-4">
                <div className="mb-2 flex justify-between text-sm">
                    <span className="text-gray-600">Overall Progress</span>
                    <span className="font-medium">
                        {summary.success || 0} / {summary.total || '...'}
                        {(summary.total || 0) > 0 && (
                            <span className="ml-1 text-gray-400">
                                ({Math.round(((summary.success || 0) / summary.total) * 100)}%)
                            </span>
                        )}
                    </span>
                </div>
                <ProgressBar value={summary.success || 0} max={summary.total || 1} />
            </div>

            {/* Per-entity cards */}
            <div className="mb-6 grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4">
                {ENTITY_TYPES.map((type) => (
                    <StepCard
                        key={type}
                        entityType={type}
                        counts={counts[type] || {}}
                        isActive={currentStep === type}
                    />
                ))}
            </div>

            {/* Last activity */}
            {lastActivity && (
                <div className="mb-6 rounded-lg border border-gray-200 bg-white px-4 py-3">
                    <div className="flex items-center gap-2 text-sm">
                        <Activity className="h-4 w-4 text-gray-400" />
                        <span className="font-medium text-gray-500">Last activity:</span>
                        <span
                            className={`rounded px-1.5 py-0.5 text-xs ${
                                lastActivity.level === 'error'
                                    ? 'bg-red-100 text-red-700'
                                    : lastActivity.level === 'warning'
                                      ? 'bg-yellow-100 text-yellow-700'
                                      : 'bg-blue-100 text-blue-700'
                            }`}
                        >
                            {lastActivity.entity_type || 'system'}
                        </span>
                        <span className="truncate text-gray-700">{lastActivity.message}</span>
                        {lastActivity.created_at && (
                            <span className="ml-auto whitespace-nowrap text-xs text-gray-400">
                                {new Date(lastActivity.created_at).toLocaleTimeString()}
                            </span>
                        )}
                    </div>
                </div>
            )}

            {/* Recent errors */}
            {recentErrors.length > 0 && (
                <div className="mb-4 rounded-lg border border-red-200 bg-white">
                    <div className="flex items-center justify-between border-b border-red-100 px-4 py-3">
                        <h3 className="flex items-center gap-2 font-medium text-red-800">
                            <XOctagon className="h-4 w-4" />
                            Errors ({recentErrors.length})
                        </h3>
                    </div>
                    <div className="max-h-64 divide-y divide-red-50 overflow-y-auto">
                        {recentErrors.map((err, i) => (
                            <div key={i} className="px-4 py-3 text-sm">
                                <div className="flex items-start gap-2">
                                    <span className="mt-0.5 shrink-0 rounded bg-red-100 px-1.5 py-0.5 text-xs font-medium text-red-700">
                                        {err.entity_type}
                                    </span>
                                    <span className="text-gray-700">{err.message}</span>
                                </div>
                                <div className="mt-1 flex gap-3 text-xs text-gray-400">
                                    {err.shopware_id && <span>ID: {err.shopware_id}</span>}
                                    {err.created_at && (
                                        <span>{new Date(err.created_at).toLocaleTimeString()}</span>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {/* Recent warnings (collapsible) */}
            {recentWarnings.length > 0 && (
                <div className="rounded-lg border border-yellow-200 bg-white">
                    <button
                        onClick={() => setShowWarnings(!showWarnings)}
                        className="flex w-full items-center justify-between border-b border-yellow-100 px-4 py-3 text-left"
                    >
                        <h3 className="flex items-center gap-2 font-medium text-yellow-800">
                            <AlertTriangle className="h-4 w-4" />
                            Warnings ({recentWarnings.length})
                        </h3>
                        <span className="text-xs text-yellow-600">
                            {showWarnings ? 'Hide' : 'Show'}
                        </span>
                    </button>
                    {showWarnings && (
                        <div className="max-h-48 divide-y divide-yellow-50 overflow-y-auto">
                            {recentWarnings.map((warn, i) => (
                                <div key={i} className="px-4 py-2 text-sm">
                                    <span className="mr-2 text-xs font-medium text-yellow-700">
                                        [{warn.entity_type}]
                                    </span>
                                    <span className="text-gray-600">{warn.message}</span>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
