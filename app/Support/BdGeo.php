<?php

namespace App\Support;

/**
 * Bangladesh's own geography: district → thana → area.
 *
 * ⭐⭐ WHY THIS IS NOT A COURIER'S LIST. Bôr dé Güna ships through more than one
 * courier, and a courier's coverage list is that courier's commercial footprint
 * rather than a fact about the country. If the customer picks from one carrier's
 * list, changing carrier changes what addresses the shop can take. So the
 * customer picks a place, and a carrier's own ids are resolved FROM that place
 * at booking time.
 *
 * ⚠ Every lookup is CONTAINED: a thana is only ever found inside the district
 * that resolved, and an area only inside that thana. A form post cannot pair
 * Gulshan with Bagerhat, whatever it sends.
 *
 * ⚠ Nothing here is business-scoped. These are public administrative facts and
 * they are the same for every tenant in the ERP.
 */
final class BdGeo
{
    /** The district spellings no rule can reduce to the standard one. */
    private const ALIASES = [
        // Couriers write Brahmanbaria as an initial.
        'B. Baria' => 'Brahmanbaria',
        'Bramanbaria' => 'Brahmanbaria',
        // The city was renamed; couriers and customers still use both.
        'Chittagong' => 'Chattogram',
    ];

    /**
     * The eight administrative divisions and the districts in each.
     *
     * ⚠ Written here rather than taken from the dataset because the dataset has
     * no division field, and this is stable public administrative fact. A test
     * proves every one of the 64 districts belongs to exactly one division, so a
     * typo in this hand-written list cannot go unnoticed.
     */
    public const DIVISIONS = [
        'Barisal' => ['Barguna', 'Barisal', 'Bhola', 'Jhalakathi', 'Patuakhali', 'Pirojpur'],
        'Chattogram' => ['Bandarban', 'Brahmanbaria', 'Chandpur', 'Chattogram', 'Comilla', 'Coxsbazar', 'Feni', 'Khagrachhari', 'Lakshmipur', 'Noakhali', 'Rangamati'],
        'Dhaka' => ['Dhaka', 'Faridpur', 'Gazipur', 'Gopalganj', 'Kishoreganj', 'Madaripur', 'Manikganj', 'Munshiganj', 'Narayanganj', 'Narsingdi', 'Rajbari', 'Shariatpur', 'Tangail'],
        'Khulna' => ['Bagerhat', 'Chuadanga', 'Jashore', 'Jhenaidah', 'Khulna', 'Kushtia', 'Magura', 'Meherpur', 'Narail', 'Satkhira'],
        'Mymensingh' => ['Jamalpur', 'Mymensingh', 'Netrokona', 'Sherpur'],
        'Rajshahi' => ['Bogura', 'Chapainawabganj', 'Joypurhat', 'Naogaon', 'Natore', 'Pabna', 'Rajshahi', 'Sirajganj'],
        'Rangpur' => ['Dinajpur', 'Gaibandha', 'Kurigram', 'Lalmonirhat', 'Nilphamari', 'Panchagarh', 'Rangpur', 'Thakurgaon'],
        'Sylhet' => ['Habiganj', 'Moulvibazar', 'Sunamganj', 'Sylhet'],
    ];

    /** @var array<int, array{name:string, bn_name:string, thanas:array}>|null */
    private static ?array $tree = null;

    private static ?string $loadedFrom = null;

    /**
     * Every district, in the order the dataset lists them (alphabetical).
     *
     * @return array<int, array{name:string, bn_name:string}>
     */
    public static function districts(): array
    {
        return array_map(
            fn (array $d) => ['name' => $d['name'], 'bn_name' => $d['bn_name'] ?? ''],
            self::tree(),
        );
    }

    /**
     * The division a district sits in, or null for a name we do not carry.
     *
     * ⭐ The delivery-price screen uses this so 64 rows can be set eight at a
     * time, which is the difference between a screen somebody fills in and a
     * screen somebody abandons.
     */
    public static function divisionOf(?string $district): ?string
    {
        $name = self::districtNamed($district);

        if ($name === null) {
            return null;
        }

        foreach (self::DIVISIONS as $division => $districts) {
            if (in_array($name, $districts, true)) {
                return $division;
            }
        }

        return null;
    }

    /**
     * The thanas of one district. Empty for a district we do not carry, which is
     * the caller's cue to ask rather than to guess.
     *
     * @return array<int, array{name:string, bn_name:string}>
     */
    public static function thanas(?string $district): array
    {
        $row = self::districtRow($district);

        if ($row === null) {
            return [];
        }

        return array_map(
            fn (array $t) => ['name' => $t['name'], 'bn_name' => $t['bn_name'] ?? ''],
            $row['thanas'],
        );
    }

    /**
     * The areas (unions and post-office areas) of one thana.
     *
     * ⚠ A handful of thanas carry no area list at all, and an empty answer means
     * the customer is simply not asked for one — never that the address is
     * incomplete.
     *
     * @return array<int, array{name:string, bn_name:string, postcode:?string}>
     */
    public static function areas(?string $district, ?string $thana): array
    {
        $row = self::thanaRow($district, $thana);

        if ($row === null) {
            return [];
        }

        return array_map(fn (array $a) => [
            'name' => $a['name'],
            'bn_name' => $a['bn_name'] ?? '',
            'postcode' => $a['postcode'] ?? null,
        ], $row['areas'] ?? []);
    }

    /** The dataset's own spelling of a district written any way at all, or null. */
    public static function districtNamed(?string $name): ?string
    {
        return self::districtRow($name)['name'] ?? null;
    }

    /** The dataset's own spelling of a thana inside a district, or null. */
    public static function thanaNamed(?string $district, ?string $thana): ?string
    {
        return self::thanaRow($district, $thana)['name'] ?? null;
    }

    /**
     * The dataset's own spelling of an area inside a thana, or null.
     *
     * ⚠ Areas are matched on the exact key ONLY, never on the loose one: a thana
     * can hold twenty areas whose names differ by a syllable, and the whole point
     * of an area is to be more precise than the thana.
     */
    public static function areaNamed(?string $district, ?string $thana, ?string $area): ?string
    {
        $key = self::key($area);

        if ($key === '') {
            return null;
        }

        foreach (self::areas($district, $thana) as $row) {
            if (self::key($row['name']) === $key) {
                return $row['name'];
            }
        }

        return null;
    }

    /**
     * The postcode for an address, or null when the dataset cannot say.
     *
     * ⭐ The customer is never asked for one. A postcode hangs off the thana and
     * narrows to the area's own code the moment an area is chosen, so it is
     * DERIVED from what the customer already picked rather than being a box most
     * Bangladeshi customers do not know by heart.
     *
     * ⚠⚠ It is derived, never invented, and the three rules below are the whole
     * of it. They are in order of how much is known, so the answer is always the
     * most specific fact available:
     *
     *   1. The chosen AREA's own postcode, when the dataset carries one. A
     *      stated fact about that area, not an inference.
     *   2. Failing that, the THANA's postcode, when every area in it that has
     *      one agrees. Also a fact: one thana, one code.
     *   3. Failing that, the thana's HEAD OFFICE — see thanaCentrePostcode().
     *      An approximation, and the only rule here that is one.
     *
     * ⚠ Rule 3 replaced an older rule that answered null whenever a thana's
     * areas disagreed. The old reasoning was that a wrong postcode is worse than
     * none. That is true of a postcode picked at random, which is why rule 3
     * identifies the head office BY NAME rather than picking one — but it was
     * the wrong trade overall, because 265 of the country's 620 thanas are in
     * that state and the whole of them were reaching the courier with nothing.
     */
    public static function postcodeFor(?string $district, ?string $thana, ?string $area = null): ?string
    {
        $areas = self::areas($district, $thana);

        if ($areas === []) {
            return null;
        }

        // Rule 1 — the area's own code, matched on the exact key only, for the
        // same reason areaNamed() does: neighbouring areas differ by a syllable.
        $areaKey = self::key($area);

        if ($areaKey !== '') {
            foreach ($areas as $row) {
                // ⚠ A matched area that carries NO code falls THROUGH to rule 2
                // rather than answering null. Returning here would be wrong:
                // most areas in a single-postcode thana have no code of their
                // own, so stopping would throw away the very answer rule 2
                // exists to give.
                if (self::key($row['name']) === $areaKey && filled($row['postcode'])) {
                    return (string) $row['postcode'];
                }
            }
        }

        // Rule 2 — the thana's code, when every coded area agrees. A stated fact
        // rather than a choice: one thana, one code.
        $codes = array_values(array_unique(array_filter(
            array_column($areas, 'postcode'),
            static fn ($code) => filled($code),
        )));

        if ($codes === []) {
            return null;
        }

        if (count($codes) === 1) {
            return (string) $codes[0];
        }

        // Rule 3 — the thana's CENTRE, when its areas carry different codes.
        return self::thanaCentrePostcode($thana, $areas);
    }

    /**
     * The head post office of a thana whose areas carry different codes.
     *
     * Bangladesh gives the upazila head office the round number and increments
     * outward, so the lowest code is usually the centre — but "usually" is not
     * good enough when the name is sitting right there in the data.
     *
     * ⚠ Measured across the whole dataset: of the 214 multi-code thanas that
     * have an area named after the thana, 212 carry that area on the lowest
     * code. In the two that differ — Chattogram/Boalkhali (named 4366, lowest
     * 4363) and Munshiganj/Lohajong (named 1530, lowest 1333) — the NAMED office
     * is the real upazila head and the lowest code belongs to a sub-office. So
     * the name decides, and the lowest code is only the fallback, which is all
     * that is left for the 51 thanas that name no area after themselves.
     *
     * @param  list<array{name: string, bn_name: string, postcode: string|null}>  $areas
     */
    private static function thanaCentrePostcode(?string $thana, array $areas): ?string
    {
        $coded = array_values(array_filter($areas, static fn (array $a) => filled($a['postcode'])));

        if ($coded === []) {
            return null;
        }

        // "Kachua UPO" and "Sylhet Sadar RS" carry a post-office designation,
        // not part of the place's name — the area is never called that.
        $thanaKey = self::districtKey((string) preg_replace('/\s+(UPO|RS|TSO|SO|EDSO)$/i', '', (string) $thana));

        if ($thanaKey !== '') {
            // ⚠ districtKey is documented as being for districts, where no two
            // of the 64 collide. It is safe to borrow here only because the
            // comparison is made inside ONE thana's short area list and the
            // guard below refuses to answer if it ever matches two codes.
            $namedCodes = array_values(array_unique(array_map(
                static fn (array $a) => (string) $a['postcode'],
                array_filter($coded, static fn (array $a) => self::districtKey($a['name']) === $thanaKey),
            )));

            if (count($namedCodes) === 1) {
                return $namedCodes[0];
            }
        }

        $codes = array_column($coded, 'postcode');

        usort(
            $codes,
            static fn ($a, $b) => (int) preg_replace('/\D/', '', (string) $a)
                <=> (int) preg_replace('/\D/', '', (string) $b),
        );

        return (string) $codes[0];
    }

    public static function hasDistrict(?string $name): bool
    {
        return self::districtNamed($name) !== null;
    }

    /**
     * A district's match key: what is left when spelling stops mattering.
     *
     * ⚠ Deliberately applied to DISTRICTS ONLY, where there are 64 names and a
     * test proves no two of them collide. It strips vowels and doubled letters,
     * so on a longer or denser list it would happily match two different places.
     */
    public static function districtKey(?string $name): string
    {
        $key = self::key($name);

        if ($key === '') {
            return '';
        }

        $loose = preg_replace('/(.)\1+/', '$1', (string) preg_replace(
            '/[aeiou]/',
            '',
            str_replace('h', '', $key),
        )) ?? '';

        return $loose === '' ? $key : $loose;
    }

    /** Lowercase letters and digits only: the exact key. */
    public static function key(?string $name): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim((string) $name)));
    }

    /** @return array{name:string, bn_name:string, thanas:array}|null */
    private static function districtRow(?string $name): ?array
    {
        $exact = self::key($name);

        if ($exact === '') {
            return null;
        }

        foreach (self::tree() as $row) {
            if (self::key($row['name']) === $exact) {
                return $row;
            }
        }

        // Only now is spelling allowed to stop mattering. An alias resolves to a
        // real district name, which is then looked up exactly — so a bad alias
        // costs a missed match, never a wrong one.
        $loose = self::districtKey($name);

        foreach (self::ALIASES as $variant => $standard) {
            if (self::districtKey($variant) !== $loose) {
                continue;
            }

            $target = self::key($standard);

            foreach (self::tree() as $row) {
                if (self::key($row['name']) === $target) {
                    return $row;
                }
            }
        }

        foreach (self::tree() as $row) {
            if (self::districtKey($row['name']) === $loose) {
                return $row;
            }
        }

        return null;
    }

    /** @return array{name:string, bn_name:string, areas:array}|null */
    private static function thanaRow(?string $district, ?string $thana): ?array
    {
        $key = self::key($thana);

        if ($key === '') {
            return null;
        }

        foreach (self::districtRow($district)['thanas'] ?? [] as $row) {
            if (self::key($row['name']) === $key) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Read once per file per process.
     *
     * ⚠ Keyed on the configured path, not on a plain "loaded once" flag, so a
     * test that points the config at a different file is not served the previous
     * one out of a static.
     */
    private static function tree(): array
    {
        $file = (string) (config('geo.dataset') ?? '');

        if (self::$tree !== null && self::$loadedFrom === $file) {
            return self::$tree;
        }

        self::$loadedFrom = $file;

        if ($file === '') {
            return self::$tree = [];
        }

        $path = resource_path($file);

        if (! is_file($path)) {
            // Missing data means "no opinion", which every caller already
            // handles by asking the customer instead of guessing for them.
            return self::$tree = [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return self::$tree = is_array($decoded) ? $decoded : [];
    }
}
