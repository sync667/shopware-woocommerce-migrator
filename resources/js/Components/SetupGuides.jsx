export const guides = {
    woocommerce_keys: {
        title: 'How to Get WooCommerce API Keys',
        content: (
            <div className="space-y-4">
                <p className="text-sm text-gray-700">
                    WooCommerce API keys allow this migration tool to read from and write to your WooCommerce store.
                </p>

                <div className="rounded-lg bg-blue-50 p-4">
                    <h4 className="font-medium text-blue-900 mb-2">Step-by-Step Instructions:</h4>
                    <ol className="list-decimal list-inside space-y-2 text-sm text-blue-800">
                        <li>Log into your WordPress admin panel</li>
                        <li>Go to <code className="bg-blue-100 px-1 py-0.5 rounded">WooCommerce → Settings → Advanced → REST API</code></li>
                        <li>Click <strong>"Add Key"</strong></li>
                        <li>Fill in the form:
                            <ul className="list-disc list-inside ml-4 mt-1 space-y-1">
                                <li><strong>Description:</strong> "Shopware Migration Tool"</li>
                                <li><strong>User:</strong> Select an admin user</li>
                                <li><strong>Permissions:</strong> Select <strong>"Read/Write"</strong></li>
                            </ul>
                        </li>
                        <li>Click <strong>"Generate API Key"</strong></li>
                        <li>Copy the <strong>Consumer Key</strong> and <strong>Consumer Secret</strong></li>
                    </ol>
                </div>

                <div className="rounded-lg bg-yellow-50 border border-yellow-200 p-4">
                    <p className="text-sm text-yellow-800">
                        <strong>⚠️ Important:</strong> The Consumer Secret is only shown once! Make sure to copy it immediately.
                    </p>
                </div>

                <div className="rounded-lg bg-gray-50 p-4">
                    <h4 className="font-medium text-gray-900 mb-2">Example Format:</h4>
                    <div className="space-y-2 text-sm font-mono">
                        <div>
                            <span className="text-gray-600">Consumer Key:</span>
                            <code className="block bg-white px-2 py-1 rounded mt-1">ck_1234567890abcdef1234567890abcdef12345678</code>
                        </div>
                        <div>
                            <span className="text-gray-600">Consumer Secret:</span>
                            <code className="block bg-white px-2 py-1 rounded mt-1">cs_1234567890abcdef1234567890abcdef12345678</code>
                        </div>
                    </div>
                </div>
            </div>
        ),
    },

    wordpress_app_password: {
        title: 'How to Create WordPress Application Password',
        content: (
            <div className="space-y-4">
                <p className="text-sm text-gray-700">
                    Application passwords allow this tool to upload media files to WordPress without exposing your main password.
                </p>

                <div className="rounded-lg bg-blue-50 p-4">
                    <h4 className="font-medium text-blue-900 mb-2">Step-by-Step Instructions:</h4>
                    <ol className="list-decimal list-inside space-y-2 text-sm text-blue-800">
                        <li>Log into your WordPress admin panel</li>
                        <li>Go to <code className="bg-blue-100 px-1 py-0.5 rounded">Users → Profile</code></li>
                        <li>Scroll down to the <strong>"Application Passwords"</strong> section</li>
                        <li>In the <strong>"New Application Password Name"</strong> field, enter: <code className="bg-blue-100 px-1 py-0.5 rounded">Shopware Migration</code></li>
                        <li>Click <strong>"Add New Application Password"</strong></li>
                        <li>Copy the generated password (it will have spaces, keep them!)</li>
                    </ol>
                </div>

                <div className="rounded-lg bg-yellow-50 border border-yellow-200 p-4">
                    <p className="text-sm text-yellow-800">
                        <strong>⚠️ Note:</strong> Application passwords are only shown once. If you lose it, you'll need to generate a new one.
                    </p>
                </div>

                <div className="rounded-lg bg-gray-50 p-4">
                    <h4 className="font-medium text-gray-900 mb-2">Example Format:</h4>
                    <div className="space-y-2 text-sm">
                        <div>
                            <span className="text-gray-600">Username:</span>
                            <code className="block bg-white px-2 py-1 rounded mt-1 font-mono">admin</code>
                        </div>
                        <div>
                            <span className="text-gray-600">Application Password:</span>
                            <code className="block bg-white px-2 py-1 rounded mt-1 font-mono">abcd 1234 EFGH 5678 ijkl 9012</code>
                            <p className="text-xs text-gray-500 mt-1">Keep the spaces as shown above</p>
                        </div>
                    </div>
                </div>

                <div className="rounded-lg bg-red-50 border border-red-200 p-4">
                    <h4 className="font-medium text-red-900 mb-2">Troubleshooting:</h4>
                    <p className="text-sm text-red-800">
                        If you don't see "Application Passwords" section, your site might need HTTPS enabled or the REST API might be disabled.
                    </p>
                </div>
            </div>
        ),
    },

    shopware_language_id: {
        title: 'How to Select Shopware Language',
        content: (
            <div className="space-y-4">
                <p className="text-sm text-gray-700">
                    The Language ID determines which language translations will be migrated from Shopware.
                </p>

                <div className="rounded-lg bg-green-50 p-4 border border-green-200">
                    <h4 className="font-medium text-green-900 mb-2">✨ Automatic Method (Easiest)</h4>
                    <ol className="list-decimal list-inside space-y-2 text-sm text-green-800">
                        <li>Fill in your Shopware database connection details above</li>
                        <li>Click the <strong>"Connect & Load Configuration"</strong> button</li>
                        <li>Select your desired language from the dropdown</li>
                    </ol>
                    <p className="text-xs text-green-700 mt-2">
                        This will connect to your database, test the connection, and automatically fetch all available languages!
                    </p>
                </div>

                <div className="rounded-lg bg-blue-50 p-4">
                    <h4 className="font-medium text-blue-900 mb-2">Manual Method (Alternative)</h4>
                    <ol className="list-decimal list-inside space-y-2 text-sm text-blue-800">
                        <li>Connect to your Shopware database using phpMyAdmin or similar tool</li>
                        <li>Run this SQL query:
                            <pre className="bg-blue-100 p-2 rounded mt-2 overflow-x-auto">
{`SELECT
  LOWER(HEX(id)) as language_id,
  name
FROM language
WHERE name LIKE '%English%' OR name LIKE '%Deutsch%';`}
                            </pre>
                        </li>
                        <li>Copy the <code className="bg-blue-100 px-1 py-0.5 rounded">language_id</code> value for your desired language</li>
                    </ol>
                </div>

                <div className="rounded-lg bg-purple-50 p-4">
                    <h4 className="font-medium text-purple-900 mb-2">Method 2: From Shopware Admin</h4>
                    <ol className="list-decimal list-inside space-y-2 text-sm text-purple-800">
                        <li>Log into Shopware admin</li>
                        <li>Go to <code className="bg-purple-100 px-1 py-0.5 rounded">Settings → Shop → Languages</code></li>
                        <li>Click on your language (e.g., "English")</li>
                        <li>Check the browser's URL - the ID is in the URL</li>
                        <li>Note: This might be a short ID, not the hex format needed</li>
                    </ol>
                </div>

                <div className="rounded-lg bg-gray-50 p-4">
                    <h4 className="font-medium text-gray-900 mb-2">Example Format:</h4>
                    <code className="block bg-white px-2 py-1 rounded font-mono text-sm">2fbb5fe2e29a4d70aa5854ce7ce3e20b</code>
                    <p className="text-xs text-gray-500 mt-2">32-character hexadecimal string (without dashes)</p>
                </div>

                <div className="rounded-lg bg-green-50 border border-green-200 p-4">
                    <p className="text-sm text-green-800">
                        <strong>💡 Tip:</strong> The default English language ID in Shopware 6 is often <code className="bg-green-100 px-1 py-0.5 rounded">2fbb5fe2e29a4d70aa5854ce7ce3e20b</code>
                    </p>
                </div>
            </div>
        ),
    },

    shopware_version_id: {
        title: 'Shopware Live Version ID',
        content: (
            <div className="space-y-4">
                <p className="text-sm text-gray-700">
                    Shopware uses versioning for content. The Live Version ID represents published/active content.
                </p>

                <div className="rounded-lg bg-green-50 p-4 border border-green-200">
                    <h4 className="font-medium text-green-900 mb-2">✨ Automatic Detection (Recommended)</h4>
                    <p className="text-sm text-green-800 mb-2">
                        When you click <strong>"Connect & Load Configuration"</strong>, the Live Version ID is automatically detected from your database.
                    </p>
                    <p className="text-xs text-green-700">
                        No manual configuration needed!
                    </p>
                </div>

                <div className="rounded-lg bg-blue-50 p-4">
                    <h4 className="font-medium text-blue-900 mb-2">Manual Method: Use Default Value</h4>
                    <div className="bg-blue-100 p-3 rounded">
                        <p className="text-sm text-blue-800 mb-2">The Live Version ID is <strong>almost always</strong> the same:</p>
                        <code className="block bg-white px-3 py-2 rounded font-mono text-sm">0fa91ce3e96a4bc2be4bd9ce752c3425</code>
                        <button
                            onClick={() => navigator.clipboard.writeText('0fa91ce3e96a4bc2be4bd9ce752c3425')}
                            className="mt-2 text-xs bg-blue-600 text-white px-2 py-1 rounded hover:bg-blue-700"
                        >
                            Copy to Clipboard
                        </button>
                    </div>
                </div>

                <div className="rounded-lg bg-purple-50 p-4">
                    <h4 className="font-medium text-purple-900 mb-2">Method 2: Verify from Database</h4>
                    <ol className="list-decimal list-inside space-y-2 text-sm text-purple-800">
                        <li>Connect to your Shopware database</li>
                        <li>Run this SQL query:
                            <pre className="bg-purple-100 p-2 rounded mt-2 overflow-x-auto">
{`SELECT
  LOWER(HEX(id)) as version_id,
  name
FROM version
WHERE name = 'live';`}
                            </pre>
                        </li>
                        <li>Copy the <code className="bg-purple-100 px-1 py-0.5 rounded">version_id</code> value</li>
                    </ol>
                </div>

                <div className="rounded-lg bg-gray-50 p-4">
                    <h4 className="font-medium text-gray-900 mb-2">What is this for?</h4>
                    <p className="text-sm text-gray-600">
                        Shopware 6 keeps draft and published versions of content. This ID ensures we only migrate
                        <strong> published/live content</strong>, not drafts or old versions.
                    </p>
                </div>
            </div>
        ),
    },

    ssh_keys: {
        title: 'How to Set Up SSH Tunnel',
        content: (
            <div className="space-y-4">
                <p className="text-sm text-gray-700">
                    SSH tunnels are used when your Shopware database is not directly accessible from this server (behind a firewall).
                </p>

                <div className="rounded-lg bg-blue-50 p-4">
                    <h4 className="font-medium text-blue-900 mb-2">When do you need SSH tunnel?</h4>
                    <ul className="list-disc list-inside space-y-1 text-sm text-blue-800">
                        <li>Your database is on a remote server</li>
                        <li>The database port (3306) is not exposed publicly</li>
                        <li>You have SSH access to the server hosting the database</li>
                    </ul>
                </div>

                <div className="rounded-lg bg-purple-50 p-4">
                    <h4 className="font-medium text-purple-900 mb-2">Using SSH Key (Recommended)</h4>
                    <ol className="list-decimal list-inside space-y-2 text-sm text-purple-800">
                        <li>Generate an SSH key pair on your local machine:
                            <pre className="bg-purple-100 p-2 rounded mt-2 overflow-x-auto">
ssh-keygen -t rsa -b 4096 -f ~/.ssh/shopware_migration
                            </pre>
                        </li>
                        <li>Copy the public key to the remote server:
                            <pre className="bg-purple-100 p-2 rounded mt-2 overflow-x-auto">
ssh-copy-id -i ~/.ssh/shopware_migration.pub user@server.com
                            </pre>
                        </li>
                        <li>Enter the <strong>absolute path</strong> to your private key:
                            <code className="block bg-purple-100 px-2 py-1 rounded mt-2">/home/username/.ssh/shopware_migration</code>
                        </li>
                    </ol>
                </div>

                <div className="rounded-lg bg-orange-50 p-4">
                    <h4 className="font-medium text-orange-900 mb-2">Using Password</h4>
                    <p className="text-sm text-orange-800 mb-2">
                        If you don't have SSH keys set up, you can use password authentication:
                    </p>
                    <ul className="list-disc list-inside space-y-1 text-sm text-orange-800">
                        <li>Select "Password" as authentication method</li>
                        <li>Enter your SSH password</li>
                        <li>Note: Requires <code className="bg-orange-100 px-1 py-0.5 rounded">sshpass</code> to be installed on the server</li>
                    </ul>
                </div>

                <div className="rounded-lg bg-gray-50 p-4">
                    <h4 className="font-medium text-gray-900 mb-2">Example Configuration:</h4>
                    <div className="space-y-2 text-sm">
                        <div className="bg-white p-2 rounded">
                            <span className="text-gray-600">SSH Host:</span> <code className="font-mono">server.example.com</code>
                        </div>
                        <div className="bg-white p-2 rounded">
                            <span className="text-gray-600">SSH Port:</span> <code className="font-mono">22</code>
                        </div>
                        <div className="bg-white p-2 rounded">
                            <span className="text-gray-600">SSH Username:</span> <code className="font-mono">root</code> or <code className="font-mono">ubuntu</code>
                        </div>
                        <div className="bg-white p-2 rounded">
                            <span className="text-gray-600">Private Key:</span> <code className="font-mono">/home/user/.ssh/id_rsa</code>
                        </div>
                    </div>
                </div>
            </div>
        ),
    },

    database_connection: {
        title: 'How to Get Database Connection Details',
        content: (
            <div className="space-y-4">
                <p className="text-sm text-gray-700">
                    You need access to the Shopware MySQL/MariaDB database to read products, customers, orders, etc.
                </p>

                <div className="rounded-lg bg-blue-50 p-4">
                    <h4 className="font-medium text-blue-900 mb-2">Method 1: From Shopware Configuration</h4>
                    <ol className="list-decimal list-inside space-y-2 text-sm text-blue-800">
                        <li>Connect to your Shopware server via SSH or FTP</li>
                        <li>Open the file: <code className="bg-blue-100 px-1 py-0.5 rounded">.env</code></li>
                        <li>Look for these variables:
                            <pre className="bg-blue-100 p-2 rounded mt-2 overflow-x-auto text-xs">
{`DATABASE_URL=mysql://username:password@host:3306/database_name`}
                            </pre>
                        </li>
                        <li>Extract the values:
                            <ul className="list-disc list-inside ml-4 mt-2 space-y-1">
                                <li><strong>Host:</strong> After @ symbol</li>
                                <li><strong>Port:</strong> After the colon (usually 3306)</li>
                                <li><strong>Username:</strong> After //</li>
                                <li><strong>Password:</strong> Between : and @</li>
                                <li><strong>Database:</strong> After the port</li>
                            </ul>
                        </li>
                    </ol>
                </div>

                <div className="rounded-lg bg-purple-50 p-4">
                    <h4 className="font-medium text-purple-900 mb-2">Method 2: Ask Your Hosting Provider</h4>
                    <p className="text-sm text-purple-800">
                        Contact your hosting provider and request:
                    </p>
                    <ul className="list-disc list-inside space-y-1 text-sm text-purple-800 mt-2">
                        <li>MySQL/MariaDB host address</li>
                        <li>Database name</li>
                        <li>Database username</li>
                        <li>Database password</li>
                        <li>Port number (usually 3306)</li>
                    </ul>
                </div>

                <div className="rounded-lg bg-yellow-50 border border-yellow-200 p-4">
                    <h4 className="font-medium text-yellow-900 mb-2">Security Note:</h4>
                    <p className="text-sm text-yellow-800">
                        This migration tool only <strong>reads</strong> from Shopware database. It never writes or modifies Shopware data.
                        Your Shopware store will remain completely unchanged.
                    </p>
                </div>
            </div>
        ),
    },
};
