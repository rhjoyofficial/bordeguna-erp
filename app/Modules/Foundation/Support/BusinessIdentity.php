<?php

namespace App\Modules\Foundation\Support;

use App\Modules\Foundation\Models\Business;

/**
 * Who the business says it is, for the top of a document that leaves the
 * building.
 *
 * ⚠⚠ EVERY FIELD IS OPTIONAL AND NOTHING IS INVENTED. An invoice header is the
 * one place a plausible-looking default does real damage: an address nobody
 * chose, printed on a document handed to a customer, is worse than a gap they
 * can see. What is set is printed; what is not, is not.
 *
 * ⚠ `isIncomplete()` exists so a screen can say so BEFORE the paper is handed
 * over. It is shown on screen only, never printed: the customer should not read
 * a note telling us to fill our own settings in.
 */
class BusinessIdentity
{
    /**
     * @param  list<string>  $missing
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $legalName,
        public readonly ?string $address,
        public readonly ?string $phone,
        public readonly ?string $email,
        public readonly array $missing,
    ) {}

    public static function for(Business $business): self
    {
        $missing = [];

        foreach (['address' => 'address', 'phone' => 'phone', 'email' => 'email'] as $column => $label) {
            if (blank($business->{$column})) {
                $missing[] = $label;
            }
        }

        return new self(
            name: (string) $business->name,
            legalName: blank($business->legal_name) ? null : (string) $business->legal_name,
            address: blank($business->address) ? null : (string) $business->address,
            phone: blank($business->phone) ? null : (string) $business->phone,
            email: blank($business->email) ? null : (string) $business->email,
            missing: $missing,
        );
    }

    public static function forCurrent(): self
    {
        $id = app(BusinessContext::class)->id();

        // ⚠ withoutGlobalScopes: the business a document belongs to is resolved
        // by id from the request's own context, not filtered by the tenant scope
        // that context sets up. Left scoped, this returned null and the header
        // came out blank.
        $business = Business::withoutGlobalScopes()->find($id);

        return $business === null
            ? new self('', null, null, null, null, ['address', 'phone', 'email'])
            : self::for($business);
    }

    /**
     * @return array{name:string, legal_name:?string, address:?string, phone:?string, email:?string, missing:list<string>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'legal_name' => $this->legalName,
            'address' => $this->address,
            'phone' => $this->phone,
            'email' => $this->email,
            'missing' => $this->missing,
        ];
    }
}
