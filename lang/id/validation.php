<?php

// Cover every framework validation rule, including new ones added by a Laravel update.
// Common rules below keep the reason specific; uncommon rules still return clear Indonesian.
$messages = require __DIR__.'/../../vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php';
array_walk_recursive($messages, static function (string &$message): void {
    $message = 'Isi :attribute belum sesuai ya.';
});

return array_replace_recursive($messages, [
    'accepted' => 'Setujui :attribute dulu ya.',
    'after' => ':attribute harus setelah :date ya.',
    'after_or_equal' => ':attribute harus pada atau setelah :date ya.',
    'array' => 'Format :attribute belum sesuai ya.',
    'before' => ':attribute harus sebelum :date ya.',
    'before_or_equal' => ':attribute harus pada atau sebelum :date ya.',
    'between' => [
        'array' => ':attribute perlu :min sampai :max item ya.',
        'file' => 'Ukuran :attribute harus :min sampai :max KB ya.',
        'numeric' => ':attribute harus antara :min dan :max ya.',
        'string' => ':attribute perlu :min sampai :max karakter ya.',
    ],
    'boolean' => ':attribute harus ya atau tidak ya.',
    'confirmed' => 'Konfirmasi :attribute belum sama ya.',
    'date' => 'Tanggal :attribute belum valid ya.',
    'date_format' => 'Format :attribute harus :format ya.',
    'distinct' => ':attribute tidak boleh berulang ya.',
    'email' => 'Email :attribute belum valid ya.',
    'exists' => ':attribute tidak ditemukan ya.',
    'file' => ':attribute harus berupa file ya.',
    'image' => ':attribute harus berupa gambar ya.',
    'in' => 'Pilihan :attribute belum valid ya.',
    'integer' => ':attribute harus angka bulat ya.',
    'max' => [
        'array' => ':attribute maksimal :max item ya.',
        'file' => 'Ukuran :attribute maksimal :max KB ya.',
        'numeric' => ':attribute maksimal :max ya.',
        'string' => ':attribute maksimal :max karakter ya.',
    ],
    'mimes' => 'Format file :attribute harus :values ya.',
    'min' => [
        'array' => ':attribute perlu minimal :min item ya.',
        'file' => 'Ukuran :attribute minimal :min KB ya.',
        'numeric' => ':attribute minimal :min ya.',
        'string' => ':attribute perlu minimal :min karakter ya.',
    ],
    'numeric' => ':attribute harus angka ya.',
    'regex' => 'Format :attribute belum sesuai ya.',
    'required' => 'Isi :attribute dulu ya.',
    'required_if' => 'Isi :attribute saat :other bernilai :value ya.',
    'same' => ':attribute harus sama dengan :other ya.',
    'string' => ':attribute harus berupa teks ya.',
    'unique' => ':attribute sudah dipakai ya.',
    'uuid' => 'ID :attribute belum valid ya.',
    'attributes' => [
        'uuid' => 'ID transaksi',
        'staff_id' => 'Rider tujuan',
        'cart_id' => 'gerobak',
        'product_id' => 'produk',
        'operating_date' => 'tanggal operasional',
        'payment_method' => 'metode pembayaran',
    ],
]);
