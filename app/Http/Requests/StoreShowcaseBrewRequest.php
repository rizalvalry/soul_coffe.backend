<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /showcase/brew` — a barista recording cups they have just brewed into the showcase.
 *
 * No cart and no staff here on purpose: brewing puts cups into central stock and says nothing
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
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')],
            // R7: cups are not divisible, and the per-line ceiling is the same one Flow B uses
            // so a barista cannot route around it by brewing instead of requesting.
            'lines.*.qty' => ['required', 'integer', 'min:1', 'max:'.config('soul.max_qty_per_line', 100)],
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
            'lines.*.qty.max' => 'Jumlah cups per produk melebihi batas',
        ];
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
