/**
 * Strings for the React layer (docs/frontend-plan.md §6).
 *
 * A plain dictionary, not an i18n library: two languages and a few hundred
 * strings do not justify the bytes. Hindi is the default (PRD §16), so `hi` is
 * the complete set and `en` is the fallback for anyone who switches.
 *
 * Every string lives here from the start — retrofitting extraction later is
 * miserable, and half-translated screens are worse than untranslated ones.
 */

const strings = {
    hi: {
        home: 'होम',
        khata: 'खाता',
        sales: 'बिक्री',
        more: 'और',

        total_due: 'कुल बकाया',
        customers: 'ग्राहक',
        search_customers: 'ग्राहक खोजें',
        no_customers: 'अभी कोई ग्राहक नहीं',
        no_customers_hint: 'पहला ग्राहक जोड़कर खाता शुरू करें।',
        add_customer: 'ग्राहक जोड़ें',
        new_customer: 'नया ग्राहक',
        name: 'नाम',
        village: 'गाँव',
        phone: 'फ़ोन',
        opening_balance: 'पुराना बकाया',
        save: 'सहेजें',
        cancel: 'रद्द करें',
        back: 'वापस',

        record_payment: 'भुगतान दर्ज करें',
        record_sale: 'बिक्री दर्ज करें',
        amount: 'राशि',
        mode: 'माध्यम',
        cash: 'नकद',
        upi: 'UPI',
        cheque: 'चेक',
        bank: 'बैंक',
        other: 'अन्य',
        date: 'दिनांक',

        ledger: 'खाता विवरण',
        no_entries: 'अभी कोई प्रविष्टि नहीं',
        opening: 'शुरुआती बकाया',
        balance: 'बाकी',
        sale: 'बिक्री',
        payment: 'भुगतान',
        sale_reversal: 'बिक्री वापसी',
        payment_reversal: 'भुगतान वापसी',
        pending_sync: 'सिंक बाकी',

        offline: 'ऑफ़लाइन',
        offline_safe: 'ऑफ़लाइन — काम सुरक्षित है',
        online: 'ऑनलाइन',
        queued: 'कतार में',
        sync_now: 'सिंक करें',
        syncing: 'सिंक हो रहा है…',
        loading: 'लोड हो रहा है…',
        sign_out: 'साइन आउट',

        stale_warn: 'कई दिनों से सिंक नहीं हुआ — कनेक्ट करें।',
        stale_blocked: 'बहुत दिनों से सिंक नहीं हुआ — नई प्रविष्टि रोक दी गई है।',
        needs_attention: 'ध्यान दें',
        rejected_entries: 'अस्वीकृत प्रविष्टियाँ',
        retry: 'फिर कोशिश करें',
        discard: 'हटाएँ',

        required: 'यह ज़रूरी है',
        must_be_positive: 'राशि शून्य से अधिक होनी चाहिए',
        select_product: 'उत्पाद चुनें',
        qty: 'मात्रा',
        add_line: 'और जोड़ें',
        total: 'कुल',
        no_catalog: 'उत्पाद सूची उपलब्ध नहीं — एक बार ऑनलाइन सिंक करें।',
    },

    en: {
        home: 'Home',
        khata: 'Khata',
        sales: 'Sales',
        more: 'More',

        total_due: 'Total outstanding',
        customers: 'Customers',
        search_customers: 'Search customers',
        no_customers: 'No customers yet',
        no_customers_hint: 'Add your first customer to start the khata.',
        add_customer: 'Add customer',
        new_customer: 'New customer',
        name: 'Name',
        village: 'Village',
        phone: 'Phone',
        opening_balance: 'Opening balance',
        save: 'Save',
        cancel: 'Cancel',
        back: 'Back',

        record_payment: 'Record payment',
        record_sale: 'Record sale',
        amount: 'Amount',
        mode: 'Mode',
        cash: 'Cash',
        upi: 'UPI',
        cheque: 'Cheque',
        bank: 'Bank',
        other: 'Other',
        date: 'Date',

        ledger: 'Statement',
        no_entries: 'No entries yet',
        opening: 'Opening balance',
        balance: 'Balance',
        sale: 'Sale',
        payment: 'Payment',
        sale_reversal: 'Sale reversal',
        payment_reversal: 'Payment reversal',
        pending_sync: 'Not synced',

        offline: 'Offline',
        offline_safe: 'Offline — your work is saved',
        online: 'Online',
        queued: 'Queued',
        sync_now: 'Sync now',
        syncing: 'Syncing…',
        loading: 'Loading…',
        sign_out: 'Sign out',

        stale_warn: 'Not synced for several days — please connect.',
        stale_blocked: 'Not synced for too long — new entries are blocked.',
        needs_attention: 'Needs attention',
        rejected_entries: 'Rejected entries',
        retry: 'Retry',
        discard: 'Discard',

        required: 'This is required',
        must_be_positive: 'Amount must be more than zero',
        select_product: 'Select product',
        qty: 'Qty',
        add_line: 'Add another',
        total: 'Total',
        no_catalog: 'Product list unavailable — sync once while online.',
    },
};

let locale = 'hi';

export function setLocale(next) {
    if (strings[next]) locale = next;
}

/** Translate. Falls back to Hindi, then to the key itself. */
export function t(key) {
    return strings[locale]?.[key] ?? strings.hi[key] ?? key;
}

/** dd-MMM-yyyy, per PRD §16. */
export function formatDate(value) {
    if (!value) return '';

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) return String(value);

    return new Intl.DateTimeFormat(locale === 'hi' ? 'hi-IN' : 'en-IN', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(date);
}

/** Today as YYYY-MM-DD in LOCAL time — not toISOString(), which is UTC and can
 *  put an evening sale on tomorrow's date in IST. */
export function today() {
    const now = new Date();
    const pad = (n) => String(n).padStart(2, '0');

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}
