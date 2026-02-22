import { useState } from 'react';
import { router } from '@inertiajs/react';
import ConnectionStatus from '../Components/ConnectionStatus';
import { ArrowLeft, Play, FlaskConical, Loader2 } from 'lucide-react';

export default function Settings() {
    const [name, setName] = useState('');
    const [shopware, setShopware] = useState({
        db_host: '',
        db_port: 3306,
        db_database: '',
        db_username: '',
        db_password: '',
        language_id: '',
        live_version_id: '',
        base_url: '',
    });
    const [woocommerce, setWoocommerce] = useState({
        base_url: '',
        consumer_key: '',
        consumer_secret: '',
    });
    const [wordpress, setWordpress] = useState({
        username: '',
        app_password: '',
    });

    const [swConnected, setSwConnected] = useState(null);
    const [wcConnected, setWcConnected] = useState(null);
    const [submitting, setSubmitting] = useState(false);
    const [testing, setTesting] = useState({ sw: false, wc: false });

    const updateShopware = (key, value) =>
        setShopware((prev) => ({ ...prev, [key]: value }));
    const updateWoo = (key, value) =>
        setWoocommerce((prev) => ({ ...prev, [key]: value }));
    const updateWp = (key, value) =>
        setWordpress((prev) => ({ ...prev, [key]: value }));

    const testShopware = async () => {
        setTesting((p) => ({ ...p, sw: true }));
        try {
            const res = await fetch('/api/shopware/ping', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(shopware),
            });
            const data = await res.json();
            setSwConnected(data.connected ?? false);
        } catch {
            setSwConnected(false);
        }
        setTesting((p) => ({ ...p, sw: false }));
    };

    const testWoocommerce = async () => {
        setTesting((p) => ({ ...p, wc: true }));
        try {
            const res = await fetch('/api/woocommerce/ping', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(woocommerce),
            });
            const data = await res.json();
            setWcConnected(data.connected ?? false);
        } catch {
            setWcConnected(false);
        }
        setTesting((p) => ({ ...p, wc: false }));
    };

    const startMigration = async (isDryRun = false) => {
        setSubmitting(true);
        try {
            const res = await fetch('/api/migrations', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({
                    name: name || `Migration ${new Date().toLocaleString()}`,
                    is_dry_run: isDryRun,
                    settings: { shopware, woocommerce, wordpress },
                }),
            });
            const data = await res.json();
            if (data.migration?.id) {
                window.location.href = `/migrations/${data.migration.id}`;
            }
        } catch (err) {
            alert('Failed to start migration: ' + (err.message || 'Unknown error'));
        }
        setSubmitting(false);
    };

    const inputClass =
        'w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500';
    const labelClass = 'block text-sm font-medium text-gray-700 mb-1';

    return (
        <div className="mx-auto max-w-4xl px-4 py-8">
            <div className="mb-6 flex items-center gap-3">
                <a href="/" className="text-gray-400 hover:text-gray-600">
                    <ArrowLeft className="h-5 w-5" />
                </a>
                <h1 className="text-2xl font-bold text-gray-900">New Migration</h1>
            </div>

            {/* Migration Name */}
            <div className="mb-6 rounded-lg border border-gray-200 bg-white p-6">
                <h2 className="mb-4 text-lg font-medium text-gray-900">Migration Name</h2>
                <input
                    type="text"
                    placeholder="e.g. Full Migration January 2026"
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    className={inputClass}
                />
            </div>

            {/* Shopware Source */}
            <div className="mb-6 rounded-lg border border-gray-200 bg-white p-6">
                <div className="mb-4 flex items-center justify-between">
                    <h2 className="text-lg font-medium text-gray-900">
                        Shopware Source Database
                    </h2>
                    <div className="flex items-center gap-3">
                        <ConnectionStatus connected={swConnected} label="Shopware DB" />
                        <button
                            onClick={testShopware}
                            disabled={testing.sw}
                            className="rounded bg-gray-100 px-3 py-1 text-xs font-medium text-gray-700 hover:bg-gray-200 disabled:opacity-50"
                        >
                            {testing.sw ? 'Testing...' : 'Test Connection'}
                        </button>
                    </div>
                </div>
                <div className="grid grid-cols-2 gap-4">
                    <div>
                        <label className={labelClass}>Host</label>
                        <input type="text" value={shopware.db_host} onChange={(e) => updateShopware('db_host', e.target.value)} className={inputClass} placeholder="127.0.0.1" />
                    </div>
                    <div>
                        <label className={labelClass}>Port</label>
                        <input type="number" value={shopware.db_port} onChange={(e) => updateShopware('db_port', parseInt(e.target.value) || 3306)} className={inputClass} />
                    </div>
                    <div>
                        <label className={labelClass}>Database</label>
                        <input type="text" value={shopware.db_database} onChange={(e) => updateShopware('db_database', e.target.value)} className={inputClass} />
                    </div>
                    <div>
                        <label className={labelClass}>Username</label>
                        <input type="text" value={shopware.db_username} onChange={(e) => updateShopware('db_username', e.target.value)} className={inputClass} />
                    </div>
                    <div>
                        <label className={labelClass}>Password</label>
                        <input type="password" value={shopware.db_password} onChange={(e) => updateShopware('db_password', e.target.value)} className={inputClass} />
                    </div>
                    <div>
                        <label className={labelClass}>Base URL</label>
                        <input type="text" value={shopware.base_url} onChange={(e) => updateShopware('base_url', e.target.value)} className={inputClass} placeholder="https://shop.example.com" />
                    </div>
                    <div>
                        <label className={labelClass}>Language ID</label>
                        <input type="text" value={shopware.language_id} onChange={(e) => updateShopware('language_id', e.target.value)} className={inputClass} placeholder="hex string" />
                    </div>
                    <div>
                        <label className={labelClass}>Live Version ID</label>
                        <input type="text" value={shopware.live_version_id} onChange={(e) => updateShopware('live_version_id', e.target.value)} className={inputClass} placeholder="hex string" />
                    </div>
                </div>
            </div>

            {/* WooCommerce Target */}
            <div className="mb-6 rounded-lg border border-gray-200 bg-white p-6">
                <div className="mb-4 flex items-center justify-between">
                    <h2 className="text-lg font-medium text-gray-900">WooCommerce Target API</h2>
                    <div className="flex items-center gap-3">
                        <ConnectionStatus connected={wcConnected} label="WooCommerce API" />
                        <button
                            onClick={testWoocommerce}
                            disabled={testing.wc}
                            className="rounded bg-gray-100 px-3 py-1 text-xs font-medium text-gray-700 hover:bg-gray-200 disabled:opacity-50"
                        >
                            {testing.wc ? 'Testing...' : 'Test Connection'}
                        </button>
                    </div>
                </div>
                <div className="grid grid-cols-2 gap-4">
                    <div className="col-span-2">
                        <label className={labelClass}>Base URL</label>
                        <input type="text" value={woocommerce.base_url} onChange={(e) => updateWoo('base_url', e.target.value)} className={inputClass} placeholder="https://woo.example.com" />
                    </div>
                    <div>
                        <label className={labelClass}>Consumer Key</label>
                        <input type="text" value={woocommerce.consumer_key} onChange={(e) => updateWoo('consumer_key', e.target.value)} className={inputClass} placeholder="ck_..." />
                    </div>
                    <div>
                        <label className={labelClass}>Consumer Secret</label>
                        <input type="password" value={woocommerce.consumer_secret} onChange={(e) => updateWoo('consumer_secret', e.target.value)} className={inputClass} placeholder="cs_..." />
                    </div>
                </div>
            </div>

            {/* WordPress Media */}
            <div className="mb-6 rounded-lg border border-gray-200 bg-white p-6">
                <h2 className="mb-4 text-lg font-medium text-gray-900">WordPress Media API</h2>
                <div className="grid grid-cols-2 gap-4">
                    <div>
                        <label className={labelClass}>WP Username</label>
                        <input type="text" value={wordpress.username} onChange={(e) => updateWp('username', e.target.value)} className={inputClass} />
                    </div>
                    <div>
                        <label className={labelClass}>Application Password</label>
                        <input type="password" value={wordpress.app_password} onChange={(e) => updateWp('app_password', e.target.value)} className={inputClass} />
                    </div>
                </div>
            </div>

            {/* Actions */}
            <div className="flex gap-3">
                <button
                    onClick={() => startMigration(true)}
                    disabled={submitting}
                    className="inline-flex items-center gap-2 rounded-lg border border-purple-300 bg-purple-50 px-6 py-3 text-sm font-medium text-purple-700 hover:bg-purple-100 disabled:opacity-50"
                >
                    <FlaskConical className="h-4 w-4" />
                    Dry Run
                </button>
                <button
                    onClick={() => startMigration(false)}
                    disabled={submitting}
                    className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-6 py-3 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
                >
                    {submitting ? (
                        <Loader2 className="h-4 w-4 animate-spin" />
                    ) : (
                        <Play className="h-4 w-4" />
                    )}
                    Start Migration
                </button>
            </div>
        </div>
    );
}
