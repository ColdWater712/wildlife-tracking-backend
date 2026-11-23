<?php

namespace App\Services\Ai;

use App\Models\AnimalEmbedding;
use App\Models\StaffFace;
use Illuminate\Support\Collection;

/**
 * Mencocokkan wajah dari kamera dengan galeri wajah staf (SFace, kemiripan kosinus).
 *
 * Ambang dikalibrasi (ai-service/training/README.md):
 *  - >= face_threshold (0,40)          -> staff          : nama staf
 *  - >= face_possible_threshold (0,30) -> possible_staff : "Kemungkinan <nama>", tetap diperlakukan tidak dikenal
 *  - lainnya                           -> unknown        : "Tidak dikenal / pengunjung"
 */
class FaceMatcher
{
    public const UNKNOWN_LABEL = 'Tidak dikenal / pengunjung';

    /** @var Collection<string, array>|null nama => daftar vektor */
    private ?Collection $gallery = null;

    public function gallery(): Collection
    {
        return $this->gallery ??= StaffFace::query()->get(['staff_face_id', 'name', 'user_id', 'vector'])
            ->groupBy('name')
            ->map(fn (Collection $rows) => [
                'user_id' => $rows->first()->user_id,
                'vectors' => $rows->map(fn (StaffFace $r) => (array) $r->vector)->all(),
            ]);
    }

    /**
     * @param  array<int, array{embedding?: array, bbox?: array, confidence?: float, time_seconds?: float}>  $faces
     * @return array<int, array> hasil per wajah, tanpa embedding
     */
    public function match(array $faces): array
    {
        $known = (float) config('services.ai_vision.face_threshold', 0.40);
        $possible = (float) config('services.ai_vision.face_possible_threshold', 0.30);
        $gallery = $this->gallery();

        return collect($faces)->filter(fn ($f) => ! empty($f['embedding']))->map(function (array $face) use ($gallery, $known, $possible) {
            $bestName = null;
            $bestScore = -1.0;
            $bestUser = null;
            foreach ($gallery as $name => $entry) {
                foreach ($entry['vectors'] as $vector) {
                    $s = AnimalEmbedding::similarity($face['embedding'], $vector);
                    if ($s > $bestScore) {
                        [$bestScore, $bestName, $bestUser] = [$s, $name, $entry['user_id']];
                    }
                }
            }

            $status = match (true) {
                $bestName !== null && $bestScore >= $known => 'staff',
                $bestName !== null && $bestScore >= $possible => 'possible_staff',
                default => 'unknown',
            };

            return [
                'status' => $status,
                'label' => match ($status) {
                    'staff' => $bestName,
                    'possible_staff' => "Kemungkinan {$bestName} (perlu verifikasi)",
                    default => self::UNKNOWN_LABEL,
                },
                'name' => $status === 'unknown' ? null : $bestName,
                'user_id' => $status === 'staff' ? $bestUser : null,
                'similarity' => $bestName !== null ? round($bestScore, 4) : null,
                'bbox' => $face['bbox'] ?? null,
                'detection_confidence' => isset($face['confidence']) ? round((float) $face['confidence'], 4) : null,
                'time_seconds' => $face['time_seconds'] ?? null,
            ];
        })->values()->all();
    }
}
