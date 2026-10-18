<x-filament-widgets::widget>
    <div class="sc-dashboard-welcome">
        <div>
            <p class="sc-dashboard-welcome__date">{{ $this->dateLabel() }}</p>
            <h1>{{ $this->greeting() }}</h1>
            <p class="sc-dashboard-welcome__summary">Ringkasan operasional armada Coffee Bike hari ini.</p>
        </div>
        @if ($this->canExport())
            <a class="sc-dashboard-welcome__export" href="{{ \App\Filament\Pages\Reports::getUrl() }}">Laporan &amp; Ekspor</a>
        @endif
    </div>
</x-filament-widgets::widget>
