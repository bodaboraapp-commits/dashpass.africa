<?php

declare(strict_types=1);

/**
 * Inline icon set (decorative; labelled by surrounding text).
 *
 * Material Icons by Google - Apache License 2.0, https://fonts.google.com/icons
 * Standard glyphs use Material Outlined 24x24 path data (fill=currentColor);
 * genuinely custom glyphs remain stroke-based where Material has no fit.
 *
 * Usage: echo \App\Core\View::capture('partials/icon', ['name' => 'home', 'class' => 'ico']);
 * Unknown names fall back to a neutral dot so templates never break.
 */

$name = isset($name) && is_string($name) ? $name : 'dot';
$class = isset($class) && is_string($class) && trim($class) !== '' ? trim($class) : 'ico';

$icons = [
    'receipt' => '<path fill="currentColor" stroke="none" d="M19.5 3.5L18 2l-1.5 1.5L15 2l-1.5 1.5L12 2l-1.5 1.5L9 2 7.5 3.5 6 2 4.5 3.5 3 2v20l1.5-1.5L6 22l1.5-1.5L9 22l1.5-1.5L12 22l1.5-1.5L15 22l1.5-1.5L18 22l1.5-1.5L21 22V2l-1.5 1.5zM19 19.09H5V4.91h14v14.18zM6 15h12v2H6zm0-4h12v2H6zm0-4h12v2H6z"/>',
    'settings' => '<path fill="currentColor" stroke="none" d="M19.43 12.98c.04-.32.07-.64.07-.98 0-.34-.03-.66-.07-.98l2.11-1.65c.19-.15.24-.42.12-.64l-2-3.46c-.09-.16-.26-.25-.44-.25-.06 0-.12.01-.17.03l-2.49 1c-.52-.4-1.08-.73-1.69-.98l-.38-2.65C14.46 2.18 14.25 2 14 2h-4c-.25 0-.46.18-.49.42l-.38 2.65c-.61.25-1.17.59-1.69.98l-2.49-1c-.06-.02-.12-.03-.18-.03-.17 0-.34.09-.43.25l-2 3.46c-.13.22-.07.49.12.64l2.11 1.65c-.04.32-.07.65-.07.98 0 .33.03.66.07.98l-2.11 1.65c-.19.15-.24.42-.12.64l2 3.46c.09.16.26.25.44.25.06 0 .12-.01.17-.03l2.49-1c.52.4 1.08.73 1.69.98l.38 2.65c.03.24.24.42.49.42h4c.25 0 .46-.18.49-.42l.38-2.65c.61-.25 1.17-.59 1.69-.98l2.49 1c.06.02.12.03.18.03.17 0 .34-.09.43-.25l2-3.46c.12-.22.07-.49-.12-.64l-2.11-1.65zm-1.98-1.71c.04.31.05.52.05.73 0 .21-.02.43-.05.73l-.14 1.13.89.7 1.08.84-.7 1.21-1.27-.51-1.04-.42-.9.68c-.43.32-.84.56-1.25.73l-1.06.43-.16 1.13-.2 1.35h-1.4l-.19-1.35-.16-1.13-1.06-.43c-.43-.18-.83-.41-1.23-.71l-.91-.7-1.06.43-1.27.51-.7-1.21 1.08-.84.89-.7-.14-1.13c-.03-.31-.05-.54-.05-.74s.02-.43.05-.73l.14-1.13-.89-.7-1.08-.84.7-1.21 1.27.51 1.04.42.9-.68c.43-.32.84-.56 1.25-.73l1.06-.43.16-1.13.2-1.35h1.39l.19 1.35.16 1.13 1.06.43c.43.18.83.41 1.23.71l.91.7 1.06-.43 1.27-.51.7 1.21-1.07.85-.89.7.14 1.13zM12 8c-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4-1.79-4-4-4zm0 6c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2z"/>',
    'home' => '<path fill="currentColor" stroke="none" d="M12 5.69l5 4.5V18h-2v-6H9v6H7v-7.81l5-4.5M12 3L2 12h3v8h6v-6h2v6h6v-8h3L12 3z"/>',
    'activity' => '<path fill="currentColor" stroke="none" d="M13 3c-4.97 0-9 4.03-9 9H1l3.89 3.89.07.14L9 12H6c0-3.87 3.13-7 7-7s7 3.13 7 7-3.13 7-7 7c-1.93 0-3.68-.79-4.94-2.06l-1.42 1.42C8.27 19.99 10.51 21 13 21c4.97 0 9-4.03 9-9s-4.03-9-9-9zm-1 5v5l4.25 2.52.77-1.28-3.52-2.09V8z"/>',
    'inbox' => '<path fill="currentColor" stroke="none" d="M19 3H4.99c-1.1 0-1.98.9-1.98 2L3 19c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 12h-4c0 1.66-1.35 3-3 3s-3-1.34-3-3H4.99V5H19v10zm-3-5h-2V7h-4v3H8l4 4 4-4z"/>',
    'account' => '<path fill="currentColor" stroke="none" d="M12 6c1.1 0 2 .9 2 2s-.9 2-2 2-2-.9-2-2 .9-2 2-2m0 10c2.7 0 5.8 1.29 6 2H6c.23-.72 3.31-2 6-2m0-12C9.79 4 8 5.79 8 8s1.79 4 4 4 4-1.79 4-4-1.79-4-4-4zm0 10c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>',
    'users' => '<path fill="currentColor" stroke="none" d="M4 13c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm1.13 1.1C4.76 14.04 4.39 14 4 14c-.99 0-1.93.21-2.78.58C.48 14.9 0 15.62 0 16.43V18h4.5v-1.61c0-.83.23-1.61.63-2.29zM20 13c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm4 3.43c0-.81-.48-1.53-1.22-1.85-.85-.37-1.79-.58-2.78-.58-.39 0-.76.04-1.13.1.4.68.63 1.46.63 2.29V18H24v-1.57zM16.24 13.65c-1.17-.52-2.61-.9-4.24-.9-1.63 0-3.07.39-4.24.9C6.68 14.13 6 15.21 6 16.39V18h12v-1.61c0-1.18-.68-2.26-1.76-2.74zM8.07 16c.09-.23.13-.39.91-.69.97-.38 1.99-.56 3.02-.56s2.05.18 3.02.56c.77.3.81.46.91.69H8.07zM12 8c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1m0-2c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>',
    'building' => '<path d="M4.5 21V4.8a1 1 0 0 1 1-1h8.5a1 1 0 0 1 1 1V21"/><path d="M15 9.5h3.5a1 1 0 0 1 1 1V21"/><path d="M3 21h18"/><path d="M8 8h2.5M8 11.5h2.5M8 15h2.5M8 18.5h2.5"/>',
    'gate' => '<path d="M4 20.5V10a8 8 0 0 1 16 0v10.5"/><path d="M3 20.5h18"/><circle cx="12" cy="13.5" r="1.8"/>',
    'vehicle' => '<path fill="currentColor" stroke="none" d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.21.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.85 7h10.29l1.08 3.11H5.77L6.85 7zM19 17H5v-5h14v5z"/><circle cx="7.5" cy="14.5" r="1.5" fill="currentColor" stroke="none"/><circle cx="16.5" cy="14.5" r="1.5" fill="currentColor" stroke="none"/>',
    'car' => '<path fill="currentColor" stroke="none" d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.21.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.85 7h10.29l1.08 3.11H5.77L6.85 7zM19 17H5v-5h14v5z"/><circle cx="7.5" cy="14.5" r="1.5" fill="currentColor" stroke="none"/><circle cx="16.5" cy="14.5" r="1.5" fill="currentColor" stroke="none"/>',
    'ticket' => '<path d="M3 9.2V7a1 1 0 0 1 1-1h16a1 1 0 0 1 1 1v2.2a2.8 2.8 0 0 0 0 5.6V17a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-2.2a2.8 2.8 0 0 0 0-5.6z"/><path d="M14.5 6.4v11.2"/>',
    'clock' => '<circle cx="12" cy="12" r="8.8"/><path d="M12 7.2V12l3.2 2"/>',
    'bell' => '<path fill="currentColor" stroke="none" d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2zm-2 1H8v-6c0-2.48 1.51-4.5 4-4.5s4 2.02 4 4.5v6z"/>',
    'shield' => '<path fill="currentColor" stroke="none" d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.72-7 8.94V12H5V6.3l7-3.11v8.8z"/>',
    'key' => '<circle cx="8.2" cy="8.2" r="3.8"/><path d="m11 11 8.6 8.6"/><path d="m16.2 16.2 2-2"/><path d="m18.4 18.4 2-2"/>',
    'pin' => '<path d="M12 21.2s6.8-5.5 6.8-10.8a6.8 6.8 0 1 0-13.6 0c0 5.3 6.8 10.8 6.8 10.8z"/><circle cx="12" cy="10.2" r="2.4"/>',
    'compass' => '<circle cx="12" cy="12" r="8.8"/><path d="m14.9 9.1-1.6 4.2-4.2 1.6 1.6-4.2z"/><path d="M12 3.2v1.2M12 19.6v1.2M3.2 12h1.2M19.6 12h1.2"/>',
    'nav' => '<path fill="currentColor" stroke="none" d="M17.27 6.73l-4.24 10.13-1.32-3.42-.32-.83-.82-.32-3.43-1.33 10.13-4.23M21 3L3 10.53v.98l6.84 2.65L12.48 21h.98L21 3z"/>',
    'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2M12 19.5v2M4.6 4.6l1.4 1.4M18 18l1.4 1.4M2.5 12h2M19.5 12h2M4.6 19.4 6 18M18 6l1.4-1.4"/>',
    'moon' => '<path d="M20.2 14.6A8.6 8.6 0 0 1 9.4 3.8a8.6 8.6 0 1 0 10.8 10.8z"/>',
    'logout' => '<path d="M9.5 20.5H5.5a1 1 0 0 1-1-1v-15a1 1 0 0 1 1-1h4"/><path d="m16 16.5 4.5-4.5L16 7.5"/><path d="M20.5 12H9.5"/>',
    'phone' => '<path d="M6.4 3.5h2.8l1.5 3.9-1.9 1.4a11.4 11.4 0 0 0 5.4 5.4l1.4-1.9 3.9 1.5v2.8a2.4 2.4 0 0 1-2.6 2.4C10.2 18.2 5.8 13.8 5 7.1a2.4 2.4 0 0 1 1.4-3.6z"/>',
    'check' => '<path d="m5 12.6 4.4 4.4L19 7.4"/>',
    'close' => '<path d="M6 6l12 12M18 6 6 18"/>',
    'alert' => '<circle cx="12" cy="12" r="8.8"/><path d="M12 7.4v5.4"/><path d="M12 16.3h.01"/>',
    'siren' => '<path d="M12 4.6a5.6 5.6 0 0 1 5.6 5.6v3.9l1.6 2.4H4.8l1.6-2.4V10.2A5.6 5.6 0 0 1 12 4.6Z"/><path d="M9.2 19.4h5.6"/><path d="M12 2.2v1.5"/><path d="M4.6 6.1 3.5 5"/><path d="m19.4 6.1 1.1-1.1"/>',
    'info' => '<circle cx="12" cy="12" r="8.8"/><path d="M12 11.2v5"/><path d="M12 7.7h.01"/>',
    'wifi-off' => '<path d="M3 3l18 18"/><path d="M8.6 8.7a7.4 7.4 0 0 1 10.6 1.5"/><path d="M5.2 12.6a10.4 10.4 0 0 1 2.4-2.7"/><path d="M12 18.6h.01"/>',
    'download' => '<path d="M12 3.5v11"/><path d="m7.5 10.5 4.5 4.5 4.5-4.5"/><path d="M4.5 20.5h15"/>',
    'copy' => '<rect x="9" y="9" width="11" height="11" rx="2.2"/><path d="M5.5 15H4.6a1.1 1.1 0 0 1-1.1-1.1V4.6a1.1 1.1 0 0 1 1.1-1.1h9.3a1.1 1.1 0 0 1 1.1 1.1v.9"/>',
    'chevron' => '<path d="m9.5 6 6 6-6 6"/>',
    'arrow-left' => '<path d="M19.5 12h-15"/><path d="m11 18-6-6 6-6"/>',
    'parking' => '<path fill="currentColor" stroke="none" d="M13 3H6v18h4v-6h3c3.31 0 6-2.69 6-6s-2.69-6-6-6zm.2 8H10V7h3.2c1.1 0 2 .9 2 2s-.9 2-2 2z"/>',
    'calendar' => '<rect x="3.6" y="5.2" width="16.8" height="15.6" rx="2.2"/><path d="M8 3.2v4M16 3.2v4M3.6 10.4h16.8"/>',
    'mail' => '<rect x="3.2" y="5.2" width="17.6" height="13.6" rx="2.2"/><path d="m3.8 6.6 8.2 6 8.2-6"/>',
    'lock' => '<rect x="4.6" y="10.2" width="14.8" height="10.2" rx="2.2"/><path d="M8.2 10.2V7.4a3.8 3.8 0 0 1 7.6 0v2.8"/>',
    'sparkle' => '<path d="M12 3.2 13.8 8.7 19.4 10.5 13.8 12.3 12 17.8 10.2 12.3 4.6 10.5 10.2 8.7z"/><path d="M18.4 16.2 19 18l1.8.6-1.8.6-.6 1.8-.6-1.8-1.8-.6 1.8-.6z"/>',
    'refresh' => '<path d="M20.2 11a8.2 8.2 0 0 0-14.6-4.7L3.9 8"/><path d="M4 4v4h4"/><path d="M3.8 13a8.2 8.2 0 0 0 14.6 4.7l1.7-1.7"/><path d="M20 20v-4h-4"/>',
    'list' => '<path d="M4 7h16M4 12h16M4 17h10"/>',
    'grid' => '<rect x="4" y="4" width="7" height="7" rx="1.8"/><rect x="13" y="4" width="7" height="7" rx="1.8"/><rect x="4" y="13" width="7" height="7" rx="1.8"/><rect x="13" y="13" width="7" height="7" rx="1.8"/>',
    'qr' => '<rect x="3.2" y="3.2" width="7" height="7" rx="1.6"/><rect x="13.8" y="3.2" width="7" height="7" rx="1.6"/><rect x="3.2" y="13.8" width="7" height="7" rx="1.6"/><path d="M13.8 13.8h3.2v3.2h-3.2z"/><path d="M20.2 13.8h.6M13.8 20.2h2.6M19.6 20.2h1.2M20.2 17h.6"/>',
    'wallet' => '<rect x="3" y="6" width="18" height="13" rx="2.6"/><path d="M3 10.2h18"/><circle cx="17" cy="14.6" r="1.2"/>',
    'room' => '<path d="M3 20.4h18"/><path d="M4.4 20.4V9.2L12 4.8l7.6 4.4v11.2"/><path d="M9.2 20.4v-4.8h5.6v4.8"/>',
    'bbq' => '<path d="M4.6 9.8h14.8l-2.1 6.2H6.7z"/><path d="M8.2 9.8V7.2a3.8 3.8 0 0 1 7.6 0v2.6"/><path d="M9.2 16v3.4M14.8 16v3.4"/>',
    'gym' => '<path d="M4.2 9.2v5.6M7 7.2v9.6M17 7.2v9.6M19.8 9.2v5.6M7 12h10"/>',
    'court' => '<rect x="3.2" y="5" width="17.6" height="14" rx="2.2"/><path d="M3.2 12h17.6M12 5v14"/>',
    'arrow-up-right' => '<path d="M7 17 17 7"/><path d="M8.4 7H17v8.6"/>',
    'chevron-left' => '<path d="m14.4 6-6 6 6 6"/>',
    'plus' => '<path d="M12 5v14M5 12h14"/>',
    'link' => '<path d="M10.2 13.8a4.2 4.2 0 0 0 6 0l2.4-2.4a4.2 4.2 0 0 0-6-6L11.4 6.6"/><path d="M13.8 10.2a4.2 4.2 0 0 0-6 0l-2.4 2.4a4.2 4.2 0 0 0 6 6l1.2-1.2"/>',
    'dot' => '<circle cx="12" cy="12" r="3.2"/>',
];

$markup = $icons[$name] ?? $icons['dot'];

// Directional affordances get a dedicated, slightly bolder chevron treatment.
if (in_array($name, ['chevron', 'chevron-left'], true) && !str_contains($class, 'ico--chevron')) {
    $class .= ' ico--chevron';
}
?>
<svg class="<?= e($class) ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><?= $markup ?></svg>
