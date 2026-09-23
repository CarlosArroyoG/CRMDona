@extends('layouts.public')

@section('title', 'No disponible')

@section('content')
    <section class="rounded-lg bg-white p-6 shadow-sm" aria-labelledby="titulo">
        <h1 id="titulo" class="text-xl font-semibold" style="color: var(--brand)">Donativos no disponibles</h1>
        <p class="mt-3">{{ $message }}</p>
        <p class="mt-4"><a href="{{ route('donate.create') }}" class="underline">Ir a la página de donativos</a></p>
    </section>
@endsection
