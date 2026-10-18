<?php

namespace App\Filament\Pages;

use App\Exports\PayrollRunExport;
use App\Models\PayrollRun;
use App\Services\Menu\MenuLabels;
use App\Services\PayrollRunService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use UnitEnum;

class PayrollRuns extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Akuntansi';

    protected static ?string $navigationLabel = 'Payroll Runs';

    protected static ?string $title = 'Payroll Runs';

    protected string $view = 'filament.pages.payroll-runs';

    public string $newPeriod = '';

    public ?int $selectedRunId = null;

    public string $paymentDate = '';

    public string $paymentAccountId = '';

    public static function canAccess(): bool
    {
        return Gate::allows('manage-payroll');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return MenuLabels::group('Akuntansi');
    }

    public function mount(): void
    {
        Gate::authorize('manage-payroll');
        $this->newPeriod = now()->format('Y-m');
        $this->paymentDate = now()->format('Y-m-d');
    }

    public function createRun(): void
    {
        Gate::authorize('manage-payroll');
        $period = $this->validate(['newPeriod' => ['required', 'date_format:Y-m']])['newPeriod'];
        $run = app(PayrollRunService::class)->create($period, Auth::user());
        $this->selectedRunId = $run->id;
        $this->notify('Run payroll siap direview');
    }

    public function selectRun(int $id): void
    {
        Gate::authorize('manage-payroll');
        $this->selectedRunId = PayrollRun::query()->findOrFail($id)->id;
    }

    public function recalculate(): void
    {
        app(PayrollRunService::class)->recalculate($this->selected(), Auth::user());
        $this->notify('Hitungan payroll diperbarui');
    }

    public function approve(): void
    {
        app(PayrollRunService::class)->approve($this->selected(), Auth::user());
        $this->notify('Payroll sudah di-approve');
    }

    public function retrySlips(): void
    {
        app(PayrollRunService::class)->approve($this->selected(), Auth::user());
        $this->notify('Slip PDF sudah dicek');
    }

    public function markPaid(): void
    {
        Gate::authorize('manage-payroll');
        $data = $this->validate([
            'paymentDate' => ['required', 'date_format:Y-m-d'],
            'paymentAccountId' => ['required', 'integer', 'exists:accounts,id'],
        ]);
        app(PayrollRunService::class)->markPaid($this->selected(), Auth::user(), $data['paymentDate'], (int) $data['paymentAccountId']);
        $this->notify('Payroll sudah ditandai Paid');
    }

    public function export(): BinaryFileResponse
    {
        Gate::authorize('manage-payroll');
        $run = $this->selected();

        return Excel::download(new PayrollRunExport($run), 'rekap-payroll-'.$run->period_start->format('Y-m').'.xlsx');
    }

    public function runs(): array
    {
        Gate::authorize('manage-payroll');

        return PayrollRun::query()->orderByDesc('period_start')->limit(36)->get()->all();
    }

    public function selectedItems(): array
    {
        Gate::authorize('manage-payroll');

        return $this->selectedRunId ? $this->selected()->items()->with('user')->orderBy('user_id')->get()->all() : [];
    }

    public function selectedStatus(): ?string
    {
        Gate::authorize('manage-payroll');

        return $this->selectedRunId ? $this->selected()->status : null;
    }

    public function paymentAccounts(): array
    {
        Gate::authorize('manage-payroll');

        return DB::table('accounts')->whereIn('code', ['1-1100', '1-1200', '1-1300'])->where('is_active', true)
            ->orderBy('code')->get(['id', 'code', 'name'])->all();
    }

    private function selected(): PayrollRun
    {
        Gate::authorize('manage-payroll');

        return PayrollRun::query()->findOrFail($this->selectedRunId);
    }

    private function notify(string $text): void
    {
        Notification::make()->title($text)->success()->send();
    }
}
