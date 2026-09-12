<?php

namespace App\Modules\Sales\Support;

use App\Support\BdGeo;
use Illuminate\Validation\ValidationException;

/**
 * Where an order is going, checked against the geography of Bangladesh.
 *
 * ⭐⭐ ONE PLACE DECIDES WHETHER A DESTINATION IS REAL, and it is not a
 * controller. The web checkout uses this today; the phone-order screen will use
 * it tomorrow, and that is the entire point. LaLaDia's web checkout required a
 * district and a thana while its admin order form collected neither, so a parcel
 * from a telephone call reached the courier with nothing to route on - not
 * because a service dropped the pair, but because two screens each owned their
 * own idea of an address.
 *
 * ⚠⚠ THE POSTCODE IS DERIVED HERE AND CANNOT BE PASSED IN. There is deliberately
 * no constructor parameter for it. A postcode sent to a browser is a postcode a
 * form can send back, and then the shop is trusting a stranger's arithmetic
 * about its own country.
 *
 * ⚠ Names are resolved to the DATASET's own spelling, so an admin's
 * "Chittagong" and a customer's "Chattogram" become one value that the rate
 * table, the courier form and the invoice all agree on.
 */
final readonly class SalesOrderDestination
{
    private function __construct(
        public string $recipientName,
        public string $recipientPhone,
        public string $district,
        public string $thana,
        public ?string $area,
        public ?string $postcode,
        public string $addressLine,
        public ?string $landmark,
    ) {}

    /**
     * ⚠ Refusals are `ValidationException`, never a 500 and never a silent
     * blank: a customer who picked a thana this shop does not recognise has to
     * be told which field is wrong, and the field name here matches the form's.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function fromInput(array $input): self
    {
        $district = BdGeo::districtNamed(self::text($input, 'district'));

        if ($district === null) {
            throw ValidationException::withMessages(['district' => 'We do not recognise that district.']);
        }

        $thana = BdGeo::thanaNamed($district, self::text($input, 'thana'));

        if ($thana === null) {
            // ⚠ Names the district in the sentence. "We do not recognise that
            // thana" on its own reads like a broken shop; naming the pair shows
            // the customer that the two answers have to belong together, which
            // is usually what went wrong.
            throw ValidationException::withMessages([
                'thana' => "We do not recognise that thana in {$district}.",
            ]);
        }

        // ⚠ Area is OPTIONAL, and an unrecognised one is dropped rather than
        // refused. The dataset's coverage is uneven (5,706 areas across 620
        // thanas), and refusing an order because a village is missing from a
        // government list would lose a sale over our own data. The pair above
        // is what routes the parcel; the area only sharpens the postcode.
        $area = BdGeo::areaNamed($district, $thana, self::text($input, 'area'));

        return new self(
            recipientName: self::required($input, 'name', 'Please tell us who is receiving this order.'),
            recipientPhone: self::required($input, 'phone', 'Please give a phone number the rider can call.'),
            district: $district,
            thana: $thana,
            area: $area,
            postcode: BdGeo::postcodeFor($district, $thana, $area),
            addressLine: self::required($input, 'address_line', 'Please give a street address.'),
            landmark: self::text($input, 'landmark'),
        );
    }

    /**
     * The row this destination becomes, ready for SalesOrderAddress.
     *
     * @return array<string, string|null>
     */
    public function toRow(): array
    {
        return [
            'recipient_name' => $this->recipientName,
            'recipient_phone' => $this->recipientPhone,
            'district' => $this->district,
            'thana' => $this->thana,
            'area' => $this->area,
            'postcode' => $this->postcode,
            'address_line' => $this->addressLine,
            'landmark' => $this->landmark,
        ];
    }

    /**
     * The destination as one line, for a field that has only room for one.
     *
     * ⚠ A CONVENIENCE COPY AND NEVER THE ROUTING TRUTH. `sales_order_addresses`
     * holds the parts, and a courier is booked from those. A flattened string is
     * for a screen that shows a customer's usual address, which is exactly the
     * shape LaLaDia shipped to its courier and then could not route.
     */
    public function oneLineAddress(): string
    {
        return collect([$this->addressLine, $this->landmark, $this->area, $this->thana, $this->district, $this->postcode])
            ->filter(fn (?string $part) => $part !== null && trim($part) !== '')
            ->implode(', ');
    }

    /** @param array<string, mixed> $input */
    private static function text(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    private static function required(array $input, string $key, string $message): string
    {
        $value = self::text($input, $key);

        if ($value === null) {
            throw ValidationException::withMessages([$key => $message]);
        }

        return $value;
    }
}
