import { useAuth } from '../hooks/useAuth';

export function HomePage() {
    const { user, logout, isAuthenticated } = useAuth();

    return (
        <div className="home-page">
            <header>
                <h1>Welcome to the App</h1>
                {isAuthenticated && (
                    <nav>
                        <span>Hello, {user?.name}</span>
                        <button onClick={logout}>Logout</button>
                    </nav>
                )}
            </header>

            <main>
                {isAuthenticated ? (
                    <div className="authenticated-content">
                        <h2>Dashboard</h2>
                        <div className="user-info">
                            <p>
                                <strong>Name:</strong> {user?.name}
                            </p>
                            <p>
                                <strong>Email:</strong> {user?.email}
                            </p>
                            {user?.roles && user.roles.length > 0 && (
                                <p>
                                    <strong>Roles:</strong> {user.roles.map((r) => r.name).join(', ')}
                                </p>
                            )}
                        </div>
                    </div>
                ) : (
                    <div className="guest-content">
                        <p>Please login to access the dashboard.</p>
                        <a href="/login" className="btn">
                            Login
                        </a>
                        <a href="/register" className="btn">
                            Register
                        </a>
                    </div>
                )}
            </main>
        </div>
    );
}
