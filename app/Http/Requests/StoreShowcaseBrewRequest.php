<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `POST /showcase/brew` — a barista recording cups they have just brewed into the showcase.
 *
 * No cart and no rider here on purpose: brewing puts cups into central stock and says nothing
 * about where they will go. Handing them out is a separate act (StoreShowcaseHandoverRequest),
 * because in the kitchen they genuinely are two separate moments.
 */
class StoreShowcaseBrewRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Role gate lives in ShowcaseStockController::middleware() (role:BARISTA), which runs
        // before this.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('is_active', true)->where('is_sellable', true)],
            // Brew adds kitchen stock; the 100-cup Rider refill-request limit does not apply.
            'lines.*.qty' => ['required', 'integer', 'min:1', 'max:9999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => 'Pilih minimal satu produk',
            'lines.min' => 'Pilih minimal satu produk',
            'lines.*.qty.min' => 'Jumlah cups harus lebih dari 0',
            'lines.*.qty.max' => 'Jumlah cups per produk maksimal 9.999',
            'lines.*.product_id.exists' => 'Produk ini bukan produk utama',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $totals = [];
            foreach ($this->input('lines', []) as $index => $line) {
                $productId = (int) $line['product_id'];
                $totals[$productId] = ($totals[$productId] ?? 0) + (int) $line['qty'];
                if ($totals[$productId] > 9999) {
                    $validator->errors()->add("lines.{$index}.qty", 'Jumlah cups per produk maksimal 9.999');
                }
            }
        });
    }

    /**
     * The service takes product_id => qty; the wire format is a list of objects because that is
     * what the other endpoints in this API use and consistency beats brevity here.
     *
     * @return array<int,int>
     */
    public function quantities(): array
    {
        $rows = [];

        foreach ($this->validated('lines') as $line) {
            // Summed rather than overwritten: a client that sends the same product twice means
            // "this many in total", and silently keeping only the last one would lose cups.
            $rows[(int) $line['product_id']] = ($rows[(int) $line['product_id']] ?? 0) + (int) $line['qty'];
        }

        return $rows;
    }
}
