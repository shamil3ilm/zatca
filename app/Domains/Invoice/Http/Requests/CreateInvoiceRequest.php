<?php

namespace App\Domains\Invoice\Http\Requests;

use App\Domains\Compliance\Fatoora\Config\FatooraConfig;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Enums\InvoiceType;
use App\Domains\Invoice\Enums\TaxCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateInvoiceRequest extends FormRequest
{
    /**
     * BT-121, the exemption reason codes ZATCA accepts.
     *
     * Taken from the BR-KSA-CL-04 assertion in ZATCA's own Schematron rather
     * than from the prose, because that assertion is what decides. The list
     * this replaced differed from it in both directions: it accepted
     * VATEX-SA-31, VATEX-SA-OOS-1 and VATEX-SA-OOS-2, which the authority does
     * not recognise, so an invoice carrying one passed this validator and drew
     * a rule violation from ZATCA; and it rejected VATEX-SA-29 and
     * VATEX-SA-MLTRY, which are valid, so a legitimate exemption could not be
     * filed at all.
     *
     * The suffixed variants — VATEX-SA-29F, -33E, -34-4S and the rest — belong
     * to a second list in the same rule, for exceptions rather than
     * exemptions. They are deliberately not here until something needs them.
     */
    private const VALID_EXEMPTION_CODES = [
        // Financial services and insurance
        'VATEX-SA-29',     // Financial services
        'VATEX-SA-29-7',   // Life insurance
        'VATEX-SA-30',     // International transport of goods and passengers
        'VATEX-SA-32',     // Supplies within customs suspension
        'VATEX-SA-33',     // Supplies of qualifying metals
        'VATEX-SA-34-1',   // Medicines and medical equipment
        'VATEX-SA-34-2',   // Qualifying means of transport
        'VATEX-SA-34-3',   // Exported goods
        'VATEX-SA-34-4',   // Exported services
        'VATEX-SA-34-5',   // Services to non-GCC residents
        'VATEX-SA-35',     // Real estate
        'VATEX-SA-36',     // Local passenger transport

        // Out of scope
        'VATEX-SA-OOS',    // Out of scope

        // Zero-rated
        'VATEX-SA-HEA',    // Private healthcare to citizens
        'VATEX-SA-EDU',    // Private education to citizens
        'VATEX-SA-MLTRY',  // Supplies to the military
    ];

    /**
     * BT-46-1, the registers a buyer identifier can come from.
     *
     * From the same Schematron that carries the exemption codes. NAT is the
     * one BR-KSA-49 insists on for healthcare and education supplies.
     */
    private const VALID_BUYER_ID_SCHEMES = [
        'TIN',   // Tax identification number
        'CRN',   // Commercial registration number
        'MOM',   // MOMRAH licence
        'MLS',   // MHRSD licence
        '700',   // 700 number
        'SAG',   // MISA licence
        'NAT',   // National ID
        'GCC',   // GCC identifier
        'IQA',   // Iqama
        'PAS',   // Passport
        'OTH',   // Other
    ];

    /**
     * Valid UN/ECE Rec 20 unit codes.
     */
    private const VALID_UNIT_CODES = [
        'PCE',  // Piece
        'EA',   // Each
        'KGM',  // Kilogram
        'GRM',  // Gram
        'MTR',  // Metre
        'CMT',  // Centimetre
        'LTR',  // Litre
        'MLT',  // Millilitre
        'MTK',  // Square metre
        'MTQ',  // Cubic metre
        'HUR',  // Hour
        'DAY',  // Day
        'MON',  // Month
        'ANN',  // Year
        'SET',  // Set
        'BX',   // Box
        'PK',   // Pack
        'CT',   // Carton
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isB2B = fn () => $this->input('type') === 'standard';

        return [
            // Invoice header
            'invoice_number' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(InvoiceType::class)],
            // Proforma (document_type=325) is not valid for ZATCA VAT reporting
            'document_type' => ['nullable', Rule::enum(DocumentType::class), Rule::notIn(['proforma'])],
            'issue_date' => ['required', 'date'],
            'supply_date' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
            // BR-KSA-CU-01: VAT is reported in SAR, so a foreign-currency
            // invoice has to say what rate it was converted at. InvoiceValidator
            // enforces this at compliance, so it has to be accepted here —
            // otherwise a non-SAR invoice is taken and then refused for a field
            // the caller had no way to send.
            'exchange_rate' => [
                'nullable',
                'numeric',
                'gt:0',
                Rule::requiredIf(fn () => strtoupper((string) $this->input('currency', 'SAR')) !== 'SAR'),
            ],
            'payment_means_code' => ['nullable', 'string', 'size:2'],

            // Buyer information
            'buyer_name' => ['required', 'string', 'max:255'],
            'buyer_vat_number' => [
                'nullable',
                'string',
                'max:50',
                // BT-46: Required for B2B (Standard) invoices
                Rule::requiredIf($isB2B),
            ],
            // BT-46 / BT-46-1: the buyer's identifier and the register it came
            // from. Required together — an identifier without its scheme says
            // nothing about which register to look it up in. ZATCA rejects an
            // identifier containing spaces, so the pattern excludes them
            // rather than trimming and hoping.
            'buyer_id' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9]+$/', 'required_with:buyer_id_scheme'],
            'buyer_id_scheme' => [
                'nullable',
                Rule::in(self::VALID_BUYER_ID_SCHEMES),
                'required_with:buyer_id',
            ],

            // BT-50/52/53: Buyer address required for B2B (Standard) invoices
            'buyer_address' => ['nullable', 'array', Rule::requiredIf($isB2B)],
            'buyer_address.street' => ['nullable', 'string', 'max:255', Rule::requiredIf($isB2B)],
            'buyer_address.city' => ['nullable', 'string', 'max:100', Rule::requiredIf($isB2B)],
            'buyer_address.district' => ['nullable', 'string', 'max:100'],
            'buyer_address.building_number' => ['nullable', 'string', 'max:20', Rule::requiredIf($isB2B)],
            'buyer_address.postal_code' => ['nullable', 'string', 'max:10'],
            // BT-55: Must be valid ISO 3166-1 alpha-2 uppercase code (e.g. SA, AE, GB)
            'buyer_address.country_code' => ['nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],

            // Credit/debit note references
            'billing_ref' => [
                'nullable',
                'string',
                'max:255',
                // Required for credit/debit notes
                Rule::requiredIf(fn () => in_array($this->document_type, ['credit_note', 'debit_note'])),
            ],
            'adjustment_reason' => ['nullable', 'string', 'max:255'],

            // Discount
            'discount_amount' => ['nullable', 'numeric', 'min:0'],

            // Notes
            'notes' => ['nullable', 'string'],

            // Invoice lines
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.class_code' => ['nullable', 'string', 'max:50'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'lines.*.unit_code' => ['nullable', 'string', Rule::in(self::VALID_UNIT_CODES)],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.tax_category' => ['nullable', Rule::enum(TaxCategory::class)],
            'lines.*.exempt_code' => [
                'nullable',
                'string',
                Rule::in(self::VALID_EXEMPTION_CODES),
            ],
            'lines.*.exempt_reason' => [
                'nullable',
                'string',
                'max:255',
                // Required when exemption code is provided
                'required_with:lines.*.exempt_code',
            ],
        ];
    }

    /**
     * Configure the validator instance with cross-field validation.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Per-line: exemption code required when tax category is Z, E, or O
            // Note: proforma rejection is handled by Rule::notIn(['proforma']) in rules()
            foreach ($this->input('lines', []) as $index => $line) {
                $category = $line['tax_category'] ?? null;
                $exemptionCode = $line['exempt_code'] ?? null;

                if (in_array($category, ['Z', 'E', 'O'], true) && empty($exemptionCode)) {
                    $validator->errors()->add(
                        "lines.{$index}.exempt_code",
                        'Exemption code is required for line '.($index + 1)." with tax category {$category}."
                    );
                }
            }
        });
    }

    /**
     * Custom validation messages.
     */
    public function messages(): array
    {
        return [
            // Buyer
            'exchange_rate.required' => 'Exchange rate to SAR is required for foreign-currency invoices (BR-KSA-CU-01).',
            'buyer_vat_number.required_if' => 'Buyer VAT number is required for B2B (standard) invoices (BT-46).',
            'buyer_address.required_if' => 'Buyer address is required for B2B (standard) invoices (BT-50).',
            'buyer_address.street.required_if' => 'Buyer street is required for B2B (standard) invoices.',
            'buyer_address.city.required_if' => 'Buyer city is required for B2B (standard) invoices.',
            'buyer_address.building_number.required_if' => 'Buyer building number is required for B2B (standard) invoices.',
            'buyer_address.country_code.regex' => 'Country code must be a valid ISO 3166-1 alpha-2 code in uppercase (e.g. SA, AE, GB).',
            // Document type
            'document_type.not_in' => 'Proforma invoices (document_type=proforma) cannot be submitted to ZATCA.',
            // Credit/debit note references
            'billing_ref.required_if' => 'Original invoice reference is required for credit/debit notes.',
            // Tax exemption
            'lines.*.exempt_code.in' => 'Invalid ZATCA exemption code. Must be a valid VATEX-SA-* code.',
            'lines.*.exempt_reason.required_with' => 'Exemption reason is required when exemption code is provided.',
            // Units
            'lines.*.unit_code.in' => 'Invalid unit code. Must be a valid UN/ECE Rec 20 code.',
        ];
    }

    /**
     * Prepare data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Set defaults
        $this->merge([
            'document_type' => $this->document_type ?? 'invoice',
            'currency' => $this->currency ?? 'SAR',
            'payment_means_code' => $this->payment_means_code ?? '10',
        ]);

        // Fill in what the caller left out, without contradicting what they
        // said. A line carrying an exemption code is not standard-rated, so
        // defaulting every line to S at 15% produced documents that claimed
        // both — which ZATCA rejects rather than reconciles. The code decides
        // the category, and an exempt line is not taxed.
        if ($this->has('lines')) {
            $lines = collect($this->lines)->map(function ($line) {
                $exemptionCode = $line['exempt_code'] ?? null;

                $category = $line['tax_category']
                    ?? FatooraConfig::taxCategoryFor($exemptionCode, (float) ($line['tax_rate'] ?? 15));

                return array_merge([
                    'unit_code' => 'PCE',
                    'tax_category' => $category,
                    'tax_rate' => $category === FatooraConfig::TAX_CATEGORY_STANDARD ? 15 : 0,
                ], $line);
            })->toArray();

            $this->merge(['lines' => $lines]);
        }
    }
}
