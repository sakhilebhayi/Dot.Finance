<?php

namespace App\Services\Integrations\Emall;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The shape of a Dot.Emall sales delivery (docs/integrations/emall-sales-contract.md). Structure is enforced here:
 * a delivery with a malformed line is refused whole (422) and nothing is stored. Whether the numbers are right is a
 * different question, answered line by line by EmallSalesImport.
 */
final class EmallPayload
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{delivery_id: string, company_uuid: string, currency: string, lines: list<array<string, mixed>>}
     *
     * @throws ValidationException
     */
    public static function validate(array $data): array
    {
        $max = (int) config('integrations.emall.max_lines_per_delivery', 500);

        $validated = Validator::make($data, [
            'delivery_id' => ['required', 'string', 'min:3', 'max:100', 'regex:/^[A-Za-z0-9._:\-]+$/'],
            'company_uuid' => ['required', 'uuid'],
            'currency' => ['nullable', 'string', 'regex:/^[A-Z]{3}$/'],
            'lines' => ['required', 'array', 'min:1', 'max:'.$max],
            'lines.*.order_item_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:\-]+$/'],
            'lines.*.order_ref' => ['required', 'string', 'max:64'],
            'lines.*.seller_ref' => ['nullable', 'string', 'max:64'],
            'lines.*.sold_on' => ['required', 'date_format:Y-m-d'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'lines.*.unit_price_cents' => ['required', 'integer', 'min:0', 'max:99999999999'],
            'lines.*.discount_cents' => ['nullable', 'integer', 'min:0', 'max:99999999999'],
            'lines.*.total_cents' => ['required', 'integer', 'min:0', 'max:99999999999'],
            'lines.*.vat_cents' => ['nullable', 'integer', 'min:0', 'max:99999999999'],
            'lines.*.tax_code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Z0-9_]+$/'],
            'lines.*.fee_cents' => ['nullable', 'integer', 'min:0', 'max:99999999999'],
            'lines.*.currency' => ['nullable', 'string', 'regex:/^[A-Z]{3}$/'],
        ])->validate();

        $ids = array_column($validated['lines'], 'order_item_id');
        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['lines' => 'The same order_item_id appears twice in one delivery.']);
        }

        $validated['currency'] = $validated['currency'] ?? 'ZAR';

        return $validated;
    }
}
