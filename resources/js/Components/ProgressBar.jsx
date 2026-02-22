export default function ProgressBar({ value = 0, max = 100, className = '' }) {
    const percent = max > 0 ? Math.min(100, Math.round((value / max) * 100)) : 0;

    return (
        <div className={`w-full bg-gray-200 rounded-full h-2.5 ${className}`}>
            <div
                className="bg-blue-600 h-2.5 rounded-full transition-all duration-300"
                style={{ width: `${percent}%` }}
            />
        </div>
    );
}
