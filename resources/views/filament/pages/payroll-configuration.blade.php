<x-filament-panels::page>
    <p>Tambahkan versi baru untuk setiap perubahan. Nilai BPJS, PTKP, dan TER harus dicocokkan dengan regulasi oleh akuntan sebelum payroll pertama. Semua nominal dalam rupiah bulat; tarif dalam basis poin (2% = 200).</p>
    <p>Riwayat di halaman ini menampilkan 100 versi terbaru per bagian; versi lama tetap tersimpan.</p>

    @php
        $sections = [
            ['title' => 'BPJS', 'state' => 'bpjs', 'method' => 'saveBpjs', 'table' => 'payroll_settings',
                'fields' => [
                    'effective_from' => 'Berlaku mulai', 'jht_employer_bps' => 'JHT perusahaan (bps)', 'jht_employee_bps' => 'JHT karyawan (bps)',
                    'jp_employer_bps' => 'JP perusahaan (bps)', 'jp_employee_bps' => 'JP karyawan (bps)',
                    'jkk_employer_bps' => 'JKK perusahaan (bps)', 'jkk_employee_bps' => 'JKK karyawan (bps)',
                    'jkm_employer_bps' => 'JKM perusahaan (bps)', 'jkm_employee_bps' => 'JKM karyawan (bps)',
                    'health_employer_bps' => 'Kesehatan perusahaan (bps)', 'health_employee_bps' => 'Kesehatan karyawan (bps)',
                    'jp_wage_cap_minor' => 'Batas upah JP (Rp)', 'health_wage_cap_minor' => 'Batas upah Kesehatan (Rp)',
                    'source_note' => 'Sumber regulasi / catatan'],
            ],
            ['title' => 'PTKP', 'state' => 'ptkp', 'method' => 'savePtkp', 'table' => 'ptkp_rates',
                'fields' => ['status' => 'Status', 'ter_category' => 'Kategori TER', 'annual_amount_minor' => 'PTKP tahunan (Rp)',
                    'effective_from' => 'Berlaku mulai', 'source_note' => 'Sumber regulasi / catatan'],
                'selects' => ['status' => ['TK/0' => 'TK/0', 'TK/1' => 'TK/1', 'TK/2' => 'TK/2', 'TK/3' => 'TK/3',
                    'K/0' => 'K/0', 'K/1' => 'K/1', 'K/2' => 'K/2', 'K/3' => 'K/3'],
                    'ter_category' => ['A' => 'A', 'B' => 'B', 'C' => 'C']],
            ],
            ['title' => 'TER PPh 21', 'state' => 'ter', 'method' => 'saveTer', 'table' => 'pph21_ter_brackets',
                'fields' => ['category' => 'Kategori', 'lower_gross_minor' => 'Bruto mulai (Rp)', 'upper_gross_minor' => 'Bruto sampai (Rp, kosong = tanpa batas)',
                    'rate_bps' => 'Tarif (bps)', 'effective_from' => 'Berlaku mulai', 'source_note' => 'Sumber regulasi / catatan'],
                'selects' => ['category' => ['A' => 'A', 'B' => 'B', 'C' => 'C']],
            ],
            ['title' => 'Insentif Rider', 'state' => 'incentive', 'method' => 'saveIncentive', 'table' => 'rider_incentive_rules',
                'fields' => ['effective_from' => 'Berlaku mulai', 'amount_per_cup_minor' => 'Per cup (Rp)', 'target_cups' => 'Target cup',
                    'bonus_minor' => 'Bonus target (Rp)', 'source_note' => 'Sumber / catatan'],
            ],
            ['title' => 'Jenis Komponen Manual', 'state' => 'component', 'method' => 'saveComponent', 'table' => 'payroll_component_types',
                'fields' => ['code' => 'Kode', 'name' => 'Nama', 'kind' => 'Jenis', 'taxable' => 'Kena pajak',
                    'effective_from' => 'Berlaku mulai', 'source_note' => 'Sumber / catatan'],
                'selects' => ['kind' => ['EARNING' => 'Tunjangan / biaya', 'DEDUCTION' => 'Potongan']],
            ],
        ];
    @endphp

    @foreach ($sections as $section)
        <section class="rounded-xl border p-4">
            <h2 class="text-lg font-semibold">{{ $section['title'] }}</h2>
            <form wire:submit="{{ $section['method'] }}" class="grid gap-3 md:grid-cols-3">
                @foreach ($section['fields'] as $field => $label)
                    <label class="grid gap-1">
                        <span>{{ $label }}</span>
                        @if (isset($section['selects'][$field]))
                            <select wire:model="{{ $section['state'] }}.{{ $field }}" class="rounded border p-2">
                                <option value="">Pilih</option>
                                @foreach ($section['selects'][$field] as $value => $text)
                                    <option value="{{ $value }}">{{ $text }}</option>
                                @endforeach
                            </select>
                        @elseif ($field === 'taxable')
                            <input type="checkbox" wire:model="{{ $section['state'] }}.{{ $field }}">
                        @elseif ($field === 'source_note')
                            <textarea wire:model="{{ $section['state'] }}.{{ $field }}" class="rounded border p-2"></textarea>
                        @else
                            <input type="{{ str_ends_with($field, '_from') ? 'date' : (str_contains($field, '_minor') || str_ends_with($field, '_bps') || $field === 'target_cups' ? 'number' : 'text') }}"
                                wire:model="{{ $section['state'] }}.{{ $field }}" class="rounded border p-2">
                        @endif
                        @error($section['state'].'.'.$field) <span class="text-red-600">{{ $message }}</span> @enderror
                    </label>
                @endforeach
                <div class="md:col-span-3"><button type="submit" class="rounded bg-primary-600 px-4 py-2 text-white">Tambah versi</button></div>
            </form>
            <div class="overflow-x-auto">
                <table class="mt-4 w-full text-sm">
                    <thead><tr>@foreach ($section['fields'] as $label)<th class="p-2 text-left">{{ $label }}</th>@endforeach</tr></thead>
                    <tbody>
                        @foreach ($this->rows($section['table']) as $row)
                            <tr class="border-t">
                                @foreach ($section['fields'] as $field => $label)
                                    <td class="p-2">{{ $field === 'taxable' ? ($row->$field ? 'Ya' : 'Tidak') : ($row->$field ?? '—') }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($section['state'] === 'ter')
                <p class="mt-3">TER baru dipakai kalkulator setelah satu versi kategori diterbitkan. Rentang harus dimulai dari 0, tanpa celah atau tumpang tindih, dan berakhir tanpa batas atas.</p>
                <form wire:submit="publishTer" class="mt-3 flex flex-wrap gap-3">
                    <label>Kategori
                        <select wire:model="terPublication.category" class="rounded border p-2">
                            <option value="">Pilih</option><option value="A">A</option><option value="B">B</option><option value="C">C</option>
                        </select>
                        @error('terPublication.category') <span class="text-red-600">{{ $message }}</span> @enderror
                    </label>
                    <label>Berlaku mulai
                        <input type="date" wire:model="terPublication.effective_from" class="rounded border p-2">
                        @error('terPublication.effective_from') <span class="text-red-600">{{ $message }}</span> @enderror
                    </label>
                    <button type="submit" class="rounded bg-primary-600 px-4 py-2 text-white">Terbitkan versi TER</button>
                </form>
                <table class="mt-3 w-full text-sm">
                    <thead><tr><th>Kategori</th><th>Berlaku mulai</th><th>Status</th></tr></thead>
                    <tbody>
                        @foreach ($this->terVersions() as $version)
                            <tr class="border-t"><td>{{ $version->category }}</td><td>{{ $version->effective_from }}</td>
                                <td>{{ $version->published_at ? 'Terbit' : 'Draf' }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    @endforeach

    <section class="rounded-xl border p-4">
        <h2 class="text-lg font-semibold">Komponen Karyawan per Periode</h2>
        <form wire:submit="saveEntry" class="grid gap-3 md:grid-cols-3">
            <label class="grid gap-1">Karyawan
                <select wire:model="entry.user_id" class="rounded border p-2">
                    <option value="">Pilih karyawan</option>
                    @foreach ($this->employeeOptions() as $employee)
                        <option value="{{ $employee->id }}">{{ $employee->name }}</option>
                    @endforeach
                </select>
                @error('entry.user_id') <span class="text-red-600">{{ $message }}</span> @enderror
            </label>
            <label class="grid gap-1">Jenis komponen
                <select wire:model="entry.component_type_id" class="rounded border p-2">
                    <option value="">Pilih komponen</option>
                    @foreach ($this->typeOptions() as $type)
                        <option value="{{ $type->id }}">{{ $type->name }} ({{ $type->effective_from->format('Y-m-d') }})</option>
                    @endforeach
                </select>
                @error('entry.component_type_id') <span class="text-red-600">{{ $message }}</span> @enderror
            </label>
            <label class="grid gap-1">Awal periode (tanggal 1)
                <input type="date" wire:model="entry.period_start" class="rounded border p-2">
                @error('entry.period_start') <span class="text-red-600">{{ $message }}</span> @enderror
            </label>
            <label class="grid gap-1">Nominal (Rp)
                <input type="number" min="1" wire:model="entry.amount_minor" class="rounded border p-2">
                @error('entry.amount_minor') <span class="text-red-600">{{ $message }}</span> @enderror
            </label>
            <label class="grid gap-1">Catatan
                <input type="text" wire:model="entry.note" class="rounded border p-2">
                @error('entry.note') <span class="text-red-600">{{ $message }}</span> @enderror
            </label>
            <div class="md:col-span-3"><button type="submit" class="rounded bg-primary-600 px-4 py-2 text-white">Tambah komponen</button></div>
        </form>
        <table class="mt-4 w-full text-sm">
            <thead><tr><th>Karyawan</th><th>Periode</th><th>Komponen</th><th>Nominal</th></tr></thead>
            <tbody>
                @foreach ($this->entryRows() as $row)
                    <tr class="border-t"><td>{{ $row->user?->name }}</td><td>{{ $row->period_start->format('Y-m') }}</td>
                        <td>{{ $row->type?->name }}</td><td>{{ $row->amount_minor }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </section>
</x-filament-panels::page>
