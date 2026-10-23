<div class="bsi-note">
    <p><strong>{{ $request->code }}</strong> · {{ $request->staff?->name }} · Coffee Bike {{ $request->cart?->code }}</p>
    <p>Status: {{ $request->status->label() }}</p>
    <p>Catatan lapangan: {{ $request->note ?: '-' }}</p>
    <div>
        <strong>Produk</strong>
        @foreach ($request->lines as $line)
            <p>{{ $line->product?->name ?? 'Produk lama' }} · diminta {{ $line->qty_requested }} · disiapkan {{ $line->qty_prepared ?? 0 }}</p>
        @endforeach
    </div>
</div>
