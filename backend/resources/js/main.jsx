import { createRoot } from 'react-dom/client';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { getToken } from './api/token';
import { apiWrite } from './api/write';
import { openTenantDb } from './offline/db';
import { enqueue, pendingCount, stalenessState } from './offline/outbox';
import { khataList, ledgerFor, outstandingFor } from './offline/khata';
import { movementsFor, stockList } from './offline/stock';
import { sync } from './offline/sync';
import { registerServiceWorker } from './offline/register-sw';
import { setLocale, t, today } from './i18n';
import { matchRoute, navigate, useRoute } from './router';
import { BottomNav, StalenessBanner, SyncBar } from './components/Chrome';
import { Home } from './screens/Home';
import { KhataList } from './screens/KhataList';
import { CustomerLedger } from './screens/CustomerLedger';
import { NewCustomer, RecordPayment, RecordSale } from './screens/Forms';
import { StockList } from './screens/StockList';
import { MaterialDetail, NewMaterial, RecordMovement } from './screens/StockForms';

const ROUTES = {
    '/': 'home',
    '/khata': 'khata',
    '/khata/:uuid': 'ledger',
    '/customer/new': 'new-customer',
    '/payment/:uuid': 'payment',
    '/sale/:uuid': 'sale',
    '/sale': 'pick-customer',
    '/stock': 'stock',
    '/stock/:id': 'material',
    '/material/new': 'new-material',
    '/movement/:id': 'movement',
};

/** Roles that may see and manage stock (PRD §7). */
const STOCK_MANAGERS = ['owner', 'admin'];

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

function App({ userName, locale }) {
    setLocale(locale);

    const online = useOnline();
    const path = useRoute();
    const route = matchRoute(path, ROUTES);

    const [db, setDb] = useState(null);
    const [tenantId, setTenantId] = useState(null);
    const [role, setRole] = useState(null);
    const [customers, setCustomers] = useState([]);
    const [materials, setMaterials] = useState([]);
    const [packs, setPacks] = useState([]);
    const [queued, setQueued] = useState(0);
    const [staleness, setStaleness] = useState('ok');
    const [syncing, setSyncing] = useState(false);
    const [syncError, setSyncError] = useState(null);
    const [ledger, setLedger] = useState(null);

    /* --- boot: resolve tenant, open its cache ---------------------- */
    useEffect(() => {
        let cancelled = false;

        (async () => {
            const token = await getToken();
            if (!token) return; // offline with no session yet; nothing to open

            const response = await fetch('/api/v1/whoami', {
                headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
            });
            const identity = await response.json();

            if (cancelled || !identity.tenant_id) return;

            setTenantId(identity.tenant_id);
            setRole(identity.role);
            setDb(openTenantDb(identity.tenant_id));
        })();

        return () => {
            cancelled = true;
        };
    }, []);

    const canManageStock = STOCK_MANAGERS.includes(role);

    /* --- reload everything the screens read ------------------------ */
    const refresh = useCallback(
        async (database) => {
            if (!database) return;

            setCustomers(await khataList(database));
            setPacks(await database.product_packs.toArray());
            setQueued(await pendingCount(database));
            setStaleness(await stalenessState(database));

            // Only managers hold stock rows (sync/pull withholds them from
            // others), so only they compute the stock list.
            if (canManageStock) setMaterials(await stockList(database));
        },
        [canManageStock]
    );

    useEffect(() => {
        refresh(db);
    }, [db, refresh]);

    const runSync = useCallback(async () => {
        if (!db) return;

        setSyncing(true);

        const result = await sync(db);

        // 'no_token' just means offline — not a failure worth alarming anyone
        // about. Anything else is a genuine problem the user should see.
        setSyncError(result.ok || result.reason === 'no_token' ? null : result.reason);

        await refresh(db);
        setSyncing(false);
    }, [db, refresh]);

    // Sync when connectivity returns — the moment a backlog should clear.
    useEffect(() => {
        if (db && online) runSync();
    }, [db, online, runSync]);

    /* --- ledger for the open customer ------------------------------ */
    const activeCustomer = useMemo(
        () => customers.find((c) => c.uuid === route.params.uuid) ?? null,
        [customers, route.params.uuid]
    );

    useEffect(() => {
        if (!db || !activeCustomer) {
            setLedger(null);
            return;
        }

        let cancelled = false;

        (async () => {
            const entries = await ledgerFor(db, activeCustomer);
            const outstanding = await outstandingFor(db, activeCustomer);

            if (!cancelled) setLedger({ entries, outstanding });
        })();

        return () => {
            cancelled = true;
        };
    }, [db, activeCustomer, queued]);

    /* --- writes: outbox first, always ------------------------------ */
    const saveCustomer = async (values) => {
        const uuid = crypto.randomUUID();

        // Write to the local cache AND the outbox: the customer must appear in
        // the khata immediately, not after a round trip that may never happen.
        await db.customers.put({
            uuid,
            id: null, // assigned by the server on sync
            ...values,
            archived_at: null,
        });

        await enqueue(db, { type: 'customer', tenantId, uuid, payload: values });
        await refresh(db);

        if (online) runSync();
    };

    const savePayment = async ({ customer, amount, mode, payment_date }) => {
        await enqueue(db, {
            type: 'payment',
            tenantId,
            uuid: crypto.randomUUID(),
            payload: { customer_id: customer.id ?? customer.uuid, amount, mode, payment_date },
            // If the customer has not synced, the push must wait for its id.
            dependsOnUuid: customer.id ? null : customer.uuid,
        });

        await refresh(db);
        if (online) runSync();
    };

    const saveSale = async ({ customer, sale_date, lines, total }) => {
        await enqueue(db, {
            type: 'sale',
            tenantId,
            uuid: crypto.randomUUID(),
            // `total` is carried for the local balance only; the server
            // recomputes it from the lines and its own frozen rates.
            payload: { customer_id: customer.id ?? customer.uuid, sale_date, lines, total },
            dependsOnUuid: customer.id ? null : customer.uuid,
        });

        await refresh(db);
        if (online) runSync();
    };

    /* --- material detail for the open material --------------------- */
    const activeMaterial = useMemo(
        () => materials.find((m) => m.id === route.params.id) ?? null,
        [materials, route.params.id]
    );

    const [materialMovements, setMaterialMovements] = useState([]);

    useEffect(() => {
        if (!db || !activeMaterial) {
            setMaterialMovements([]);
            return;
        }

        let cancelled = false;

        (async () => {
            const rows = await movementsFor(db, activeMaterial);
            if (!cancelled) setMaterialMovements(rows);
        })();

        return () => {
            cancelled = true;
        };
    }, [db, activeMaterial]);

    /* --- stock writes: online-only, straight to the API ------------ */
    const saveMaterial = async (body) => {
        const result = await apiWrite('/raw-materials', body);
        // A successful write returns the row; pull it (and its movements) into
        // the cache so the list reflects it without waiting for the next sync.
        if (result.ok && online) await runSync();
        return result;
    };

    const saveMovement = async (body) => {
        const result = await apiWrite('/stock-movements', body);
        if (result.ok && online) await runSync();
        return result;
    };

    /* --- today's entries for Home ---------------------------------- */
    const [entriesToday, setEntriesToday] = useState([]);

    useEffect(() => {
        if (!db) return;

        let cancelled = false;

        (async () => {
            const all = await Promise.all(
                customers.map((customer) => ledgerFor(db, customer))
            );

            const stamp = today();
            const mine = all.flat().filter((entry) => entry.date === stamp);

            if (!cancelled) setEntriesToday(mine);
        })();

        return () => {
            cancelled = true;
        };
    }, [db, customers]);

    if (!db) {
        return (
            <div className="flex min-h-dvh items-center justify-center p-4">
                <p className="text-ink-muted">{t('loading')}</p>
            </div>
        );
    }

    const screen = () => {
        switch (route.name) {
            case 'khata':
                return <KhataList customers={customers} />;

            case 'ledger':
                return (
                    <CustomerLedger
                        customer={activeCustomer}
                        entries={ledger?.entries ?? []}
                        // null, not 0: showing ₹0.00 before the real balance
                        // loads is a wrong number a shopkeeper could act on.
                        outstandingPaise={ledger ? ledger.outstanding : null}
                    />
                );

            case 'new-customer':
                return <NewCustomer onSave={saveCustomer} />;

            case 'payment':
                return activeCustomer ? (
                    <RecordPayment customer={activeCustomer} onSave={savePayment} />
                ) : null;

            case 'sale':
                return activeCustomer ? (
                    <RecordSale customer={activeCustomer} packs={packs} onSave={saveSale} />
                ) : null;

            case 'pick-customer':
                // "Sales" tab with no customer chosen yet: the khata list IS the
                // picker, so send them there rather than inventing a second one.
                return <KhataList customers={customers} />;

            case 'stock':
            case 'material':
            case 'new-material':
            case 'movement':
                // Defence in depth: the tab is already hidden for non-managers,
                // but a salesman deep-linking to /stock must also be refused —
                // and they hold no stock rows anyway.
                if (!canManageStock) return <Home userName={userName} customers={customers} entriesToday={entriesToday} />;

                if (route.name === 'material') {
                    return (
                        <MaterialDetail
                            material={activeMaterial}
                            movements={materialMovements}
                            online={online}
                        />
                    );
                }
                if (route.name === 'new-material') return <NewMaterial onSave={saveMaterial} />;
                if (route.name === 'movement') {
                    return activeMaterial ? (
                        <RecordMovement material={activeMaterial} onSave={saveMovement} />
                    ) : null;
                }
                return <StockList materials={materials} online={online} />;

            default:
                return <Home userName={userName} customers={customers} entriesToday={entriesToday} />;
        }
    };

    return (
        <>
            <SyncBar
                online={online}
                queued={queued}
                syncing={syncing}
                error={syncError}
                onSync={runSync}
            />
            <StalenessBanner staleness={staleness} />
            {screen()}
            <BottomNav path={path} canManageStock={canManageStock} />
        </>
    );
}

registerServiceWorker();

const root = document.getElementById('app-root');
createRoot(root).render(<App userName={root.dataset.userName} locale={root.dataset.locale} />);
