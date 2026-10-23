<x-filament-panels::page>
    <div class="bsi-erp space-y-5">
        @php
            $canCreate = \App\Services\Access\PermissionMatrix::can(auth()->user(), \App\Enums\PanelModule::EMPLOYEE_ADVANCES, 'create');
            $canEdit = \App\Services\Access\PermissionMatrix::can(auth()->user(), \App\Enums\PanelModule::EMPLOYEE_ADVANCES, 'edit');
            $reconciliation = $this->reconciliation();
            $selected = $this->selectedLoan();
            $loans = $this->loans();
        @endphp
        <section class="rounded-xl border p-4">
            <h2 class="text-lg font-semibold">Cocokkan saldo kasbon</h2>
            <label>Tanggal <input type="date" wire:model.live="asOfDate" class="rounded border p-2"></label>
            <p>Saldo pinjaman: Rp {{ number_format($reconciliation['loans_minor'], 0, ',', '.') }} · Buku besar 1-1500: Rp {{ number_format($reconciliation['ledger_minor'], 0, ',', '.') }}</p>
            <p>Selisih: Rp {{ number_format($reconciliation['difference_minor'], 0, ',', '.') }}</p>
            @if ($reconciliation['difference_minor'] !== 0)
                <p class="bsi-cms-danger">Saldo belum cocok. Cek mutasi kasbon dan jurnal akun 1-1500 ya.</p>
            @endif
        </section>
        <section class="rounded-xl border p-4">
            <h2 class="text-lg font-semibold">Saldo per karyawan</h2>
            <div class="overflow-x-auto"><table class="w-full text-left"><thead><tr><th>Karyawan</th><th>Saldo</th></tr></thead><tbody>
                @forelse ($this->employeeBalances() as $row)
                    <tr><td>{{ $row->name }}</td><td>Rp {{ number_format($row->balance_minor, 0, ',', '.') }}</td></tr>
                @empty
                    <tr><td colspan="2">Belum ada saldo kasbon.</td></tr>
                @endforelse
            </tbody></table></div>
        </section>
        @if ($canCreate)
            <section class="rounded-xl border p-4">
                <h2 class="text-lg font-semibold">Catat pinjaman</h2>
                <form wire:submit="createLoan">
                    {{ $this->loanForm }}
                    <x-filament::button type="submit">Simpan kasbon</x-filament::button>
                </form>
            </section>
        @endif
        @if ($canEdit)
            <section class="rounded-xl border p-4">
                <h2 class="text-lg font-semibold">Tambah jenis kasbon</h2>
                <form wire:submit="createType">{{ $this->typeForm }}<x-filament::button type="submit">Tambah jenis</x-filament::button></form>
            </section>
        @endif
        <section class="rounded-xl border p-4">
            <h2 class="text-lg font-semibold">Daftar pinjaman</h2>
            <div class="overflow-x-auto"><table class="w-full text-left"><thead><tr><th>Karyawan</th><th>Jenis</th><th>Tanggal</th><th>Pokok</th><th>Saldo</th><th>Status</th><th></th></tr></thead><tbody>
                @forelse ($loans as $advance)
                    <tr>
                        <td>{{ $advance->user->name }}</td><td>{{ $advance->type->name }}</td><td>{{ $advance->start_date->format('d M Y') }}</td>
                        <td>Rp {{ number_format($advance->principal_minor, 0, ',', '.') }}</td>
                        <td>Rp {{ number_format($advance->balance_minor ?? 0, 0, ',', '.') }}</td>
                        <td>{{ match ($advance->status) { 'ACTIVE' => 'Aktif', 'PAID' => 'Lunas', 'CANCELLED' => 'Dibatalkan', default => $advance->status } }}</td>
                        <td><button type="button" wire:click="selectAdvance({{ $advance->id }})" class="rounded border p-2">Kartu mutasi</button></td>
                    </tr>
                @empty
                    <tr><td colspan="7">Belum ada pinjaman.</td></tr>
                @endforelse
            </tbody></table></div>
            {{ $loans->links() }}
        </section>
        @if ($selected)
            <section class="rounded-xl border p-4">
                <h2 class="text-lg font-semibold">Kartu mutasi · {{ $selected->user->name }}</h2>
                <p>Alasan: {{ $selected->reason }} · Saldo: Rp {{ number_format($selected->balanceMinor(), 0, ',', '.') }}</p>
                <div class="overflow-x-auto"><table class="w-full text-left"><thead><tr><th>Tanggal</th><th>Jenis</th><th>Perubahan</th><th>Catatan</th><th></th></tr></thead><tbody>
                    @foreach ($this->selectedMovements() as $movement)
                        <tr><td>{{ $movement->entry_date->format('d M Y') }}</td><td>{{ match ($movement->kind) { 'OPENING' => 'Saldo awal', 'DISBURSEMENT' => 'Pencairan', 'CASH_REPAYMENT' => 'Cicilan tunai', 'REPAYMENT_REVERSAL' => 'Pembatalan cicilan', 'PAYROLL_DEDUCTION' => 'Potongan payroll', 'PAYROLL_REVERSAL' => 'Pembatalan payroll', 'ADJUSTMENT' => 'Penyesuaian', 'CANCELLATION' => 'Pembatalan', default => $movement->kind } }}</td><td>Rp {{ number_format($movement->amount_minor, 0, ',', '.') }}</td><td>{{ $movement->reason }}</td><td>@if ($canEdit && $this->canReverseRepayment($movement)) <button type="button" wire:click="reverseRepayment({{ $movement->id }})" wire:confirm="Balik cicilan dan jurnal kasnya?" class="rounded border p-2">Balik cicilan</button> @endif</td></tr>
                    @endforeach
                </tbody></table></div>
                @if ($selected->status === 'ACTIVE' && $canCreate)
                    <h3 class="font-semibold">Cicilan tunai</h3>
                    <form wire:submit="repay">{{ $this->paymentForm }}<x-filament::button type="submit">Catat cicilan</x-filament::button></form>
                @endif
                @if ($selected->status !== 'CANCELLED' && $canEdit)
                    <label>Alasan pembatalan cicilan <input type="text" wire:model="repaymentCorrectionReason" class="rounded border p-2"></label>
                    <h3 class="font-semibold">Penyesuaian saldo</h3>
                    <form wire:submit="adjust">{{ $this->adjustmentForm }}<x-filament::button type="submit">Catat penyesuaian</x-filament::button></form>
                    @if ($selected->movements()->count() === 1)
                        <label>Alasan pembatalan <input type="text" wire:model="cancelReason" class="rounded border p-2"></label>
                        <button type="button" wire:click="cancelAdvance" wire:confirm="Batalkan kasbon dan balik jurnalnya?" class="rounded border p-2">Batalkan kasbon</button>
                    @endif
                @endif
            </section>
        @endif
        @foreach (['loan', 'payment', 'adjustment', 'newType', 'amount_minor', 'entry_date', 'cash_account_id', 'reason', 'advance'] as $key)
            @error($key) <p class="bsi-cms-danger">{{ $message }}</p> @enderror
        @endforeach
    </div>
</x-filament-panels::page>
