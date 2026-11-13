# Blog cover images: sources, licences and credits

Owner request (2026-11-13): give every blog article a real cover image sourced from a
public repository. All six images below come from **Wikimedia Commons**, are stored
locally in `public/assets/img/blog/` (no hotlinking or CDN, so the strict CSP and offline
PWA behaviour are unchanged), and were cropped to 16:9 and downscaled to a light
**960×540 WebP** (each file is 50–90 KB) so the blog index can show them as compact
thumbnails instead of full-bleed heroes.

| Post slug | File | Creator | Licence | Source page |
|---|---|---|---|---|
| `digital-visitor-passes-vs-paper-registers` | `digital-visitor-passes-vs-paper-registers.webp` | Alex Proimos | CC BY 2.0 | [Security on Guard](https://commons.wikimedia.org/wiki/File:Security_on_Guard_(3993877931).jpg) |
| `true-cost-of-queues-at-the-gate` | `true-cost-of-queues-at-the-gate.webp` | JacobSanchez295 | CC0 1.0 (public domain dedication) | [Duta Bukit Mas Entrance](https://commons.wikimedia.org/wiki/File:Duta_Bukit_Mas_Entrance.jpg) |
| `automated-access-rules-explained` | `automated-access-rules-explained.webp` | Donald Trung Quoc Don | CC BY-SA 4.0 | [Keycard panel at the Holiday Inn, Voorburg (2022)](https://commons.wikimedia.org/wiki/File:Keycard_panel_at_the_Holiday_Inn,_Voorburg_(2022)_02.jpg) |
| `frictionless-payment-collection-for-estates` | `frictionless-payment-collection-for-estates.webp` | Fiona Graham | CC BY-SA 2.0 | [M-PESA mobile money and Equity agent, Nairobi, Kenya](https://commons.wikimedia.org/wiki/File:M-PESA_mobile_money_and_Equity_agent,_Nairobi,_Kenya.jpg) |
| `qr-plates-and-boom-barriers` | `qr-plates-and-boom-barriers.webp` | ParkingBOXXSolutions | CC BY 4.0 | [Parking boom barrier gate arm closed commercial entrance](https://commons.wikimedia.org/wiki/File:Parking_boom_barrier_gate_arm_closed_commercial_entrance.jpg) |
| `real-time-logs-and-blacklisting-playbook` | `real-time-logs-and-blacklisting-playbook.webp` | WrS.tm.pl | CC0 1.0 (public domain dedication) | [CCTV camera on the Jerozolimska and Polna corner](https://commons.wikimedia.org/wiki/File:CCTV_camera_on_the_Jerozolimska_and_Polna_corner.jpg) |

## Attribution notes

- Each post page shows the credit line under the image, e.g.
  "Photo: Alex Proimos, CC BY 2.0, via Wikimedia Commons (cropped and resized)."
- **CC BY / CC BY-SA** images require attribution; keep the credit line intact. The two
  CC BY-SA images are adaptations (cropped/resized) and remain available under the same
  licence (CC BY-SA 4.0 / CC BY-SA 2.0 respectively).
- **CC0** images are public-domain dedications and need no attribution, but the credit is
  shown for transparency.
- Metadata is wired in `app/Modules/Marketing/BlogController.php` (`IMAGES` map); the alt
  text is descriptive for accessibility and image SEO, and `og:image` uses the same local
  file for social sharing.
