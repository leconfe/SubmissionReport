<x-filament-panels::page>
    <form wire:submit="submit" class="space-y-6">
        {{ $this->form }}
        <x-filament::button type="submit" icon="heroicon-o-arrow-down-tray" wire:loading.attr="disabled" wire:target="submit">
            Export Excel
        </x-filament::button>
    </form>
</x-filament-panels::page>
