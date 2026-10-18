@php $style = 'display:inline-block; padding:4px 12px; border-radius:6px; background-color:#eef2ff; color:#4338ca; font-size:13px; font-weight:600; font-family:ui-monospace,SFMono-Regular,Menlo,monospace;'; @endphp
@if (isset($url))
    <a href="{{ $url }}" style="{{ $style }} text-decoration:none;">{{ $ecosystem }}</a>
@else
    <span style="{{ $style }}">{{ $ecosystem }}</span>
@endif
