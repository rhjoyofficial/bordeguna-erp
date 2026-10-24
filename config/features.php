<?php

/*
|--------------------------------------------------------------------------
| Hidden features (Joy's decision, 24 Oct 2026)
|--------------------------------------------------------------------------
|
| Each line is one feature Joy asked to REMOVE from the ERP. "Remove" means
| hidden, never deleted: with its flag false the feature has no menu entry,
| no section tab, no search hit, no dashboard or health signal, no link from
| any other page, and every one of its routes answers 404. Its code, its
| tables and its permissions all stay, so turning one back on is this one
| line (or the matching FEATURE_* entry in .env, then `php artisan
| config:cache` on the server).
|
| Live row counts were checked on 24 Oct 2026: every table behind these held
| 0 rows, so hiding them takes nothing out of anybody's sight.
|
| ⚠ The routes stay REGISTERED (route() and Ziggy keep resolving); the
| `feature:<key>` middleware is what answers 404. See App\Support\Features.
|
*/

return [
    'chat' => (bool) env('FEATURE_CHAT', false),
    'crm_tickets' => (bool) env('FEATURE_CRM_TICKETS', false),
    'recruitment' => (bool) env('FEATURE_RECRUITMENT', false),
    'budgets' => (bool) env('FEATURE_BUDGETS', false),
    'cash_forecast' => (bool) env('FEATURE_CASH_FORECAST', false),
    'dunning' => (bool) env('FEATURE_DUNNING', false),
    'notes' => (bool) env('FEATURE_NOTES', false),
    'calendar' => (bool) env('FEATURE_CALENDAR', false),
    'org_chart' => (bool) env('FEATURE_ORG_CHART', false),
];
