/**
 * Logout user (destroy session)
 */
export async function logout() {
    try {
        await fetch('/auth/logout', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            },
            credentials: 'same-origin', // Include session cookie
        });
    } catch (err) {
        console.error('Logout error:', err);
    } finally {
        window.location.href = '/login';
    }
}
