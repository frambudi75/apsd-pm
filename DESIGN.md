# Design Direction - APSD PM Report System

> antislop active: during (session override)

## Brand Identity & Personality
- Product: Sistem Preventive Maintenance (PM) Checklist & Laporan PDF Otomatis
- Organization: PT Angkasa Pura Sarana Digital (APSD) / PT IAS Support Indonesia
- Partner / Operator: Telkomsel & Tower Providers
- Audience: Teknisi lapangan (mobile users), Koordinator PM / Team Leader, dan Manajemen Operasional (desktop users)
- Tone: Fungsional, tangguh (industrial), rapi, akurat, dan berstandar teknis tinggi

## Dial Settings
- ENERGY: 1 (Calm, utilitarian, efisien untuk kerja lapangan tanpa distraksi)
- RHYTHM: 2 (Konsisten dengan pembagian section formulir dan kartu status)
- MOTION: 1 (Transisi ringan dan fungsional pada tab/accordion, tanpa animasi berlebihan)

## Palette
- Primary Brand: Teal & Cyan Blue (`#008080`, `#0284c7`, `#0369a1`)
- Deep Neutral / Slate: `#0f172a`, `#1e293b`, `#334155`
- Surface / Background: `#f8fafc`, `#ffffff`, `#f1f5f9`
- Accent / Alert:
  - OK / Normal: Emerald Green (`#059669`, `#10b981`)
  - NOK / Warning: Crimson Red / Rose (`#e11424`, `#dc2626`)
  - N/A / Neutral: Muted Slate (`#64748b`, `#94a3b8`)
- Telkomsel Brand Accent: `#e11424` (digunakan pada logo dan header laporan Telkomsel)

## Typography
- Font Family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif
- Hierarchy:
  - Header: Bold 600/700, tracking tight
  - Body & Form: Medium 400/500, line-height 1.5, ukuran ideal untuk input sentuh (minimal 14-16px di mobile)
  - PDF Print: Monospaced / Sans-serif kompak presisi cetak (Arial / Helvetica / Inter)

## Mobile & Touch Rules
- Tap targets: Minimal 44px x 44px untuk semua tombol aksi, radio button status, dan upload gambar
- Layout: 1 kolom di mobile (< 768px), 2-3 kolom di desktop (>= 1024px)
- Zero horizontal overflow
