@php
    $severityEnum = \App\Enums\SeverityEnum::tryFrom(strtolower($severity));
    $colors = match ($severityEnum) {
        \App\Enums\SeverityEnum::Critical => ['#fee2e2', '#b91c1c'],
        \App\Enums\SeverityEnum::High => ['#ffedd5', '#c2410c'],
        \App\Enums\SeverityEnum::Medium => ['#fef9c3', '#a16207'],
        \App\Enums\SeverityEnum::Low => ['#dcfce7', '#15803d'],
        default => ['#e5e7eb', '#4b5563'],
    };
    $label = $severityEnum?->label() ?? ucfirst($severity);
@endphp
<span style="display:inline-block; padding:2px 8px; border-radius:999px; background-color:{{ $colors[0] }}; color:{{ $colors[1] }}; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.02em;">{{ $label }}</span>
