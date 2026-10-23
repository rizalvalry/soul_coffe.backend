<x-filament-panels::page>
    <div class="bsi-erp space-y-5">
        <section class="rounded-xl border p-4">
            <form wire:submit="save">{{ $this->remittanceForm }}
                @php($status = $this->status())
                <div class="bsi-cms-stats">
                    <span class="bsi-cms-stat"><span>Terutang:</span><strong>Rp {{ number_format($status['accrued_minor'], 0, ',', '.') }}</strong></span>
                    <span class="bsi-cms-stat"><span>Sudah disetor:</span><strong>Rp {{ number_format($status['paid_minor'], 0, ',', '.') }}</strong></span>
                    <span class="bsi-cms-stat"><span>Selisih:</span><strong>Rp {{ number_format($status['difference_minor'], 0, ',', '.') }}</strong></span>
                </div>
                <p>Status: {{ match ($status['status']) { 'TERUTANG' => 'Terutang', 'SUDAH_DISETOR' => 'Sudah disetor', 'GABUNGAN_BELUM_RINCI' => 'Setoran gabungan belum dirinci per program', default => 'Selisih' } }}</p>
                <button type="button" wire:click="prefill" class="rounded border p-2">Isi sisa utang</button>
                <x-filament::button type="submit">Catat setoran</x-filament::button>
            </form>
            <p class="text-sm">Nominal bisa berbeda dari utang; selisihnya tetap kelihatan untuk dicek.</p>
        </section>
        <section class="rounded-xl border p-4">
            <h2 class="text-lg font-semibold">Data bukti potong</h2>
            <p>Rekap Excel ini untuk persiapan Coretax. Impor Coretax memakai XML, jadi file ini bukan template impor langsung. Bukti potong dibuat klien di Coretax.</p>
            <x-filament::button type="button" wire:click="exportMonthly">Unduh rekap masa</x-filament::button>
            <x-filament::button type="button" wire:click="exportAnnual">Unduh rekap A1 tahunan</x-filament::button>
        </section>
        <section class="rounded-xl border p-4">
            <h2 class="text-lg font-semibold">Riwayat setor</h2>
            <div class="overflow-x-auto"><table class="bsi-grid"><thead><tr><th>Masa</th><th>Jenis</th><th>Program</th><th>Tanggal</th><th class="bsi-num">Nominal</th><th>NTPN / bukti</th><th>Status</th><th></th></tr></thead><tbody>
                @foreach ($this->remittances() as $remittance)
                    <tr><td>{{ $remittance->month->format('Y-m') }}</td><td>{{ $remittance->kind }}</td><td>{{ $remittance->program ?? 'Gabungan' }}</td><td>{{ $remittance->paid_on->format('d-m-Y') }}</td><td class="bsi-num">Rp {{ number_format($remittance->amount_minor, 0, ',', '.') }}</td><td>{{ $remittance->receipt_number }}</td><td>{{ $remittance->status }}</td><td>@if ($remittance->status === 'POSTED')<button type="button" wire:click="$set('voidId', {{ $remittance->id }})" class="underline">Void</button>@endif</td></tr>
                @endforeach
            </tbody></table></div>
            @if ($voidId)
                <label>Alasan void <input type="text" wire:model="voidReason" class="rounded border p-2"></label>
                <x-filament::button type="button" wire:click="void" wire:confirm="Void setoran dan balik jurnalnya?">Konfirmasi void</x-filament::button>
            @endif
        </section>
        @foreach (['month', 'kind', 'program', 'paid_on', 'amount_minor', 'receipt_number', 'account_id', 'idempotency_key', 'entry_date', 'reason'] as $field)
            @error($field)<p class="bsi-cms-danger">{{ $message }}</p>@enderror
        @endforeach
    </div>
</x-filament-panels::page>
