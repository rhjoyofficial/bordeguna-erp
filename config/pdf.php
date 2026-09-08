<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The browser that turns a document into a PDF
    |--------------------------------------------------------------------------
    |
    | ⭐⭐ WHY A BROWSER AND NOT dompdf. This application already renders every
    | document correctly in a browser, and dompdf CANNOT: measured on 8 Sep 2026,
    | it writes Bengali unshaped. Rendering "ক্ষ কি" puts six codepoints on the
    | page in logical order, so the conjunct becomes three letters with a visible
    | hasant and the vowel lands on the wrong side of its consonant. Since every
    | customer-facing document here can carry a Bengali product or customer name,
    | a dompdf export would be worse than the Print button we already have.
    |
    | ⚠ mPDF does shape Indic text and is still ruled out: it is GPL-2.0-only,
    | which is a real problem the day this ERP is delivered to a client rather
    | than hosted for one. That decision (Correction C9a) is not reopened here.
    |
    | ⚠ NOT REQUIRED FOR THE APPLICATION TO RUN. With no binary present the
    | Download PDF button is not offered and the route says why. A missing
    | browser must never be able to stop somebody raising an invoice.
    |
    */

    'binary' => env('PDF_CHROME_BINARY', 'chromium-browser'),

    /*
    | Where the browser fetches the document FROM. It navigates to this
    | application over HTTP like any other reader, so what it prints is exactly
    | what a person sees - there is no second template to keep in step.
    |
    | ⚠ Defaults to APP_URL. Override only if the browser should reach the app
    | by an internal address instead of going out through the proxy.
    */
    'base_url' => env('PDF_BASE_URL'),

    /*
    | Seconds. A document that has not rendered in this long is a fault, not a
    | slow page: the browser is killed and the download fails with a reason
    | rather than holding a request open.
    */
    'timeout' => (int) env('PDF_TIMEOUT', 60),

    /*
    | Milliseconds of "virtual time" the browser is allowed before it prints.
    |
    | ⚠⚠ THIS IS NOT A SLEEP. Chrome fast-forwards its own clock, so the page
    | gets its full chance to run the Inertia bootstrap, fetch the stylesheet and
    | load the Bangla font, and printing then happens as soon as that settles.
    | Too low and a document prints half-built - which is silent, because the
    | result is a valid PDF of an unfinished page.
    */
    'virtual_time_budget' => (int) env('PDF_VIRTUAL_TIME_BUDGET', 15000),

    /*
    | Seconds a render token stays valid. It is also single use, and bound to
    | one exact URL, so this only bounds the window in which an unused one could
    | be replayed.
    */
    'token_ttl' => (int) env('PDF_TOKEN_TTL', 60),

];
