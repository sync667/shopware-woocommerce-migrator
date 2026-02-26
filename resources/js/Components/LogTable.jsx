import { useState } from 'react';

function LogMessage({ message }) {
    const [expanded, setExpanded] = useState(false);
    const isLong = message && message.length > 120;

    if (!isLong) {
        return <span>{message}</span>;
    }

    return (
        <span>
            {expanded ? message : message.slice(0, 120) + '…'}
            <button
                onClick={() => setExpanded(!expanded)}
                className="ml-2 text-xs text-blue-500 hover:underline whitespace-nowrap"
            >
                {expanded ? 'show less' : 'show more'}
            </button>
        </span>
    );
}

export default function LogTable({ logs = [], loading = false }) {
    const [filter, setFilter] = useState('');

    const filtered = filter
        ? logs.filter(
              (l) =>
                  l.message?.toLowerCase().includes(filter.toLowerCase()) ||
                  l.entity_type?.toLowerCase().includes(filter.toLowerCase()),
          )
        : logs;

    return (
        <div>
            <div className="mb-4">
                <input
                    type="text"
                    placeholder="Search logs..."
                    value={filter}
                    onChange={(e) => setFilter(e.target.value)}
                    className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                />
            </div>

            {loading && (
                <p className="py-4 text-center text-sm text-gray-500">Loading...</p>
            )}

            <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200 text-sm">
                    <thead className="bg-gray-50">
                        <tr>
                            <th className="px-3 py-2 text-left font-medium text-gray-500">Level</th>
                            <th className="px-3 py-2 text-left font-medium text-gray-500">Entity</th>
                            <th className="px-3 py-2 text-left font-medium text-gray-500">Message</th>
                            <th className="px-3 py-2 text-left font-medium text-gray-500">Time</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {filtered.map((log, i) => (
                            <tr key={log.id || i} className="hover:bg-gray-50">
                                <td className="whitespace-nowrap px-3 py-2">
                                    <span
                                        className={`inline-block rounded px-2 py-0.5 text-xs font-medium ${
                                            log.level === 'error'
                                                ? 'bg-red-100 text-red-700'
                                                : log.level === 'warning'
                                                  ? 'bg-yellow-100 text-yellow-700'
                                                  : 'bg-blue-100 text-blue-700'
                                        }`}
                                    >
                                        {log.level}
                                    </span>
                                </td>
                                <td className="whitespace-nowrap px-3 py-2 text-gray-600">
                                    {log.entity_type || '—'}
                                </td>
                                <td className="max-w-md px-3 py-2 text-gray-800 break-words">
                                    <LogMessage message={log.message} />
                                </td>
                                <td className="whitespace-nowrap px-3 py-2 text-gray-500">
                                    {log.created_at
                                        ? new Date(log.created_at).toLocaleTimeString()
                                        : '—'}
                                </td>
                            </tr>
                        ))}
                        {filtered.length === 0 && !loading && (
                            <tr>
                                <td colSpan={4} className="px-3 py-8 text-center text-gray-400">
                                    No logs found
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
