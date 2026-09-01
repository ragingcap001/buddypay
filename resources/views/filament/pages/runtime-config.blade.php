<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div style="margin-top: 1.5rem; display: flex; justify-content: flex-end;">
            <x-filament::button type="submit">
                Save configuration
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
