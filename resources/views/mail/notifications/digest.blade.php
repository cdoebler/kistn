@extends('mail.layout')

@section('content')
    <h1 style="margin:0 0 20px; font-size:20px; color:#111827;">{{ __('Daily digest') }}</h1>

    @foreach ($projectSections as $section)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb; border-radius:8px; margin-bottom:20px;">
            <tr>
                <td style="padding:8px 18px; background-color:#f9fafb; border-bottom:1px solid #e5e7eb; border-radius:8px 8px 0 0;">
                    <a href="{{ route('projects.packages', $section['project']) }}" style="font-size:14px; font-weight:700; color:#111827; text-decoration:none;">{{ $section['project']->name }}</a>
                </td>
            </tr>
            <tr>
                <td style="padding:14px 18px;">
                    @foreach ($section['ecosystems'] as $ecosystemSection)
                        <div style="margin-bottom:{{ $loop->last ? '0' : '14px' }};">
                            <div style="margin-bottom:8px;">
                                @include('mail.partials.ecosystem-badge', ['ecosystem' => $ecosystemSection['ecosystem']->value, 'url' => route('projects.packages', ['project' => $section['project'], 'ecosystem' => $ecosystemSection['ecosystem']->value])])
                            </div>

                            @if ($ecosystemSection['outdated'] !== [])
                                <div style="font-size:12px; font-weight:600; color:#6b7280; margin-bottom:4px;">{{ __('Outdated') }} ({{ count($ecosystemSection['outdated']) }})</div>

                                @foreach ($ecosystemSection['outdated'] as $pkg)
                                    <div style="font-size:13px; padding:3px 0;">
                                        <a href="{{ $pkg['url'] }}" style="color:#4338ca; text-decoration:none; font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">{{ $pkg['name'] }}</a>
                                        <span style="color:#6b7280;">: {{ $pkg['current'] }} → {{ $pkg['latest'] }}</span>
                                    </div>
                                @endforeach
                            @endif

                            @if ($ecosystemSection['vuln'] !== [])
                                <div style="font-size:12px; font-weight:600; color:#6b7280; margin:8px 0 4px;">{{ __('Vulnerabilities') }} ({{ count($ecosystemSection['vuln']) }})</div>

                                @foreach ($ecosystemSection['vuln'] as $finding)
                                    <div style="font-size:13px; padding:3px 0;">
                                        <a href="{{ $finding['url'] }}" style="color:#4338ca; text-decoration:none; font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">{{ $finding['package_name'] }} {{ $finding['package_version'] }}</a>
                                        <span style="color:#6b7280;"> — </span>
                                        @if ($finding['advisory_url'] !== null)
                                            <a href="{{ $finding['advisory_url'] }}" style="color:#6b7280; text-decoration:none;">{{ $finding['advisory_id'] }}</a>
                                        @else
                                            <span style="color:#6b7280;">{{ $finding['advisory_id'] }}</span>
                                        @endif
                                        @include('mail.partials.severity-badge', ['severity' => $finding['severity']])
                                    </div>
                                @endforeach
                            @endif
                        </div>
                    @endforeach
                </td>
            </tr>
        </table>
    @endforeach
@endsection
