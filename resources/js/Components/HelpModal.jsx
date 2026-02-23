import { X } from 'lucide-react';

export default function HelpModal({ isOpen, onClose, title, children }) {
    if (!isOpen) return null;

    return (
        <div className="fixed inset-0 z-50 overflow-y-auto" onClick={onClose}>
            <div className="flex min-h-screen items-center justify-center p-4">
                {/* Backdrop */}
                <div className="fixed inset-0 bg-black bg-opacity-50 transition-opacity" />

                {/* Modal */}
                <div
                    className="relative w-full max-w-2xl rounded-lg bg-white shadow-xl"
                    onClick={(e) => e.stopPropagation()}
                >
                    {/* Header */}
                    <div className="flex items-center justify-between border-b border-gray-200 px-6 py-4">
                        <h3 className="text-lg font-semibold text-gray-900">{title}</h3>
                        <button
                            onClick={onClose}
                            className="rounded-lg p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
                        >
                            <X className="h-5 w-5" />
                        </button>
                    </div>

                    {/* Content */}
                    <div className="max-h-[calc(100vh-200px)] overflow-y-auto px-6 py-4">
                        {children}
                    </div>

                    {/* Footer */}
                    <div className="border-t border-gray-200 px-6 py-4">
                        <button
                            onClick={onClose}
                            className="w-full rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
                        >
                            Got it, thanks!
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}
