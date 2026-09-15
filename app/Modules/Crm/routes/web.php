<?php

use App\Modules\Crm\Http\Controllers\CadenceController;
use App\Modules\Crm\Http\Controllers\ContactController;
use App\Modules\Crm\Http\Controllers\ContactGroupController;
use App\Modules\Crm\Http\Controllers\ContactImportController;
use App\Modules\Crm\Http\Controllers\CrmActivityController;
use App\Modules\Crm\Http\Controllers\CrmReportController;
use App\Modules\Crm\Http\Controllers\CrmSettingsController;
use App\Modules\Crm\Http\Controllers\LeadController;
use App\Modules\Crm\Http\Controllers\LeadEvidenceController;
use App\Modules\Crm\Http\Controllers\MessageProviderController;
use App\Modules\Crm\Http\Controllers\MessagingController;
use App\Modules\Crm\Http\Controllers\OpportunityController;
use App\Modules\Crm\Http\Controllers\TicketController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'business'])->prefix('crm')->name('crm.')->group(function () {
    // Reading is open to anyone who may view leads; changing is gated per
    // operation, the same split Sales and Purchasing use.
    Route::middleware('can:crm.leads.view')->group(function () {
        Route::get('leads', [LeadController::class, 'index'])->name('leads.index');
        // ⚠ Duplicate detection is a READ. It must be available to anyone who can
        // see leads, or the warning cannot fire on the screen that needs it.
        Route::get('leads/duplicates', [LeadController::class, 'duplicates'])->name('leads.duplicates');
        // The pipeline board: reading deals is reading the sales work.
        Route::get('pipeline', [OpportunityController::class, 'index'])->name('pipeline');
        // Tickets: complaints with a running clock. Reading them is reading
        // the customer work.
        Route::get('tickets', [TicketController::class, 'index'])->name('tickets');
    });

    Route::middleware('can:crm.leads.manage')->group(function () {
        Route::post('leads', [LeadController::class, 'store'])->name('leads.store');
        Route::put('leads/{lead}', [LeadController::class, 'update'])->name('leads.update');

        // Deals move with the same trust that works the leads they grew from.
        Route::post('opportunities', [OpportunityController::class, 'store'])->name('opportunities.store');
        Route::post('opportunities/{opportunity}/stage', [OpportunityController::class, 'stage'])->name('opportunities.stage');
        Route::post('opportunities/{opportunity}/won', [OpportunityController::class, 'won'])->name('opportunities.won');
        Route::post('opportunities/{opportunity}/lost', [OpportunityController::class, 'lost'])->name('opportunities.lost');
        // Ticket writes travel with the same trust that works the leads.
        Route::post('tickets', [TicketController::class, 'store'])->name('tickets.store');
        Route::post('tickets/{ticket}/reply', [TicketController::class, 'reply'])->name('tickets.reply');
        Route::post('tickets/{ticket}/resolve', [TicketController::class, 'resolve'])->name('tickets.resolve');
        Route::post('tickets/{ticket}/assign', [TicketController::class, 'assign'])->name('tickets.assign');
    });

    // ⚠⚠ Conversion creates MASTER DATA with a permanent code, so it is its own
    // permission and deliberately NOT given to Staff. They qualify a lead;
    // deciding that a customer record exists is a manager's call (C-CRMB-14).
    Route::middleware('can:crm.leads.convert')->group(function () {
        Route::post('leads/{lead}/convert', [LeadController::class, 'convert'])->name('leads.convert');
    });

    // ⚠⚠ Verification and archiving are the ASM's, NOT the officer's, and that
    // separation is the whole reason verification was chosen over approval on
    // every edit. The officer who created a retailer keeps working it, corrects
    // its phone and records visits with no permission beyond managing leads -
    // but cannot certify their own record, and cannot make it disappear.
    Route::middleware('can:crm.leads.verify')->group(function () {
        Route::post('leads/{lead}/verify', [LeadController::class, 'verify'])->name('leads.verify');
        Route::delete('leads/{lead}/verify', [LeadController::class, 'unverify'])->name('leads.unverify');
        Route::post('leads/{lead}/archive', [LeadController::class, 'archive'])->name('leads.archive');
        Route::delete('leads/{lead}/archive', [LeadController::class, 'restore'])->name('leads.restore');
        // ⚠ Moved here from the manage group on 15 Sep 2026. Linking a duplicate
        // deletes nothing and moves nothing, but it takes a lead out of the
        // working list exactly as archiving does, and Joy reserved merging for
        // the ASM. See LeadController::linkDuplicate().
        Route::post('leads/{lead}/duplicate-of', [LeadController::class, 'linkDuplicate'])->name('leads.duplicate-of');
        // ⭐ Joy's answer 17: before appointing a distributor the ASM MAY record
        // what he saw. Evidence, so there is no edit or delete route.
        Route::post('leads/{lead}/inspections', [LeadEvidenceController::class, 'storeInspection'])->name('leads.inspections.store');
    });

    // ⭐ Joy's answer 15 (plan D13): whoever works a supplier lead logs a sample
    // that arrived; only the Owner records whether it passed, once. No edit or
    // delete route for either - a re-test is a new sample.
    Route::middleware('can:crm.leads.manage')->group(function () {
        Route::post('leads/{lead}/samples', [LeadEvidenceController::class, 'storeSample'])->name('leads.samples.store');
    });
    Route::middleware('can:crm.samples.judge')->group(function () {
        Route::post('samples/{sample}/result', [LeadEvidenceController::class, 'judgeSample'])->name('samples.result');
    });

    // ⚠ Sources and campaigns are the REPORTING AXES. Staff capture leads;
    // inventing a source mid-campaign is how a report ends up with four
    // spellings of "Meta" (C-CRM-14).
    Route::middleware('can:crm.settings.manage')->group(function () {
        // Cadences: standing rhythms that write tasks. Paused, never deleted.
        Route::get('cadences', [CadenceController::class, 'index'])->name('cadences');
        Route::post('cadences', [CadenceController::class, 'store'])->name('cadences.store');
        Route::post('cadences/{cadence}/toggle', [CadenceController::class, 'toggle'])->name('cadences.toggle');
        Route::get('settings', [CrmSettingsController::class, 'index'])->name('settings.index');
        Route::post('settings/sources', [CrmSettingsController::class, 'storeSource'])->name('settings.sources.store');
        Route::put('settings/sources/{leadSource}', [CrmSettingsController::class, 'updateSource'])->name('settings.sources.update');
        Route::post('settings/campaigns', [CrmSettingsController::class, 'storeCampaign'])->name('settings.campaigns.store');
        Route::put('settings/campaigns/{campaign}', [CrmSettingsController::class, 'updateCampaign'])->name('settings.campaigns.update');
        // The lists a lead picks from, and the lost reasons (CRM slice 2c).
        // ⚠ No DELETE: an entry is retired, never removed.
        Route::post('settings/lists/{list}', [CrmSettingsController::class, 'storeListEntry'])
            ->whereIn('list', ['shop_type', 'supplier_type', 'competitor_brand', 'lead_lost'])->name('settings.lists.store');
        Route::put('settings/lists/{list}/{entry}', [CrmSettingsController::class, 'updateListEntry'])
            ->whereIn('list', ['shop_type', 'supplier_type', 'competitor_brand', 'lead_lost'])->whereNumber('entry')->name('settings.lists.update');
    });

    // ── Phase B: timeline and work queue ────────────────────────────────────
    // ⚠ The subject is `lead` or `customer`, constrained in the route so a
    // typo 404s at routing rather than reaching the controller as a null model.
    Route::middleware('can:crm.leads.view')->group(function () {
        // ⚠⚠ Declared BEFORE the {type}/{id} pattern, which would otherwise
        // swallow `/crm/queue` as a subject called "queue".
        Route::get('queue', [CrmActivityController::class, 'queue'])->name('queue');
        Route::get('reports', [CrmReportController::class, 'index'])->name('reports');

        Route::get('{type}/{id}/timeline', [CrmActivityController::class, 'show'])
            ->whereIn('type', ['lead', 'customer'])->whereNumber('id')->name('timeline');
    });

    Route::middleware('can:crm.leads.manage')->group(function () {
        Route::post('{type}/{id}/activities', [CrmActivityController::class, 'store'])
            ->whereIn('type', ['lead', 'customer'])->whereNumber('id')->name('activities.store');
        Route::post('{type}/{id}/tasks', [CrmActivityController::class, 'storeTask'])
            ->whereIn('type', ['lead', 'customer'])->whereNumber('id')->name('tasks.store');
        Route::put('tasks/{task}', [CrmActivityController::class, 'updateTask'])->name('tasks.update');

        // ⚠ Interest is CLOSED, never deleted - there is deliberately no
        // DELETE route. A demand signal that quietly disappears is one nobody
        // can audit.
        Route::post('{type}/{id}/interests', [CrmActivityController::class, 'storeInterest'])
            ->whereIn('type', ['lead', 'customer'])->whereNumber('id')->name('interests.store');
        Route::put('interests/{interest}/close', [CrmActivityController::class, 'closeInterest'])->name('interests.close');
    });

    // ── Sending to the phone book (Joy, 29 Aug 2026) ────────────────────────
    // ⚠⚠ THE FIRST THING IN THE ERP THAT SPENDS MONEY BY ITSELF. Three brakes,
    // none optional: a preview that must be read, a hard cap per campaign, and
    // approval above a threshold. Approving is a SEPARATE permission from
    // sending, so the person who spends is not automatically the person who
    // agrees to the spend.
    Route::middleware('can:crm.campaigns.send')->group(function () {
        Route::get('messages/{channel}', [MessagingController::class, 'index'])
            ->whereIn('channel', ['sms', 'email'])->name('messages.index');
        Route::post('messages/{channel}', [MessagingController::class, 'store'])
            ->whereIn('channel', ['sms', 'email'])->name('messages.store');
        Route::post('messages/{channel}/opt-out', [MessagingController::class, 'optOut'])
            ->whereIn('channel', ['sms', 'email'])->name('messages.opt-out');

        Route::put('campaigns/{campaign}', [MessagingController::class, 'update'])->name('campaigns.update');
        // ⚠ Preview writes only the figures it just worked out. It is a POST
        // because it records the estimate that approval will approve.
        Route::post('campaigns/{campaign}/preview', [MessagingController::class, 'preview'])->name('campaigns.preview');
        Route::post('campaigns/{campaign}/send', [MessagingController::class, 'send'])->name('campaigns.send');
        Route::get('campaigns/{campaign}/deliveries', [MessagingController::class, 'deliveries'])->name('campaigns.deliveries');
    });

    // ⚠⚠ Held apart from sending on purpose.
    Route::middleware('can:crm.campaigns.approve')->group(function () {
        Route::post('campaigns/{campaign}/approve', [MessagingController::class, 'approve'])->name('campaigns.approve');
        Route::post('campaigns/{campaign}/reject', [MessagingController::class, 'reject'])->name('campaigns.reject');
    });

    // ⚠ Provider credentials sit with settings management, not with sending.
    // Somebody trusted to run a send does not need to read the API key.
    Route::middleware('can:crm.settings.manage')->group(function () {
        Route::get('settings/messaging', [MessageProviderController::class, 'index'])->name('settings.messaging');
        Route::post('settings/messaging', [MessageProviderController::class, 'store'])->name('settings.messaging.store');
        Route::put('settings/messaging/{provider}', [MessageProviderController::class, 'update'])->name('settings.messaging.update');
        Route::post('settings/messaging/{provider}/toggle', [MessageProviderController::class, 'toggle'])->name('settings.messaging.toggle');

        // ⚠⚠ A TEST SENDS A REAL MESSAGE AND COSTS REAL MONEY - one message,
        // deliberately, so a wrong endpoint or a wrong field name is found for
        // the price of one SMS instead of the price of a campaign. It is a POST
        // because it spends.
        Route::post('settings/messaging/{provider}/test', [MessageProviderController::class, 'test'])->name('settings.messaging.test');
        Route::post('settings/messaging/{provider}/balance', [MessageProviderController::class, 'balance'])->name('settings.messaging.balance');

        // The approval threshold. ⚠ Not under the send permission: the number
        // that decides what needs approving is not for the person sending.
        Route::put('settings/messaging', [MessageProviderController::class, 'settings'])->name('settings.messaging.settings');
    });

    // ── The phone book (Joy, 29 Aug 2026) ───────────────────────────────────
    // ⚠ Its own permissions, not the lead ones. The phone book holds people who
    // are neither leads nor customers, and somebody trusted to run a send is not
    // automatically somebody trusted to work the sales pipeline.
    Route::middleware('can:crm.contacts.view')->group(function () {
        Route::get('contacts', [ContactController::class, 'index'])->name('contacts.index');

        // ⚠⚠ Declared BEFORE any '{contact}' pattern, which would otherwise
        // swallow /crm/contacts/export as a contact called "export". The same
        // trap the CRM queue route carries a warning about.
        Route::get('contacts/export', [ContactImportController::class, 'export'])->name('contacts.export');
        Route::get('contacts/template', [ContactImportController::class, 'template'])->name('contacts.template');
    });

    Route::middleware('can:crm.contacts.manage')->group(function () {
        Route::post('contacts', [ContactController::class, 'store'])->name('contacts.store');
        Route::put('contacts/{contact}', [ContactController::class, 'update'])->whereNumber('contact')->name('contacts.update');
        Route::post('contacts/{contact}/deactivate', [ContactController::class, 'deactivate'])->whereNumber('contact')->name('contacts.deactivate');
        Route::post('contacts/{contact}/reactivate', [ContactController::class, 'reactivate'])->whereNumber('contact')->name('contacts.reactivate');

        Route::post('contact-groups', [ContactGroupController::class, 'store'])->name('contact-groups.store');
        Route::put('contact-groups/{group}', [ContactGroupController::class, 'update'])->name('contact-groups.update');
        Route::post('contact-groups/add', [ContactController::class, 'addToGroup'])->name('contact-groups.add');
        Route::post('contact-groups/remove', [ContactController::class, 'removeFromGroup'])->name('contact-groups.remove');

        Route::post('contacts/pull', [ContactController::class, 'pullFromCrm'])->name('contacts.pull');

        // ⚠⚠ TWO requests on purpose. `review` writes nothing and returns a
        // plan; `apply` writes what the person has just read. A single
        // upload-and-write route would make the preview a decoration.
        Route::post('contacts/import/review', [ContactImportController::class, 'review'])->name('contacts.import.review');
        Route::post('contacts/import/apply', [ContactImportController::class, 'apply'])->name('contacts.import.apply');
    });
});
