<?php

return [
    'title' => 'प्लान और बिलिंग',
    'heading' => 'प्लान और बिलिंग',
    'back_to_app' => 'ऐप पर वापस',

    // Plan names.
    'plan_free' => 'फ़्री',
    'plan_pro' => 'प्रो',
    'current_plan' => 'वर्तमान प्लान',

    // Status banners — one is shown at the top depending on subscription state.
    'trial_banner' => '{1} आपका मुफ़्त ट्रायल कल समाप्त होगा।|[2,*] आपका मुफ़्त ट्रायल :days दिन में समाप्त होगा।',
    'trial_ends_on' => 'ट्रायल समाप्ति :date',
    'active_banner' => 'आपका प्रो प्लान चालू है।',
    'renews_on' => ':date तक मान्य',
    'past_due_banner' => 'आपकी प्रो अवधि समाप्त हो गई है — आप फ़्री प्लान पर हैं। प्रो सुविधाएँ वापस पाने के लिए अपग्रेड करें।',
    'read_only_banner' => 'आपका भुगतान बकाया है, इसलिए नई प्रविष्टियाँ रोक दी गई हैं। पूरी पहुँच वापस पाने के लिए भुगतान दर्ज करें — आपका डेटा सुरक्षित है।',

    // Usage against plan limits.
    'usage' => 'उपयोग',
    'customers' => 'ग्राहक',
    'staff' => 'स्टाफ़',
    'unlimited' => 'असीमित',
    'over_limit' => 'सीमा से अधिक',
    'of' => 'में से',

    // Record-payment form.
    'upgrade_heading' => 'प्रो में अपग्रेड करें',
    'upgrade_hint' => 'UPI या बैंक ट्रांसफ़र से भुगतान करें, फिर यहाँ दर्ज करें। हम जाँच कर प्रो चालू कर देंगे — आमतौर पर एक दिन में।',
    'amount' => 'राशि (₹)',
    'gst_note' => '18% GST जोड़ा जाएगा।',
    'mode' => 'भुगतान माध्यम',
    'mode_upi' => 'UPI',
    'mode_bank' => 'बैंक ट्रांसफ़र',
    'mode_manual' => 'नकद / अन्य',
    'reference' => 'संदर्भ / UPI रेफ़ (वैकल्पिक)',
    'period_months' => 'महीने',
    'note' => 'टिप्पणी (वैकल्पिक)',
    'record_payment' => 'भुगतान दर्ज करें',
    'payment_recorded' => 'भुगतान दर्ज हो गया — जाँच बाकी है। पुष्टि होते ही प्रो चालू हो जाएगा।',

    // Payment history.
    'history' => 'भुगतान इतिहास',
    'no_payments' => 'अभी कोई भुगतान नहीं।',
    'col_date' => 'दिनांक',
    'col_amount' => 'राशि',
    'col_mode' => 'माध्यम',
    'col_status' => 'स्थिति',
    'status_pending' => 'लंबित',
    'status_verified' => 'सत्यापित',
    'status_rejected' => 'अस्वीकृत',
    'incl_gst' => 'GST सहित :amount',
];
