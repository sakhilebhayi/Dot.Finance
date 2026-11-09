@page { size: A4; margin: 14mm 14mm 16mm; }
* { box-sizing: border-box; }
body.sd { margin: 0; color: #14181f; background: #fff; font-family: {!! $pdf ? "'DejaVu Sans', sans-serif" : "system-ui, 'Segoe UI', Arial, sans-serif" !!}; font-size: {{ $pdf ? '8.6pt' : '10pt' }}; line-height: 1.45; }
.sd table { width: 100%; border-collapse: collapse; }
.sd td, .sd th { vertical-align: top; text-align: left; padding: 0; }
.sd h1 { margin: 0; font-size: {{ $pdf ? '19pt' : '22pt' }}; line-height: 1.1; font-weight: 700; color: {{ $brand }}; letter-spacing: 0; }
.sd h2 { margin: 0 0 3px; font-size: 8pt; font-weight: 700; color: #5a6371; }
.sd .muted { color: #5a6371; }
.sd .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
.sd .head td { padding-bottom: 14px; }
.sd .logo { max-height: 56px; max-width: 200px; margin-bottom: 6px; }
.sd .name { font-size: 11pt; font-weight: 700; }
.sd .meta td { padding: 2px 0; }
.sd .meta td:first-child { color: #5a6371; padding-right: 12px; }
.sd .meta td:last-child { text-align: right; font-weight: 600; }
.sd .parties { margin: 6px 0 16px; }
.sd .parties td { width: 50%; padding-right: 16px; }
.sd .banner { margin: 0 0 12px; padding: 6px 10px; border: 1px solid #b0241c; color: #b0241c; font-weight: 700; }
.sd .lines th { padding: 6px 6px 6px 0; border-bottom: 1.5px solid {{ $brand }}; font-size: 8pt; color: #3a424e; }
.sd .lines td { padding: 7px 6px 7px 0; border-bottom: 0.5px solid #d6dae1; }
.sd .lines th.num, .sd .lines td.num { padding-right: 0; padding-left: 8px; }
.sd .lines .sub { color: #5a6371; font-size: 8pt; }
.sd .totals { margin-top: 12px; }
.sd .totals td { padding: 3px 0; }
.sd .totals tr.grand td { padding-top: 7px; border-top: 1.5px solid #14181f; font-size: 11pt; font-weight: 700; }
.sd .vat { margin-top: 14px; }
.sd .vat th, .sd .vat td { padding: 3px 6px 3px 0; font-size: 8pt; border-bottom: 0.5px solid #d6dae1; }
.sd .block { margin-top: 16px; }
.sd .block p { margin: 0 0 3px; white-space: pre-line; }
.sd .foot { margin-top: 22px; padding-top: 8px; border-top: 0.5px solid #aab1bd; color: #5a6371; font-size: 8pt; }
.sd .keep { page-break-inside: avoid; }
