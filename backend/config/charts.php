<?php
// config/charts.php

/*
|--------------------------------------------------------------------------
| Chart colours
|--------------------------------------------------------------------------
|
| tailwind.config.js is the token source for everything expressed as a CLASS.
| Chart marks are SVG fill/stroke ATTRIBUTES built in PHP, so a Tailwind class
| cannot reach them — this file is the same rule's equivalent for that surface.
| Nothing under resources/views may hardcode a chart hex; reference these.
|
| Every series colour clears 4.9:1 against BOTH #FFFFFF (card) and #F8FAFC
| (canvas). WCAG only asks 3:1 of a non-text graphical object, but these bars
| are read outdoors on a cheap phone — the same reason tailwind.config.js keeps
| the fashionable low-contrast greys out of the semantic scale. The colours
| replaced here sat at 3.15:1 (green) and 3.52:1 (cyan) on canvas: passing, but
| the two weakest things on the screen.
|
| The ramp is blue / orange / violet / teal. Orange against blue is the pair
| that survives every common colour-vision deficiency, and it carries the two
| series most often compared (sales vs gross profit). Colour is not the only
| channel regardless: bars keep a fixed position within each group, the legend
| names them, and the hover tooltip repeats the series name.
|
*/

return [

    /*
    | Keyed by metric rather than by index, so a partial names what it is
    | plotting and two charts showing the same metric cannot drift apart.
    */
    'series' => [
        'sales' => '#1D4ED8',        // blue-700   6.70:1 white / 6.41:1 canvas
        'gross_profit' => '#C2410C', // orange-700 5.18:1 / 4.95:1
        'net_profit' => '#6D28D9',   // violet-700 7.10:1 / 6.79:1
        'production' => '#1D4ED8',   // blue-700 — sole series on its own chart
        'net_cash' => '#0F766E',     // teal-700   5.47:1 / 5.23:1
    ],

    /*
    | Negative bars (a loss month) override their series colour. This is the
    | `danger` token from tailwind.config.js, not a second red: a loss here and
    | an error message elsewhere mean the same thing to the reader.
    */
    'loss' => '#B91C1C',             // red-700    6.47:1 / 6.18:1

    /*
    | Chart chrome. Gridlines stay deliberately faint so they never compete
    | with the data; everything carrying a word is held to the 4.5:1 text bar.
    */
    'chrome' => [
        'gridline' => '#E2E8F0',     // slate-200  1.23:1 — subtle ON PURPOSE
        'zero_line' => '#475569',    // slate-600  7.58:1 — separates profit from loss
        'tick_label' => '#475569',   // slate-600  7.58:1
        'group_label' => '#475569',  // slate-600  7.58:1
        'legend_label' => '#0F172A', // slate-900 17.85:1
        'tooltip_bg' => '#0F172A',
        'tooltip_fg' => '#FFFFFF',
        // The one dark-mode rule in the app: the tooltip floats above page
        // content and would glare at night. Inverted pair, same 17.85:1.
        'tooltip_bg_dark' => '#E2E8F0',
        'tooltip_fg_dark' => '#0F172A',
    ],
];
