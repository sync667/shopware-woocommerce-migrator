import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import LogTable from '../../Components/LogTable';
import { ArrowLeft, Download } from 'lucide-react';

export default function Log({ migrationId }) {
    const [entityType, setEntityType] = useState('');
    const [level, setLevel] = useState('');
    const [page, setPage] = useState(1);

    const { data, isLoading } = useQuery({
        queryKey: ['migration-logs', migrationId, entityType, level, page],
        queryFn: async () => {
            const params = new URLSearchParams({ page, per_page: 100 });
            if (entityType) params.set('entity_type', entityType);
            if (level) params.set('level', level);
            const res = await fetch(
                `/api/migrations/${migrationId}/logs?${params}`,
            );
            return res.json();
        },
        refetchInterval: 5000,
    });

    const logs = data?.data || [];
    const lastPage = data?.last_page || 1;

    const exportCsv = () => {
        const headers = 'Level,Entity,Shopware ID,Message,Time\n';
        const rows = logs
            .map(
                (l) =>
                    `${l.level},${l.entity_type || ''},${l.shopware_id || ''},"${(l.message || '').replace(/"/g, '""')}",${l.created_at || ''}`,
            )
            .join('\n');
        const blob = new Blob([headers + rows], { type: 'text/csv' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `migration-${migrationId}-logs.csv`;
        a.click();
        URL.revokeObjectURL(url);
    };

    return (
        <div className="mx-auto max-w-6xl px-4 py-8">
            <div className="mb-6 flex items-center justify-between">
                <div className="flex items-center gap-3">
                    <a
                        href={`/migrations/${migrationId}`}
                        className="text-gray-400 hover:text-gray-600"
                    >
                        <ArrowLeft className="h-5 w-5" />
                    </a>
                    <h1 className="text-2xl font-bold text-gray-900">Migration Logs</h1>
                </div>
                <button
                    onClick={exportCsv}
                    className="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"
                >
                    <Download className="h-4 w-4" />
                    Export CSV
                </button>
            </div>

            {/* Filters */}
            <div className="mb-4 flex gap-3">
                <select
                    value={entityType}
                    onChange={(e) => { setEntityType(e.target.value); setPage(1); }}
                    className="rounded-md border border-gray-300 px-3 py-2 text-sm"
                >
                    <option value="">All Entities</option>
                    {[
                        'manufacturer',
                        'tax',
                        'category',
                        'product',
                        'variation',
                        'customer',
                        'order',
                        'coupon',
                        'review',
                    ].map((t) => (
                        <option key={t} value={t}>
                            {t.charAt(0).toUpperCase() + t.slice(1)}
                        </option>
                    ))}
                </select>
                <select
                    value={level}
                    onChange={(e) => { setLevel(e.target.value); setPage(1); }}
                    className="rounded-md border border-gray-300 px-3 py-2 text-sm"
                >
                    <option value="">All Levels</option>
                    <option value="debug">Debug</option>
                    <option value="info">Info</option>
                    <option value="warning">Warning</option>
                    <option value="error">Error</option>
                </select>
            </div>

            <div className="rounded-lg border border-gray-200 bg-white p-4">
                <LogTable logs={logs} loading={isLoading} />
            </div>

            {/* Pagination */}
            {lastPage > 1 && (
                <div className="mt-4 flex justify-center gap-2">
                    <button
                        onClick={() => setPage((p) => Math.max(1, p - 1))}
                        disabled={page <= 1}
                        className="rounded border px-3 py-1 text-sm disabled:opacity-50"
                    >
                        Previous
                    </button>
                    <span className="px-3 py-1 text-sm text-gray-600">
                        Page {page} of {lastPage}
                    </span>
                    <button
                        onClick={() => setPage((p) => Math.min(lastPage, p + 1))}
                        disabled={page >= lastPage}
                        className="rounded border px-3 py-1 text-sm disabled:opacity-50"
                    >
                        Next
                    </button>
                </div>
            )}
        </div>
    );
}
