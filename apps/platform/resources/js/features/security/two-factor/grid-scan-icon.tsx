import { ScanLine } from 'lucide-react';

const GRID_LINES = ['1', '2', '3', '4', '5'] as const;

/**
 * Decorative QR-scan glyph for the two-factor setup dialog header.
 */
export default function GridScanIcon() {
    return (
        <div className="border-border bg-card mb-3 rounded-full border p-0.5 shadow-sm">
            <div className="border-border bg-muted relative overflow-hidden rounded-full border p-2.5">
                <div className="absolute inset-0 grid grid-cols-5 opacity-50">
                    {GRID_LINES.map((line) => (
                        <div
                            key={`grid-col-${line}`}
                            className="border-border border-r last:border-r-0"
                        />
                    ))}
                </div>
                <div className="absolute inset-0 grid grid-rows-5 opacity-50">
                    {GRID_LINES.map((line) => (
                        <div
                            key={`grid-row-${line}`}
                            className="border-border border-b last:border-b-0"
                        />
                    ))}
                </div>
                <ScanLine className="text-foreground relative z-20 size-6" />
            </div>
        </div>
    );
}
