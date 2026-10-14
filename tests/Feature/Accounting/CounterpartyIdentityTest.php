<?php

use App\Models\User;
use App\Modules\Accounting\Actions\SaveCounterparty;
use App\Modules\Accounting\Enums\CounterpartyKind;
use App\Modules\Accounting\Models\Counterparty;
use App\Modules\Accounting\Support\CounterpartyIdentity;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * Counterparties (loans plan v2, phase 1): one row per legal party, group-wide,
 * never one of our own businesses, never the same party twice.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Bor de Guna', 'slug' => 'bor-de-guna', 'fiscal_year_start_month' => 7]);
    $this->laladia = Business::create(['name' => 'LaLaDia', 'slug' => 'laladia', 'legal_name' => 'LaLaDia Foods']);
    TenantScope::enter($this->business->id);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    $this->save = fn (array $data, ?Counterparty $counterparty = null) => app(SaveCounterparty::class)->handle($data + [
        'kind' => 'company',
    ], $this->user, $counterparty);

    $this->refusal = function (callable $act): array {
        try {
            $act();
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    };
});

it('keeps a counterparty with its identity, who made it, and an activity log entry', function () {
    $tycoon = ($this->save)([
        'legal_name' => 'Tycoon Hi-Tech Park Ltd.',
        'kind' => 'company',
        'tin' => '1234-5678-9012',
        'bin' => '000123456-0101',
        'phone' => '+880 1711-000000',
        'email' => 'accounts@tycoon.example',
        'address' => 'Gazipur',
        'bank_details' => 'BRAC Bank, Gulshan, A/C 150...',
        'notes' => 'Borrows for the hi-tech park fit-out.',
    ]);

    expect($tycoon->legal_name)->toBe('Tycoon Hi-Tech Park Ltd.')
        ->and($tycoon->kind)->toBe(CounterpartyKind::Company)
        ->and($tycoon->related_party)->toBeFalse()
        ->and($tycoon->is_active)->toBeTrue()
        ->and($tycoon->created_by)->toBe($this->user->id)
        ->and($tycoon->updated_by)->toBe($this->user->id)
        // The matching forms, never what was typed.
        ->and($tycoon->name_key)->toBe('tycoonhitechpark')
        ->and($tycoon->phone_key)->toBe('01711000000')
        ->and($tycoon->tin_key)->toBe('123456789012')
        ->and($tycoon->phone)->toBe('+880 1711-000000');

    expect(Activity::query()->where('subject_type', Counterparty::class)->where('subject_id', $tycoon->id)->where('description', 'created')->exists())->toBeTrue();
});

it('is group-wide: the same row is seen from every business', function () {
    $bank = ($this->save)(['legal_name' => 'BRAC Bank PLC', 'kind' => 'bank']);

    TenantScope::enter($this->laladia->id);

    expect(Counterparty::query()->find($bank->id))->not->toBeNull()
        ->and(Schema::hasColumn('counterparties', 'business_id'))->toBeFalse();
});

it('refuses a second counterparty with the same TIN, however it is spelt', function () {
    ($this->save)(['legal_name' => 'Tycoon Hi-Tech Park Ltd.', 'tin' => '123456789012']);

    $errors = ($this->refusal)(fn () => ($this->save)(['legal_name' => 'Tycoon Park', 'tin' => '1234 5678-9012']));

    expect($errors['tin'][0] ?? '')->toContain('TIN 1234 5678-9012 already belongs to Tycoon Hi-Tech Park Ltd.')
        ->and(Counterparty::count())->toBe(1);
});

it('refuses the same name with the same phone, and allows the same name with another phone', function () {
    ($this->save)(['legal_name' => 'Tycoon Hi-Tech Park Ltd.', 'phone' => '01711000000']);

    $errors = ($this->refusal)(fn () => ($this->save)(['legal_name' => 'tycoon hi tech park limited', 'phone' => '+8801711000000']));

    expect($errors['legal_name'][0] ?? '')->toContain('Tycoon Hi-Tech Park Ltd. with phone 01711000000 is already a counterparty');

    // Two people may share a name; a different number is a different party.
    ($this->save)(['legal_name' => 'Tycoon Hi-Tech Park', 'phone' => '01811000000', 'kind' => 'individual']);

    expect(Counterparty::count())->toBe(2);
});

it('says when the duplicate is a deactivated counterparty', function () {
    $old = ($this->save)(['legal_name' => 'Padma Finance', 'tin' => '999']);
    $old->forceFill(['is_active' => false])->save();

    $errors = ($this->refusal)(fn () => ($this->save)(['legal_name' => 'Padma Finance PLC', 'tin' => '999']));

    expect($errors['tin'][0] ?? '')->toContain('deactivated; reactivate it instead');
});

it('does not count a counterparty as its own duplicate when it is edited', function () {
    $tycoon = ($this->save)(['legal_name' => 'Tycoon Hi-Tech Park Ltd.', 'tin' => '123456789012', 'phone' => '01711000000']);

    $edited = ($this->save)(['legal_name' => 'Tycoon Hi-Tech Park Limited', 'tin' => '123456789012', 'phone' => '01711000000', 'notes' => 'Renamed'], $tycoon);

    expect($edited->legal_name)->toBe('Tycoon Hi-Tech Park Limited')
        ->and(Activity::query()->where('subject_type', Counterparty::class)->where('subject_id', $tycoon->id)->where('description', 'updated')->exists())->toBeTrue();
});

it('refuses one of our own businesses, by name, legal name or slug, on create and on rename', function (string $name) {
    $errors = ($this->refusal)(fn () => ($this->save)(['legal_name' => $name]));

    expect($errors['legal_name'][0] ?? '')->toContain('one of the group\'s own businesses')
        ->and($errors['legal_name'][0])->toContain('Inter-business transfers')
        ->and(Counterparty::count())->toBe(0);
})->with([
    'name' => ['LaLaDia'],
    'name with a suffix' => ['Lala Dia Ltd.'],
    'legal name' => ['LaLaDia Foods Limited'],
    'this business' => ['BOR DE GUNA'],
]);

it('refuses renaming a counterparty to one of our own businesses', function () {
    $bank = ($this->save)(['legal_name' => 'Somebody Else']);

    $errors = ($this->refusal)(fn () => ($this->save)(['legal_name' => 'Bor de Guna'], $bank));

    expect($errors['legal_name'][0] ?? '')->toContain('one of the group\'s own businesses')
        ->and($bank->fresh()->legal_name)->toBe('Somebody Else');
});

it('asks how a related party is related, and forgets the relationship when the mark is cleared', function () {
    $errors = ($this->refusal)(fn () => ($this->save)(['legal_name' => 'Rahim Uddin', 'kind' => 'individual', 'related_party' => true]));

    expect($errors['relationship'][0] ?? '')->toContain('Say how this party is related');

    $rahim = ($this->save)(['legal_name' => 'Rahim Uddin', 'kind' => 'individual', 'related_party' => true, 'relationship' => "The owner's brother"]);
    expect($rahim->related_party)->toBeTrue()->and($rahim->relationship)->toBe("The owner's brother");

    $rahim = ($this->save)(['legal_name' => 'Rahim Uddin', 'kind' => 'individual', 'related_party' => false, 'relationship' => "The owner's brother"], $rahim);
    expect($rahim->related_party)->toBeFalse()->and($rahim->relationship)->toBeNull();
});

it('links one customer and one employee to one counterparty only', function () {
    $customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Tycoon Hi-Tech Park', 'type' => CustomerType::Corporate]);
    $employee = Employee::create(['employee_code' => 'BDG-000009', 'first_name' => 'Karim', 'last_name' => 'Mia', 'employment_type' => 'permanent', 'status' => 'active']);

    $tycoon = ($this->save)(['legal_name' => 'Tycoon Hi-Tech Park Ltd.', 'customer_id' => $customer->id]);
    $karim = ($this->save)(['legal_name' => 'Karim Mia', 'kind' => 'individual', 'employee_id' => $employee->id]);

    expect($tycoon->customer->is($customer))->toBeTrue()
        ->and($karim->employee->is($employee))->toBeTrue();

    $errors = ($this->refusal)(fn () => ($this->save)(['legal_name' => 'Tycoon Again', 'customer_id' => $customer->id]));
    expect($errors['customer_id'][0] ?? '')->toBe('That customer is already linked to Tycoon Hi-Tech Park Ltd.. One customer, one counterparty.');
});

it('refuses linking another business\'s customer', function () {
    $theirs = Customer::create(['business_id' => $this->laladia->id, 'name' => 'Their customer', 'type' => CustomerType::Retail]);

    $errors = ($this->refusal)(fn () => ($this->save)(['legal_name' => 'Somebody', 'customer_id' => $theirs->id]));

    expect($errors['customer_id'][0] ?? '')->toBe("That customer is not in this business's list.");
});

it('makes the same matching key of the usual spellings of one name', function () {
    expect(CounterpartyIdentity::nameKey('Tycoon Hi-Tech Park Ltd.'))->toBe(CounterpartyIdentity::nameKey('TYCOON HI TECH PARK LIMITED'))
        ->and(CounterpartyIdentity::nameKey('A & B Traders Pvt. Ltd.'))->toBe(CounterpartyIdentity::nameKey('A and B Traders'))
        // A legal word is only dropped from the END of a name.
        ->and(CounterpartyIdentity::nameKey('Company Bazar'))->toBe('companybazar')
        // Bangla vowel signs are marks, and stay part of the word.
        ->and(CounterpartyIdentity::nameKey('রহিম উদ্দিন'))->toBe(Normalizer::normalize('রহিমউদ্দিন', Normalizer::FORM_KC));
});
