<x-filament-panels::page>
    <div class="bsi-erp space-y-5">
        @php $reconciliation = $this->reconciliation(); @endphp
        <section class="rounded-xl border p-4">
            <h2 class="text-lg font-semibold">Cocokkan saldo insentif</h2>
            <label>Tanggal <input type="date" wire:model.live="asOfDate" class="rounded border p-2"></label>
            <p>Saldo Rider: Rp {{ number_format($reconciliation['balances_minor'], 0, ',', '.') }} · Buku besar 2-1800: Rp {{ number_format($reconciliation['ledger_minor'], 0, ',', '.') }}</p>
            <p>Selisih: Rp {{ number_format($reconciliation['difference_minor'], 0, ',', '.') }}</p>
        </section>
        <section class="rounded-xl border p-4">
            <h2 class="text-lg font-semibold">Saldo per Rider</h2>
            <div class="overflow-x-auto"><table class="w-full text-left"><thead><tr><th>Rider</th><th>Saldo</th><th></th></tr></thead><tbody>
                @foreach ($this->balances() as $row)
                    <tr><td>{{ $row['name'] }}</td><td>Rp {{ number_format($row['balance_minor'], 0, ',', '.') }}</td><td><button type="button" wire:click="$set('selectedRiderId', {{ $row['id'] }})">Kartu mutasi</button></td></tr>
                @endforeach
            </tbody></table></div>
        </section>
        @if (\App\Services\Access\PermissionMatrix::can(auth()->user(), \App\Enums\PanelModule::RIDER_INCENTIVE_BALANCE, 'create'))
            <section class="rounded-xl border p-4">
                <h2 class="text-lg font-semibold">Catat mutasi</h2>
                <form wire:submit="submit" class="space-y-5">
                    <select wire:model.live="entry.kind" class="rounded border p-2"><option value="WITHDRAWAL">Pengambilan tunai</option><option value="MANUAL_DEDUCTION">Potongan manual</option>@if (\App\Services\Access\PermissionMatrix::can(auth()->user(), \App\Enums\PanelModule::EMPLOYEE_ADVANCES, 'create'))<option value="ADVANCE_REPAYMENT">Cicilan kasbon</option>@endif</select>
                    <select wire:model.live="entry.rider_id" class="rounded border p-2"><option value="">Pilih Rider</option>@foreach ($this->riders() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                    <input type="date" wire:model="entry.entry_date" class="rounded border p-2">
                    <input type="number" min="1" step="1" wire:model="entry.amount_minor" placeholder="Nominal (Rp)" class="rounded border p-2">
                    @if ($entry['kind'] === 'WITHDRAWAL')
                        <select wire:model="entry.cash_account_id" class="rounded border p-2"><option value="">Pilih akun Kas/Bank</option>@foreach ($this->cashAccounts() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                    @elseif ($entry['kind'] === 'ADVANCE_REPAYMENT')
                        <select wire:model="entry.advance_id" class="rounded border p-2"><option value="">Pilih kasbon</option>@foreach ($this->advances() as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select>
                    @endif
                    <input type="text" wire:model="entry.reason" placeholder="Alasan {{ $entry['kind'] === 'MANUAL_DEDUCTION' ? '(wajib)' : '(opsional)' }}" class="rounded border p-2">
                    <x-filament::button type="submit">Simpan mutasi</x-filament::button>
                </form>
                @foreach (['request_key', 'rider_id', 'entry_date', 'amount_minor', 'reason', 'cash_account_id', 'advance_id'] as $key)
                    @error($key) <p class="bsi-cms-danger">{{ $message }}</p> @enderror
                @endforeach
            </section>
        @endif
        @if ($selectedRiderId)
            <section class="rounded-xl border p-4">
                <h2 class="text-lg font-semibold">Kartu mutasi</h2>
                <div class="overflow-x-auto"><table class="w-full text-left"><thead><tr><th>Tanggal</th><th>Jenis</th><th>Perubahan</th><th>Alasan</th><th></th></tr></thead><tbody>
                    @foreach ($this->movements() as $movement)
                        <tr><td>{{ $movement->entry_date }}</td><td>{{ match ($movement->kind) { 'ACCRUAL' => 'Akrual harian', 'WITHDRAWAL' => 'Pengambilan', 'MANUAL_DEDUCTION' => 'Potongan manual', 'ADVANCE_REPAYMENT' => 'Cicilan kasbon', 'PAYROLL_PAYMENT' => 'Dibayar di payroll', 'PAYROLL_REVERSAL' => 'Payroll dibatalkan', 'CORRECTION' => 'Koreksi', default => $movement->kind } }}</td><td>Rp {{ number_format($movement->amount_minor, 0, ',', '.') }}</td><td>{{ $movement->reason }}</td><td>@if (\App\Services\Access\PermissionMatrix::can(auth()->user(), \App\Enums\PanelModule::RIDER_INCENTIVE_BALANCE, 'edit') && ($movement->kind !== 'ADVANCE_REPAYMENT' || \App\Services\Access\PermissionMatrix::can(auth()->user(), \App\Enums\PanelModule::EMPLOYEE_ADVANCES, 'edit')) && in_array($movement->kind, ['WITHDRAWAL', 'MANUAL_DEDUCTION', 'ADVANCE_REPAYMENT'], true) && $movement->correction_id === null)<button type="button" wire:click="$set('correction.corrects_movement_id', {{ $movement->id }})">Koreksi</button>@endif</td></tr>
                    @endforeach
                </tbody></table></div>
                @if (\App\Services\Access\PermissionMatrix::can(auth()->user(), \App\Enums\PanelModule::RIDER_INCENTIVE_BALANCE, 'edit') && $correction['corrects_movement_id'])
                    <form wire:submit="correct" class="space-y-5">
                        <p>Balik mutasi #{{ $correction['corrects_movement_id'] }}. Kalau ini pengambilan, pastikan uangnya sudah kembali ya.</p>
                        <input type="date" wire:model="correction.entry_date" class="rounded border p-2">
                        <input type="text" wire:model="correction.reason" placeholder="Alasan koreksi (wajib)" class="rounded border p-2">
                        <x-filament::button type="submit">Simpan koreksi</x-filament::button>
                        @foreach (['corrects_movement_id', 'entry_date', 'reason'] as $key) @error($key) <p class="bsi-cms-danger">{{ $message }}</p> @enderror @endforeach
                    </form>
                @endif
            </section>
        @endif
    </div>
</x-filament-panels::page>
