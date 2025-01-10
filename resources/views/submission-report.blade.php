<x-filament-panels::page>
	<div class="">
		<x-filament::section>
			<form wire:submit='submit'>
				<div class="space-y-4">
					{{ $this->form }}
					<x-filament::button type="submit">
						Export
					</x-filament::button>
				</div>
			</form>
		</x-filament::section>
	</div>
</x-filament-panels::page>