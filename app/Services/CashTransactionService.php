<?php

namespace App\Services;

use App\Enums\PanelModule;
use App\Models\AuditLog;
use App\Models\CashAccount;
use App\Models\CashCategory;
use App\Models\CashTransaction;
use App\Models\JournalEntry;
use App\Models\OfficeItem;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CashTransactionService
{
    public function __construct(private readonly JournalService $journals) {}

    public function record(User $actor, array $input): CashTransaction
    {
        $this->authorize($actor);
        $data = Validator::make($input, [
            'uuid' => ['required', 'uuid'],
            'date' => ['required', 'date_format:Y-m-d'],
            'cash_category_id' => ['required', 'integer', 'exists:cash_categories,id'],
            'cash_account_id' => ['required', 'integer', 'exists:cash_accounts,id'],
            'amount_minor' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'description' => ['required', 'string', 'max:255'],
            'office_item_id' => ['nullable', 'integer', 'exists:office_items,id'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'evidence_path' => ['nullable', 'string', 'max:255'],
        ])->validate();

        if (isset($data['office_item_id']) !== isset($data['quantity'])) {
            throw ValidationException::withMessages(['quantity' => 'Barang dan jumlah harus diisi bersama.']);
        }
        if (isset($data['evidence_path'])) {
            $path = $data['evidence_path'];
            $disk = Storage::disk('local');
            if (preg_match('~\Acash-evidence/[^/\\\\]+\z~D', $path) !== 1 || str_contains($path, '..') || ! $disk->exists($path)
                || $disk->size($path) > 5 * 1024 * 1024
                || ! in_array($disk->mimeType($path), ['image/jpeg', 'image/png', 'application/pdf'], true)) {
                throw ValidationException::withMessages(['evidence_path' => 'Bukti harus JPG, PNG, atau PDF maksimal 5 MB pada penyimpanan privat.']);
            }
        }

        return DB::transaction(function () use ($actor, $data): CashTransaction {
            $existing = CashTransaction::query()->where('uuid', $data['uuid'])->lockForUpdate()->first();
            if ($existing) {
                foreach (['date', 'cash_category_id', 'cash_account_id', 'amount_minor', 'description',
                    'office_item_id', 'quantity', 'evidence_path'] as $field) {
                    $old = $field === 'date' ? $existing->date->toDateString() : $existing->$field;
                    if ((string) ($old ?? '') !== (string) ($data[$field] ?? '')) {
                        throw ValidationException::withMessages(['uuid' => 'UUID ini sudah dipakai untuk transaksi yang berbeda.']);
                    }
                }

                return $existing;
            }

            $category = CashCategory::query()->with('account')->lockForUpdate()->findOrFail($data['cash_category_id']);
            $cash = CashAccount::query()->with('account')->lockForUpdate()->findOrFail($data['cash_account_id']);
            if (! $category->is_active || ! $category->account->is_active
                || ! $cash->is_active || ! $cash->account->is_active
                || $cash->account->type !== 'ASSET') {
                throw ValidationException::withMessages(['cash_account_id' => 'Kategori dan akun kas harus aktif; akun kas harus aset.']);
            }
            if (($category->direction === 'OUT' && ! in_array($category->account->type, ['EXPENSE', 'ASSET'], true))
                || ($category->direction === 'IN' && ! in_array($category->account->type, ['REVENUE', 'EQUITY'], true))) {
                throw ValidationException::withMessages(['cash_category_id' => 'Akun kategori tidak cocok dengan arah transaksi.']);
            }
            if ($category->account_id === $cash->account_id) {
                throw ValidationException::withMessages(['cash_account_id' => 'Sumber dan tujuan tidak boleh akun yang sama.']);
            }
            if (isset($data['office_item_id']) && ! OfficeItem::query()->whereKey($data['office_item_id'])->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['office_item_id' => 'Barang kantor tidak aktif.']);
            }
            if (isset($data['office_item_id']) && $category->direction !== 'OUT') {
                throw ValidationException::withMessages(['office_item_id' => 'Barang kantor hanya untuk kas keluar.']);
            }

            if ($category->name === 'Setor tunai ke bank' && $cash->account->code !== '1-1200') {
                throw ValidationException::withMessages(['cash_account_id' => 'Pilih Kas Setoran sebagai sumber dana ya.']);
            }
            if ($category->name === 'Setor tunai ke bank' && $category->account->code !== '1-1300') {
                throw ValidationException::withMessages(['cash_category_id' => 'Kategori setor bank harus menuju akun Bank ya.']);
            }
            $balanceBefore = null;
            if ($category->direction === 'OUT' && $category->account->code === '1-1300'
                && $cash->account->code === '1-1200') {
                $balanceBefore = (int) DB::table('journal_lines as line')
                    ->join('journal_entries as entry', 'entry.id', '=', 'line.entry_id')
                    ->where('line.account_id', $cash->account_id)
                    ->where('entry.entry_date', '<=', $data['date'])
                    ->selectRaw('COALESCE(SUM(CAST(line.debit_minor AS SIGNED) - CAST(line.credit_minor AS SIGNED)), 0) AS balance')
                    ->value('balance');
            }

            $transaction = CashTransaction::query()->create([
                ...$data,
                'direction' => $category->direction,
                'recorded_by' => $actor->id,
                'status' => 'POSTED',
            ]);
            $amount = (int) $data['amount_minor'];
            $debit = $category->direction === 'OUT' ? $category->account_id : $cash->account_id;
            $credit = $category->direction === 'OUT' ? $cash->account_id : $category->account_id;
            $this->journals->post([
                'entry_date' => $data['date'],
                'description' => $data['description'],
                'source_type' => 'cash_transaction',
                'source_id' => $transaction->id,
                'purpose' => 'CASH_TX',
                'posted_by' => $actor->id,
            ], [
                ['account_id' => $debit, 'debit_minor' => $amount, 'credit_minor' => 0],
                ['account_id' => $credit, 'debit_minor' => 0, 'credit_minor' => $amount],
            ]);

            AuditLog::recordAction($actor, 'cash_transaction.created', $transaction, null, [
                'date' => $data['date'], 'amount_minor' => $amount,
                'cash_category_id' => $data['cash_category_id'], 'cash_account_id' => $data['cash_account_id'],
            ]);
            if ($balanceBefore !== null && $amount > $balanceBefore) {
                AuditLog::recordAction($actor, 'cash_transaction.balance_exceeded', $transaction, null, [
                    'date' => $data['date'], 'cash_account_id' => $cash->id,
                    'balance_before_minor' => $balanceBefore, 'amount_minor' => $amount,
                    'excess_minor' => $amount - $balanceBefore,
                ]);
            }

            return $transaction;
        }, 3);
    }

    public function void(User $actor, CashTransaction $transaction, string $reason): CashTransaction
    {
        $this->authorize($actor);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 225) {
            throw ValidationException::withMessages(['reason' => 'Alasan pembatalan wajib diisi, maksimal 225 karakter.']);
        }

        return DB::transaction(function () use ($actor, $transaction, $reason): CashTransaction {
            $locked = CashTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
            if ($locked->status !== 'POSTED') {
                throw ValidationException::withMessages(['transaction' => 'Transaksi sudah dibatalkan.']);
            }
            $entry = JournalEntry::query()->where('source_type', 'cash_transaction')
                ->where('source_id', $locked->id)->where('purpose', 'CASH_TX')->firstOrFail();
            $this->journals->reverse($entry, $reason);
            $locked->update(['status' => 'VOID', 'voided_by' => $actor->id,
                'voided_at' => now(), 'void_reason' => $reason]);

            AuditLog::recordAction($actor, 'cash_transaction.voided', $locked,
                ['status' => 'POSTED'], ['status' => 'VOID', 'reason' => $reason]);

            return $locked;
        }, 3);
    }

    private function authorize(User $actor): void
    {
        abort_unless(PermissionMatrix::can($actor, PanelModule::CASH, 'edit'), 403);
    }
}
