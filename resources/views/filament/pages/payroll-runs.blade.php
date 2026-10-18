<x-filament-panels::page>
    <section class="rounded-xl border p-4">
        <h2 class="text-lg font-semibold">Buat run payroll</h2>
        <form wire:submit="createRun" class="flex gap-3">
            <input type="month" wire:model="newPeriod" class="rounded border p-2">
            <button type="submit" class="rounded bg-primary-600 px-4 py-2 text-white">Buat dan hitung</button>
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
                    <label>Tanggal bayar <input type="date" wire:model="paymentDate" class="rounded border p-2"></label>
                    <label>Akun bayar <select wire:model="paymentAccountId" class="rounded border p-2"><option value="">Pilih Kas atau Bank</option>
                        @foreach ($this->paymentAccounts() as $account)<option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>@endforeach
                    </select></label>
                    <button type="button" wire:click="markPaid" wire:confirm="Pembayaran sudah dilakukan?" class="rounded bg-primary-600 p-2 text-white">Tandai Paid</button>
                </div>
            @endif
            @if ($this->selectedStatus() === 'CANCELLED')
                <button type="button" wire:click="reopenRun" wire:confirm="Buka lagi run ini untuk dihitung ulang?" class="rounded border p-2">Buka lagi untuk koreksi</button>
            @endif
            @if (in_array($this->selectedStatus(), ['APPROVED', 'PAID']))
                <div class="mt-3 flex flex-wrap gap-3">
                    <label>Alasan pembatalan <input type="text" wire:model="cancellationReason" maxlength="225" class="rounded border p-2"></label>
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
            <div class="overflow-x-auto"><table class="w-full text-sm">
                <thead><tr><th>Karyawan</th><th>Bruto</th><th>BPJS pegawai</th><th>PPh 21</th><th>Take-home pay</th><th>Komponen</th><th>Slip</th></tr></thead>
                <tbody>
                @foreach ($this->selectedItems() as $item)
                    @php($s = $item->snapshot)
                    <tr class="border-t"><td>{{ $item->user?->name }}</td><td>{{ number_format($s['gross_pay_minor'], 0, ',', '.') }}</td>
                        <td>{{ number_format($s['bpjs_employee_total_minor'], 0, ',', '.') }}</td>
                        <td>{{ number_format($s['pph21_minor'], 0, ',', '.') }}</td>
                        <td>{{ number_format($s['take_home_minor'], 0, ',', '.') }}</td>
                        <td>@foreach ($s['components'] as $component)<div>{{ $component['name'] }}: {{ number_format($component['amount_minor'], 0, ',', '.') }}</div>@endforeach</td>
                        <td>@if ($item->slip_path)<a href="{{ route('admin.payroll-slips.download', $item) }}" class="underline">Unduh PDF</a>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        </section>
    @endif
</x-filament-panels::page>
