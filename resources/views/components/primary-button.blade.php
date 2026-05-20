<button {{ $attributes->merge(['type' => 'submit', 'class' => 'cm-btn cm-btn-primary']) }}>
    {{ $slot }}
</button>
