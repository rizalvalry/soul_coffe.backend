<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\ClearingReceipt;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\ClearingService;
use App\Services\JournalService;
use Database\Seeders\AccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ClearingTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private int $bankId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->seed(AccountSeeder::class);
        $this->finance = User::factory()->role(Role::FINANCE)->create();
        $this->bankId = Account::query()->where('code', '1-1300')->value('id');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function source(string $date, int $amount, int $id, string $sourceType = 'settlement'): void
    {
        app(JournalService::class)->post([
            'entry_date' => $date, 'description' => 'Penjualan uji',
            'source_type' => $sourceType, 'source_id' => $id,
            'purpose' => $sourceType === 'settlement' ? 'SETTLEMENT' : 'DIRECT_SALE',
            'posted_by' => $this->finance->id,
        ], [
            ['account_id' => Account::query()->where('code', '1-1400')->value('id'), 'debit_minor' => $amount, 'credit_minor' => 0],
            ['account_id' => Account::query()->where('code', '4-1100')->value('id'), 'debit_minor' => 0, 'credit_minor' => $amount],
        ]);
    }

    public function test_receipt_allocates_days_posts_mdr_and_void_reverses_it(): void
    {
        $this->source('2026-10-15', 100, 1);
        $this->source('2026-10-18', 100, 2, 'direct_sale');
        $this->source('2026-10-20', 100, 3);
        $service = app(ClearingService::class);

        $before = $service->aging('2026-10-20');
        $this->assertSame(['0_1_minor' => 100, '2_3_minor' => 100, 'over_3_minor' => 100], $before['buckets']);

        $input = ['uuid' => (string) Str::uuid(), 'received_on' => '2026-10-20',
            'amount_minor' => 90, 'mdr_minor' => 10, 'bank_account_id' => $this->bankId,
            'sale_dates' => ['2026-10-18']];
        $receipt = $service->record($this->finance, $input);
        $this->assertSame($receipt->id, $service->record($this->finance, $input)->id);
        $entry = JournalEntry::query()->where('source_type', 'clearing_receipt')->sole();
        $this->assertSame([
            '1-1300' => [90, 0], '1-1400' => [0, 100], '6-1990' => [10, 0],
        ], $entry->lines()->with('account')->get()->mapWithKeys(fn ($line): array => [
            $line->account->code => [$line->debit_minor, $line->credit_minor],
        ])->all());
        $this->assertSame(100, $receipt->allocations->sole()->amount_minor);
        $this->assertSame(['0_1_minor' => 100, '2_3_minor' => 0, 'over_3_minor' => 100], $service->aging('2026-10-20')['buckets']);
        $this->assertSame(1, AuditLog::query()->where('action', 'clearing_receipt.created')->count());

        $service->void($this->finance, $receipt, 'Nominal keliru');
        $this->assertSame('VOID', $receipt->fresh()->status);
        $this->assertNotNull($entry->fresh()->reversal);
        $this->assertSame($before['buckets'], $service->aging('2026-10-20')['buckets']);
        $this->assertSame(1, AuditLog::query()->where('action', 'clearing_receipt.voided')->count());
    }

    public function test_unmatched_receipt_is_visible_and_reconciles_to_net_clearing(): void
    {
        $this->source('2026-10-15', 100, 1);
        $receipt = app(ClearingService::class)->record($this->finance, [
            'uuid' => (string) Str::uuid(), 'received_on' => '2026-10-20',
            'amount_minor' => 30, 'bank_account_id' => $this->bankId,
        ]);
        $this->assertCount(0, $receipt->allocations);
        $aging = app(ClearingService::class)->aging('2026-10-20');
        $this->assertSame(30, $aging['unmatched_credit_minor']);
        $this->assertSame(70, $aging['net_balance_minor']);
        $this->assertTrue($aging['rows'][0]['flagged']);
    }

    public function test_unmatched_receipt_can_be_matched_later_once_without_another_journal(): void
    {
        $this->source('2026-10-15', 100, 1);
        $service = app(ClearingService::class);
        $receipt = $service->record($this->finance, [
            'uuid' => (string) Str::uuid(), 'received_on' => '2026-10-20',
            'amount_minor' => 100, 'bank_account_id' => $this->bankId,
        ]);
        $this->assertSame(100, $service->aging('2026-10-20')['buckets']['over_3_minor']);
        $input = ['uuid' => (string) Str::uuid(), 'sale_dates' => ['2026-10-15']];
        $service->match($this->finance, $receipt, $input);
        $service->match($this->finance, $receipt, $input);
        $this->assertSame(0, $service->aging('2026-10-20')['buckets']['over_3_minor']);
        $this->assertSame(0, $service->aging('2026-10-20')['unmatched_credit_minor']);
        $this->assertSame(1, $receipt->allocations()->count());
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'clearing_receipt')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'clearing_receipt.matched')->count());
    }

    public function test_backdated_receipt_does_not_allocate_a_day_already_matched_by_later_receipt(): void
    {
        $this->source('2026-10-15', 100, 1);
        $service = app(ClearingService::class);
        $first = $service->record($this->finance, [
            'uuid' => (string) Str::uuid(), 'received_on' => '2026-10-20',
            'amount_minor' => 100, 'bank_account_id' => $this->bankId,
            'sale_dates' => ['2026-10-15'],
        ]);
        $second = $service->record($this->finance, [
            'uuid' => (string) Str::uuid(), 'received_on' => '2026-10-19',
            'amount_minor' => 100, 'bank_account_id' => $this->bankId,
            'sale_dates' => ['2026-10-15'],
        ]);
        $this->assertSame(100, (int) $first->allocations()->sum('amount_minor'));
        $this->assertSame(0, (int) $second->allocations()->sum('amount_minor'));
        $aging = $service->aging('2026-10-20');
        $this->assertSame(-100, $aging['net_balance_minor']);
        $this->assertSame(100, $aging['unmatched_credit_minor']);
    }

    public function test_later_void_preserves_historical_aging_before_reversal_date(): void
    {
        $this->source('2026-10-18', 100, 1);
        $service = app(ClearingService::class);
        $receipt = $service->record($this->finance, [
            'uuid' => (string) Str::uuid(), 'received_on' => '2026-10-20',
            'amount_minor' => 100, 'bank_account_id' => $this->bankId,
            'sale_dates' => ['2026-10-18'],
        ]);
        Carbon::setTestNow('2026-10-25 12:00:00');
        try {
            $service->void($this->finance, $receipt, 'Dana masuk salah');
            $this->assertSame(0, $service->aging('2026-10-22')['net_balance_minor']);
            $this->assertSame(0, $service->aging('2026-10-22')['buckets']['over_3_minor']);
            $this->assertSame(100, $service->aging('2026-10-25')['net_balance_minor']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_opening_balance_in_clearing_is_visible_as_unattributed(): void
    {
        app(JournalService::class)->postOpening('2026-10-15', [
            ['account_id' => Account::query()->where('code', '1-1400')->value('id'), 'debit_minor' => 100, 'credit_minor' => 0],
            ['account_id' => Account::query()->where('code', '3-1200')->value('id'), 'debit_minor' => 0, 'credit_minor' => 100],
        ]);
        $aging = app(ClearingService::class)->aging('2026-10-20');
        $this->assertSame(100, $aging['net_balance_minor']);
        $this->assertSame(100, $aging['unattributed_minor']);
    }

    public function test_api_finance_role_matrix_for_receipt_and_aging(): void
    {
        foreach ([Role::FINANCE, Role::MANAGEMENT, Role::ADMINISTRATOR] as $role) {
            $actor = $role === Role::FINANCE ? $this->finance : User::factory()->role($role)->create();
            $payload = ['uuid' => (string) Str::uuid(), 'received_on' => '2026-10-20',
                'amount_minor' => 100, 'bank_account_id' => $this->bankId];

            $this->actingAs($actor, 'sanctum')->getJson('/api/v1/clearing-receipts')->assertOk();
            $this->actingAs($actor, 'sanctum')->getJson('/api/v1/clearing-receipts/options')->assertOk();
            $this->actingAs($actor, 'sanctum')->getJson('/api/v1/clearing-receipts/aging')->assertOk();
            $this->actingAs($actor, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
                ->postJson('/api/v1/clearing-receipts', $payload)->assertCreated();
        }

        foreach ([Role::BARISTA, Role::STAFF, Role::RIDER, Role::CONTENT_CREATOR] as $role) {
            $actor = User::factory()->role($role)->create();
            $payload = ['uuid' => (string) Str::uuid(), 'received_on' => '2026-10-20',
                'amount_minor' => 100, 'bank_account_id' => $this->bankId];

            $this->actingAs($actor, 'sanctum')->getJson('/api/v1/clearing-receipts')->assertForbidden();
            $this->actingAs($actor, 'sanctum')->getJson('/api/v1/clearing-receipts/options')->assertForbidden();
            $this->actingAs($actor, 'sanctum')->getJson('/api/v1/clearing-receipts/aging')->assertForbidden();
            $this->actingAs($actor, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
                ->postJson('/api/v1/clearing-receipts', $payload)->assertForbidden();
        }

        $this->assertSame(3, ClearingReceipt::query()->count());
    }

    public function test_api_finance_role_matrix_for_matching_and_voiding_receipts(): void
    {
        $this->source('2026-10-15', 100, 1);

        foreach ([Role::FINANCE, Role::MANAGEMENT, Role::ADMINISTRATOR] as $role) {
            $actor = $role === Role::FINANCE ? $this->finance : User::factory()->role($role)->create();
            $receipt = app(ClearingService::class)->record($this->finance, [
                'uuid' => (string) Str::uuid(), 'received_on' => '2026-10-20',
                'amount_minor' => 100, 'bank_account_id' => $this->bankId,
            ]);

            $this->actingAs($actor, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
                ->postJson("/api/v1/clearing-receipts/{$receipt->id}/match", [
                    'uuid' => (string) Str::uuid(), 'sale_dates' => ['2026-10-15'],
                ])->assertOk();
            $this->actingAs($actor, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
                ->postJson("/api/v1/clearing-receipts/{$receipt->id}/void", ['reason' => 'Uji hak akses'])
                ->assertOk();
        }

        $receipt = app(ClearingService::class)->record($this->finance, [
            'uuid' => (string) Str::uuid(), 'received_on' => '2026-10-20',
            'amount_minor' => 100, 'bank_account_id' => $this->bankId,
        ]);
        foreach ([Role::BARISTA, Role::STAFF, Role::RIDER, Role::CONTENT_CREATOR] as $role) {
            $actor = User::factory()->role($role)->create();
            $this->actingAs($actor, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
                ->postJson("/api/v1/clearing-receipts/{$receipt->id}/match", [
                    'uuid' => (string) Str::uuid(), 'sale_dates' => ['2026-10-15'],
                ])->assertForbidden();
            $this->actingAs($actor, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
                ->postJson("/api/v1/clearing-receipts/{$receipt->id}/void", ['reason' => 'Uji hak akses'])
                ->assertForbidden();
        }
    }

    public function test_audit_log_cannot_be_changed_or_deleted_through_model(): void
    {
        $audit = AuditLog::recordAction($this->finance, 'test.action', $this->finance, null, ['ok' => true]);
        try {
            $audit->update(['action' => 'rewritten']);
            $this->fail('Audit update succeeded.');
        } catch (ValidationException) {
            $this->assertSame('test.action', $audit->fresh()->action);
        }
        try {
            $audit->delete();
            $this->fail('Audit delete succeeded.');
        } catch (ValidationException) {
            $this->assertSame(1, AuditLog::query()->count());
        }
    }
}
