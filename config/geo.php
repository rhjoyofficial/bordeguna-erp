<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bangladesh's geography
    |--------------------------------------------------------------------------
    |
    | The district / thana / area tree, relative to resources/. 64 districts,
    | 620 thanas and 5,706 areas, assembled from government sources.
    |
    | ⭐⭐ IT IS DELIBERATELY NOT A COURIER'S LIST. A courier's coverage list is
    | that courier's commercial footprint, and it changes when they change their
    | business. Bôr dé Güna ships through several couriers, so the address a
    | customer gives has to be a fact about Bangladesh, and a courier's own ids
    | are looked up FROM it at booking time rather than being the thing the
    | customer picked.
    |
    | ⚠ Set this to an empty string and geography says nothing at all rather
    | than breaking: every caller already treats "no opinion" as "ask the
    | customer", which is also what an unrecognised place produces. A test holds
    | that, so the dataset can be swapped without taking a shop down.
    |
    */

    'dataset' => env('GEO_DATASET', 'data/bd-geo.json'),

];
