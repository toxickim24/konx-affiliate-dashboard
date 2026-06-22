# KonX Affiliate Dashboard — PDF Export Plan

## Status: Planned (Future Phase)

## Library Comparison

| Library | License | Size | PHP Support | WordPress Compatible | Recommendation |
|---|---|---|---|---|---|
| **Dompdf** | LGPL | ~2MB | 7.1+ | Yes | Recommended |
| TCPDF | LGPL | ~10MB | 5.2+ | Yes | Heavy, complex API |
| MPDF | GPL | ~30MB | 7.0+ | Yes | Very heavy |

## Recommendation: Dompdf

**Why:**
- Smallest footprint (~2MB)
- Active maintenance
- Simple API: render HTML/CSS to PDF
- Good UTF-8 support
- Composer installable: `composer require dompdf/dompdf`

**Limitations:**
- Limited CSS3 support (no flexbox, grid)
- Tables and basic styling only
- No JavaScript execution
- Large tables may be slow

## Planned Export Types

1. **Affiliate Commission Report** — per-affiliate PDF with commission history
2. **Monthly Summary Report** — all commissions/withdrawals for a date range
3. **Withdrawal Receipt** — individual receipt for completed withdrawals
4. **Milestone Certificate** — milestone bonus achievement record

## Security

- Exports should require `manage_konx_commissions` capability
- Nonce protection on export URLs
- No public-facing PDF generation
- Temporary files cleaned up immediately

## Implementation Notes

- Use HTML templates that work with Dompdf's CSS subset
- Use `<table>` layouts (not flexbox/grid)
- Include plugin logo as base64 in template
- Set paper size to Letter (8.5x11)
- Use `stream()` for direct download (no temp files)

## Performance

- Small reports (<100 rows): <1 second
- Large reports (1000+ rows): consider pagination or background generation
- Memory limit: may need 256MB for large PDFs

## Timeline

Implement after Phase 19 is stable. Estimated: 1-2 days of development.
