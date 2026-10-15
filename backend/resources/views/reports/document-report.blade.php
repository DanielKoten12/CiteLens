<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Pemeriksaan Referensi</title>
    <style>
        * { box-sizing: border-box; }

        body {
            font-family: DejaVu Sans, Helvetica, Arial, sans-serif;
            font-size: 10px;
            color: #1f2937;
            margin: 0;
        }

        h1 { font-size: 18px; margin: 0 0 2px; }
        h2 { font-size: 13px; margin: 18px 0 6px; border-bottom: 1px solid #d1d5db; padding-bottom: 3px; }
        h3 { font-size: 11px; margin: 10px 0 4px; }

        .muted { color: #6b7280; }
        .section { page-break-inside: avoid; }

        table { width: 100%; border-collapse: collapse; margin-top: 4px; }
        th, td { border: 1px solid #d1d5db; padding: 4px 6px; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; font-weight: bold; }
        tr { page-break-inside: avoid; }

        .summary td:first-child { width: 55%; }
        .summary td:last-child { text-align: right; width: 45%; }

        .footer { margin-top: 20px; border-top: 1px solid #d1d5db; padding-top: 6px; }
    </style>
</head>
<body>
    <h1>Laporan Pemeriksaan Referensi</h1>
    <p class="muted">{{ $payload->documentName }}</p>

    <div class="section">
        <h2>Informasi Dokumen</h2>
        <table class="summary">
            <tr>
                <td>Nama dokumen</td>
                <td>{{ $payload->documentName }}</td>
            </tr>
            <tr>
                <td>Tanggal unggah</td>
                <td>{{ $payload->documentCreatedAt->format('d/m/Y H:i') }}</td>
            </tr>
            <tr>
                <td>Analisis selesai</td>
                <td>{{ $payload->analysisCompletedAt?->format('d/m/Y H:i') ?? '-' }}</td>
            </tr>
            <tr>
                <td>Laporan dibuat</td>
                <td>{{ $payload->generatedAt->format('d/m/Y H:i') }}</td>
            </tr>
        </table>
    </div>

    <div class="section">
        <h2>Ringkasan Analisis</h2>

        <h3>Referensi</h3>
        <table class="summary">
            <tr><td>Total referensi</td><td>{{ $payload->summary->totalReferences }}</td></tr>
            <tr><td>Valid</td><td>{{ $payload->summary->valid }}</td></tr>
            <tr><td>Perlu ditinjau</td><td>{{ $payload->summary->suspicious }}</td></tr>
            <tr><td>Tidak valid</td><td>{{ $payload->summary->invalid }}</td></tr>
            <tr><td>Tidak ditemukan</td><td>{{ $payload->summary->notFound }}</td></tr>
        </table>

        <h3>Sitasi</h3>
        <table class="summary">
            <tr><td>Total sitasi</td><td>{{ $payload->summary->totalCitations }}</td></tr>
            <tr><td>Valid</td><td>{{ $payload->summary->validCitations }}</td></tr>
            <tr><td>Tidak dapat diandalkan</td><td>{{ $payload->summary->unreliableCitations }}</td></tr>
            <tr><td>Menunggu</td><td>{{ $payload->summary->pendingCitations }}</td></tr>
            <tr><td>Belum tertaut</td><td>{{ $payload->summary->unresolvedCitations }}</td></tr>
            <tr><td>Tidak ada referensi</td><td>{{ $payload->summary->hallucinationCitations }}</td></tr>
        </table>
    </div>

    <div class="section">
        <h2>Daftar Referensi</h2>

        @if ($payload->references === [])
            <p class="muted">Tidak ada referensi yang terdeteksi pada dokumen ini.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th style="width: 24px;">#</th>
                        <th>Referensi</th>
                        <th style="width: 90px;">Status</th>
                        <th style="width: 55px;">Keyakinan</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($payload->references as $index => $reference)
                        <tr>
                            <td>{{ $index + 1 }}</td>
                            <td>
                                {{ $reference->title ?? $reference->rawText ?? '-' }}
                                @if ($reference->authors !== null)
                                    <br><span class="muted">{{ $reference->authors }}</span>
                                @endif
                                @if ($reference->publicationName !== null || $reference->publicationYear !== null)
                                    <br><span class="muted">{{ $reference->publicationName ?? '-' }} ({{ $reference->publicationYear ?? '-' }})</span>
                                @endif
                                @if ($reference->doi !== null)
                                    <br><span class="muted">DOI: {{ $reference->doi }}</span>
                                @endif
                                @if ($reference->reason !== null)
                                    <br><span class="muted">Catatan: {{ $reference->reason }}</span>
                                @endif
                            </td>
                            <td>{{ $reference->status->label() }}</td>
                            <td>{{ $reference->confidence === null ? '-' : number_format($reference->confidence * 100, 1).'%' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="section">
        <h2>Masalah Sitasi</h2>

        @if ($payload->citationIssues === [])
            <p class="muted">Tidak ada masalah sitasi yang terdeteksi.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th style="width: 24px;">#</th>
                        <th>Sitasi</th>
                        <th style="width: 100px;">Status</th>
                        <th>Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($payload->citationIssues as $index => $citation)
                        <tr>
                            <td>{{ $index + 1 }}</td>
                            <td>
                                {{ $citation->citationText ?? '-' }}
                                @if ($citation->referenceLabel !== null)
                                    <br><span class="muted">Tertaut ke: {{ $citation->referenceLabel }}</span>
                                @endif
                            </td>
                            <td>{{ $citation->status->label() }}</td>
                            <td>{{ $citation->message }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <p class="footer muted">
        Hasil pemeriksaan ini dihasilkan otomatis oleh sistem dan dapat memerlukan peninjauan manual.
    </p>
</body>
</html>
