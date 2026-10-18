<x-filament-panels::page>
    <div class="bsi-erp">
    <section>
        <form wire:submit="createRun">
            {{ $this->createRunForm }}
            <x-filament::button type="submit">Buat dan hitung</x-filament::button>
        </form>
        @error('newPeriod') <p class="text-red-600">{{ $message }}</p> @enderror
        @error('period') <p class="text-red-600">{{ $message }}</p> @enderror
    </section>
    <section class="rounded-xl border p-4">
        <h2 class="text-lg font-semibold">Riwayat run</h2>
        @foreach ($this->runs() as $run)
            <button type="button" wire:click="selectRun({{ $run->id }})" class="mr-3 rounded border p-2">
                {{ $run->period_start->format('Y-m') }} · {{ $this->statusLabel($run->status) }}
            </button>
        @endforeach
    </section>
    @if ($selectedRunId)
        <section class="rounded-xl border p-4">
            <h2 class="text-lg font-semibold">Review payroll · {{ $this->statusLabel($this->selectedStatus()) }}</h2>
            @if ($this->selectedStatus() === 'DRAFT')
                <p>Tambah atau ubah komponen manual di <a href="{{ \App\Filament\Pages\PayrollConfiguration::getUrl() }}" class="underline">Konfigurasi Payroll</a>, lalu hitung ulang sebelum Approve.</p>
                <button type="button" wire:click="recalculate" class="rounded border p-2">Hitung ulang</button>
                <button type="button" wire:click="approve" wire:confirm="Approve dan kunci payroll ini?" class="rounded bg-primary-600 p-2 text-white">Approve</button>
            @elseif ($this->selectedStatus() === 'APPROVED')
                <div class="flex flex-wrap gap-3">
                    {{ $this->paymentForm }}
                    <x-filament::button type="button" wire:click="markPaid" wire:confirm="Pembayaran sudah dilakukan?">Tandai Paid</x-filament::button>
                </div>
            @endif
            @if ($this->selectedStatus() === 'CANCELLED')
                <button type="button" wire:click="reopenRun" wire:confirm="Buka lagi run ini untuk dihitung ulang?" class="rounded border p-2">Buka lagi untuk koreksi</button>
            @endif
            @if (in_array($this->selectedStatus(), ['APPROVED', 'PAID']))
                <div class="mt-3 flex flex-wrap gap-3">
                    {{ $this->cancellationForm }}
                    @if ($this->selectedStatus() === 'PAID')
                        <button type="button" wire:click="cancelPayment" wire:confirm="Batalkan pembayaran dan balik jurnalnya?" class="rounded border p-2">Batalkan pembayaran</button>
                    @else
                        <button type="button" wire:click="cancelRun" wire:confirm="Batalkan run dan balik jurnal akrualnya?" class="rounded border p-2">Batalkan run</button>
                    @endif
                </div>
                @error('cancellationReason') <p class="text-red-600">{{ $message }}</p> @enderror
            @endif
            @if (in_array($this->selectedStatus(), ['APPROVED', 'PAID', 'CANCELLED']))
                <button type="button" wire:click="retrySlips" class="rounded border p-2">Cek/ulang slip PDF</button>
            @endif
            <button type="button" wire:click="export" class="rounded border p-2">Unduh rekap Excel</button>
            @foreach (['run', 'payment', 'paymentDate', 'paymentAccountId', 'account_id', 'entry_date', 'slip', 'journal', 'reason'] as $key)
                @error($key) <p class="text-red-600">{{ $message }}</p> @enderror
            @endforeach
            <div class="overflow-x-auto">{{ $this->table }}</div>
        </section>
    @endif
    </div>
</x-filament-panels::page>
