<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Validation;

/**
 * Ready-made format rules. Patterns use the PatternMatcher dialect: matched against the whole value, Unicode,
 * case-sensitive, and runnable as-is by the browser. Extend through the `rules` argument in di.xml.
 */
class BuiltInRules
{
    private const DEFAULTS = [
        'uk_postcode' => [
            'label' => 'UK postcode', 'group' => 'Postcodes', 'example' => 'SW1A 1AA',
            'pattern' => '[A-Za-z]{1,2}[0-9][A-Za-z0-9]? ?[0-9][A-Za-z]{2}',
            'message' => 'Please enter a valid UK postcode, such as SW1A 1AA.',
        ],
        'us_zip' => [
            'label' => 'US ZIP code', 'group' => 'Postcodes', 'example' => '90210',
            'pattern' => '[0-9]{5}(?:-[0-9]{4})?',
            'message' => 'Please enter a valid ZIP code, such as 90210 or 90210-1234.',
        ],
        'ca_postal_code' => [
            'label' => 'Canadian postal code', 'group' => 'Postcodes', 'example' => 'K1A 0B1',
            'pattern' => '[A-Za-z][0-9][A-Za-z] ?[0-9][A-Za-z][0-9]',
            'message' => 'Please enter a valid postal code, such as K1A 0B1.',
        ],
        'ie_eircode' => [
            'label' => 'Irish Eircode', 'group' => 'Postcodes', 'example' => 'D02 X285',
            'pattern' => '[A-Za-z][0-9][0-9Ww] ?[0-9A-Za-z]{4}',
            'message' => 'Please enter a valid Eircode, such as D02 X285.',
        ],
        'nl_postcode' => [
            'label' => 'Dutch postcode', 'group' => 'Postcodes', 'example' => '1012 AB',
            'pattern' => '[1-9][0-9]{3} ?[A-Za-z]{2}',
            'message' => 'Please enter a valid postcode, such as 1012 AB.',
        ],
        'five_digit_postcode' => [
            'label' => '5-digit postcode (DE, FR, ES, IT)', 'group' => 'Postcodes', 'example' => '75008',
            'pattern' => '[0-9]{5}',
            'message' => 'Please enter a 5-digit postcode.',
        ],
        'au_postcode' => [
            'label' => 'Australian postcode', 'group' => 'Postcodes', 'example' => '2000',
            'pattern' => '[0-9]{4}',
            'message' => 'Please enter a 4-digit postcode.',
        ],
        'uk_phone' => [
            'label' => 'UK phone number', 'group' => 'Phone', 'example' => '020 7946 0958',
            'pattern' => '(?:\+44 ?|0)[1-9][0-9 ]{8,12}',
            'message' => 'Please enter a UK phone number, such as 020 7946 0958 or +44 20 7946 0958.',
        ],
        'international_phone' => [
            'label' => 'International phone (+ country code)', 'group' => 'Phone', 'example' => '+447700900123',
            'pattern' => '\+[1-9][0-9]{6,14}',
            'message' => 'Please enter the number with its country code and no spaces, such as +447700900123.',
        ],
        'uk_sort_code' => [
            'label' => 'UK sort code', 'group' => 'Business and finance', 'example' => '12-34-56',
            'pattern' => '[0-9]{2}-?[0-9]{2}-?[0-9]{2}',
            'message' => 'Please enter a sort code, such as 12-34-56.',
        ],
        'iban_format' => [
            'label' => 'IBAN (format only)', 'group' => 'Business and finance', 'example' => 'GB82 WEST 1234 5698 7654 32',
            'pattern' => '[A-Za-z]{2}[0-9]{2}(?: ?[A-Za-z0-9]){11,30}',
            'message' => 'Please enter an IBAN, such as GB82 WEST 1234 5698 7654 32.',
        ],
        'uk_vat' => [
            'label' => 'UK VAT number', 'group' => 'Business and finance', 'example' => 'GB123 4567 89',
            'pattern' => '(?:[Gg][Bb])? ?[0-9]{3} ?[0-9]{4} ?[0-9]{2}(?: ?[0-9]{3})?',
            'message' => 'Please enter a UK VAT number, such as GB123 4567 89.',
        ],
        'uk_company_number' => [
            'label' => 'UK company number', 'group' => 'Business and finance', 'example' => '01234567',
            'pattern' => '[0-9]{8}|[A-Za-z]{2}[0-9]{6}',
            'message' => 'Please enter a company number, such as 01234567 or SC123456.',
        ],
        'uk_ni_number' => [
            'label' => 'UK National Insurance number', 'group' => 'Personal', 'example' => 'AB 12 34 56 C',
            'pattern' => '[A-CEGHJ-PR-TW-Za-ceghj-pr-tw-z]{2} ?[0-9]{2} ?[0-9]{2} ?[0-9]{2} ?[A-Da-d]',
            'message' => 'Please enter a National Insurance number, such as AB 12 34 56 C.',
        ],
        'full_name' => [
            'label' => 'Full name (two or more words)', 'group' => 'Personal', 'example' => 'Ada Lovelace',
            'pattern' => '[\p{L}\'.-]+ [\p{L}\' .-]+',
            'message' => 'Please enter your first and last name.',
        ],
        'username' => [
            'label' => 'Username', 'group' => 'Personal', 'example' => 'ada_lovelace',
            'pattern' => '[A-Za-z0-9_.-]{3,30}',
            'message' => 'Use 3 to 30 letters, numbers, dots, dashes or underscores.',
        ],
        'order_number' => [
            'label' => 'Order number', 'group' => 'Store', 'example' => '000000123',
            'pattern' => '#?[0-9]{5,20}',
            'message' => 'Please enter your order number, such as 000000123.',
        ],
        'coupon_code' => [
            'label' => 'Coupon code', 'group' => 'Store', 'example' => 'SUMMER-25',
            'pattern' => '[A-Za-z0-9_-]{3,32}',
            'message' => 'Use 3 to 32 letters, numbers, dashes or underscores.',
        ],
        'no_links' => [
            'label' => 'No links', 'group' => 'Content', 'example' => 'Please call me back',
            'pattern' => '(?![\s\S]*(?:[Hh][Tt][Tt][Pp][Ss]?:|[Ww][Ww][Ww]\.))[\s\S]*',
            'message' => 'Please remove any links.',
        ],
    ];

    /**
     * @param array<string, array{label: string, group?: string, pattern: string, message: string, example?: string}> $rules
     */
    public function __construct(private readonly array $rules = [])
    {
    }

    /**
     * @return array<string, array{label: string, group: string, pattern: string, message: string, example: string}>
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->rules + self::DEFAULTS as $code => $rule) {
            $out[(string)$code] = [
                'label' => (string)($rule['label'] ?? $code),
                'group' => (string)($rule['group'] ?? 'Other'),
                'pattern' => (string)($rule['pattern'] ?? ''),
                'message' => (string)($rule['message'] ?? ''),
                'example' => (string)($rule['example'] ?? ''),
            ];
        }
        return $out;
    }
}
