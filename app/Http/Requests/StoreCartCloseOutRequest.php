<?php

namespace App\Http\Requests;

use App\Models\RiderClosing;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `POST /showcase/close-out` — end of day, sorting what came back off a cart.
 *
 * Two buckets because they mean genuinely different things to the day's numbers: cups going back
 * into the showcase will be sold tomorrow, cups marked reject are gone. Both leave the cart, so
 * both have to be recorded, and the difference between them is the whole reason this endpoint
 * exists rather than a single "sisa" number.
 */
class StoreCartCloseOutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cart_id' => ['required', 'integer', Rule::exists('carts', 'id')],

            // Both optional individually — a day with nothing rejected is the good day, and a
            // day where everything left is rejected is a bad one, and both must be expressible.
            // A zero physical count is valid after Rider Closing and is flagged against it.
            'returned' => ['nullable', 'array'],
            'returned.*.product_id' => ['required_with:returned', 'integer', Rule::exists('products', 'id')->where('is_active', true)->where('is_sellable', true)],
            'returned.*.qty' => ['required_with:returned', 'integer', 'min:1'],

            'rejected' => ['nullable', 'array'],
            'rejected.*.product_id' => ['required_with:rejected', 'integer', Rule::exists('products', 'id')->where('is_active', true)->where('is_sellable', true)],
            'rejected.*.qty' => ['required_with:rejected', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'returned.*.qty.min' => 'Jumlah sisa harus lebih dari 0',
            'rejected.*.qty.min' => 'Jumlah reject harus lebih dari 0',
            'returned.*.product_id.exists' => 'Produk ini bukan produk utama',
            'rejected.*.product_id.exists' => 'Produk ini bukan produk utama',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->quantities('returned') !== [] || $this->quantities('rejected') !== []) {
                return;
            }
            $hasClosing = RiderClosing::query()->where('cart_id', (int) $this->input('cart_id'))
                ->whereDate('operating_date', today()->toDateString())->exists();
            if (! $hasClosing) {
                $validator->errors()->add('returned', 'Isi minimal satu: cups yang masuk showcase atau cups yang reject.');
            }
        });
    }

    /**
     * @return array<int,int>
     */
    public function quantities(string $bucket): array
    {
        $rows = [];

        foreach ((array) $this->input($bucket, []) as $line) {
            if (! is_array($line) || ! isset($line['product_id'], $line['qty'])) {
                continue;
            }

            $productId = (int) $line['product_id'];
            $rows[$productId] = ($rows[$productId] ?? 0) + (int) $line['qty'];
        }

        return $rows;
    }
}
