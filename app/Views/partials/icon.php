<?php

declare(strict_types=1);

/**
 * Inline icon set (decorative; labelled by surrounding text).
 *
 * Usage: echo \App\Core\View::capture('partials/icon', ['name' => 'home', 'class' => 'ico']);
 * Unknown names fall back to a neutral dot so templates never break.
 */

$name = isset($name) && is_string($name) ? $name : 'dot';
$class = isset($class) && is_string($class) && trim($class) !== '' ? trim($class) : 'ico';

$icons = [
    'home' => '<path d="M3.5 10.6 12 3.5l8.5 7.1V20a1 1 0 0 1-1 1h-5v-6h-5v6h-5a1 1 0 0 1-1-1z"/>',
    'activity' => '<path d="M3 12h4l2.4-6.6 5.2 13.2 2.4-6.6H21"/>',
    'inbox' => '<path d="M4.5 4.5h15l2 8.5v5.5a1 1 0 0 1-1 1h-17a1 1 0 0 1-1-1V13z"/><path d="M2.5 13h5.5l1.8 3h4.4l1.8-3h5.5"/>',
    'account' => '<circle cx="12" cy="8" r="3.8"/><path d="M4.8 20.5a7.4 7.4 0 0 1 14.4 0"/>',
    'users' => '<circle cx="9" cy="8" r="3.4"/><path d="M2.8 20a6.2 6.2 0 0 1 12.4 0"/><path d="M16 5.3a3.4 3.4 0 0 1 0 6.4"/><path d="M18 14.4a6 6 0 0 1 3.4 5.6"/>',
    'building' => '<path d="M4.5 21V4.8a1 1 0 0 1 1-1h8.5a1 1 0 0 1 1 1V21"/><path d="M15 9.5h3.5a1 1 0 0 1 1 1V21"/><path d="M3 21h18"/><path d="M8 8h2.5M8 11.5h2.5M8 15h2.5M8 18.5h2.5"/>',
    'gate' => '<path d="M4 20.5V10a8 8 0 0 1 16 0v10.5"/><path d="M3 20.5h18"/><circle cx="12" cy="13.5" r="1.8"/>',
    'car' => '<path d="M5.5 16.5h13"/><path d="M6 16.5v-4.2l1.7-4a1.8 1.8 0 0 1 1.7-1.1h5.2a1.8 1.8 0 0 1 1.7 1.1l1.7 4v4.2"/><path d="M4 12h16"/><circle cx="8" cy="18.2" r="1.5"/><circle cx="16" cy="18.2" r="1.5"/>',
    'ticket' => '<path d="M3 9.2V7a1 1 0 0 1 1-1h16a1 1 0 0 1 1 1v2.2a2.8 2.8 0 0 0 0 5.6V17a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-2.2a2.8 2.8 0 0 0 0-5.6z"/><path d="M14.5 6.4v11.2"/>',
    'clock' => '<circle cx="12" cy="12" r="8.8"/><path d="M12 7.2V12l3.2 2"/>',
    'bell' => '<path d="M6.2 9.4a5.8 5.8 0 0 1 11.6 0c0 4.6 1.9 5.6 1.9 5.6H4.3s1.9-1 1.9-5.6"/><path d="M10 19.6a2.1 2.1 0 0 0 4 0"/>',
    'shield' => '<path d="M12 3.2 5.2 6v6c0 4.4 2.9 7.7 6.8 8.8 3.9-1.1 6.8-4.4 6.8-8.8V6z"/><path d="m9.1 12 2.1 2.1 3.9-4"/>',
    'key' => '<circle cx="8.2" cy="8.2" r="3.8"/><path d="m11 11 8.6 8.6"/><path d="m16.2 16.2 2-2"/><path d="m18.4 18.4 2-2"/>',
    'pin' => '<path d="M12 21.2s6.8-5.5 6.8-10.8a6.8 6.8 0 1 0-13.6 0c0 5.3 6.8 10.8 6.8 10.8z"/><circle cx="12" cy="10.2" r="2.4"/>',
    'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2M12 19.5v2M4.6 4.6l1.4 1.4M18 18l1.4 1.4M2.5 12h2M19.5 12h2M4.6 19.4 6 18M18 6l1.4-1.4"/>',
    'moon' => '<path d="M20.2 14.6A8.6 8.6 0 0 1 9.4 3.8a8.6 8.6 0 1 0 10.8 10.8z"/>',
    'logout' => '<path d="M9.5 20.5H5.5a1 1 0 0 1-1-1v-15a1 1 0 0 1 1-1h4"/><path d="m16 16.5 4.5-4.5L16 7.5"/><path d="M20.5 12H9.5"/>',
    'phone' => '<path d="M6.4 3.5h2.8l1.5 3.9-1.9 1.4a11.4 11.4 0 0 0 5.4 5.4l1.4-1.9 3.9 1.5v2.8a2.4 2.4 0 0 1-2.6 2.4C10.2 18.2 5.8 13.8 5 7.1a2.4 2.4 0 0 1 1.4-3.6z"/>',
    'check' => '<path d="m5 12.6 4.4 4.4L19 7.4"/>',
    'close' => '<path d="M6 6l12 12M18 6 6 18"/>',
    'alert' => '<circle cx="12" cy="12" r="8.8"/><path d="M12 7.4v5.4"/><path d="M12 16.3h.01"/>',
    'info' => '<circle cx="12" cy="12" r="8.8"/><path d="M12 11.2v5"/><path d="M12 7.7h.01"/>',
    'wifi-off' => '<path d="M3 3l18 18"/><path d="M8.6 8.7a7.4 7.4 0 0 1 10.6 1.5"/><path d="M5.2 12.6a10.4 10.4 0 0 1 2.4-2.7"/><path d="M12 18.6h.01"/>',
    'download' => '<path d="M12 3.5v11"/><path d="m7.5 10.5 4.5 4.5 4.5-4.5"/><path d="M4.5 20.5h15"/>',
    'copy' => '<rect x="9" y="9" width="11" height="11" rx="2.2"/><path d="M5.5 15H4.6a1.1 1.1 0 0 1-1.1-1.1V4.6a1.1 1.1 0 0 1 1.1-1.1h9.3a1.1 1.1 0 0 1 1.1 1.1v.9"/>',
    'chevron' => '<path d="m9.5 6 6 6-6 6"/>',
    'arrow-left' => '<path d="M19.5 12h-15"/><path d="m11 18-6-6 6-6"/>',
    'parking' => '<rect x="4" y="4" width="16" height="16" rx="3.2"/><path d="M9.6 16.4V7.8h3.1a2.9 2.9 0 0 1 0 5.8H9.6"/>',
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
?>
<svg class="<?= e($class) ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><?= $markup ?></svg>
