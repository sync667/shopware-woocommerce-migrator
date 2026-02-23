import { useState } from 'react';
import ConnectionStatus from '../Components/ConnectionStatus';
import { ArrowLeft, Play, FlaskConical, Loader2, ChevronDown, ChevronUp, Check, X, AlertCircle } from 'lucide-react';

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
        ssh: null,
    });
    const [sshEnabled, setSshEnabled] = useState(false);
    const [sshConfig, setSshConfig] = useState({
        host: '',
        port: 22,
        username: '',
        auth_method: 'key',
        password: '',
        key: '',
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

    // Migration options
    const [syncMode, setSyncMode] = useState('full');
    const [conflictStrategy, setConflictStrategy] = useState('shopware_wins');

    // CMS options
    const [cmsEnabled, setCmsEnabled] = useState(false);
    const [cmsMigrateAll, setCmsMigrateAll] = useState(true);
    const [cmsPages, setCmsPages] = useState([]);
    const [selectedCmsPages, setSelectedCmsPages] = useState([]);
    const [loadingCmsPages, setLoadingCmsPages] = useState(false);

    const [testResults, setTestResults] = useState(null);
    const [submitting, setSubmitting] = useState(false);
    const [testing, setTesting] = useState(false);

    const updateShopware = (key, value) =>
        setShopware((prev) => ({ ...prev, [key]: value }));
    const updateWoo = (key, value) =>
        setWoocommerce((prev) => ({ ...prev, [key]: value }));
    const updateWp = (key, value) =>
        setWordpress((prev) => ({ ...prev, [key]: value }));
    const updateSsh = (key, value) =>
        setSshConfig((prev) => ({ ...prev, [key]: value }));

    const testAllConnections = async () => {
        setTesting(true);
        setTestResults(null);

        try {
            const swConfig = { ...shopware };
            if (sshEnabled) {
                swConfig.ssh = {
                    host: sshConfig.host,
                    port: sshConfig.port,
                    username: sshConfig.username,
                    ...(sshConfig.auth_method === 'password'
                        ? { password: sshConfig.password }
                        : { key: sshConfig.key }
                    )
                };
            }

            const res = await fetch('/api/test-connections', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({
                    shopware: swConfig,
                    woocommerce,
                    wordpress,
                }),
            });
            const data = await res.json();
            setTestResults(data.results);
        } catch (err) {
            setTestResults({
                shopware: { success: false, error: err.message },
                woocommerce: { success: false, error: err.message },
                wordpress: { success: false, error: err.message },
            });
        }
        setTesting(false);
    };

    const loadCmsPages = async () => {
        setLoadingCmsPages(true);
        try {
            const swConfig = { ...shopware };
            if (sshEnabled) {
                swConfig.ssh = {
                    host: sshConfig.host,
                    port: sshConfig.port,
                    username: sshConfig.username,
                    ...(sshConfig.auth_method === 'password'
                        ? { password: sshConfig.password }
                        : { key: sshConfig.key }
                    )
                };
            }

            const res = await fetch('/api/cms-pages/list', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(swConfig),
            });
            const data = await res.json();
            if (data.success) {
                setCmsPages(data.pages);
            } else {
                alert('Failed to load CMS pages: ' + data.error);
            }
        } catch (err) {
            alert('Failed to load CMS pages: ' + err.message);
        }
        setLoadingCmsPages(false);
    };

    const startMigration = async (isDryRun = false) => {
        setSubmitting(true);
        try {
            const swConfig = { ...shopware };
            if (sshEnabled) {
                swConfig.ssh = {
                    host: sshConfig.host,
                    port: sshConfig.port,
                    username: sshConfig.username,
                    ...(sshConfig.auth_method === 'password'
                        ? { password: sshConfig.password }
                        : { key: sshConfig.key }
                    )
                };
            }

            const cmsOptions = cmsEnabled ? {
                migrate_all: cmsMigrateAll,
                selected_ids: cmsMigrateAll ? null : selectedCmsPages,
            } : null;

            const res = await fetch('/api/migrations', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({
                    name: name || `Migration ${new Date().toLocaleString()}`,
                    is_dry_run: isDryRun,
                    sync_mode: syncMode,
                    conflict_strategy: conflictStrategy,
                    cms_options: cmsOptions,
                    settings: { shopware: swConfig, woocommerce, wordpress },
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
    const sectionClass = 'mb-6 rounded-lg border border-gray-200 bg-white p-6';

    return (
        <div className="mx-auto max-w-4xl px-4 py-8">
            <div className="mb-6 flex items-center gap-3">
                <a href="/" className="text-gray-400 hover:text-gray-600">
                    <ArrowLeft className="h-5 w-5" />
                </a>
                <h1 className="text-2xl font-bold text-gray-900">New Migration</h1>
            </div>

            {/* Migration Name */}
            <div className={sectionClass}>
                <h2 className="mb-4 text-lg font-medium text-gray-900">Migration Name</h2>
                <input
                    type="text"
                    placeholder="e.g. Full Migration January 2026"
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    className={inputClass}
                />
            </div>

            {/* Migration Options */}
            <div className={sectionClass}>
                <h2 className="mb-4 text-lg font-medium text-gray-900">Migration Options</h2>
                <div className="grid grid-cols-2 gap-4">
                    <div>
                        <label className={labelClass}>Migration Mode</label>
                        <select value={syncMode} onChange={(e) => setSyncMode(e.target.value)} className={inputClass}>
                            <option value="full">Full Migration (all records)</option>
                            <option value="delta">Delta Migration (only changes)</option>
                        </select>
                        <p className="mt-1 text-xs text-gray-500">
                            {syncMode === 'delta' ? 'Only migrate new/updated records since last sync' : 'Migrate all records'}
                        </p>
                    </div>
                    <div>
                        <label className={labelClass}>Conflict Resolution</label>
                        <select value={conflictStrategy} onChange={(e) => setConflictStrategy(e.target.value)} className={inputClass}>
                            <option value="shopware_wins">Shopware Wins (overwrite WooCommerce)</option>
                            <option value="woo_wins">WooCommerce Wins (skip updates)</option>
                            <option value="manual">Manual Review (flag conflicts)</option>
                        </select>
                    </div>
                </div>
            </div>

            {/* Migration Entities Info */}
            <div className={sectionClass}>
                <h2 className="mb-4 text-lg font-medium text-gray-900">What Will Be Migrated</h2>
                <div className="grid grid-cols-2 md:grid-cols-3 gap-3 text-sm">
                    <div className="flex items-center gap-2 p-2 bg-gray-50 rounded">
                        <Check className="h-4 w-4 text-green-600" />
                        <span>Manufacturers</span>
                    </div>
                    <div className="flex items-center gap-2 p-2 bg-gray-50 rounded">
                        <Check className="h-4 w-4 text-green-600" />
                        <span>Taxes</span>
                    </div>
                    <div className="flex items-center gap-2 p-2 bg-gray-50 rounded">
                        <Check className="h-4 w-4 text-green-600" />
                        <span>Categories</span>
                    </div>
                    <div className="flex items-center gap-2 p-2 bg-gray-50 rounded">
                        <Check className="h-4 w-4 text-green-600" />
                        <span>Products & Variations</span>
                    </div>
                    <div className="flex items-center gap-2 p-2 bg-gray-50 rounded">
                        <Check className="h-4 w-4 text-green-600" />
                        <span>Customers</span>
                    </div>
                    <div className="flex items-center gap-2 p-2 bg-gray-50 rounded">
                        <Check className="h-4 w-4 text-green-600" />
                        <span>Orders</span>
                    </div>
                    <div className="flex items-center gap-2 p-2 bg-gray-50 rounded">
                        <Check className="h-4 w-4 text-green-600" />
                        <span>Coupons</span>
                    </div>
                    <div className="flex items-center gap-2 p-2 bg-gray-50 rounded">
                        <Check className="h-4 w-4 text-green-600" />
                        <span>Reviews</span>
                    </div>
                    <div className="flex items-center gap-2 p-2 bg-gray-50 rounded">
                        <Check className="h-4 w-4 text-green-600" />
                        <span>Shipping Methods</span>
                    </div>
                    <div className="flex items-center gap-2 p-2 bg-gray-50 rounded">
                        <Check className="h-4 w-4 text-green-600" />
                        <span>Payment Methods</span>
                    </div>
                    <div className="flex items-center gap-2 p-2 bg-gray-50 rounded">
                        <Check className="h-4 w-4 text-green-600" />
                        <span>SEO URLs</span>
                    </div>
                    <div className={`flex items-center gap-2 p-2 rounded ${cmsEnabled ? 'bg-green-50' : 'bg-gray-100'}`}>
                        {cmsEnabled ? (
                            <Check className="h-4 w-4 text-green-600" />
                        ) : (
                            <AlertCircle className="h-4 w-4 text-gray-400" />
                        )}
                        <span className={cmsEnabled ? '' : 'text-gray-500'}>CMS Pages (Optional)</span>
                    </div>
                </div>
                <p className="mt-3 text-xs text-gray-500">
                    All entities are migrated automatically. CMS pages migration can be configured below.
                </p>
            </div>

            {/* Shopware Source */}
            <div className={sectionClass}>
                <h2 className="mb-4 text-lg font-medium text-gray-900">Shopware Source Database</h2>
                <div className="grid grid-cols-2 gap-4 mb-4">
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

                {/* SSH Tunnel Section */}
                <div className="border-t border-gray-200 pt-4">
                    <div className="flex items-center justify-between mb-3">
                        <label className="flex items-center gap-2 cursor-pointer">
                            <input
                                type="checkbox"
                                checked={sshEnabled}
                                onChange={(e) => setSshEnabled(e.target.checked)}
                                className="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                            />
                            <span className="text-sm font-medium text-gray-700">Use SSH Tunnel</span>
                        </label>
                    </div>

                    {sshEnabled && (
                        <div className="grid grid-cols-2 gap-4 p-4 bg-blue-50 rounded-md border border-blue-200">
                            <div className="col-span-2">
                                <label className={labelClass}>SSH Host</label>
                                <input type="text" value={sshConfig.host} onChange={(e) => updateSsh('host', e.target.value)} className={inputClass} placeholder="server.example.com" />
                            </div>
                            <div>
                                <label className={labelClass}>SSH Port</label>
                                <input type="number" value={sshConfig.port} onChange={(e) => updateSsh('port', parseInt(e.target.value) || 22)} className={inputClass} />
                            </div>
                            <div>
                                <label className={labelClass}>SSH Username</label>
                                <input type="text" value={sshConfig.username} onChange={(e) => updateSsh('username', e.target.value)} className={inputClass} />
                            </div>
                            <div className="col-span-2">
                                <label className={labelClass}>Authentication Method</label>
                                <select value={sshConfig.auth_method} onChange={(e) => updateSsh('auth_method', e.target.value)} className={inputClass}>
                                    <option value="key">SSH Key (Recommended)</option>
                                    <option value="password">Password</option>
                                </select>
                            </div>
                            {sshConfig.auth_method === 'key' ? (
                                <div className="col-span-2">
                                    <label className={labelClass}>Private Key Path</label>
                                    <input type="text" value={sshConfig.key} onChange={(e) => updateSsh('key', e.target.value)} className={inputClass} placeholder="/path/to/private_key" />
                                </div>
                            ) : (
                                <div className="col-span-2">
                                    <label className={labelClass}>SSH Password</label>
                                    <input type="password" value={sshConfig.password} onChange={(e) => updateSsh('password', e.target.value)} className={inputClass} />
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </div>

            {/* WooCommerce Target */}
            <div className={sectionClass}>
                <h2 className="mb-4 text-lg font-medium text-gray-900">WooCommerce Target API</h2>
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
            <div className={sectionClass}>
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

            {/* CMS Pages */}
            <div className={sectionClass}>
                <div className="flex items-center justify-between mb-4">
                    <label className="flex items-center gap-2 cursor-pointer">
                        <input
                            type="checkbox"
                            checked={cmsEnabled}
                            onChange={(e) => setCmsEnabled(e.target.checked)}
                            className="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                        />
                        <span className="text-lg font-medium text-gray-900">Migrate CMS Pages</span>
                    </label>
                </div>

                {cmsEnabled && (
                    <div className="space-y-4">
                        <div className="flex items-center gap-4">
                            <label className="flex items-center gap-2">
                                <input
                                    type="radio"
                                    checked={cmsMigrateAll}
                                    onChange={() => setCmsMigrateAll(true)}
                                    className="border-gray-300 text-blue-600 focus:ring-blue-500"
                                />
                                <span className="text-sm text-gray-700">Migrate all CMS pages</span>
                            </label>
                            <label className="flex items-center gap-2">
                                <input
                                    type="radio"
                                    checked={!cmsMigrateAll}
                                    onChange={() => setCmsMigrateAll(false)}
                                    className="border-gray-300 text-blue-600 focus:ring-blue-500"
                                />
                                <span className="text-sm text-gray-700">Select specific pages</span>
                            </label>
                        </div>

                        {!cmsMigrateAll && (
                            <div>
                                <button
                                    onClick={loadCmsPages}
                                    disabled={loadingCmsPages}
                                    className="mb-3 rounded bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200 disabled:opacity-50"
                                >
                                    {loadingCmsPages ? 'Loading...' : 'Load Available Pages'}
                                </button>

                                {cmsPages.length > 0 && (
                                    <div className="max-h-60 overflow-y-auto border border-gray-200 rounded-md p-3">
                                        {cmsPages.map(page => (
                                            <label key={page.id} className="flex items-center gap-2 py-2 hover:bg-gray-50 px-2 rounded cursor-pointer">
                                                <input
                                                    type="checkbox"
                                                    checked={selectedCmsPages.includes(page.id)}
                                                    onChange={(e) => {
                                                        if (e.target.checked) {
                                                            setSelectedCmsPages([...selectedCmsPages, page.id]);
                                                        } else {
                                                            setSelectedCmsPages(selectedCmsPages.filter(id => id !== page.id));
                                                        }
                                                    }}
                                                    className="rounded border-gray-300 text-blue-600"
                                                />
                                                <span className="text-sm flex-1">{page.name || 'Untitled'}</span>
                                                <span className="text-xs text-gray-500">{page.type}</span>
                                            </label>
                                        ))}
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                )}
            </div>

            {/* Connection Test */}
            <div className={sectionClass}>
                <div className="flex items-center justify-between mb-4">
                    <h2 className="text-lg font-medium text-gray-900">Test Connections</h2>
                    <button
                        onClick={testAllConnections}
                        disabled={testing}
                        className="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
                    >
                        {testing ? 'Testing...' : 'Test All Connections'}
                    </button>
                </div>

                {testResults && (
                    <div className="space-y-3">
                        {/* Shopware Results */}
                        <div className={`p-4 rounded-md ${testResults.shopware?.success ? 'bg-green-50 border border-green-200' : 'bg-red-50 border border-red-200'}`}>
                            <div className="flex items-center gap-2 mb-2">
                                {testResults.shopware?.success ? (
                                    <Check className="h-5 w-5 text-green-600" />
                                ) : (
                                    <X className="h-5 w-5 text-red-600" />
                                )}
                                <span className="font-medium">Shopware Database</span>
                            </div>
                            {testResults.shopware?.success && testResults.shopware.details && (
                                <div className="text-sm text-gray-700 space-y-1 ml-7">
                                    <div>✓ MySQL {testResults.shopware.details.version}</div>
                                    <div>✓ Database: {testResults.shopware.details.database}</div>
                                    <div>✓ Products: {testResults.shopware.details.product_count}</div>
                                    {testResults.shopware.details.language && <div>✓ Language: {testResults.shopware.details.language}</div>}
                                </div>
                            )}
                            {testResults.shopware?.error && (
                                <div className="text-sm text-red-700 ml-7">{testResults.shopware.error}</div>
                            )}
                        </div>

                        {/* WooCommerce Results */}
                        {testResults.woocommerce && (
                            <div className={`p-4 rounded-md ${testResults.woocommerce?.success ? 'bg-green-50 border border-green-200' : 'bg-red-50 border border-red-200'}`}>
                                <div className="flex items-center gap-2 mb-2">
                                    {testResults.woocommerce?.success ? (
                                        <Check className="h-5 w-5 text-green-600" />
                                    ) : (
                                        <X className="h-5 w-5 text-red-600" />
                                    )}
                                    <span className="font-medium">WooCommerce API</span>
                                </div>
                                {testResults.woocommerce?.success && testResults.woocommerce.details && (
                                    <div className="text-sm text-gray-700 ml-7">
                                        <div>✓ Version: {testResults.woocommerce.details.version}</div>
                                    </div>
                                )}
                                {testResults.woocommerce?.error && (
                                    <div className="text-sm text-red-700 ml-7">{testResults.woocommerce.error}</div>
                                )}
                            </div>
                        )}

                        {/* WordPress Results */}
                        {testResults.wordpress && (
                            <div className={`p-4 rounded-md ${testResults.wordpress?.success ? 'bg-green-50 border border-green-200' : 'bg-red-50 border border-red-200'}`}>
                                <div className="flex items-center gap-2 mb-2">
                                    {testResults.wordpress?.success ? (
                                        <Check className="h-5 w-5 text-green-600" />
                                    ) : (
                                        <X className="h-5 w-5 text-red-600" />
                                    )}
                                    <span className="font-medium">WordPress Media</span>
                                </div>
                                {testResults.wordpress?.success && (
                                    <div className="text-sm text-gray-700 ml-7">
                                        <div>✓ Upload test successful</div>
                                    </div>
                                )}
                                {testResults.wordpress?.error && (
                                    <div className="text-sm text-red-700 ml-7">{testResults.wordpress.error}</div>
                                )}
                            </div>
                        )}
                    </div>
                )}
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
