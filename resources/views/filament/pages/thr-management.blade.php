<x-filament-panels::page>
    <div class="bsi-erp space-y-5">
        <section class="rounded-xl border p-4">
            <h2 class="text-lg font-semibold">Aturan THR</h2>
            @if ($this->latestSetting())
                <p>Versi berlaku sejak {{ $this->latestSetting()->effective_from->format('d-m-Y') }}. Minimal {{ $this->latestSetting()->minimum_months }} bulan, penuh mulai {{ $this->latestSetting()->full_months }} bulan, pro-rata dibagi {{ $this->latestSetting()->prorata_denominator }}. Pengingat {{ $this->latestSetting()->reminder_days }} hari; cadangan {{ $this->latestSetting()->reserve_enabled ? 'aktif' : 'nonaktif' }}.</p>
            @else
                <p>Aturan awal: minimal 1 bulan, penuh setelah 12 bulan, pro-rata bulan kerja ÷ 12, pengingat 7 hari, cadangan nonaktif.</p>
            @endif
            <form wire:submit="saveSetting">{{ $this->settingForm }}<x-filament::button type="submit">Simpan versi baru</x-filament::button></form>
        </section>
        <section class="rounded-xl border p-4">
            <h2 class="text-lg font-semibold">Cadangan bulanan</h2>
            <input type="month" wire:model="provisionMonth" class="rounded border p-2">
            <x-filament::button type="button" wire:click="postProvision" wire:confirm="Catat cadangan THR bulan ini?">Catat cadangan</x-filament::button>
        </section>
        <section class="rounded-xl border p-4">
            <form wire:submit="createRun">{{ $this->runForm }}<x-filament::button type="submit">Buat dan hitung THR</x-filament::button></form>
            <p class="text-sm">Tanggal pengingat hanya info, jadi run tetap bisa dicatat setelah tenggat.</p>
        </section>
        <section class="rounded-xl border p-4">
            <h2 class="text-lg font-semibold">Riwayat run THR</h2>
            @foreach ($this->runs() as $run)
                <button type="button" wire:click="selectRun({{ $run->id }})" class="mr-2 rounded border p-2">{{ $run->holiday_date->format('d-m-Y') }} · {{ $run->status }}</button>
            @endforeach
        </section>
        @if ($selectedRunId && ($run = $this->selectedRun()))
            <section class="rounded-xl border p-4">
                <h2 class="text-lg font-semibold">THR {{ $run->holiday_date->format('d-m-Y') }} · {{ $run->status }}</h2>
                @php($reminder = $this->paymentReminder($run))
                <p>Target bayar: {{ \Illuminate\Support\Carbon::parse($reminder['due_date'])->format('d-m-Y') }}.</p>
                @if ($reminder['due'] && $run->items->sum(fn ($item) => $item->snapshot['net_minor']) > 0)
                    <p>Ingat bayar THR ya. Targetnya sudah dekat atau lewat.</p>
                @endif
                @if ($run->status === 'DRAFT')
                    <x-filament::button type="button" wire:click="approve" wire:confirm="Approve dan posting akrual THR ini?">Approve</x-filament::button>
                @elseif ($run->status === 'APPROVED' && $run->items->sum(fn ($item) => $item->snapshot['net_minor']) > 0)
                    <label>Tanggal bayar <input type="date" wire:model="paymentDate" class="rounded border p-2"></label>
                    <label>Akun kas/bank <select wire:model="paymentAccountId" class="rounded border p-2"><option value="">Pilih akun</option>@foreach ($this->paymentAccounts() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
                    <x-filament::button type="button" wire:click="pay" wire:confirm="Pembayaran THR sudah dilakukan?">Catat pembayaran</x-filament::button>
                @endif
                @if (in_array($run->status, ['APPROVED', 'PAID']))
                    <x-filament::button type="button" wire:click="retrySlips">Cek/ulang slip PDF</x-filament::button>
                @endif
                <div class="overflow-x-auto"><table class="bsi-grid"><thead><tr><th>Karyawan</th><th>Masa kerja</th><th>Dasar</th><th>THR</th><th>PPh 21</th><th>Dibayar</th><th>Slip</th></tr></thead><tbody>
                    @foreach ($run->items as $item)
                        @php($s = $item->snapshot)
                        <tr><td>{{ $item->user->name }}</td><td>{{ $s['months'] }} bulan</td><td>Rp {{ number_format($s['base_minor'], 0, ',', '.') }}</td><td>Rp {{ number_format($s['gross_minor'], 0, ',', '.') }}</td><td>Rp {{ number_format($s['pph21_minor'], 0, ',', '.') }}</td><td>Rp {{ number_format($s['net_minor'], 0, ',', '.') }}</td><td>@if ($item->slip_path)<a href="{{ route('admin.thr-slips.download', $item) }}" class="underline">Unduh PDF</a>@endif</td></tr>
                    @endforeach
                </tbody></table></div>
            </section>
        @endif
        @foreach (['effective_from', 'component_type_ids', 'minimum_months', 'full_months', 'prorata_denominator', 'reminder_days', 'reason', 'holiday_date', 'planned_payment_date', 'user_ids', 'month', 'run', 'date', 'account_id', 'entry_date', 'slip'] as $field)
            @error($field)<p class="text-red-600">{{ $message }}</p>@enderror
        @endforeach
    </div>
</x-filament-panels::page>
