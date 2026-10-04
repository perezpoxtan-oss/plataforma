{{-- Resultado de la última operación (mensajes en sesión, nunca en la URL) --}}
@if (session('ok'))
    <div class="alert alert-success aviso mb-3" role="status"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> {{ session('ok') }}</div>
@endif
@if (session('aviso'))
    <div class="alert alert-warning aviso mb-3" role="status"><i class="bi bi-info-circle-fill" aria-hidden="true"></i> {{ session('aviso') }}</div>
@endif
@if (session('error'))
    <div class="alert alert-danger aviso mb-3" role="alert"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> {{ session('error') }}</div>
@endif
@if ($errors->any())
    <div class="alert alert-danger aviso mb-3" role="alert">
        <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
        <div>@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
    </div>
@endif
