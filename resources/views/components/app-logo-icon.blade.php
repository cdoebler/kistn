<svg width="680" height="680" viewBox="0 0 680 680" role="img" xmlns="http://www.w3.org/2000/svg" {{ $attributes }}>
    <mask id="logo-face">
        <rect width="680" height="680" fill="white"/>
        <circle cx="244" cy="344" r="32" fill="black"/>
        <circle cx="436" cy="344" r="32" fill="black"/>
        <path d="M248 448 Q340 524 432 448" stroke="black" stroke-width="36" fill="none" stroke-linecap="round"/>
    </mask>
    <!-- Lid -->
    <rect x="24" y="24" width="632" height="118" rx="28" fill="currentColor"/>
    <!-- Body with face cut out -->
    <rect x="56" y="152" width="568" height="504" rx="34" fill="currentColor" mask="url(#logo-face)"/>
</svg>
