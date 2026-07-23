@extends('layouts.app')

@section('title', $statusCode)

@section('content')
<x-card>
    <x-alert type="error">{{ $message }}</x-alert>
    <a href="{{ url()->previous() }}" class="btn">{{ __('ui.common.back') }}</a>
</x-card>
@endsection
