<x-filament-panels::page>
    <div class="bsi-erp space-y-5">
        <section class="rounded-xl border p-4"><form wire:submit="saveMapping">{{ $this->mappingForm }}<x-filament::button type="submit">Simpan kelompok</x-filament::button></form>
            <div class="overflow-x-auto"><table class="bsi-grid"><thead><tr><th>Akun</th><th>Kelompok</th></tr></thead><tbody>@foreach ($this->mappings() as $row)<tr><td>{{ $row->code }} · {{ $row->name }}</td><td>{{ $row->category }}</td></tr>@endforeach</tbody></table></div>
        </section>
        <section class="rounded-xl border p-4"><form wire:submit="saveBudget">{{ $this->budgetForm }}<x-filament::button type="submit">Simpan anggaran</x-filament::button></form>
            <div class="overflow-x-auto"><table class="bsi-grid"><thead><tr><th>Bulan</th><th>Akun</th><th>Coffee Bike</th><th>Anggaran</th></tr></thead><tbody>@foreach ($this->budgets() as $row)<tr><td>{{ substr($row->month, 0, 7) }}</td><td>{{ $row->code }} · {{ $row->name }}</td><td>{{ $row->cart_code ?? 'Semua' }}</td><td>Rp {{ number_format($row->amount_minor, 0, ',', '.') }}</td></tr>@endforeach</tbody></table></div>
        </section>
        <section class="rounded-xl border p-4"><form wire:submit="saveTarget">{{ $this->targetForm }}<x-filament::button type="submit">Simpan target</x-filament::button></form>
            <div class="overflow-x-auto"><table class="bsi-grid"><thead><tr><th>Metrik</th><th>Target tersimpan</th></tr></thead><tbody>@foreach ($this->targets() as $row)<tr><td>{{ \App\Services\FinancialReportSettingsService::KPI_METRICS[$row->metric] ?? $row->metric }}</td><td>{{ $row->target_value }}</td></tr>@endforeach</tbody></table></div>
        </section>
        @foreach (['accountId', 'account_id', 'category', 'month', 'cart_id', 'amount_minor', 'target'] as $field)
            @error($field)<p class="text-red-600">{{ $message }}</p>@enderror
        @endforeach
    </div>
</x-filament-panels::page>
