<?php
// includes/icons.php
// Clean stroke-based SVG vector icon library

if (!function_exists('icon')) {
    function icon(string $name, int $size = 18, string $extraClass = '', string $extraStyle = ''): string {
        static $paths = null;
        if ($paths === null) {
            $paths = [
                // Navigation & Core
                'home'        => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
                'order'       => '<path d="M3 2h18M3 6h18M21 12H3M3 16h10"/><circle cx="17" cy="18" r="3"/>',
                'pending'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
                'inventory'   => '<path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/>',
                'history'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/><path d="M3 3v6h6"/>',
                'menu'        => '<path d="M4 19V5a2 2 0 012-2h4a2 2 0 012 2v14"/><path d="M14 19V9a2 2 0 012-2h2a2 2 0 012 2v10"/><path d="M2 19h20"/>',
                'analytics'   => '<path d="M3 3v18h18"/><rect x="7" y="12" width="3" height="6"/><rect x="12" y="8" width="3" height="10"/><rect x="17" y="5" width="3" height="13"/>',
                'dashboard'   => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
                'portal'      => '<path d="M3 21h18"/><path d="M5 21V9l7-5 7 5v12"/><path d="M10 21v-6h4v6"/>',
                'logout'      => '<path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
                'chevron'     => '<path d="M9 6l6 6-6 6"/>',
                'chevron-down'=> '<polyline points="6 9 12 15 18 9"/>',
                'chevron-up'  => '<polyline points="18 15 12 9 6 15"/>',
                'menu-lines'  => '<line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>',
                'cart'        => '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>',
                'shopping-cart'=> '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>',
                'credit-card' => '<rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/>',
                'bar-chart'   => '<line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/>',
                'shield'      => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
                'shield-check'=> '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/>',

                // HR, Workforce & Recruitment
                'employees'   => '<circle cx="9" cy="8" r="3.2"/><path d="M2.5 20c0-3.6 2.9-6.4 6.5-6.4s6.5 2.8 6.5 6.4"/><circle cx="17.5" cy="9" r="2.4"/><path d="M15.7 13.6c2.6.4 4.6 2.6 4.8 5.4"/>',
                'users'       => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
                'user-plus'   => '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/>',
                'user-check'  => '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><polyline points="17 11 19 13 23 9"/>',
                'requests'    => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 7h8M8 11h8M8 15h5"/>',
                'attendance'  => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><path d="M9 15l2 2 4-4"/>',
                'leave'       => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><path d="M8 15h3M8 18h6"/>',
                'permissions' => '<path d="M12 2l7 3.5v5.4c0 4.7-3 8.9-7 10.1-4-1.2-7-5.4-7-10.1V5.5L12 2z"/><path d="M9.5 12l1.8 1.8L15 10"/>',
                'briefcase'   => '<rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
                'clipboard'   => '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/>',
                'mic'         => '<path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/>',
                'award'       => '<circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/>',
                'scale'       => '<path d="M12 3v18"/><path d="M5 7h14"/><path d="M5 7l-3 6a3 3 0 0 0 6 0z"/><path d="M19 7l-3 6a3 3 0 0 0 6 0z"/>',

                // Actions & Controls
                'plus'        => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
                'edit'        => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
                'trash'       => '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
                'eye'         => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
                'eye-off'     => '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>',
                'save'        => '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>',
                'lock'        => '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
                'unlock'      => '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/>',
                'key'         => '<path d="M21 2l-2 2m-1.5 1.5L14 9l-1.5-1.5L11 9l-1.5-1.5L8 9a5 5 0 1 0 7 7l6-6v-4h-4z"/>',
                'search'      => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
                'download'    => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
                'external-link'=> '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>',
                'printer'     => '<polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>',
                'refresh'     => '<polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>',
                'globe'       => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
                'message'     => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
                'check'       => '<polyline points="20 6 9 17 4 12"/>',
                'x'           => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',

                // Status & Indicators
                'check-circle'=> '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
                'alert-triangle' => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
                'x-circle'    => '<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>',
                'info'        => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
                'help'        => '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
                'clock'       => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
                'calendar'    => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
                'dot-open'    => '<circle cx="12" cy="12" r="6" fill="currentColor"/>',
                'dot-filled'  => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3" fill="currentColor"/>',

                // Procurement & Finance
                'rfq'         => '<path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/>',
                'truck'       => '<path d="M3 7h11v9H3z"/><path d="M14 10h4l3 3v3h-7z"/><circle cx="7" cy="18" r="1.6"/><circle cx="17.5" cy="18" r="1.6"/>',
                'invoice'     => '<path d="M6 2h12v20l-3-2-3 2-3-2-3 2z"/><path d="M9 7h6M9 11h6M9 15h4"/>',
                'card'        => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
                'star'        => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
                'coin'        => '<circle cx="12" cy="12" r="9"/><path d="M9.2 15.4c.5.9 1.5 1.5 2.8 1.5 1.8 0 3-1 3-2.3 0-3.2-5.6-1.7-5.6-4.9 0-1.3 1.2-2.3 3-2.3 1.2 0 2.2.5 2.7 1.4M12 6.4v1.2M12 16.4v1.2"/>',
                'dollar'      => '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
                'package'     => '<line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>',
                'bell'        => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/>',
                'pin'         => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
                'tag'         => '<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>',
                'send'        => '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',

                // Café & Categories
                'coffee'      => '<path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/>',
                'ice-coffee'  => '<path d="M7 2h10l1 18a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L7 2z"/><line x1="5" y1="6" x2="19" y2="6"/><path d="M10 10h4"/><path d="M9 14h6"/><line x1="12" y1="1" x2="12" y2="4"/>',
                'cup-tea'     => '<path d="M6 3h12l-1.5 16a2 2 0 0 1-2 1.8H9.5A2 2 0 0 1 7.5 19L6 3z"/><line x1="10" y1="7" x2="14" y2="7"/><circle cx="10" cy="14" r="1.2" fill="currentColor"/><circle cx="14" cy="14" r="1.2" fill="currentColor"/><circle cx="12" cy="17" r="1.2" fill="currentColor"/>',
                'fruit'       => '<circle cx="12" cy="14" r="7"/><path d="M12 7V3"/><path d="M12 3c3 0 5 1.5 5 4"/><circle cx="10" cy="13" r="1"/><circle cx="14" cy="13" r="1"/>',
                'pastry'      => '<path d="M12 2C6.5 2 2 6.5 2 12c0 2.5 1 4.5 2.5 6l1.5-1.5C5 15.5 4.5 14 4.5 12 4.5 7.8 7.8 4.5 12 4.5s7.5 3.3 7.5 7.5c0 2-.5 3.5-1.5 4.5l1.5 1.5c1.5-1.5 2.5-3.5 2.5-6 0-5.5-4.5-10-10-10z"/><circle cx="9" cy="11" r="1.2" fill="currentColor"/><circle cx="15" cy="11" r="1.2" fill="currentColor"/><path d="M9 15c1.5 1.5 4.5 1.5 6 0"/>',
                'milk'        => '<path d="M8 2h8v4H8z"/><path d="M6 6h12v15a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V6z"/><line x1="6" y1="11" x2="18" y2="11"/>',
                'cake'        => '<path d="M20 21H4a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2z"/><path d="M4 13V8a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v5"/><line x1="12" y1="6" x2="12" y2="2"/>',
                'utensils'    => '<path d="M18 2v20M21 2v6a3 3 0 0 1-3 3M14 2v6a3 3 0 0 0 3 3M3 2v7c0 1.1.9 2 2 2h2c1.1 0 2-.9 2-2V2M6 11v11"/>',
                'shopping-bag'=> '<path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/>',
                'car'         => '<rect x="1" y="7" width="22" height="11" rx="2"/><path d="M5 7l2-4h10l2 4"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',

                // Miscellaneous UI
                'sun'         => '<circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>',
                'camera'      => '<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/>',
                'sparkles'    => '<path d="M12 3l1.912 4.908L19 9.824l-4.088 1.912L13 16.644l-1.912-4.908L7 9.824l4.088-1.912z"/><path d="M19 16l.956 2.454L22.5 19.5l-2.544.956L19 23l-.956-2.544L15.5 19.5l2.544-.956z"/>',
                'database'    => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>',
                'building'    => '<rect x="4" y="2" width="16" height="20" rx="2" ry="2"/><line x1="9" y1="22" x2="9" y2="22.01"/><line x1="15" y1="22" x2="15" y2="22.01"/><line x1="8" y1="6" x2="8.01" y2="6"/><line x1="12" y1="6" x2="12.01" y2="6"/><line x1="16" y1="6" x2="16.01" y2="6"/><line x1="8" y1="10" x2="8.01" y2="10"/><line x1="12" y1="10" x2="12.01" y2="10"/><line x1="16" y1="10" x2="16.01" y2="10"/><line x1="8" y1="14" x2="8.01" y2="14"/><line x1="12" y1="14" x2="12.01" y2="14"/><line x1="16" y1="14" x2="16.01" y2="14"/><line x1="8" y1="18" x2="8.01" y2="18"/><line x1="12" y1="18" x2="12.01" y2="18"/><line x1="16" y1="18" x2="16.01" y2="18"/>',
                'inbox'       => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
                'file-text'   => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>',
                'bot'         => '<rect x="3" y="11" width="18" height="10" rx="2"/><circle cx="12" cy="5" r="2"/><path d="M12 7v4M8 16h.01M16 16h.01"/>',
                'folder'      => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
                'link'        => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
                'coffee-pot'  => '<path d="M5 4h14v16a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V4z"/><path d="M19 8h2a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2"/><line x1="3" y1="4" x2="21" y2="4"/>',
                'zap'         => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
                'flag'        => '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/>',
                'trophy'      => '<path d="M6 9H3a2 2 0 0 0-2 2v1a4 4 0 0 0 4 4h1m12-7h3a2 2 0 0 1 2 2v1a4 4 0 0 1-4 4h-1"/><path d="M6 3h12a2 2 0 0 1 2 2v6a6 6 0 0 1-12 0V5a2 2 0 0 1 2-2z"/><path d="M12 15v5M8 20h8"/>',
                'thumbs-up'   => '<path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"/>',
                'leaf'        => '<path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/><path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"/>',
                'citrus'      => '<circle cx="12" cy="12" r="9"/><line x1="12" y1="12" x2="12" y2="3"/><line x1="12" y1="12" x2="18.36" y2="5.64"/><line x1="12" y1="12" x2="21" y2="12"/>',
            ];
        }

        $body = $paths[$name] ?? ($paths['home'] ?? '');
        $classAttr = $extraClass ? ' class="' . htmlspecialchars($extraClass) . '"' : '';
        $styleAttr = $extraStyle ? ' style="' . htmlspecialchars($extraStyle) . '"' : '';

        return '<svg width="' . $size . '" height="' . $size . '"' . $classAttr . $styleAttr . ' viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $body . '</svg>';
    }
}
