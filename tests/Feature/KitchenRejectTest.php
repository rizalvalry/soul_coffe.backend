<?php

namespace Tests\Feature;

use App\Enums\KitchenRejectReason;
use App\Enums\MovementType;
use App\Enums\PanelModule;
use App\Enums\Role;
use App\Exports\KitchenRejectExport;
use App\Filament\Resources\KitchenRejects\KitchenRejectResource;
use App\Filament\Resources\KitchenRejects\Pages\ListKitchenRejects;
use App\Models\AppNotification;
use App\Models\CentralKitchen;
use App\Models\KitchenReject;
use App\Models\Product;
use App\Models\ProductPriceVersion;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use App\Services\KitchenRejectService;
use App\Services\PendingStockCostService;
use App\Services\Reporting\ManagementReportService;
use App\Services\StockLedgerService;
use Database\Seeders\AccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class KitchenRejectTest extends TestCase
{
    use RefreshDatabase;

    private CentralKitchen $kitchen;

    private Product $product;

    private User $production;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['outbox.publish_mode' => 'queue']);
        $this->seed(AccountSeeder::class);
        $this->kitchen = CentralKitchen::create(['name' => 'Dapur Reject', 'address' => 'Jakarta',
            'open_at' => '00:00', 'close_at' => '23:59', 'is_active' => true]);
        $this->product = Product::create(['code' => 'KOPI-REJECT', 'name' => 'Kopi Uji',
            'unit' => 'cup', 'is_sellable' => true, 'is_active' => true, 'sort_order' => 1]);
        ProductPriceVersion::create(['product_id' => $this->product->id, 'cost_price_minor' => 1000,
            'sell_price_minor' => 5000, 'effective_from' => now()->subDay()]);
        $this->production = User::factory()->role(Role::BARISTA)->create(['kitchen_id' => $this->kitchen->id]);
        $this->finance = User::factory()->role(Role::FINANCE)->create();
        app(StockLedgerService::class)->post('kitchen', $this->kitchen->id, $this->product->id,
            MovementType::PRODUCTION_IN, 20, $this->production->id, $this->kitchen->id);
    }

    private function payload(int $qty, int $imageWidth = 640): array
    {
        return ['lines' => json_encode([['product_id' => $this->product->id, 'qty' => $qty]]),
            'reason_code' => 'DROPPED', 'photo' => UploadedFile::fake()->image('reject.jpg', $imageWidth, 480),
            'photo_taken_at' => now()->toIso8601String()];
    }

    private function submit(User $actor, array $payload, ?string $key = null)
    {
        return $this->actingAs($actor, 'sanctum')->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())
            ->withHeader('Accept', 'application/json')->post('/api/v1/kitchen-rejects', $payload);
    }

    public function test_production_reject_posts_stock_journal_notification_and_audit_once(): void
    {
        $manager = User::factory()->role(Role::FINANCE)->create(['is_management' => true]);
        $key = (string) Str::uuid();
        $originalPayload = $this->payload(2);
        $response = $this->submit($this->production, $originalPayload, $key)->assertCreated()
            ->assertJsonPath('data.status', 'RECORDED');
        $id = $response->json('data.id');
        $this->assertSame(18, StockLedger::projectedStock('kitchen', $this->kitchen->id, $this->product->id));
        $waste = StockLedger::query()->where('ref_type', 'kitchen_reject')->where('ref_id', $id)->sole();
        $this->assertSame(MovementType::WASTE_OUT, $waste->movement_type);
        $this->assertSame(-2, $waste->qty_delta);
        $this->assertSame(2000, $waste->cost_minor);
        $this->assertDatabaseHas('journal_entries', ['source_type' => 'stock_ledger',
            'source_id' => $waste->id, 'purpose' => 'STOCK_LOSS']);
        $this->assertDatabaseHas('audit_log', ['action' => 'kitchen_reject.recorded', 'subject_id' => $id]);
        $this->assertSame(1, AppNotification::query()->where('user_id', $this->finance->id)
            ->where('type', 'KitchenRejectRecorded')->count());
        $this->assertSame(1, AppNotification::query()->where('user_id', $manager->id)
            ->where('type', 'KitchenRejectRecorded')->count());
        $retryPayload = $this->payload(2);
        $retryPayload['photo_taken_at'] = $originalPayload['photo_taken_at'];
        $this->submit($this->production, $retryPayload, $key)->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'true');
        $this->assertDatabaseCount('kitchen_rejects', 1);
        $this->assertSame(1, StockLedger::query()->where('ref_type', 'kitchen_reject')->count());
        $this->actingAs($this->production, 'sanctum')->getJson('/api/v1/kitchen-rejects')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_all_reason_labels_match_in_notifications_cms_and_excel(): void
    {
        $manager = User::factory()->role(Role::FINANCE)->create(['is_management' => true]);
        $reasons = [
            'DROPPED' => 'Jatuh / tumpah',
            'PACKAGING' => 'Kemasan rusak',
            'QUALITY' => 'Kualitas tidak layak',
            'OTHER' => 'Lainnya',
        ];

        $imageWidth = 640;
        foreach ($reasons as $code => $label) {
            $payload = $this->payload(1, $imageWidth++);
            $payload['reason_code'] = $code;
            if ($code === 'OTHER') {
                $payload['note'] = 'Alasan lain';
            }

            $this->submit($this->production, $payload)->assertCreated()
                ->assertJsonPath('data.reason_code', $code);
            $expectedBody = 'Kopi Uji 1 cup · '.$label.' · '.$this->production->name.' · Dapur Reject';
            foreach ([$this->finance, $manager] as $recipient) {
                $notification = AppNotification::query()->where('user_id', $recipient->id)
                    ->where('type', 'KitchenRejectRecorded')->latest('id')->firstOrFail();
                $this->assertSame($expectedBody, $notification->payload_json['body']);
            }
            $this->assertSame($label, KitchenRejectReason::labelFor($code));
        }

        $this->actingAs($this->finance, 'web')->get(KitchenRejectResource::getUrl())
            ->assertOk();
        $table = Livewire::test(ListKitchenRejects::class);
        foreach ($reasons as $label) {
            $table->assertSee($label);
        }

        $export = new KitchenRejectExport(KitchenRejectResource::getEloquentQuery(), true);
        $exportLabels = $export->query()->get()->map(fn ($row): string => $export->map($row)[5])->all();
        $this->assertSame(array_values($reasons), $exportLabels);

        $report = app(ManagementReportService::class)->build(today(), today());
        $reportLabels = array_column($report['sections']['reject_dapur']['rows'], 5);
        $this->assertEqualsCanonicalizing(array_values($reasons), $reportLabels);
    }

    public function test_invalid_photo_other_note_stock_and_non_main_product_are_rejected(): void
    {
        $noPhoto = $this->payload(1);
        unset($noPhoto['photo']);
        $this->submit($this->production, $noPhoto)->assertStatus(422);
        $other = $this->payload(1);
        $other['reason_code'] = 'OTHER';
        $this->submit($this->production, $other)->assertStatus(422);
        $this->submit($this->production, $this->payload(21))->assertStatus(422)
            ->assertSee('Stok showcase tidak cukup untuk');
        $secondary = Product::create(['code' => 'ICE', 'name' => 'Es', 'unit' => 'cup',
            'is_sellable' => false, 'is_active' => true, 'sort_order' => 2]);
        $bad = $this->payload(1);
        $bad['lines'] = json_encode([['product_id' => $secondary->id, 'qty' => 1]]);
        $this->submit($this->production, $bad)->assertStatus(422);
        $this->assertDatabaseCount('kitchen_rejects', 0);
    }

    public function test_stale_or_reused_photo_is_rejected_without_extra_stock_movement(): void
    {
        $stale = $this->payload(1);
        $stale['photo_taken_at'] = now()->subHour()->toIso8601String();
        $this->submit($this->production, $stale)->assertStatus(422);
        $this->submit($this->production, $this->payload(1))->assertCreated();
        $this->submit($this->production, $this->payload(1))->assertStatus(422);
        $this->assertDatabaseCount('kitchen_rejects', 1);
        $this->assertSame(19, StockLedger::projectedStock('kitchen', $this->kitchen->id, $this->product->id));
    }

    public function test_daily_limit_flags_without_blocking_and_finance_can_change_it(): void
    {
        $this->submit($this->production, $this->payload(5))->assertCreated();
        $response = $this->submit($this->production, $this->payload(1, 641))->assertCreated()
            ->assertJsonPath('data.status', 'NEEDS_REVIEW');
        $this->assertSame(14, StockLedger::projectedStock('kitchen', $this->kitchen->id, $this->product->id));
        $this->assertTrue(AppNotification::query()->where('user_id', $this->finance->id)
            ->where('type', 'KitchenRejectRecorded')->get()
            ->contains(fn ($notice): bool => str_contains($notice->payload_json['body'], 'melewati batas harian')));
        app(KitchenRejectService::class)->setLimit($this->finance, $this->kitchen->id, $this->product->id, 10);
        $this->assertDatabaseHas('kitchen_reject_limits', ['kitchen_id' => $this->kitchen->id,
            'product_id' => $this->product->id, 'daily_limit' => 10]);
        $this->assertDatabaseHas('audit_log', ['action' => 'kitchen_reject.limit_changed']);
        $this->assertSame('NEEDS_REVIEW', KitchenReject::findOrFail($response->json('data.id'))->status);
    }

    public function test_matrix_revocation_and_runner_grant_change_server_authorization(): void
    {
        $runner = User::factory()->role(Role::RIDER)->create();
        $rider = User::factory()->role(Role::STAFF)->create();
        $creator = User::factory()->role(Role::CONTENT_CREATOR)->create();
        $this->submit($runner, $this->payload(1))->assertForbidden();
        $this->submit($rider, $this->payload(1))->assertForbidden();
        $this->submit($creator, $this->payload(1))->assertForbidden();
        PermissionMatrix::set(Role::BARISTA, PanelModule::KITCHEN_REJECT, ['view']);
        $this->submit($this->production, $this->payload(1))->assertForbidden();
        PermissionMatrix::set(Role::RIDER, PanelModule::KITCHEN_REJECT, ['view', 'create']);
        $this->submit($runner, [...$this->payload(1), 'kitchen_id' => $this->kitchen->id])
            ->assertCreated();
    }

    public function test_multipart_retry_replays_only_identical_fields_and_photo(): void
    {
        $key = (string) Str::uuid();
        $this->submit($this->production, $this->payload(2), $key)->assertCreated();
        $this->submit($this->production, $this->payload(2), $key)
            ->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        $this->submit($this->production, $this->payload(3), $key)->assertStatus(422);
        $this->submit($this->production, $this->payload(2, 641), $key)->assertStatus(422);
        $this->assertDatabaseCount('kitchen_rejects', 1);
    }

    public function test_barista_granted_edit_cannot_read_or_change_another_kitchen(): void
    {
        $other = CentralKitchen::create(['name' => 'Dapur Lain', 'address' => 'Jakarta',
            'open_at' => '00:00', 'close_at' => '23:59', 'is_active' => true]);
        $otherBarista = User::factory()->role(Role::BARISTA)->create(['kitchen_id' => $other->id]);
        app(StockLedgerService::class)->post('kitchen', $other->id, $this->product->id,
            MovementType::PRODUCTION_IN, 5, $otherBarista->id, $other->id);
        $id = $this->submit($otherBarista, $this->payload(1))->assertCreated()->json('data.id');
        PermissionMatrix::set(Role::BARISTA, PanelModule::KITCHEN_REJECT, ['view', 'create', 'edit']);
        $this->actingAs($this->production, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/kitchen-rejects/'.$id.'/correct', ['reason' => 'Bukan dapur saya'])
            ->assertForbidden();
        $this->actingAs($this->production, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/kitchen-rejects/'.$id.'/review')->assertForbidden();
        $this->actingAs($this->production, 'web');
        Livewire::test(ListKitchenRejects::class)
            ->assertCanNotSeeTableRecords([KitchenReject::findOrFail($id)]);
        $this->assertSame('RECORDED', KitchenReject::findOrFail($id)->status);
    }

    public function test_barista_export_is_kitchen_scoped_and_escapes_formula_text(): void
    {
        $other = CentralKitchen::create(['name' => 'Dapur Lain', 'address' => 'Jakarta',
            'open_at' => '00:00', 'close_at' => '23:59', 'is_active' => true]);
        $otherBarista = User::factory()->role(Role::BARISTA)->create(['kitchen_id' => $other->id]);
        app(StockLedgerService::class)->post('kitchen', $other->id, $this->product->id,
            MovementType::PRODUCTION_IN, 5, $otherBarista->id, $other->id);
        $own = $this->payload(1);
        $own['note'] = '=1+1';
        $this->submit($this->production, $own)->assertCreated();
        $this->submit($otherBarista, $this->payload(1, 641))->assertCreated();
        $this->actingAs($this->production, 'web');
        $visibleRecord = Livewire::test(ListKitchenRejects::class)->instance()->getFilteredTableQuery()->first();
        $this->assertArrayNotHasKey('net_cost', $visibleRecord->getAttributes());
        $this->assertArrayNotHasKey('cost_minor', $visibleRecord->lines->first()->getAttributes());
        $export = new KitchenRejectExport(KitchenRejectResource::getEloquentQuery(), false);
        $rows = $export->query()->get();
        $this->assertCount(1, $rows);
        $mapped = $export->map($rows->first());
        $this->assertSame('=1+1', $mapped[8]);
        $this->assertSame('', $mapped[4]);
        $cell = (new Spreadsheet)->getActiveSheet()->getCell('A1');
        $export->bindValue($cell, $mapped[8]);
        $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
    }

    public function test_finance_correction_restores_stock_and_journal_and_production_gets_403(): void
    {
        $id = $this->submit($this->production, $this->payload(2))->assertCreated()->json('data.id');
        $this->actingAs($this->production, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/kitchen-rejects/'.$id.'/correct', ['reason' => 'Salah input'])
            ->assertForbidden();
        $this->actingAs($this->finance, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/kitchen-rejects/'.$id.'/correct', ['reason' => 'Salah input'])
            ->assertOk()->assertJsonPath('data.status', 'CORRECTED');
        $this->assertSame(20, StockLedger::projectedStock('kitchen', $this->kitchen->id, $this->product->id));
        $correction = StockLedger::query()->where('ref_type', 'kitchen_reject_correction')->sole();
        $this->assertSame(2, $correction->qty_delta);
        $this->assertSame(2000, $correction->cost_minor);
        $this->assertDatabaseHas('journal_entries', ['source_type' => 'stock_ledger',
            'source_id' => $correction->id, 'purpose' => 'STOCK_LOSS']);
        $this->assertDatabaseHas('audit_log', ['action' => 'kitchen_reject.corrected', 'subject_id' => $id]);
        $this->assertDatabaseCount('kitchen_rejects', 1);
        $this->actingAs($this->finance, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/kitchen-rejects/'.$id.'/correct', ['reason' => 'Ulang'])
            ->assertStatus(422);
    }

    public function test_cms_and_management_report_show_separate_reject_rows(): void
    {
        $this->submit($this->production, $this->payload(2))->assertCreated();
        $this->actingAs($this->finance, 'web')->get(KitchenRejectResource::getUrl())
            ->assertOk()->assertSee('Kopi Uji')->assertSee('Dapur Reject');
        $report = app(ManagementReportService::class)->build(today(), today());
        $this->assertSame(2, $report['sections']['reject_dapur']['rows'][0][3]);
        $this->assertSame(2000, $report['sections']['reject_dapur']['rows'][0][4]);
        $this->assertSame([], $report['sections']['refill_waste']['rows']);
    }

    public function test_correction_removes_reject_from_net_report_and_original_remains(): void
    {
        $id = $this->submit($this->production, $this->payload(2))->assertCreated()->json('data.id');
        app(KitchenRejectService::class)->correct(KitchenReject::findOrFail($id), $this->finance, 'Salah input');
        $report = app(ManagementReportService::class)->build(today(), today());
        $this->assertSame(0, $report['sections']['reject_dapur']['rows'][0][3]);
        $this->assertSame(0, $report['sections']['reject_dapur']['rows'][0][4]);
        $this->assertDatabaseHas('kitchen_reject_lines', ['kitchen_reject_id' => $id, 'qty' => 2]);
    }

    public function test_next_day_correction_is_a_negative_row_on_the_correction_date(): void
    {
        $originalDate = today()->toDateString();
        $id = $this->submit($this->production, $this->payload(2))->assertCreated()->json('data.id');
        $this->travel(1)->days();
        $correctionDate = today()->toDateString();
        app(KitchenRejectService::class)->correct(KitchenReject::findOrFail($id), $this->finance, 'Salah input');

        $original = app(ManagementReportService::class)->build(Carbon::parse($originalDate), Carbon::parse($originalDate));
        $this->assertSame(2, $original['sections']['reject_dapur']['rows'][0][3]);
        $this->assertSame(2000, $original['sections']['reject_dapur']['rows'][0][4]);
        $correction = app(ManagementReportService::class)->build(Carbon::parse($correctionDate), Carbon::parse($correctionDate));
        $this->assertSame(-2, $correction['sections']['reject_dapur']['rows'][0][3]);
        $this->assertSame(-2000, $correction['sections']['reject_dapur']['rows'][0][4]);
    }

    public function test_pending_cost_correction_revalues_to_zero_net_loss(): void
    {
        $pendingProduct = Product::create(['code' => 'PENDING-REJECT', 'name' => 'Pending Kopi',
            'unit' => 'cup', 'is_sellable' => true, 'is_active' => true, 'sort_order' => 2]);
        app(StockLedgerService::class)->post('kitchen', $this->kitchen->id, $pendingProduct->id,
            MovementType::PRODUCTION_IN, 5, $this->production->id, $this->kitchen->id);
        $payload = $this->payload(2);
        $payload['lines'] = json_encode([['product_id' => $pendingProduct->id, 'qty' => 2]]);
        $id = $this->submit($this->production, $payload)->assertCreated()->json('data.id');
        app(KitchenRejectService::class)->correct(KitchenReject::findOrFail($id), $this->finance, 'Salah input');
        $correction = StockLedger::query()->where('ref_type', 'kitchen_reject_correction')->sole();
        $this->assertTrue($correction->cost_pending);
        $this->travel(2)->seconds();
        ProductPriceVersion::create(['product_id' => $pendingProduct->id, 'cost_price_minor' => 1000,
            'sell_price_minor' => 5000, 'effective_from' => now()]);
        app(PendingStockCostService::class)->resolveProduct($pendingProduct, $this->finance);
        $loss = DB::table('journal_lines as l')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.code', '6-1910')
            ->selectRaw('SUM(CAST(l.debit_minor AS SIGNED) - CAST(l.credit_minor AS SIGNED)) AS amount')
            ->value('amount');
        $this->assertSame(0, (int) $loss);
        $report = app(ManagementReportService::class)->build(today(), today());
        $this->assertSame(0, $report['sections']['reject_dapur']['rows'][0][4]);
    }

    public function test_correction_after_pending_cost_resolution_uses_effective_hpp(): void
    {
        $pendingProduct = Product::create(['code' => 'PENDING-AFTER', 'name' => 'Pending Sesudah',
            'unit' => 'cup', 'is_sellable' => true, 'is_active' => true, 'sort_order' => 2]);
        app(StockLedgerService::class)->post('kitchen', $this->kitchen->id, $pendingProduct->id,
            MovementType::PRODUCTION_IN, 5, $this->production->id, $this->kitchen->id);
        $payload = $this->payload(2);
        $payload['lines'] = json_encode([['product_id' => $pendingProduct->id, 'qty' => 2]]);
        $id = $this->submit($this->production, $payload)->assertCreated()->json('data.id');
        $this->travel(2)->seconds();
        ProductPriceVersion::create(['product_id' => $pendingProduct->id, 'cost_price_minor' => 1000,
            'sell_price_minor' => 5000, 'effective_from' => now()]);
        app(PendingStockCostService::class)->resolveProduct($pendingProduct, $this->finance);
        app(KitchenRejectService::class)->correct(KitchenReject::findOrFail($id), $this->finance, 'Salah input');
        $correction = StockLedger::query()->where('ref_type', 'kitchen_reject_correction')->sole();
        $this->assertSame(2000, $correction->cost_minor);
        $this->assertFalse($correction->cost_pending);
        $report = app(ManagementReportService::class)->build(today(), today());
        $this->assertSame(0, $report['sections']['reject_dapur']['rows'][0][4]);
    }

    public function test_next_day_hpp_resolution_reports_cost_on_resolution_date(): void
    {
        $pendingProduct = Product::create(['code' => 'PENDING-NEXT', 'name' => 'Pending Besok',
            'unit' => 'cup', 'is_sellable' => true, 'is_active' => true, 'sort_order' => 2]);
        app(StockLedgerService::class)->post('kitchen', $this->kitchen->id, $pendingProduct->id,
            MovementType::PRODUCTION_IN, 5, $this->production->id, $this->kitchen->id);
        $payload = $this->payload(2);
        $payload['lines'] = json_encode([['product_id' => $pendingProduct->id, 'qty' => 2]]);
        $originalDate = today()->toDateString();
        $this->submit($this->production, $payload)->assertCreated();
        $this->travel(1)->days();
        $resolutionDate = today()->toDateString();
        ProductPriceVersion::create(['product_id' => $pendingProduct->id, 'cost_price_minor' => 1000,
            'sell_price_minor' => 5000, 'effective_from' => now()]);
        app(PendingStockCostService::class)->resolveProduct($pendingProduct, $this->finance);

        $original = app(ManagementReportService::class)->build(Carbon::parse($originalDate), Carbon::parse($originalDate));
        $this->assertSame(2, $original['sections']['reject_dapur']['rows'][0][3]);
        $this->assertSame(0, $original['sections']['reject_dapur']['rows'][0][4]);
        $resolution = app(ManagementReportService::class)->build(Carbon::parse($resolutionDate), Carbon::parse($resolutionDate));
        $this->assertSame(0, $resolution['sections']['reject_dapur']['rows'][0][3]);
        $this->assertSame(2000, $resolution['sections']['reject_dapur']['rows'][0][4]);
    }

    public function test_cms_filters_exports_reviews_and_sets_audited_limits(): void
    {
        $id = $this->submit($this->production, $this->payload(2))->assertCreated()->json('data.id');
        $reject = KitchenReject::findOrFail($id);
        $this->actingAs($this->finance, 'web');
        Livewire::test(ListKitchenRejects::class)
            ->filterTable('tanggal', ['from' => today()->toDateString(), 'to' => today()->toDateString()])
            ->filterTable('kitchen_id', $this->kitchen->id)
            ->filterTable('status', 'RECORDED')
            ->assertCanSeeTableRecords([$reject]);
        Livewire::test(ListKitchenRejects::class)
            ->filterTable('status', 'NEEDS_REVIEW')->assertCanNotSeeTableRecords([$reject]);
        Livewire::test(ListKitchenRejects::class)->callAction('exportExcel')
            ->assertFileDownloaded('reject-dapur.xlsx');
        Livewire::test(ListKitchenRejects::class)->callAction('setLimit', [
            'kitchen_id' => $this->kitchen->id, 'product_id' => $this->product->id,
            'daily_limit' => 7,
        ])->assertHasNoErrors();
        $this->assertDatabaseHas('kitchen_reject_limits', ['kitchen_id' => $this->kitchen->id,
            'product_id' => $this->product->id, 'daily_limit' => 7]);
        Livewire::test(ListKitchenRejects::class)->callTableAction('review', $reject)
            ->assertHasNoTableActionErrors();
        $this->assertSame('REVIEWED', $reject->fresh()->status);
        $this->assertDatabaseHas('audit_log', ['action' => 'kitchen_reject.reviewed', 'subject_id' => $id]);
    }
}
