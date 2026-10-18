@extends('mail.layout')

@section('content')
    <h1 style="margin:0 0 20px; font-size:20px; color:#111827;">{{ __('Daily digest') }}</h1>
    <p style="font-size:14px; color:#374151;">{{ __('No changes to report today — none of your projects had new updates or vulnerabilities since your last digest.') }}</p>
@endsection
