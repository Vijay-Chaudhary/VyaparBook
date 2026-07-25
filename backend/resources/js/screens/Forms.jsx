import { Fragment, useMemo, useState } from 'react';
import { getLocale, t, today } from '../i18n';
import { productName } from '../offline/catalog';
import { formatRupees, toPaise } from '../offline/money';
import { belowFloor, floorPaise, readRatePaise, sendableRate } from '../offline/pricing';
import { navigate } from '../router';
import { Screen } from '../components/Chrome';

/**
 * The three data-entry forms. All work fully offline: they write to the outbox
 * and return immediately, never awaiting a network round trip.
 *
 * Shared rules across all three (SKILL.md §8):
 *  - visible labels, never placeholder-only
 *  - errors below the field they belong to, announced to screen readers
 *  - inputMode="decimal" so a phone shows a numeric keypad for money
 *  - 16px inputs (.field-input) so iOS does not auto-zoom on focus
 */

function Field({ id, label, error, children, hint }) {
    return (
        <div>
            <label htmlFor={id} className="field-label">
                {label}
            </label>
            {children}
            {hint && !error && <p className="mt-1 text-sm text-ink-muted">{hint}</p>}
            {error && (
                <p id={`${id}-error`} role="alert" className="field-error">
                    {error}
                </p>
            )}
        </div>
    );
}

/* ------------------------------------------------------------------ */

export function NewCustomer({ onSave }) {
    const [values, setValues] = useState({ name: '', village: '', phone: '', opening_balance: '' });
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    const set = (key) => (e) => setValues((v) => ({ ...v, [key]: e.target.value }));

    const submit = async (e) => {
        e.preventDefault();

        if (!values.name.trim()) {
            setErrors({ name: t('required') });
            document.getElementById('name')?.focus(); // §8 focus-management
            return;
        }

        setSaving(true);

        try {
            await onSave({
                name: values.name.trim(),
                village: values.village.trim() || null,
                phone: values.phone.trim() || null,
                opening_balance: values.opening_balance.trim() || '0',
            });
            navigate('/khata');
        } catch (error) {
            setErrors({ form: error.message });
            setSaving(false);
        }
    };

    return (
        <Screen title={t('new_customer')} onBack={() => navigate('/khata')}>
            <form onSubmit={submit} className="card space-y-4" noValidate>
                {errors.form && (
                    <p role="alert" className="field-error">
                        {errors.form}
                    </p>
                )}

                <Field id="name" label={t('name')} error={errors.name}>
                    <input
                        id="name"
                        className="field-input"
                        value={values.name}
                        onChange={set('name')}
                        autoFocus
                        aria-invalid={errors.name ? 'true' : undefined}
                        aria-describedby={errors.name ? 'name-error' : undefined}
                        data-testid="customer-name"
                    />
                </Field>

                <Field id="village" label={t('village')}>
                    <input id="village" className="field-input" value={values.village} onChange={set('village')} />
                </Field>

                <Field id="phone" label={t('phone')}>
                    <input
                        id="phone"
                        type="tel"
                        inputMode="tel"
                        className="field-input tabular"
                        value={values.phone}
                        onChange={set('phone')}
                    />
                </Field>

                <Field
                    id="opening_balance"
                    label={t('opening_balance')}
                    hint={t('opening')}
                >
                    <input
                        id="opening_balance"
                        inputMode="decimal"
                        className="field-input tabular"
                        value={values.opening_balance}
                        onChange={set('opening_balance')}
                        placeholder="0.00"
                    />
                </Field>

                <button type="submit" className="btn-primary w-full" disabled={saving} data-testid="save-customer">
                    {saving ? t('loading') : t('save')}
                </button>
            </form>
        </Screen>
    );
}

/* ------------------------------------------------------------------ */

export function RecordPayment({ customer, onSave }) {
    const [amount, setAmount] = useState('');
    const [mode, setMode] = useState('cash');
    const [date, setDate] = useState(today());
    const [error, setError] = useState(null);
    const [saving, setSaving] = useState(false);

    const submit = async (e) => {
        e.preventDefault();

        const paise = (() => {
            try {
                return toPaise(amount);
            } catch {
                return 0;
            }
        })();

        // Server rule: amount must be gt:0. Catch it here so an offline entry
        // is never queued only to be parked on the next sync.
        if (paise <= 0) {
            setError(t('must_be_positive'));
            document.getElementById('amount')?.focus();
            return;
        }

        setSaving(true);

        try {
            await onSave({ customer, amount, mode, payment_date: date });
            navigate(`/khata/${customer.uuid}`);
        } catch (err) {
            setError(err.message);
            setSaving(false);
        }
    };

    return (
        <Screen title={t('record_payment')} onBack={() => navigate(`/khata/${customer.uuid}`)}>
            <form onSubmit={submit} className="card space-y-4" noValidate>
                <p className="text-sm text-ink-muted">{customer.name}</p>

                <Field id="amount" label={t('amount')} error={error}>
                    <input
                        id="amount"
                        /* decimal keypad, not a spinner — faster and no accidental
                           scroll-to-change on a touch device */
                        inputMode="decimal"
                        className="field-input tabular text-lg"
                        value={amount}
                        onChange={(e) => setAmount(e.target.value)}
                        autoFocus
                        placeholder="0.00"
                        aria-invalid={error ? 'true' : undefined}
                        aria-describedby={error ? 'amount-error' : undefined}
                        data-testid="payment-amount"
                    />
                </Field>

                <Field id="mode" label={t('mode')}>
                    <select
                        id="mode"
                        className="field-input"
                        value={mode}
                        onChange={(e) => setMode(e.target.value)}
                    >
                        {['cash', 'upi', 'cheque', 'bank', 'other'].map((m) => (
                            <option key={m} value={m}>
                                {t(m)}
                            </option>
                        ))}
                    </select>
                </Field>

                <Field id="date" label={t('date')}>
                    <input
                        id="date"
                        type="date"
                        className="field-input tabular"
                        value={date}
                        onChange={(e) => setDate(e.target.value)}
                    />
                </Field>

                <button type="submit" className="btn-primary w-full" disabled={saving} data-testid="save-payment">
                    {saving ? t('loading') : t('save')}
                </button>
            </form>
        </Screen>
    );
}

/* ------------------------------------------------------------------ */

export function RecordOrder({ customer, packs, products = [], onSave }) {
    const [lines, setLines] = useState([{ product_pack_id: '', qty: '1', rate: '' }]);

    // Pack rows carry product_id but not the product's name, so the join
    // happens here. Built once rather than scanning products per option.
    const productsById = useMemo(
        () => new Map(products.map((product) => [product.id, product])),
        [products]
    );
    const [date, setDate] = useState(today());
    const [error, setError] = useState(null);
    const [saving, setSaving] = useState(false);

    // A rate being typed may not parse yet ('12..3', 'abc'), and toPaise throws
    // on those — which would crash the form mid-keystroke. Display therefore
    // reads an unparseable rate as zero. Submit does NOT: see sendableRate.
    const ratePaise = (value) => readRatePaise(value) ?? 0;

    const setLine = (index, key) => (e) =>
        setLines((current) =>
            current.map((line, i) => {
                if (i !== index) return line;

                const next = { ...line, [key]: e.target.value };

                if (key === 'product_pack_id') {
                    const pack = packs.find((p) => p.id === e.target.value);
                    next.rate = pack ? String(pack.default_sell_price) : '';
                }

                return next;
            })
        );

    const linePaise = (line) => {
        const qty = Number(line.qty);
        const rate = ratePaise(line.rate);

        return Number.isFinite(qty) ? rate * qty : 0;
    };

    const totalPaise = lines.reduce((sum, line) => sum + (line.product_pack_id ? linePaise(line) : 0), 0);

    const floorFor = (line) => {
        const pack = packs.find((p) => p.id === line.product_pack_id);

        return pack ? floorPaise(pack, productsById.get(pack.product_id)) : null;
    };

    // A half-typed rate parses as 0 paise (see ratePaise above), which is
    // below almost any floor. That's deliberate, not a false positive: the
    // salesman hasn't finished typing a real rate yet, so treating the field
    // as "not yet valid" and showing the warning is correct — it clears the
    // moment the rate they mean to charge is fully entered. Suppressing the
    // check for unparseable input would let an incomplete-but-still-below-floor
    // rate slip through submit's `some(v => v !== null)` gate undetected.
    const violations = lines.map((line) =>
        line.product_pack_id && belowFloor(ratePaise(line.rate), floorFor(line))
            ? floorFor(line)
            : null
    );

    const submit = async (e) => {
        e.preventDefault();

        const valid = lines.filter((l) => l.product_pack_id && Number(l.qty) !== 0);

        // Server rule: at least one line, qty not zero.
        if (valid.length === 0) {
            setError(t('select_product'));
            return;
        }

        // Before the floor check: an unreadable rate would otherwise be reported
        // as "below cost", which is confusing and wrong. Without this the raw
        // text reaches the server, gets rejected, and parks the sale — which the
        // salesman only finds out about long after the customer has gone.
        if (valid.some((l) => !sendableRate(l.rate))) {
            setError(t('invalid_rate'));
            return;
        }

        if (violations.some((v) => v !== null)) {
            setError(t('below_floor'));
            return;
        }

        setSaving(true);

        try {
            await onSave({
                customer,
                sale_date: date,
                lines: valid.map((l) => ({
                    product_pack_id: l.product_pack_id,
                    qty: Number(l.qty),
                    rate: l.rate,
                })),
                total: (totalPaise / 100).toFixed(2),
            });
            navigate(`/khata/${customer.uuid}`);
        } catch (err) {
            setError(err.message);
            setSaving(false);
        }
    };

    if (packs.length === 0) {
        // The catalog is cached on sync; without it a sale cannot be described.
        return (
            <Screen title={t('take_order')} onBack={() => navigate(`/khata/${customer.uuid}`)}>
                <p className="card text-center text-warning">{t('no_catalog')}</p>
            </Screen>
        );
    }

    return (
        <Screen title={t('take_order')} onBack={() => navigate(`/khata/${customer.uuid}`)}>
            <form onSubmit={submit} className="card space-y-4" noValidate>
                <p className="text-sm text-ink-muted">{customer.name}</p>

                {error && (
                    <p role="alert" className="field-error">
                        {error}
                    </p>
                )}

                {lines.map((line, index) => (
                    <Fragment key={index}>
                        {/* Product gets its own row: with qty, rate and subtotal
                            beside it the select was crushed to ~64px on a phone. */}
                        <div className="space-y-2">
                            <div className="min-w-0">
                                <label htmlFor={`pack-${index}`} className="field-label">
                                    {t('select_product')}
                                </label>
                                <select
                                    id={`pack-${index}`}
                                    className="field-input"
                                    value={line.product_pack_id}
                                    onChange={setLine(index, 'product_pack_id')}
                                    data-testid={`sale-pack-${index}`}
                                >
                                    <option value="">—</option>
                                    {packs.map((pack) => {
                                        const name = productName(productsById.get(pack.product_id), getLocale());

                                        return (
                                            <option key={pack.id} value={pack.id}>
                                                {name ? `${name} · ` : ''}{pack.label} · {formatRupees(toPaise(pack.default_sell_price))}
                                            </option>
                                        );
                                    })}
                                </select>
                            </div>

                            <div className="flex gap-2">
                            <div className="w-16 shrink-0">
                                <label htmlFor={`qty-${index}`} className="field-label">{t('qty')}</label>
                                <input
                                    id={`qty-${index}`}
                                    inputMode="numeric"
                                    className="field-input tabular"
                                    value={line.qty}
                                    onChange={setLine(index, 'qty')}
                                    data-testid={`sale-qty-${index}`}
                                />
                            </div>

                            <div className="w-24 shrink-0">
                                <label htmlFor={`rate-${index}`} className="field-label">{t('rate')}</label>
                                <input
                                    id={`rate-${index}`}
                                    inputMode="decimal"
                                    className="field-input tabular"
                                    value={line.rate}
                                    onChange={setLine(index, 'rate')}
                                    data-testid={`sale-rate-${index}`}
                                />
                            </div>

                            {/* Read-only, and computed from the NEGOTIATED rate — it is
                                what lets a salesman check a six-item order before saving. */}
                            <div className="w-20 shrink-0">
                                <span className="field-label">{t('subtotal')}</span>
                                <p className="tabular pt-2 text-sm" data-testid={`sale-subtotal-${index}`}>
                                    {formatRupees(line.product_pack_id ? linePaise(line) : 0)}
                                </p>
                            </div>
                            </div>
                        </div>

                        {violations[index] !== null && (
                            <p className="field-error" data-testid={`sale-floor-${index}`}>
                                {t('below_floor')} {formatRupees(violations[index])}
                            </p>
                        )}
                    </Fragment>
                ))}

                <button
                    type="button"
                    className="btn-secondary w-full"
                    onClick={() => setLines((l) => [...l, { product_pack_id: '', qty: '1', rate: '' }])}
                >
                    + {t('add_line')}
                </button>

                <Field id="sale-date" label={t('date')}>
                    <input
                        id="sale-date"
                        type="date"
                        className="field-input tabular"
                        value={date}
                        onChange={(e) => setDate(e.target.value)}
                    />
                </Field>

                <div className="flex items-center justify-between border-t border-hairline pt-3">
                    <span className="font-medium">{t('total')}</span>
                    <span className="tabular text-xl font-bold" data-testid="sale-total">
                        {formatRupees(totalPaise)}
                    </span>
                </div>

                <button type="submit" className="btn-primary w-full" disabled={saving} data-testid="save-sale">
                    {saving ? t('loading') : t('save')}
                </button>
            </form>
        </Screen>
    );
}
