<?php
// lang/hi/customers.php

return [
    'title' => 'ग्राहक',
    'heading' => 'ग्राहक',
    'back_to_dashboard' => 'डैशबोर्ड पर वापस',
    'back_to_customers' => 'ग्राहकों पर वापस',

    'add' => 'ग्राहक जोड़ें',
    'name' => 'नाम',
    'village' => 'गाँव',
    'phone' => 'फ़ोन',
    'opening_balance' => 'शुरुआती बकाया',
    'opening_hint' => 'यह खाता शुरू होने से पहले का बकाया। बिक्री और भुगतान ऐप में दर्ज होते हैं।',
    'save' => 'सेव करें',
    'no_customers' => 'अभी कोई ग्राहक नहीं। ऊपर पहला ग्राहक जोड़ें।',
    'total_outstanding' => 'कुल बकाया',

    'edit' => 'जानकारी बदलें',
    'update' => 'अपडेट करें',
    'phone_hint' => 'रिमाइंडर के लिए फ़ोन नंबर चाहिए — बिना नंबर के यह ग्राहक छूट जाएगा।',

    'archive' => 'आर्काइव करें',
    'archive_confirm' => 'इस ग्राहक को आर्काइव करें? खाता सुरक्षित रहेगा और वापस लाया जा सकता है।',
    'archived_heading' => 'आर्काइव किए गए',
    'archived_hint' => 'आर्काइव ग्राहकों का खाता सुरक्षित रहता है और वे सूची में नहीं दिखते।',
    'restore' => 'वापस लाएँ',

    'outstanding' => 'बकाया',
    'read_only' => 'बिक्री और भुगतान ऐप में दर्ज होते हैं। यहाँ आप उन्हें सुधार सकते हैं।',

    'void' => 'रद्द करें',
    'reverse' => 'वापस करें',
    'confirm_void' => 'यह बिक्री रद्द करें? एक काटने वाली प्रविष्टि जुड़ेगी — कुछ मिटेगा नहीं।',
    'confirm_reverse' => 'यह भुगतान वापस करें? एक काटने वाली प्रविष्टि जुड़ेगी — कुछ मिटेगा नहीं।',
    'is_correction' => 'सुधार',
    'corrected' => 'सुधारा गया',
    'voided' => 'बिक्री रद्द हुई। काटने वाली प्रविष्टि जुड़ी।',
    'reversed' => 'भुगतान वापस हुआ। काटने वाली प्रविष्टि जुड़ी।',

    // Correcting a payment: reverse the original and record what was meant, so
    // the statement explains the change instead of a balance quietly differing.
    'correct_payment' => 'भुगतान ठीक करें',
    'payment_corrected' => 'भुगतान ठीक हुआ। पुरानी प्रविष्टि वापस होकर सही रकम दर्ज हुई।',
    'mode' => 'माध्यम',
    'modes' => [
        'cash' => 'नकद', 'upi' => 'यूपीआई', 'cheque' => 'चेक',
        'bank' => 'बैंक ट्रांसफर', 'other' => 'अन्य',
    ],
    'cannot_void_reversal' => 'यह पंक्ति स्वयं एक सुधार है, इसे रद्द नहीं किया जा सकता।',
    'already_voided' => 'यह बिक्री पहले ही रद्द हो चुकी है।',
    'cannot_reverse_reversal' => 'यह पंक्ति स्वयं एक सुधार है, इसे वापस नहीं किया जा सकता।',
    'already_reversed' => 'यह भुगतान पहले ही वापस हो चुका है।',

    'ledger' => 'खाता',
    'date' => 'तारीख',
    'particulars' => 'विवरण',
    'amount' => 'रकम',
    'balance' => 'बाकी',
    'opening' => 'शुरुआती बकाया',
    'sale' => 'बिक्री',
    'payment' => 'भुगतान',
    'sale_reversal' => 'बिक्री वापस',
    'payment_reversal' => 'भुगतान वापस',
    'no_entries' => 'अभी कोई बिक्री या भुगतान नहीं।',
];
