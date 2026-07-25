/**
 * Strings for the React layer (docs/frontend-plan.md §6).
 *
 * A plain dictionary, not an i18n library: two languages and a few hundred
 * strings do not justify the bytes. English is the default; `en` is the complete
 * set and the fallback, while `hi` is kept fully translated for anyone who
 * switches (the product still serves Hindi-speaking shopkeepers first-hand).
 *
 * Every string lives here from the start — retrofitting extraction later is
 * miserable, and half-translated screens are worse than untranslated ones.
 */

const strings = {
    hi: {
        greeting: 'नमस्ते',
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
        rate: 'दर',
        below_floor: 'लागत से कम — न्यूनतम है',
        subtotal: 'उप-योग',
        add_line: 'और जोड़ें',
        total: 'कुल',
        no_catalog: 'उत्पाद सूची उपलब्ध नहीं — एक बार ऑनलाइन सिंक करें।',

        stock: 'स्टॉक',
        on_hand: 'मौजूद',
        low_stock: 'कम स्टॉक',
        reorder_level: 'पुनः-ऑर्डर स्तर',
        add_material: 'सामग्री जोड़ें',
        new_material: 'नई सामग्री',
        material_name: 'सामग्री का नाम',
        unit: 'इकाई',
        no_materials: 'अभी कोई सामग्री नहीं',
        no_materials_hint: 'कच्चा माल जोड़कर स्टॉक शुरू करें।',
        record_movement: 'स्टॉक दर्ज करें',
        movement_in: 'आया (in)',
        movement_out: 'गया (out)',
        movement_adjust: 'समायोजन',
        note: 'टिप्पणी',
        production: 'उत्पादन',
        record_batch: 'बैच दर्ज करें',
        no_batches: 'अभी कोई बैच नहीं',
        product: 'उत्पाद',
        output_kg: 'उत्पादन (किग्रा)',
        materials_used: 'उपयोग की गई सामग्री',
        online_only: 'स्टॉक बदलने के लिए ऑनलाइन होना ज़रूरी है।',
        needs_upgrade: 'यह सुविधा आपके प्लान में नहीं है।',
        try_again_online: 'ऑनलाइन होने पर फिर कोशिश करें।',

        record_batch: 'बैच दर्ज करें',
        new_batch: 'नया बैच',
        no_batches: 'अभी कोई बैच नहीं',
        no_products: 'कोई उत्पाद नहीं — पहले उत्पाद सूची जोड़ें।',
        add_consumption: 'और सामग्री जोड़ें',

        choose_business: 'दुकान चुनें',
        switch_business: 'दुकान बदलें',
        sync_before_switch: 'दुकान बदलने से पहले सिंक करें — कुछ प्रविष्टियाँ बाकी हैं।',

        plan_billing: 'प्लान और बिलिंग',
        reports_dashboard: 'डैशबोर्ड',

        impersonating: 'सहायता दृश्य',
        read_only: 'केवल-पढ़ें',
        exit_support_view: 'बाहर निकलें',
        read_only_support: 'यह केवल-पढ़ने वाला सहायता दृश्य है — बदलाव नहीं कर सकते।',
    },

    en: {
        greeting: 'Namaste',
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
        opening: 'Amount owed before you started using VyaparBook',
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
        rate: 'Rate',
        below_floor: 'Below cost — lowest is',
        subtotal: 'Subtotal',
        add_line: 'Add another',
        total: 'Total',
        no_catalog: 'Product list unavailable — sync once while online.',

        stock: 'Stock',
        on_hand: 'On hand',
        low_stock: 'Low stock',
        reorder_level: 'Reorder level',
        add_material: 'Add material',
        new_material: 'New material',
        material_name: 'Material name',
        unit: 'Unit',
        no_materials: 'No materials yet',
        no_materials_hint: 'Add a raw material to start tracking stock.',
        record_movement: 'Record stock',
        movement_in: 'In',
        movement_out: 'Out',
        movement_adjust: 'Adjust',
        note: 'Note',
        production: 'Production',
        record_batch: 'Record batch',
        no_batches: 'No batches yet',
        product: 'Product',
        output_kg: 'Output (kg)',
        materials_used: 'Materials used',
        online_only: 'You must be online to change stock.',
        needs_upgrade: 'This feature is not in your plan.',
        try_again_online: 'Try again when you are connected.',

        record_batch: 'Record batch',
        new_batch: 'New batch',
        no_batches: 'No batches yet',
        no_products: 'No products — add a product list first.',
        add_consumption: 'Add material',

        choose_business: 'Choose a shop',
        switch_business: 'Switch shop',
        sync_before_switch: 'Sync before switching shop — some entries are still queued.',

        plan_billing: 'Plan & billing',
        reports_dashboard: 'Dashboard',

        impersonating: 'Support view',
        read_only: 'read-only',
        exit_support_view: 'Exit',
        read_only_support: 'This is a read-only support view — you cannot make changes.',
    },
};

let locale = 'en';

export function setLocale(next) {
    if (strings[next]) locale = next;
}

/** The active locale, for content that is data rather than a translation key —
 *  product names, for instance, which come from the tenant's own catalog. */
export function getLocale() {
    return locale;
}

/** Translate. Falls back to English (the default), then to the key itself. */
export function t(key) {
    return strings[locale]?.[key] ?? strings.en[key] ?? key;
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
