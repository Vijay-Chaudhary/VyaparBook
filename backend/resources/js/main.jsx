import { createRoot } from 'react-dom/client';
import { useCallback, useEffect, useState } from 'react';
import { getToken } from './api/token';
import { openTenantDb } from './offline/db';
import { enqueue, pendingCount, stalenessState } from './offline/outbox';
import { sync } from './offline/sync';
import { registerServiceWorker } from './offline/register-sw';

/**
 * Phase 2 harness for the offline core.
 *
 * Still not the real UI (screens land in Phase 3). What it does is exercise the
 * offline path end to end in a real browser — queue a mutation, sync it, watch
 * the outbox drain — so the machinery is proven before any screen depends on it.
 */

function useOnline() {
    const [online, setOnline] = useState(navigator.onLine);

    useEffect(() => {
        const on = () => setOnline(true);
        const off = () => setOnline(false);

        window.addEventListener('online', on);
        window.addEventListener('offline', off);

        return () => {
            window.removeEventListener('online', on);
            window.removeEventListener('offline', off);
        };
    }, []);

    return online;
}

function App({ userName }) {
    const online = useOnline();
    const [tenantId, setTenantId] = useState(null);
    const [db, setDb] = useState(null);
    const [queued, setQueued] = useState(0);
    const [staleness, setStaleness] = useState('ok');
    const [lastSync, setLastSync] = useState(null);
    const [status, setStatus] = useState('starting');

    // Resolve the tenant, then open its namespaced cache.
    useEffect(() => {
        let cancelled = false;

        (async () => {
            const token = await getToken();

            if (!token) {
                if (!cancelled) setStatus('no-session');
                return;
            }

            const response = await fetch('/api/v1/whoami', {
                headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
            });

            if (cancelled) return;

            const identity = await response.json();

            if (!identity.tenant_id) {
                setStatus('no-tenant');
                return;
            }

            setTenantId(identity.tenant_id);
            setDb(openTenantDb(identity.tenant_id));
            setStatus('ready');
        })();

        return () => {
            cancelled = true;
        };
    }, []);

    const refresh = useCallback(async (database) => {
        setQueued(await pendingCount(database));
        setStaleness(await stalenessState(database));
    }, []);

    useEffect(() => {
        if (db) refresh(db);
    }, [db, refresh]);

    // Sync when connectivity returns — the moment a shop's backlog should clear.
    useEffect(() => {
        if (!db || !online) return;

        (async () => {
            const result = await sync(db);
            if (result.ok) setLastSync(new Date().toLocaleTimeString('en-IN'));
            await refresh(db);
        })();
    }, [db, online, refresh]);

    const recordTestSale = async () => {
        await enqueue(db, {
            type: 'customer',
            tenantId,
            uuid: crypto.randomUUID(),
            payload: { name: `परीक्षण ग्राहक ${new Date().toLocaleTimeString('en-IN')}` },
        });

        await refresh(db);
    };

    const syncNow = async () => {
        const result = await sync(db);
        if (result.ok) setLastSync(new Date().toLocaleTimeString('en-IN'));
        await refresh(db);
    };

    return (
        <div className="mx-auto max-w-md space-y-4 p-4">
            <h1 className="text-xl font-bold">नमस्ते, {userName}</h1>

            <div className="card space-y-2" aria-live="polite">
                <p data-testid="net" className={online ? 'text-success' : 'text-warning'}>
                    {online ? '● ऑनलाइन' : '○ ऑफ़लाइन — काम सुरक्षित है'}
                </p>

                <p className="text-sm text-ink-muted">
                    स्थिति: <span data-testid="status">{status}</span>
                </p>

                <p className="text-sm text-ink-muted">
                    कतार में: <span data-testid="queued" className="tabular font-medium">{queued}</span>
                </p>

                {lastSync && (
                    <p className="text-sm text-ink-muted">
                        अंतिम सिंक: <span className="tabular">{lastSync}</span>
                    </p>
                )}

                {staleness === 'warn' && (
                    <p className="text-warning text-sm">सिंक करने के लिए कनेक्ट करें।</p>
                )}
                {staleness === 'blocked' && (
                    <p className="text-danger text-sm">
                        बहुत दिनों से सिंक नहीं हुआ — नई प्रविष्टि रोक दी गई है।
                    </p>
                )}
            </div>

            <div className="flex gap-2">
                <button
                    type="button"
                    className="btn-primary flex-1"
                    onClick={recordTestSale}
                    disabled={!db}
                    data-testid="add"
                >
                    प्रविष्टि जोड़ें
                </button>

                <button
                    type="button"
                    className="btn-secondary flex-1"
                    onClick={syncNow}
                    disabled={!db}
                    data-testid="sync"
                >
                    सिंक करें
                </button>
            </div>
        </div>
    );
}

registerServiceWorker();

const root = document.getElementById('app-root');
createRoot(root).render(<App userName={root.dataset.userName} />);
