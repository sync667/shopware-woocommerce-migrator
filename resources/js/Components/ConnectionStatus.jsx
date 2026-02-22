export default function ConnectionStatus({ connected, label }) {
    return (
        <div className="flex items-center gap-2">
            <span
                className={`inline-block w-3 h-3 rounded-full ${
                    connected === null
                        ? 'bg-gray-400'
                        : connected
                          ? 'bg-green-500'
                          : 'bg-red-500'
                }`}
            />
            <span className="text-sm text-gray-700">{label}</span>
        </div>
    );
}
