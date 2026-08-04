import { createRoot } from 'react-dom/client';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { getActiveBusiness, getImpersonation, getToken, setActiveBusiness } from './api/token';
import { apiWrite } from './api/write';
import { closeTenantDb, deleteTenantDb, openTenantDb } from './offline/db';
import { buildOrderList, SELF_APPROVING_ROLES } from './offline/orders';
import { enqueue, pending, pendingCount, stalenessState } from './offline/outbox';
import { productName } from './offline/catalog';
import { khataList, ledgerFor, outstandingFor } from './offline/khata';
import { annotateReversals, movementsFor, stockList } from './offline/stock';
import { assertSafeToSwitch, sync } from './offline/sync';
import { registerServiceWorker } from './offline/register-sw';
import { getLocale, setLocale, t, today } from './i18n';
import { matchRoute, navigate, useRoute } from './router';
import { BottomNav, BusinessSwitcher, ImpersonationBanner, StalenessBanner, SyncBar } from './components/Chrome';
import { Home } from './screens/Home';
import { KhataList } from './screens/KhataList';
import { CustomerLedger } from './screens/CustomerLedger';
import { NewCustomer, RecordPayment, RecordOrder } from './screens/Forms';
import { Orders } from './screens/Orders';
import { StockList } from './screens/StockList';
import { MaterialDetail, NewMaterial, RecordMovement } from './screens/StockForms';
import { ProductionList, RecordBatch } from './screens/Production';
import { BusinessPicker } from './screens/BusinessPicker';

const ROUTES = {
    '/': 'home',
    '/khata': 'khata',
    '/khata/:uuid': 'ledger',
    '/customer/new': 'new-customer',
    '/payment/:uuid': 'payment',
    // Kept mapped to the same names as before the order-workflow rename, so an
    // old bookmark or home-screen shortcut still resolves.
    '/sale/:uuid': 'order',
    '/sale': 'pick-customer',
    '/order/:uuid': 'order',
    '/order': 'pick-customer',
    '/orders': 'orders',
    '/stock': 'stock',
    '/stock/:id': 'material',
    '/material/new': 'new-material',
    '/movement/:id': 'movement',
    '/production': 'production',
    '/batch/new': 'new-batch',
};

/** Roles that may see and manage stock (PRD §7). */
const STOCK_MANAGERS = ['owner', 'admin'];

/**
 * Who may accept an order. Same two roles, but a different reason — acceptance
 * is an online-only Blade screen outside this app, so the phone can only link
 * to it, never do it.
 *
 * The same list decides whether a queued order shows as accepted, so it lives
 * in offline/orders.js and is imported rather than declared twice.
 */
const ORDER_APPROVERS = SELF_APPROVING_ROLES;

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
    // Who this device is. whoami has always returned it; nothing kept it until
    // orders became visible shop-wide and the list had to say which are yours.
    const [userId, setUserId] = useState(null);
    // Non-null when a platform admin launched a read-only "view as tenant" from
    // the console. Drives the banner and blocks every write path.
    const [impersonation, setImpersonation] = useState(null);
    const [memberships, setMemberships] = useState([]);
    const [needsPick, setNeedsPick] = useState(false);
    const [customers, setCustomers] = useState([]);
    const [orders, setOrders] = useState([]);
    const [materials, setMaterials] = useState([]);
    const [batches, setBatches] = useState([]);
    const [products, setProducts] = useState([]);
    const [packs, setPacks] = useState([]);
    const [queued, setQueued] = useState(0);
    const [staleness, setStaleness] = useState('ok');
    const [syncing, setSyncing] = useState(false);
    const [syncError, setSyncError] = useState(null);
    const [ledger, setLedger] = useState(null);
    // Bumped to re-run tenant resolution after a business switch.
    const [bootKey, setBootKey] = useState(0);

    /* --- boot: resolve the tenant, or ask which business ----------- */
    useEffect(() => {
        let cancelled = false;

        (async () => {
            const token = await getToken();
            if (!token || cancelled) return; // offline with no session; nothing to open

            // Captured as a side effect of getToken(): non-null iff this is a
            // read-only support view launched from the platform console.
            setImpersonation(getImpersonation());

            const identity = await (
                await fetch('/api/v1/whoami', {
                    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
                })
            ).json();

            if (cancelled) return;

            if (identity.tenant_id) {
                setNeedsPick(false);
                setTenantId(identity.tenant_id);
                setRole(identity.role);
                setUserId(identity.user_id);
                setDb(openTenantDb(identity.tenant_id));
                return;
            }

            // Tenant-less: the user belongs to several businesses and has not
            // chosen. Without this branch the app would hang on "loading" —
            // fetch the list and show the picker.
            const mine = await (
                await fetch('/api/v1/businesses/mine', {
                    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
                })
            ).json();

            if (cancelled) return;

            setMemberships(mine);
            setNeedsPick(true);
        })();

        return () => {
            cancelled = true;
        };
    }, [bootKey]);

    // Load the membership list once a tenant is active too, so the switcher has
    // something to offer (the picker path already has it).
    useEffect(() => {
        if (!tenantId || memberships.length > 0) return;

        (async () => {
            const token = await getToken();
            if (!token) return;

            const mine = await (
                await fetch('/api/v1/businesses/mine', {
                    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
                })
            ).json();

            setMemberships(mine);
        })();
    }, [tenantId, memberships.length]);

    /**
     * Pick or switch business.
     *
     * The guard is the whole point (PRD §9): refuse while the outbox has
     * unsynced work, or it would be stranded in a cache about to close. On a
     * clean switch, close the old cache, point the token at the new business,
     * and re-resolve from scratch.
     */
    const switchBusiness = useCallback(
        async (businessId) => {
            if (db) await assertSafeToSwitch(db); // throws with a message if unsafe

            closeTenantDb();
            setActiveBusiness(businessId);

            // Reset per-tenant state so nothing bleeds from the old business
            // into the new one's screens before the reload lands.
            setDb(null);
            setTenantId(null);
            setRole(null);
            setCustomers([]);
            setMaterials([]);
            setBatches([]);
            setNeedsPick(false);
            navigate('/');

            setBootKey((k) => k + 1);
        },
        [db]
    );

    const canManageStock = STOCK_MANAGERS.includes(role);
    const canAcceptOrders = ORDER_APPROVERS.includes(role);
    const readOnly = impersonation !== null;

    /**
     * Central write block for a read-only support view. The server rejects writes
     * on the impersonation token anyway (SetTenantContext bars unsafe methods),
     * but stopping them here keeps a failed write out of the outbox entirely and
     * tells the operator why. Write CTAs are also hidden, so this is a backstop.
     */
    const blockedByImpersonation = useCallback(() => {
        if (!readOnly) return false;
        // eslint-disable-next-line no-alert -- deliberate hard stop for a support view
        alert(t('read_only_support'));
        return true;
    }, [readOnly]);

    /**
     * Leave the support view: wipe the tenant's cache off THIS device first (it
     * is not the operator's data to keep), then POST to end the session server-side
     * and land back on the console. A form POST carries CSRF; a GET must not end a
     * session.
     */
    const exitImpersonation = useCallback(async () => {
        const exitUrl = impersonation?.exit_url;
        if (!exitUrl) return;

        if (tenantId) {
            closeTenantDb();
            await deleteTenantDb(tenantId);
        }

        const form = document.createElement('form');
        form.method = 'POST';
        form.action = exitUrl;

        const token = document.createElement('input');
        token.type = 'hidden';
        token.name = '_token';
        token.value = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        form.appendChild(token);

        document.body.appendChild(form);
        form.submit();
    }, [impersonation, tenantId]);

    /* --- reload everything the screens read ------------------------ */
    const refresh = useCallback(
        async (database) => {
            if (!database) return;

            setCustomers(await khataList(database));

            const packRows = await database.product_packs.toArray();
            setPacks(packRows);
            // Every role caches the catalog (CatalogPolicy leaves reads ungated:
            // a salesman cannot sell without it), so products load for everyone.
            // Gating this behind canManageStock left the sale dropdown nameless
            // for exactly the person who records sales.
            const productRows = await database.products.toArray();
            setProducts(productRows);

            // Two sources: synced rows with their lines, plus anything still in
            // the outbox — an order taken offline must not vanish until sync.
            // Reads the catalog loaded just above rather than fetching it twice.
            setOrders(buildOrderList({
                orders: await database.orders.toArray(),
                orderLines: await database.order_lines.toArray(),
                // pending() ends in sortBy(), which already resolves to an array —
                // it is not a Dexie Collection, so there is nothing to toArray().
                outbox: await pending(database),
                packs: packRows,
                products: productRows,
                locale: getLocale(),
                // Marks which orders this device took, now that the pull sends
                // the whole shop's. In the deps below, so the list is remarked
                // if whoami resolves after the first refresh.
                userId,
                // So an approver's queued order shows the status the server
                // will give it, instead of waiting in "Waiting" for their own
                // decision until the next pull.
                role,
            }));
            setQueued(await pendingCount(database));
            setStaleness(await stalenessState(database));

            // Only managers hold stock/production rows (sync/pull withholds them
            // from others), so only they compute these lists.
            if (canManageStock) {
                setMaterials(await stockList(database));

                // Batches newest first. sync/pull sends the raw rows (no joined
                // product_name, unlike the index endpoint), so resolve the name
                // from the cached products loaded above.
                const nameById = Object.fromEntries(
                    productRows.map((p) => [p.id, productName(p, getLocale())])
                );
                const rows = await database.production_batches.toArray();

                setBatches(
                    annotateReversals(rows)
                        .map((b) => ({ ...b, product_name: nameById[b.product_id] ?? null }))
                        .sort((a, b) => String(b.batch_date).localeCompare(String(a.batch_date)))
                );
            }
        },
        [canManageStock, userId, role]
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
        if (blockedByImpersonation()) return;

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
        if (blockedByImpersonation()) return;

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

    const saveOrder = async ({ customer, sale_date, lines, total }) => {
        if (blockedByImpersonation()) return;

        await enqueue(db, {
            type: 'order',
            tenantId,
            uuid: crypto.randomUUID(),
            // `total` is carried for the local balance only; the server
            // recomputes it from the lines and its own frozen rates.
            payload: { customer_id: customer.id ?? customer.uuid, order_date: sale_date, lines, total },
            dependsOnUuid: customer.id ? null : customer.uuid,
        });

        await refresh(db);
        if (online) runSync();
    };

    /** pack | deliver | cancel — each is its own outbox mutation. */
    const orderAction = async (order, action) => {
        if (blockedByImpersonation()) return;

        await enqueue(db, {
            type: `order_${action}`,
            tenantId,
            uuid: crypto.randomUUID(),
            payload: { order_uuid: order.uuid },
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
        if (blockedByImpersonation()) return { ok: false, status: 0 };

        const result = await apiWrite('/raw-materials', body);
        // A successful write returns the row; pull it (and its movements) into
        // the cache so the list reflects it without waiting for the next sync.
        if (result.ok && online) await runSync();
        return result;
    };

    const saveMovement = async (body) => {
        if (blockedByImpersonation()) return { ok: false, status: 0 };

        const result = await apiWrite('/stock-movements', body);
        if (result.ok && online) await runSync();
        return result;
    };

    // Corrections. Append-only on the server: each writes a mirror-image row,
    // so a re-sync is what makes the new figures appear.
    const reverseMovement = async (id) => {
        if (blockedByImpersonation()) return { ok: false, status: 0 };

        const result = await apiWrite(`/stock-movements/${id}/reverse`, {});
        if (result.ok && online) await runSync();
        return result;
    };

    const reverseBatch = async (id) => {
        if (blockedByImpersonation()) return { ok: false, status: 0 };

        // Puts the raw materials back as well as negating the output, so the
        // sync pulls new movements too.
        const result = await apiWrite(`/production/${id}/reverse`, {});
        if (result.ok && online) await runSync();
        return result;
    };

    const saveBatch = async (body) => {
        if (blockedByImpersonation()) return { ok: false, status: 0 };

        // A batch draws its consumptions out of stock server-side, so a sync
        // after success pulls both the new batch and the stock movements it
        // created — the on-hand figures update without a manual refresh.
        const result = await apiWrite('/production', body);
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

    // Multi-business user who has not chosen: pick before anything opens.
    if (needsPick) {
        return <BusinessPicker memberships={memberships} onPick={switchBusiness} />;
    }

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
                return <KhataList customers={customers} readOnly={readOnly} />;

            case 'ledger':
                return (
                    <CustomerLedger
                        customer={activeCustomer}
                        entries={ledger?.entries ?? []}
                        // null, not 0: showing ₹0.00 before the real balance
                        // loads is a wrong number a shopkeeper could act on.
                        outstandingPaise={ledger ? ledger.outstanding : null}
                        readOnly={readOnly}
                    />
                );

            case 'new-customer':
                return <NewCustomer onSave={saveCustomer} />;

            case 'payment':
                return activeCustomer ? (
                    <RecordPayment customer={activeCustomer} onSave={savePayment} />
                ) : null;

            case 'order':
                return activeCustomer ? (
                    <RecordOrder customer={activeCustomer} packs={packs} products={products} onSave={saveOrder} />
                ) : null;

            case 'orders':
                return (
                    <Orders
                        orders={orders}
                        customersById={new Map(customers.map((c) => [c.id, c]))}
                        onAction={orderAction}
                        // An impersonating operator is read-only, so do not send
                        // them to a screen whose whole purpose is to write.
                        canAccept={canAcceptOrders && !readOnly}
                    />
                );

            case 'pick-customer':
                // "Sales" tab with no customer chosen yet: the khata list IS the
                // picker, so send them there rather than inventing a second one.
                return <KhataList customers={customers} readOnly={readOnly} />;

            case 'production':
            case 'new-batch':
                if (!canManageStock) return <Home userName={userName} customers={customers} entriesToday={entriesToday} isOwner={role === 'owner'} tenantId={tenantId} readOnly={readOnly} />;

                if (route.name === 'new-batch') {
                    // Products come from the catalog cache; materials from stock.
                    return <RecordBatch products={products} materials={materials} onSave={saveBatch} />;
                }
                return <ProductionList batches={batches} online={online} onReverse={reverseBatch} />;

            case 'stock':
            case 'material':
            case 'new-material':
            case 'movement':
                // Defence in depth: the tab is already hidden for non-managers,
                // but a salesman deep-linking to /stock must also be refused —
                // and they hold no stock rows anyway.
                if (!canManageStock) return <Home userName={userName} customers={customers} entriesToday={entriesToday} isOwner={role === 'owner'} tenantId={tenantId} readOnly={readOnly} />;

                if (route.name === 'material') {
                    return (
                        <MaterialDetail
                            material={activeMaterial}
                            movements={annotateReversals(materialMovements)}
                            online={online}
                            onReverse={reverseMovement}
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
                return <Home userName={userName} customers={customers} entriesToday={entriesToday} isOwner={role === 'owner'} tenantId={tenantId} readOnly={readOnly} />;
        }
    };

    return (
        <>
            <ImpersonationBanner impersonation={impersonation} onExit={exitImpersonation} />
            {/* A support view has no business switching businesses. */}
            {!readOnly && (
                <BusinessSwitcher current={tenantId} memberships={memberships} onSwitch={switchBusiness} />
            )}
            <SyncBar
                online={online}
                queued={queued}
                syncing={syncing}
                error={syncError}
                onSync={runSync}
            />
            <StalenessBanner staleness={staleness} />
            {screen()}
            <BottomNav path={path} canManageStock={canManageStock} readOnly={readOnly} />
        </>
    );
}

registerServiceWorker();

const root = document.getElementById('app-root');
createRoot(root).render(<App userName={root.dataset.userName} locale={root.dataset.locale} />);
