{{-- $label: string, $items: array of [label, route, active, icon, badge?, badge_warn?] --}}

<div style="margin-bottom:4px;padding:0 10px;">
    <p style="font-size:10px;font-family:var(--font-display);font-weight:700;
               letter-spacing:0.14em;text-transform:uppercase;
               color:oklch(45% 0.012 50);padding:8px 8px 4px;">
        {{ $label }}
    </p>

    @foreach($items as $item)
    @php
        $isActive   = $item['active'] ?? false;
        $badge      = $item['badge'] ?? 0;
        $badgeWarn  = $item['badge_warn'] ?? false;
    @endphp
    <a href="{{ route($item['route']) }}"
       style="display:flex;align-items:center;gap:9px;padding:8px 10px;
              border-radius:var(--r-sm);font-size:13px;font-weight:500;
              text-decoration:none;margin-bottom:1px;transition:background .12s;
              {{ $isActive
                    ? 'background:oklch(30% 0.022 50);color:var(--brand-orange);font-weight:600;'
                    : 'color:oklch(68% 0.012 50);' }}"
       onmouseover="if(!{{ $isActive ? 'true' : 'false' }}) this.style.background='oklch(28% 0.018 50)', this.style.color='var(--cream-200)'"
       onmouseout="if(!{{ $isActive ? 'true' : 'false' }}) this.style.background='transparent', this.style.color='oklch(68% 0.012 50)'">

        {{-- Active indicator --}}
        <span style="width:3px;height:16px;border-radius:999px;flex-shrink:0;
                     background:{{ $isActive ? 'var(--brand-orange)' : 'transparent' }};"></span>

        {{-- Icon --}}
        <svg style="width:15px;height:15px;flex-shrink:0;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            {!! $item['icon'] !!}
        </svg>

        {{-- Label --}}
        <span style="flex:1;">{{ $item['label'] }}</span>

        {{-- Numeric badge --}}
        @if($badge > 0)
            <span style="min-width:18px;height:18px;border-radius:999px;padding:0 5px;
                         background:var(--brand-orange-deep);color:var(--cream-50);
                         font-size:10px;font-weight:700;display:flex;align-items:center;
                         justify-content:center;flex-shrink:0;font-family:var(--font-display);">
                {{ $badge > 99 ? '99+' : $badge }}
            </span>
        @elseif($badgeWarn)
            <span style="width:8px;height:8px;border-radius:999px;
                         background:var(--brand-orange);flex-shrink:0;
                         animation:pulse 2s cubic-bezier(.4,0,.6,1) infinite;"></span>
        @endif
    </a>
    @endforeach
</div>
