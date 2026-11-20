@php
    // Marca por tokens: ajustes del tenant activo -> plataforma -> config/brand.php (docs/mail-system.md §Marca)
    $brand = app(\App\Contracts\BrandResolverInterface::class)->get();
@endphp
<x-mail::layout>
{{-- Colores de marca sobre el tema por defecto --}}
<x-slot:head>
<style>
.button-primary { background-color: {{ $brand['colors']['primary'] }} !important; border-bottom: 8px solid {{ $brand['colors']['primary'] }} !important; border-left: 18px solid {{ $brand['colors']['primary'] }} !important; border-right: 18px solid {{ $brand['colors']['primary'] }} !important; border-top: 8px solid {{ $brand['colors']['primary'] }} !important; }
a { color: {{ $brand['colors']['link'] }}; }
body, .wrapper { background-color: {{ $brand['colors']['background'] }} !important; }
</style>
</x-slot:head>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="$brand['url']">
@if ($brand['logo_url'])
<img src="{{ $brand['logo_url'] }}" class="logo" alt="{{ $brand['name'] }}" style="max-height: 75px; width: auto;">
@else
{{ $brand['name'] }}
@endif
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ $brand['name'] }}. {{ __('All rights reserved.') }}
@if ($brand['legal_address'])

{{ $brand['legal_address'] }}
@endif
@if ($brand['support_email'])

{{ $brand['support_email'] }}
@endif
@if ($brand['social'])

@foreach ($brand['social'] as $network => $url)
[{{ ucfirst((string) $network) }}]({{ $url }}){{ $loop->last ? '' : ' · ' }}
@endforeach
@endif
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
