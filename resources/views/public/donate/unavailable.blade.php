@extends('layouts.public')

@section('title', 'No disponible')

@section('content')
    <section aria-labelledby="titulo">
        <h1 id="titulo" class="text-xl font-semibold text-db-navy">Donativos no disponibles</h1>
        <p class="mt-3 text-db-text">{{ $message }}</p>
        <p class="mt-4"><a href="{{ route('donate.create') }}" class="font-medium text-db-blue underline underline-offset-2">Ir a la página de donativos</a></p>
    </section>
@endsection
