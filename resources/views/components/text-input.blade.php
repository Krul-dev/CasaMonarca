@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'w-full rounded-[var(--r-md)] border px-4 py-3 text-sm focus:outline-none transition-colors duration-150',
    'style' => 'border-color: var(--cream-300); background: var(--paper); color: var(--ink-900); font-family: var(--font-body);']) }}
    onfocus="this.style.borderColor='var(--brand-orange)'"
    onblur="this.style.borderColor='var(--cream-300)'">
