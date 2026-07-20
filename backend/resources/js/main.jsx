import { createRoot } from 'react-dom/client';
import { getToken } from './api/token';
import { useEffect, useState } from 'react';

/**
 * Phase 1 placeholder for the offline app shell.
 *
 * Its only job right now is to prove the auth bridge end to end: the session
 * cookie mints a JWT, and that JWT is accepted by the JSON API. Screens and
 * the Dexie/outbox layer arrive in Phases 2–3.
 */
function App({ userName }) {
    const [status, setStatus] = useState('checking');
    const [identity, setIdentity] = useState(null);

    useEffect(() => {
        let cancelled = false;

        (async () => {
            const token = await getToken();

            if (!token) {
                // No token means no sync — never "logged out". Phase 2 shows
                // cached data here instead of an error.
                if (!cancelled) setStatus('offline');
                return;
            }

            const response = await fetch('/api/v1/whoami', {
                headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
            });

            if (cancelled) return;

            if (!response.ok) {
                setStatus('error');
                return;
            }

            setIdentity(await response.json());
            setStatus('ready');
        })();

        return () => {
            cancelled = true;
        };
    }, []);

    return (
        <div className="mx-auto max-w-md p-4">
            <h1 className="mb-2 text-xl font-bold">नमस्ते, {userName}</h1>

            {/* aria-live so a screen reader hears the state change rather than
                being left on a silently-updated region. */}
            <div className="card" aria-live="polite">
                {status === 'checking' && <p className="text-ink-muted">जाँच हो रही है…</p>}

                {status === 'offline' && (
                    <p className="text-warning">
                        ऑफ़लाइन — आपका काम सुरक्षित है और कनेक्ट होने पर सिंक हो जाएगा।
                    </p>
                )}

                {status === 'error' && <p className="text-danger">API कनेक्शन विफल।</p>}

                {status === 'ready' && (
                    <div className="space-y-1 text-sm">
                        <p className="text-success font-medium">✓ session → JWT → API</p>
                        <p className="tabular text-ink-muted">user_id: {identity.user_id}</p>
                        <p className="tabular text-ink-muted">
                            tenant_id: {identity.tenant_id ?? '—'}
                        </p>
                        <p className="text-ink-muted">role: {identity.role ?? '—'}</p>
                    </div>
                )}
            </div>
        </div>
    );
}

const root = document.getElementById('app-root');
createRoot(root).render(<App userName={root.dataset.userName} />);
