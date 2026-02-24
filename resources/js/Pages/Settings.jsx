import { useState, useEffect } from 'react';
import HelpModal from '../Components/HelpModal';
import { guides } from '../Components/SetupGuides';
import { ArrowLeft, Play, FlaskConical, Loader2, ChevronDown, ChevronUp, Check, X, AlertCircle, HelpCircle, LogOut, Upload, Database, FileUp } from 'lucide-react';
import { logout } from '../utils/auth';

export default function Settings() {
    // Check if cloning from existing migration
    const urlParams = new URLSearchParams(window.location.search);
    const cloneMigrationId = urlParams.get('clone');

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
        custom_headers: {},
    });
    const [customHeadersEnabled, setCustomHeadersEnabled] = useState(false);

    // Migration options
    const [syncMode, setSyncMode] = useState('full');
    const [conflictStrategy, setConflictStrategy] = useState('shopware_wins');
    const [cleanWoocommerce, setCleanWoocommerce] = useState(false);

    // CMS options
    const [cmsEnabled, setCmsEnabled] = useState(false);
    const [cmsMigrateAll, setCmsMigrateAll] = useState(true);
    const [cmsPages, setCmsPages] = useState([]);
    const [selectedCmsPages, setSelectedCmsPages] = useState([]);
    const [loadingCmsPages, setLoadingCmsPages] = useState(false);

    // Shopware config options
    const [availableLanguages, setAvailableLanguages] = useState([]);
    const [loadingLanguages, setLoadingLanguages] = useState(false);

    // Database dump upload mode
    const [dbSourceMode, setDbSourceMode] = useState('direct'); // 'direct' or 'dump'
    const [dumpFile, setDumpFile] = useState(null);
    const [dumpUploading, setDumpUploading] = useState(false);
    const [dumpResult, setDumpResult] = useState(null);
    const [dumpContainerName, setDumpContainerName] = useState(null);

    const [testResults, setTestResults] = useState(null);
    const [submitting, setSubmitting] = useState(false);
    const [testing, setTesting] = useState(false);
    const [activeGuide, setActiveGuide] = useState(null);

    const updateShopware = (key, value) =>
        setShopware((prev) => ({ ...prev, [key]: value }));
    const updateWoo = (key, value) =>
        setWoocommerce((prev) => ({ ...prev, [key]: value }));
    const updateWp = (key, value) =>
        setWordpress((prev) => ({ ...prev, [key]: value }));
    const updateSsh = (key, value) =>
        setSshConfig((prev) => ({ ...prev, [key]: value }));

    const handleDumpUpload = async () => {
        if (!dumpFile) return;

        setDumpUploading(true);
        setDumpResult(null);

        try {
            const formData = new FormData();
            formData.append('dump_file', dumpFile);

            const res = await fetch('/api/dump/upload', {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body: formData,
            });
            const data = await res.json();

            if (data.success) {
                setDumpResult(data);
                setDumpContainerName(data.container_name);

                // Auto-fill Shopware connection details
                setShopware((prev) => ({
                    ...prev,
                    db_host: data.connection.db_host,
                    db_port: data.connection.db_port,
                    db_database: data.connection.db_database,
                    db_username: data.connection.db_username,
                    db_password: data.connection.db_password,
                }));

                // Auto-populate test results for Shopware
                setTestResults((prev) => ({
                    ...prev,
                    shopware: {
                        success: true,
                        details: {
                            database: data.connection.db_database,
                            version: data.validation?.mysql_version || 'MySQL 8.0',
                            tables_found: data.validation?.tables_found?.length || 0,
                        }
                    }
                }));
            } else {
                setDumpResult({ success: false, error: data.error, validation: data.validation });
            }
        } catch (err) {
            setDumpResult({ success: false, error: err.message });
        }

        setDumpUploading(false);
    };

    const handleDumpCleanup = async () => {
        if (!dumpContainerName) return;

        try {
            await fetch('/api/dump/cleanup', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ container_name: dumpContainerName }),
            });
            setDumpContainerName(null);
            setDumpResult(null);
            setDumpFile(null);
        } catch (err) {
            console.error('Cleanup failed:', err);
        }
    };

    // Load settings from existing migration if cloning
    useEffect(() => {
        if (cloneMigrationId) {
            fetch(`/api/migrations/${cloneMigrationId}/status`)
                .then(res => res.json())
                .then(data => {
                    const migration = data.migration;
                    if (migration?.settings) {
                        const settings = typeof migration.settings === 'string'
                            ? JSON.parse(migration.settings)
                            : migration.settings;

                        // Pre-fill form with cloned settings
                        setName(migration.name + ' (Copy)');

                        if (settings.shopware) {
                            setShopware(settings.shopware);
                            if (settings.shopware.ssh) {
                                setSshEnabled(true);
                                setSshConfig(settings.shopware.ssh);
                            }
                        }

                        if (settings.woocommerce) {
                            setWoocommerce(settings.woocommerce);
                        }

                        if (settings.wordpress) {
                            setWordpress(settings.wordpress);
                            if (settings.wordpress.custom_headers && Object.keys(settings.wordpress.custom_headers).length > 0) {
                                setCustomHeadersEnabled(true);
                            }
                        }

                        // Load additional options
                        setSyncMode(migration.sync_mode || 'full');
                        setConflictStrategy(migration.conflict_strategy || 'shopware_wins');
                        setCleanWoocommerce(migration.clean_woocommerce || false);

                        // Show notification
                        alert(`Settings loaded from migration: ${migration.name}`);
                    }
                })
                .catch(err => {
                    console.error('Failed to load migration settings:', err);
                    alert('Failed to load migration settings');
                });
        }
    }, [cloneMigrationId]);

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

            // Filter out empty custom headers
            const wpConfig = { ...wordpress };
            if (wpConfig.custom_headers) {
                wpConfig.custom_headers = Object.fromEntries(
                    Object.entries(wpConfig.custom_headers).filter(([_, v]) => v && v.trim() !== '')
                );
                if (Object.keys(wpConfig.custom_headers).length === 0) {
                    delete wpConfig.custom_headers;
                }
            }

            const res = await fetch('/api/test-connections', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({
                    shopware: swConfig,
                    woocommerce,
                    wordpress: wpConfig,
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

    const loadShopwareConfig = async () => {
        setLoadingLanguages(true);
        try {
            const swConfig = {
                db_host: shopware.db_host,
                db_port: shopware.db_port,
                db_database: shopware.db_database,
                db_username: shopware.db_username,
                db_password: shopware.db_password,
            };

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

            // Load languages
            const langRes = await fetch('/api/shopware/languages', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(swConfig),
            });
            const langData = await langRes.json();

            if (langData.success) {
                setAvailableLanguages(langData.languages);

                // Auto-select first/default language if none selected
                if (!shopware.language_id && langData.languages.length > 0) {
                    updateShopware('language_id', langData.languages[0].id);
                }

                // Since we successfully connected to Shopware, auto-populate test results
                setTestResults((prev) => ({
                    ...prev,
                    shopware: {
                        success: true,
                        details: {
                            database: swConfig.db_database,
                            languages_found: langData.languages.length,
                        }
                    }
                }));
            } else {
                alert('Failed to load languages: ' + langData.error);
                setTestResults((prev) => ({
                    ...prev,
                    shopware: {
                        success: false,
                        error: langData.error,
                    }
                }));
            }

            // Load live version ID
            const versionRes = await fetch('/api/shopware/live-version', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(swConfig),
            });
            const versionData = await versionRes.json();

            if (versionData.success && !shopware.live_version_id) {
                updateShopware('live_version_id', versionData.live_version_id);
            }
        } catch (err) {
            alert('Failed to load Shopware configuration: ' + err.message);
            setTestResults((prev) => ({
                ...prev,
                shopware: {
                    success: false,
                    error: err.message,
                }
            }));
        }
        setLoadingLanguages(false);
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

            // Filter out empty custom headers
            const wpConfig = { ...wordpress };
            if (wpConfig.custom_headers) {
                wpConfig.custom_headers = Object.fromEntries(
                    Object.entries(wpConfig.custom_headers).filter(([_, v]) => v && v.trim() !== '')
                );
                if (Object.keys(wpConfig.custom_headers).length === 0) {
                    delete wpConfig.custom_headers;
                }
            }

            const res = await fetch('/api/migrations', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({
                    name: name || `Migration ${new Date().toLocaleString()}`,
                    is_dry_run: isDryRun,
                    clean_woocommerce: cleanWoocommerce,
                    sync_mode: syncMode,
                    conflict_strategy: conflictStrategy,
                    cms_options: cmsOptions,
                    settings: { shopware: swConfig, woocommerce, wordpress: wpConfig },
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

    const HelpButton = ({ guideKey }) => (
        <button
            type="button"
            onClick={() => setActiveGuide(guideKey)}
            className="ml-2 inline-flex items-center text-blue-600 hover:text-blue-700"
            title="Click for help"
        >
            <HelpCircle className="h-4 w-4" />
        </button>
    );

    return (
        <div className="mx-auto max-w-4xl px-4 py-8">
            <div className="mb-6 flex items-center justify-between">
                <div className="flex items-center gap-3">
                    <a href="/" className="text-gray-400 hover:text-gray-600">
                        <ArrowLeft className="h-5 w-5" />
                    </a>
                    <h1 className="text-2xl font-bold text-gray-900">New Migration</h1>
                </div>
                <button
                    onClick={logout}
                    className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                    title="Logout"
                >
                    <LogOut className="h-4 w-4" />
                    Logout
                </button>
            </div>

            {/* Clone Notice */}
            {cloneMigrationId && (
                <div className="rounded-lg border-2 border-blue-200 bg-blue-50 p-4">
                    <div className="flex items-start gap-3">
                        <AlertCircle className="h-5 w-5 text-blue-600 mt-0.5 flex-shrink-0" />
                        <div>
                            <h3 className="text-sm font-medium text-blue-900">Cloning Migration Settings</h3>
                            <p className="mt-1 text-sm text-blue-800">
                                Settings have been loaded from migration #{cloneMigrationId}. Review and modify as needed, then start the migration.
                            </p>
                        </div>
                    </div>
                </div>
            )}

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

                {/* Clean WooCommerce Option */}
                <div className="mt-4 border-t border-gray-200 pt-4">
                    <label className="flex items-start gap-3 cursor-pointer">
                        <input
                            type="checkbox"
                            checked={cleanWoocommerce}
                            onChange={(e) => setCleanWoocommerce(e.target.checked)}
                            className="mt-1 rounded border-gray-300 text-red-600 focus:ring-red-500"
                        />
                        <div className="flex-1">
                            <div className="flex items-center gap-2">
                                <span className="text-sm font-medium text-gray-900">Clean WooCommerce Before Migration</span>
                                <span className="rounded bg-red-100 px-2 py-0.5 text-xs text-red-700">Destructive</span>
                            </div>
                            <p className="mt-1 text-xs text-gray-500">
                                <AlertCircle className="inline h-3 w-3 mr-1" />
                                Delete all existing WooCommerce products, categories, customers, orders, coupons, and reviews before migration.
                                <span className="font-medium text-red-600"> This cannot be undone!</span>
                            </p>
                        </div>
                    </label>
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
                <h2 className="mb-4 text-lg font-medium text-gray-900">
                    Shopware Source Database
                    <HelpButton guideKey="database_connection" />
                </h2>

                {/* Source Mode Toggle */}
                <div className="flex gap-3 mb-4">
                    <button
                        type="button"
                        onClick={() => setDbSourceMode('direct')}
                        className={`flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium border ${
                            dbSourceMode === 'direct'
                                ? 'border-blue-500 bg-blue-50 text-blue-700'
                                : 'border-gray-300 bg-white text-gray-600 hover:bg-gray-50'
                        }`}
                    >
                        <Database className="h-4 w-4" />
                        Direct Connection
                    </button>
                    <button
                        type="button"
                        onClick={() => setDbSourceMode('dump')}
                        className={`flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium border ${
                            dbSourceMode === 'dump'
                                ? 'border-blue-500 bg-blue-50 text-blue-700'
                                : 'border-gray-300 bg-white text-gray-600 hover:bg-gray-50'
                        }`}
                    >
                        <FileUp className="h-4 w-4" />
                        Upload SQL Dump
                    </button>
                </div>

                {dbSourceMode === 'dump' ? (
                    /* Dump Upload Mode */
                    <div>
                        <div className="p-4 bg-blue-50 border border-blue-200 rounded-lg mb-4">
                            <p className="text-sm text-blue-800">
                                <strong>How it works:</strong> Upload a database dump from your Shopware instance. A local Docker MySQL container will be
                                spawned to load the dump and serve as the data source for migration. Requires Docker to be installed.
                            </p>
                            <p className="mt-2 text-xs text-blue-700">
                                Supported formats: <code>.sql</code>, <code>.sql.gz</code>, <code>.tar.gz</code>, <code>.zip</code>
                            </p>
                        </div>

                        {/* File Upload */}
                        <div className="mb-4">
                            <label className={labelClass}>Database Dump File</label>
                            <div className="flex items-center gap-3">
                                <label className="flex-1 cursor-pointer">
                                    <div className={`flex items-center justify-center gap-2 rounded-lg border-2 border-dashed px-4 py-6 text-center ${
                                        dumpFile ? 'border-green-300 bg-green-50' : 'border-gray-300 hover:border-blue-400 hover:bg-gray-50'
                                    }`}>
                                        {dumpFile ? (
                                            <>
                                                <Check className="h-5 w-5 text-green-600" />
                                                <span className="text-sm text-green-700">{dumpFile.name} ({(dumpFile.size / (1024 * 1024)).toFixed(1)} MB)</span>
                                            </>
                                        ) : (
                                            <>
                                                <Upload className="h-5 w-5 text-gray-400" />
                                                <span className="text-sm text-gray-500">Click to select dump file</span>
                                            </>
                                        )}
                                    </div>
                                    <input
                                        type="file"
                                        accept=".sql,.gz,.zip,.tgz"
                                        onChange={(e) => {
                                            setDumpFile(e.target.files[0] || null);
                                            setDumpResult(null);
                                        }}
                                        className="hidden"
                                    />
                                </label>
                            </div>
                        </div>

                        {/* Upload Button */}
                        <div className="mb-4">
                            <button
                                onClick={handleDumpUpload}
                                disabled={!dumpFile || dumpUploading}
                                className="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center gap-2"
                            >
                                {dumpUploading ? (
                                    <>
                                        <Loader2 className="h-4 w-4 animate-spin" />
                                        Uploading &amp; Importing...
                                    </>
                                ) : (
                                    <>
                                        <Upload className="h-4 w-4" />
                                        Upload &amp; Import Dump
                                    </>
                                )}
                            </button>
                            {dumpUploading && (
                                <p className="mt-2 text-xs text-gray-500">
                                    This may take several minutes depending on the dump size. A Docker MySQL container is being created and the dump is being imported.
                                </p>
                            )}
                        </div>

                        {/* Dump Result */}
                        {dumpResult && (
                            <div className={`p-4 rounded-lg mb-4 ${dumpResult.success ? 'bg-green-50 border border-green-200' : 'bg-red-50 border border-red-200'}`}>
                                <div className="flex items-center gap-2 mb-2">
                                    {dumpResult.success ? (
                                        <Check className="h-5 w-5 text-green-600" />
                                    ) : (
                                        <X className="h-5 w-5 text-red-600" />
                                    )}
                                    <span className="font-medium text-sm">
                                        {dumpResult.success ? 'Dump imported successfully' : 'Import failed'}
                                    </span>
                                </div>
                                {dumpResult.success && dumpResult.validation && (
                                    <div className="text-sm text-gray-700 space-y-1 ml-7">
                                        {dumpResult.validation.mysql_version && (
                                            <div>✓ MySQL Version: {dumpResult.validation.mysql_version}</div>
                                        )}
                                        <div>✓ Tables found: {dumpResult.validation.tables_found?.join(', ')}</div>
                                        <div>✓ Container: {dumpResult.container_name}</div>
                                        <div>✓ Connection: {dumpResult.connection?.db_host}:{dumpResult.connection?.db_port}</div>
                                        {dumpResult.validation.warnings?.length > 0 && (
                                            <div className="mt-2">
                                                {dumpResult.validation.warnings.map((w, i) => (
                                                    <div key={i} className="text-yellow-700">⚠ {w}</div>
                                                ))}
                                            </div>
                                        )}
                                    </div>
                                )}
                                {dumpResult.error && (
                                    <div className="text-sm text-red-700 ml-7">{dumpResult.error}</div>
                                )}
                                {dumpResult.validation && !dumpResult.success && dumpResult.validation.tables_missing?.length > 0 && (
                                    <div className="text-sm text-red-700 ml-7 mt-1">
                                        Missing tables: {dumpResult.validation.tables_missing.join(', ')}
                                    </div>
                                )}
                            </div>
                        )}

                        {/* Cleanup Button */}
                        {dumpContainerName && (
                            <div className="flex items-center gap-2 text-sm text-gray-600">
                                <span>Docker container: <code className="text-xs bg-gray-100 px-1 rounded">{dumpContainerName}</code></span>
                                <button
                                    onClick={handleDumpCleanup}
                                    className="text-red-600 hover:text-red-800 underline text-xs"
                                >
                                    Remove Container
                                </button>
                            </div>
                        )}

                        {/* Base URL - always needed for media migration */}
                        <div className="mt-4 border-t border-gray-200 pt-4">
                            <div>
                                <label className={labelClass}>Shopware Base URL (for media/image migration)</label>
                                <input type="text" value={shopware.base_url} onChange={(e) => updateShopware('base_url', e.target.value)} className={inputClass} placeholder="https://shop.example.com" />
                                <p className="mt-1 text-xs text-gray-500">
                                    Required to download product images from your Shopware instance during migration
                                </p>
                            </div>
                        </div>
                    </div>
                ) : (
                    /* Direct Connection Mode */
                    <div>
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
                        </div>

                        {/* SSH Tunnel Section */}
                        <div className="border-t border-gray-200 pt-4 mb-4">
                            <div className="flex items-center justify-between mb-3">
                                <label className="flex items-center gap-2 cursor-pointer">
                                    <input
                                        type="checkbox"
                                        checked={sshEnabled}
                                        onChange={(e) => setSshEnabled(e.target.checked)}
                                        className="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                    />
                                    <span className="text-sm font-medium text-gray-700">Use SSH Tunnel</span>
                                    <HelpButton guideKey="ssh_keys" />
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
                )}

                {/* Load Configuration Button - shared between modes */}
                <div className="mb-4">
                    <button
                        onClick={loadShopwareConfig}
                        disabled={loadingLanguages || !shopware.db_host || !shopware.db_database}
                        className="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        {loadingLanguages ? 'Connecting...' : 'Connect & Load Configuration'}
                    </button>
                    <p className="mt-2 text-xs text-gray-500">
                        {dbSourceMode === 'dump'
                            ? 'Connect to the imported database to load available languages and version info'
                            : 'This will connect to your Shopware database, test the connection, and load available languages'}
                    </p>
                </div>

                {/* Language & Version Selection */}
                {availableLanguages.length > 0 && (
                    <div className="grid grid-cols-2 gap-4 p-4 bg-green-50 rounded-md border border-green-200">
                        <div>
                            <label className={labelClass}>
                                Language
                                <HelpButton guideKey="shopware_language_id" />
                            </label>
                            <select
                                value={shopware.language_id}
                                onChange={(e) => updateShopware('language_id', e.target.value)}
                                className={inputClass}
                            >
                                {availableLanguages.map(lang => (
                                    <option key={lang.id} value={lang.id}>
                                        {lang.name} {lang.locale_code ? `(${lang.locale_code})` : ''}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className={labelClass}>
                                Live Version ID
                                <HelpButton guideKey="shopware_version_id" />
                            </label>
                            <input
                                type="text"
                                value={shopware.live_version_id}
                                onChange={(e) => updateShopware('live_version_id', e.target.value)}
                                className={inputClass}
                                placeholder="Auto-detected"
                            />
                            <p className="mt-1 text-xs text-green-700">✓ Auto-detected from database</p>
                        </div>
                    </div>
                )}
            </div>

            {/* WooCommerce Target */}
            <div className={sectionClass}>
                <h2 className="mb-4 text-lg font-medium text-gray-900">
                    WooCommerce Target API
                    <HelpButton guideKey="woocommerce_keys" />
                </h2>
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
                <h2 className="mb-4 text-lg font-medium text-gray-900">
                    WordPress Media API
                    <HelpButton guideKey="wordpress_app_password" />
                </h2>
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

            {/* Custom Headers for Zero Trust */}
            <div className={sectionClass}>
                <div
                    onClick={() => setCustomHeadersEnabled(!customHeadersEnabled)}
                    className="flex items-center justify-between cursor-pointer mb-4"
                >
                    <div className="flex items-center gap-2">
                        <h2 className="text-lg font-medium text-gray-900">
                            Custom Headers (Optional)
                        </h2>
                        <span className="rounded bg-blue-100 px-2 py-0.5 text-xs text-blue-700">Zero Trust</span>
                    </div>
                    {customHeadersEnabled ? <ChevronUp className="h-5 w-5 text-gray-400" /> : <ChevronDown className="h-5 w-5 text-gray-400" />}
                </div>

                {customHeadersEnabled && (
                    <div className="space-y-4">
                        <p className="text-sm text-gray-600 mb-3">
                            Add custom HTTP headers for Zero Trust services like Cloudflare Access, Azure AD, or other authentication proxies.
                        </p>

                        {/* Cloudflare Access */}
                        <div className="p-4 bg-gray-50 rounded-md">
                            <h3 className="text-sm font-medium text-gray-900 mb-3 flex items-center gap-2">
                                <span>Cloudflare Access</span>
                                <a
                                    href="https://developers.cloudflare.com/cloudflare-one/identity/service-tokens/"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="text-blue-600 hover:text-blue-800"
                                >
                                    <HelpCircle className="h-4 w-4" />
                                </a>
                            </h3>
                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <label className={labelClass}>CF-Access-Client-Id</label>
                                    <input
                                        type="text"
                                        value={wordpress.custom_headers?.['CF-Access-Client-Id'] || ''}
                                        onChange={(e) => updateWp('custom_headers', {
                                            ...wordpress.custom_headers,
                                            'CF-Access-Client-Id': e.target.value
                                        })}
                                        className={inputClass}
                                        placeholder="Service Token Client ID"
                                    />
                                </div>
                                <div>
                                    <label className={labelClass}>CF-Access-Client-Secret</label>
                                    <input
                                        type="password"
                                        value={wordpress.custom_headers?.['CF-Access-Client-Secret'] || ''}
                                        onChange={(e) => updateWp('custom_headers', {
                                            ...wordpress.custom_headers,
                                            'CF-Access-Client-Secret': e.target.value
                                        })}
                                        className={inputClass}
                                        placeholder="Service Token Client Secret"
                                    />
                                </div>
                            </div>
                        </div>

                        {/* Azure AD / Other */}
                        <div className="p-4 bg-gray-50 rounded-md">
                            <h3 className="text-sm font-medium text-gray-900 mb-3">
                                Other Custom Headers
                            </h3>
                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <label className={labelClass}>X-Auth-Token</label>
                                    <input
                                        type="text"
                                        value={wordpress.custom_headers?.['X-Auth-Token'] || ''}
                                        onChange={(e) => updateWp('custom_headers', {
                                            ...wordpress.custom_headers,
                                            'X-Auth-Token': e.target.value
                                        })}
                                        className={inputClass}
                                        placeholder="Optional"
                                    />
                                </div>
                                <div>
                                    <label className={labelClass}>X-API-Key</label>
                                    <input
                                        type="text"
                                        value={wordpress.custom_headers?.['X-API-Key'] || ''}
                                        onChange={(e) => updateWp('custom_headers', {
                                            ...wordpress.custom_headers,
                                            'X-API-Key': e.target.value
                                        })}
                                        className={inputClass}
                                        placeholder="Optional"
                                    />
                                </div>
                            </div>
                            <p className="mt-3 text-xs text-gray-500">
                                💡 Leave empty if not using Zero Trust authentication. Headers with empty values will be ignored.
                            </p>
                        </div>
                    </div>
                )}
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

            {/* Configuration Warning */}
            {(!shopware.language_id || !shopware.live_version_id) && (
                <div className={`${sectionClass} bg-yellow-50 border-2 border-yellow-300`}>
                    <div className="flex items-start gap-3">
                        <AlertCircle className="h-5 w-5 text-yellow-600 mt-0.5 flex-shrink-0" />
                        <div>
                            <h3 className="text-sm font-medium text-yellow-900">Configuration Required</h3>
                            <p className="mt-1 text-sm text-yellow-800">
                                You must load Shopware languages and configuration before testing connections or starting migration.
                                Click the <strong>&quot;Load Languages &amp; Configuration&quot;</strong> button in the Shopware Source Database section above.
                            </p>
                        </div>
                    </div>
                </div>
            )}

            {/* Connection Test */}
            <div className={sectionClass}>
                <div className="flex items-center justify-between mb-4">
                    <h2 className="text-lg font-medium text-gray-900">Connection Status</h2>
                    <button
                        onClick={testAllConnections}
                        disabled={testing || !shopware.language_id || !shopware.live_version_id}
                        className="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        {testing ? 'Testing...' : 'Test WooCommerce & WordPress'}
                    </button>
                </div>
                {(!shopware.language_id || !shopware.live_version_id) && (
                    <p className="text-sm text-gray-500 mb-4">
                        ℹ️ Connect to Shopware first (using &quot;Connect &amp; Load Configuration&quot; button above), then test WooCommerce and WordPress
                    </p>
                )}

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
            <div className="space-y-3">
                {(!shopware.language_id || !shopware.live_version_id) && (
                    <div className="flex items-center gap-2 text-sm text-yellow-700 bg-yellow-50 border border-yellow-200 rounded-lg p-3">
                        <AlertCircle className="h-4 w-4 flex-shrink-0" />
                        <span>
                            Migration buttons are disabled until you load Shopware configuration (language & version ID)
                        </span>
                    </div>
                )}
                <div className="flex gap-3">
                    <button
                        onClick={() => startMigration(true)}
                        disabled={submitting || !shopware.language_id || !shopware.live_version_id}
                        className="inline-flex items-center gap-2 rounded-lg border border-purple-300 bg-purple-50 px-6 py-3 text-sm font-medium text-purple-700 hover:bg-purple-100 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        <FlaskConical className="h-4 w-4" />
                        Dry Run
                    </button>
                    <button
                        onClick={() => startMigration(false)}
                        disabled={submitting || !shopware.language_id || !shopware.live_version_id}
                        className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-6 py-3 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed"
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

            {/* Help Modals */}
            {activeGuide && guides[activeGuide] && (
                <HelpModal
                    isOpen={true}
                    onClose={() => setActiveGuide(null)}
                    title={guides[activeGuide].title}
                >
                    {guides[activeGuide].content}
                </HelpModal>
            )}
        </div>
    );
}
