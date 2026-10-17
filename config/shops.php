<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Market-priced products on the shops (Phase 2 / 1B, 17 Oct 2026)
    |--------------------------------------------------------------------------
    |
    | Fish and daily groceries are priced from the market each morning in the
    | ERP (Sales\Support\ProductPricing). The shops are NOT in that release:
    | until a shop can apply today's price and lock the price an accepted
    | order was placed at (ChatGPT ruling Q5), a market-priced product is
    | refused for publishing and for a cart (CataloguePublishing::refusalFor).
    | Switch this on only when the shop side is proven.
    |
    */

    'market_pricing' => (bool) env('SHOPS_MARKET_PRICING', false),

];
