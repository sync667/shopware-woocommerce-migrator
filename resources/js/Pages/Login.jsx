import { useState } from 'react';
import { Key, AlertCircle, Loader2 } from 'lucide-react';

export default function Login() {
    const [token, setToken] = useState('');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');

    const handleSubmit = async (e) => {
        e.preventDefault();
        setError('');
        setLoading(true);

        try {
            const res = await fetch('/auth/validate', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                credentials: 'same-origin', // Include cookies for session
                body: JSON.stringify({ token: token.trim() }),
            });

            const data = await res.json();

            if (data.success) {
                // Session created on server, just redirect
                window.location.href = '/';
            } else {
                setError(data.error || 'Invalid access token');
            }
        } catch (err) {
            setError('Failed to validate token. Please try again.');
            console.error(err);
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="min-h-screen bg-gradient-to-br from-blue-50 to-indigo-100 flex items-center justify-center p-4">
            <div className="w-full max-w-md">
                {/* Logo/Title */}
                <div className="text-center mb-8">
                    <div className="inline-flex items-center justify-center w-16 h-16 bg-blue-600 rounded-full mb-4">
                        <Key className="h-8 w-8 text-white" />
                    </div>
                    <h1 className="text-3xl font-bold text-gray-900 mb-2">
                        Shopware → WooCommerce
                    </h1>
                    <p className="text-gray-600">Migration Tool</p>
                </div>

                {/* Login Card */}
                <div className="bg-white rounded-lg shadow-xl p-8">
                    <h2 className="text-xl font-semibold text-gray-900 mb-6">
                        Enter Access Token
                    </h2>

                    <form onSubmit={handleSubmit} className="space-y-4">
                        <div>
                            <label htmlFor="token" className="block text-sm font-medium text-gray-700 mb-2">
                                Access Token
                            </label>
                            <input
                                id="token"
                                type="text"
                                value={token}
                                onChange={(e) => setToken(e.target.value)}
                                placeholder="Enter your access token"
                                className="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 font-mono text-sm"
                                disabled={loading}
                                autoFocus
                            />
                            <p className="mt-2 text-xs text-gray-500">
                                Token creates a 24-hour session. You won't need to enter it again during this time.
                            </p>
                        </div>

                        {error && (
                            <div className="flex items-start gap-2 p-3 bg-red-50 border border-red-200 rounded-lg">
                                <AlertCircle className="h-5 w-5 text-red-600 flex-shrink-0 mt-0.5" />
                                <span className="text-sm text-red-800">{error}</span>
                            </div>
                        )}

                        <button
                            type="submit"
                            disabled={loading || !token.trim()}
                            className="w-full flex items-center justify-center gap-2 bg-blue-600 text-white px-6 py-3 rounded-lg font-medium hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                        >
                            {loading ? (
                                <>
                                    <Loader2 className="h-5 w-5 animate-spin" />
                                    Validating...
                                </>
                            ) : (
                                <>
                                    <Key className="h-5 w-5" />
                                    Access Tool
                                </>
                            )}
                        </button>
                    </form>

                    {/* Help Section */}
                    <div className="mt-6 pt-6 border-t border-gray-200">
                        <h3 className="text-sm font-medium text-gray-900 mb-3">
                            How does authentication work?
                        </h3>
                        <div className="space-y-3">
                            <div className="bg-blue-50 rounded-lg p-3 border border-blue-200">
                                <p className="text-xs text-blue-900 font-medium mb-1">🔐 Token → 24-Hour Session</p>
                                <p className="text-xs text-blue-700">
                                    Enter your token once to create a secure 24-hour session.
                                    You'll stay logged in until the session expires.
                                </p>
                            </div>

                            <div className="bg-gray-50 rounded-lg p-4 space-y-3">
                                <p className="text-xs text-gray-700 font-medium">
                                    Server administrators can generate tokens:
                                </p>
                                <div className="relative">
                                    <code className="block bg-gray-900 text-green-400 px-3 py-2 rounded text-xs">
                                        php artisan token:generate
                                    </code>
                                    <button
                                        onClick={() => navigator.clipboard.writeText('php artisan token:generate')}
                                        className="absolute right-2 top-1/2 -translate-y-1/2 text-xs bg-gray-800 hover:bg-gray-700 text-gray-300 px-2 py-1 rounded transition-colors"
                                    >
                                        Copy
                                    </button>
                                </div>
                                <p className="text-xs text-gray-500">
                                    Optional: Add expiration with <code className="bg-gray-200 px-1 rounded">--expires=7</code>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Footer */}
                <div className="mt-6 text-center text-sm text-gray-600">
                    <p>Access is restricted to authorized users only</p>
                </div>
            </div>
        </div>
    );
}
