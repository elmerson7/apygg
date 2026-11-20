<x-filament-panels::page>
    @php($state = $this->state())

    <x-filament::section>
        @if ($state === null)
            <p>La API está <strong>operativa</strong>.</p>
        @else
            <p>La API está en <strong>mantenimiento</strong> desde {{ $state['since'] }} (origen: {{ $state['source'] }}).</p>
            @if ($state['message'])
                <p>Mensaje: {{ $state['message'] }}</p>
            @endif
            @if ($state['until'])
                <p>Fin estimado: {{ $state['until'] }}</p>
            @endif
            @if ($state['source'] === 'env')
                <p>Forzado por entorno (MAINTENANCE_ENABLED): no se apaga desde aquí.</p>
            @endif
        @endif
    </x-filament::section>
</x-filament-panels::page>
