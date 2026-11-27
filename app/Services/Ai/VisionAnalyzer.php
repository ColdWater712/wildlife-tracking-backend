<?php

namespace App\Services\Ai;

use App\Models\Species;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Analisis gambar/video kamera jebak.
 *
 * Bila ai-service visual dikonfigurasi (AI_VISION_SERVICE_URL), bagian yang sudah ada model terlatihnya
 * memakai model tersebut:
 *  - deteksi satwa/manusia/kendaraan + jumlah individu  -> MegaDetector v5a
 *  - spesies                                            -> SpeciesNet (classifier Google, ~2.500 label)
 *  - embedding re-identifikasi individu                 -> MegaDescriptor
 * Kondisi fisik, perilaku, ciri pembeda, dan tanda senjata/jerat/penebangan/api belum punya model terlatih
 * publik, jadi tetap dari Groq vision (bila dikonfigurasi) dengan konteks hasil deteksi di atas.
 * Tanpa ai-service, seluruh analisis memakai Groq vision seperti sebelumnya.
 */
class VisionAnalyzer
{
    /** Label generik / non-spesies dari model lokal atau LLM yang harus dianggap belum spesifik. */
    private const GENERIC_LABELS = [
        'animal',
        'animals',
        'mammal',
        'mammals',
        'mamalia',
        'bird',
        'birds',
        'vertebrate',
        'carnivore',
        'primate',
        'ungulate',
        'blank',
        'empty',
        'no cv result',
        'no_cv_result',
        'no_animal',
        'no animal',
        'unidentified',
        'unclassified',
        'unknown',
        'unknown species',
        'tidak diketahui',
        'tidak teridentifikasi',
        'satwa',
        'satwa liar',
        'hewan',
        'binatang',
        'human',
        'person',
        'manusia',
        'vehicle',
        'kendaraan',
    ];

    public function __construct(
        private GroqClient $groq,
        private SpeciesMatcher $matcher,
        private WildlifeVisionClient $models,
    ) {}

    public function modelVersion(): string
    {
        if ($this->models->isConfigured()) {
            return 'speciesnet-v4.0.3a' . ($this->groq->isConfigured() ? '+groq:' . $this->groq->visionModel() : '');
        }

        return 'groq:' . $this->groq->visionModel();
    }

    /**
     * @param  array{zone_name?: ?string, zone_type?: ?string, captured_at?: ?string, device?: ?string}  $context
     * @param  string|null  $videoPath  video asli (bila media berupa video) agar beberapa frame dianalisis
     */
    public function analyze(string $imagePath, array $context = [], ?string $videoPath = null): array
    {
        if ($this->models->isConfigured() && ($videoPath || is_file($imagePath))) {
            try {
                return $this->analyzeWithModels($imagePath, $context, $videoPath);
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                // ai-service mati / belum jalan: pakai Groq agar upload tetap dianalisis.
                if (! $this->groq->isConfigured() || ! is_file($imagePath)) {
                    throw new RuntimeException('ai-service tidak bisa dihubungi (' . config('services.ai_vision.url') . '). Jalankan uvicorn di port 8001.', 0, $e);
                }
                Log::warning('ai-service tidak terjangkau; fallback ke Groq', ['error' => $e->getMessage()]);
            }
        }

        if (! is_file($imagePath)) {
            throw new RuntimeException('File gambar tidak ditemukan.');
        }

        $result = $this->groq->chatJson(
            [$this->groq->imageMessage($this->prompt($context), $imagePath)],
            $this->groq->visionModel(),
            1500,
        );

        // model_version jujur: hasil ini dari Groq saja, bukan SpeciesNet.
        return $this->normalize($result['json'], $result['raw']) + ['model_version' => 'groq:' . $this->groq->visionModel()];
    }

    private function analyzeWithModels(string $imagePath, array $context, ?string $videoPath): array
    {
        $det = $videoPath ? $this->models->analyzeVideo($videoPath) : $this->models->analyzeImage($imagePath);
        $detections = is_array($det['detections'] ?? null) ? $det['detections'] : [];
        $top = is_array($det['top_species'] ?? null) ? $det['top_species'] : null;
        $animals = (int) ($det['animal_count'] ?? 0);

        $species = $top ? $this->matcher->matchTaxon($top['scientific_name'] ?? null, $top['rank'] ?? null, $top['genus'] ?? null) : null;
        $withEmbedding = collect($detections)->filter(fn ($d) => ($d['category'] ?? null) === 'animal' && ! empty($d['embedding']));
        $primary = $withEmbedding->sortByDesc('confidence')->first();

        // Kumpulkan semua nama spesies dari setiap deteksi lokal (untuk gambar multi-hewan).
        $localSpeciesNames = [];
        foreach ($detections as $d) {
            if (($d['category'] ?? null) !== 'animal') {
                continue;
            }
            $detSpecies = is_array($d['species'] ?? null) ? $d['species'] : null;
            if (! $detSpecies) {
                continue;
            }
            $matchedLocal = $this->matcher->matchTaxon(
                $detSpecies['scientific_name'] ?? null,
                $detSpecies['rank'] ?? null,
                $detSpecies['genus'] ?? null,
            );
            if ($matchedLocal !== null) {
                $localSpeciesNames[] = $matchedLocal->common_name;
                $species ??= $matchedLocal;
            } else {
                $cleanLocal = $this->cleanSpeciesLabel($detSpecies['common_name'] ?? null);
                if ($cleanLocal !== null) {
                    $localSpeciesNames[] = $cleanLocal;
                }
            }
        }

        // Bagian tanpa model terlatih publik: tanyakan ke Groq dengan konteks deteksi.
        $groq = null;
        $groqError = null;
        if ($this->groq->isConfigured() && is_file($imagePath)) {
            try {
                $result = $this->groq->chatJson(
                    [$this->groq->imageMessage($this->prompt($context, $this->detectionContext($det)), $imagePath)],
                    $this->groq->visionModel(),
                    1500,
                );
                $groq = $this->normalize($result['json'], $result['raw']);
            } catch (\Throwable $e) {
                $groqError = $e->getMessage();
                Log::warning('Groq vision (kondisi/perilaku) gagal; hasil model terlatih tetap dipakai', ['error' => $e->getMessage()]);
            }
        }

        $flags = $groq['human_activity'] ?? array_fill_keys(['weapons', 'traps_or_snares', 'logging', 'fire_or_smoke'], false) + ['notes' => null];
        $flags['humans_present'] = (int) ($det['person_count'] ?? 0) > 0;
        $flags['vehicles'] = (int) ($det['vehicle_count'] ?? 0) > 0;

        $animalDetected = $animals > 0;
        $commonName = $species?->common_name ?? $this->cleanSpeciesLabel($top['common_name'] ?? null);
        $scientificName = $species?->scientific_name ?? $this->cleanSpeciesLabel($top['scientific_name'] ?? null);
        $confidence = round((float) ($top['score'] ?? ($detections[0]['confidence'] ?? 0)), 4);

        $detectedSpeciesList = $this->mergeSpeciesNames(
            $commonName ? [$commonName] : [],
            $localSpeciesNames,
        );

        // Rekonsiliasi dengan hasil Groq: baik saat model lokal gagal/unknown MAUPUN saat Groq
        // mengidentifikasi beberapa satwa berbeda dalam satu gambar.
        if ($groq !== null) {
            $groqSpecies = $groq['species'] ?? null;
            $groqNames = is_array($groq['detected_species'] ?? null) ? $groq['detected_species'] : [];
            if (! empty($groq['species_common_name'])) {
                $groqNames = $this->mergeSpeciesNames($groqNames, $this->splitSpeciesNames($groq['species_common_name']));
            }

            if ($species === null && $groqSpecies !== null) {
                $species = $groqSpecies;
                $scientificName = $groqSpecies->scientific_name;
            }

            if ($groqNames !== []) {
                // Bila lokal kosong/unknown atau multi-spesies terdeteksi Groq, gabungkan semua nama spesies.
                $detectedSpeciesList = $species !== null && $commonName !== null
                    ? $this->mergeSpeciesNames([$species->common_name], $detectedSpeciesList, $groqNames)
                    : $this->mergeSpeciesNames($detectedSpeciesList, $groqNames);

                $commonName = implode(', ', $detectedSpeciesList);
                $scientificName = $scientificName ?? ($groq['species_scientific_name'] ?? null);
                $animalDetected = true;
                $animals = max(1, $animals, (int) ($groq['individual_count'] ?? 1), count($detectedSpeciesList));
                $confidence = $this->reconcileConfidence($confidence, (float) ($groq['confidence'] ?? 0), true);
            } elseif ($groqSpecies !== null) {
                $detectedSpeciesList = $this->mergeSpeciesNames([$groqSpecies->common_name], $detectedSpeciesList);
                $commonName = implode(', ', $detectedSpeciesList);
                $scientificName = $groqSpecies->scientific_name;
                $animalDetected = true;
                $animals = max(1, $animals, (int) ($groq['individual_count'] ?? 1));
                $confidence = $this->reconcileConfidence($confidence, (float) ($groq['confidence'] ?? 0), true);
            }
        } elseif ($detectedSpeciesList !== []) {
            $commonName = implode(', ', $detectedSpeciesList);
            $animalDetected = true;
            $animals = max(1, $animals, count($detectedSpeciesList));
        }

        return [
            'animal_detected' => $animalDetected,
            'species_common_name' => $commonName ? ucfirst($commonName) : null,
            'species_scientific_name' => $scientificName,
            'species' => $species,
            'detected_species' => $detectedSpeciesList,
            'confidence' => $confidence,
            'individual_count' => $animals,
            'physical_condition' => $animalDetected ? ($groq['physical_condition'] ?? 'unknown') : null,
            'condition_notes' => $groq['condition_notes'] ?? null,
            'behavior' => $animalDetected ? ($groq['behavior'] ?? 'unknown') : null,
            'behavior_notes' => $groq['behavior_notes'] ?? null,
            'distinctive_features' => $groq['distinctive_features'] ?? null,
            'human_activity' => $flags,
            'objects' => $this->objectsFromDetections($detections),
            'summary' => $groq['summary'] ?? $this->summaryFromDetections($det, $commonName),
            'faces' => is_array($det['faces'] ?? null) ? $det['faces'] : [],
            'audio' => is_array($det['audio'] ?? null) ? $det['audio'] : null,
            'embedding' => $primary['embedding'] ?? null,
            'embedding_bbox' => $primary['bbox'] ?? null,
            'embedding_model' => $det['embedding_model'] ?? null,
            'raw' => [
                'engine' => 'speciesnet+megadetector' . ($groq ? '+groq' : ''),
                'detector' => collect($detections)->map(fn ($d) => array_diff_key($d, ['embedding' => 1]))->values()->all(),
                'top_species' => $top,
                'detected_species' => $detectedSpeciesList,
                'counts' => ['animal' => $animals, 'person' => (int) ($det['person_count'] ?? 0), 'vehicle' => (int) ($det['vehicle_count'] ?? 0)],
                'video_frames' => $det['frames'] ?? null,
                'groq' => $groq['raw'] ?? null,
                'groq_error' => $groqError,
            ],
        ];
    }

    private function detectionContext(array $det): string
    {
        $top = $det['top_species'] ?? null;
        $hasSpecies = $top && $this->cleanSpeciesLabel($top['common_name'] ?? null) !== null;
        $species = $hasSpecies
            ? sprintf('%s (%s, skor %.0f%%)', $top['common_name'] ?? '?', $top['scientific_name'] ?? '?', ($top['score'] ?? 0) * 100)
            : 'tidak teridentifikasi oleh model lokal';

        $guidance = $hasSpecies
            ? 'Gunakan ini sebagai referensi, namun jika ada lebih dari satu jenis satwa pada gambar, cantumkan SEMUA spesies pada detected_species dan species_common_name. Fokuskan juga analisis pada kondisi fisik, perilaku, ciri pembeda individu, serta tanda senjata, jerat, penebangan, atau api/asap.'
            : 'Model lokal belum mengenali spesiesnya; jika satwa terlihat pada gambar (baik satu maupun beberapa spesies berbeda), WAJIB identifikasi semua spesiesnya pada detected_species, species_common_name, dan species_scientific_name dengan confidence yang sesuai agar selaras dengan ringkasan Anda.';

        return sprintf(
            'Model deteksi terlatih (MegaDetector + SpeciesNet + detektor wajah) sudah menemukan: %d satwa, %d manusia, %d kendaraan, %d wajah. Spesies: %s. %s',
            (int) ($det['animal_count'] ?? 0), (int) ($det['person_count'] ?? 0), (int) ($det['vehicle_count'] ?? 0), count($det['faces'] ?? []), $species, $guidance,
        );
    }

    private function objectsFromDetections(array $detections): array
    {
        $label = fn ($d) => match ($d['category'] ?? null) {
            'person' => 'manusia',
            'vehicle' => 'kendaraan',
            default => $this->cleanSpeciesLabel($d['species']['common_name'] ?? null) ?? 'satwa',
        };

        return collect($detections)->take(30)->map(fn ($d) => [
            'label' => $label($d),
            'score' => round((float) ($d['confidence'] ?? 0), 4),
            'box' => isset($d['bbox'][3]) ? [
                'xmin' => round($d['bbox'][0] * 100, 2),
                'ymin' => round($d['bbox'][1] * 100, 2),
                'xmax' => round(($d['bbox'][0] + $d['bbox'][2]) * 100, 2),
                'ymax' => round(($d['bbox'][1] + $d['bbox'][3]) * 100, 2),
            ] : null,
        ])->values()->all();
    }

    private function summaryFromDetections(array $det, ?string $common): string
    {
        $parts = [];
        if (($n = (int) ($det['animal_count'] ?? 0)) > 0) {
            $parts[] = $n . ' satwa' . ($common ? " ({$common})" : '');
        }
        if (($n = (int) ($det['person_count'] ?? 0)) > 0) {
            $parts[] = "{$n} manusia";
        }
        if (($n = (int) ($det['vehicle_count'] ?? 0)) > 0) {
            $parts[] = "{$n} kendaraan";
        }

        return $parts
            ? 'Model terlatih mendeteksi ' . implode(', ', $parts) . '. Kondisi fisik dan perilaku belum dinilai (Groq tidak dikonfigurasi).'
            : 'Model terlatih tidak mendeteksi satwa, manusia, maupun kendaraan pada gambar ini.';
    }

    private function prompt(array $context, ?string $detections = null): string
    {
        $species = $this->matcher->promptList() ?: '- (belum ada data spesies)';
        $where = trim(sprintf(
            '%s %s',
            $context['zone_name'] ?? '',
            isset($context['zone_type']) ? "(tipe zona: {$context['zone_type']})" : '',
        ));
        $when = $context['captured_at'] ?? 'tidak diketahui';
        $device = $context['device'] ?? 'kamera lapangan';

        return <<<PROMPT
Anda adalah ahli ekologi satwa liar Sumatra yang menganalisis foto kamera jebak.
Lokasi: {$where}. Waktu pengambilan: {$when}. Sumber: {$device}.

Spesies yang dipantau di sistem (prioritaskan jika cocok):
{$species}
{$detections}

ATURAN KONSISTENSI PENTING:
- Jika Anda mengenali atau menyebut nama hewan/satwa di "summary" (baik 1 hewan maupun beberapa jenis hewan berbeda dalam satu foto), Anda WAJIB mengisi "animal_detected": true, "species_common_name" (pisahkan koma jika lebih dari 1 spesies), "detected_species" (array semua spesies yang terlihat), dan "confidence" minimal 0.75 sesuai keyakinan visual.
- JANGAN mengembalikan "unknown" atau null pada "species_common_name" bila Anda berhasil mendeskripsikan hewannya pada "summary".

Analisis gambar dan balas HANYA satu objek JSON valid dengan kunci berikut:
{
  "animal_detected": true|false,
  "species_common_name": "nama umum spesies utama atau daftar dipisah koma (bahasa Indonesia), atau null jika benar-benar tidak ada hewan",
  "species_scientific_name": "nama ilmiah spesies utama atau null",
  "detected_species": ["nama umum setiap jenis satwa yang terlihat pada gambar"],
  "confidence": 0.0-1.0,
  "individual_count": bilangan bulat,
  "physical_condition": "healthy|injured|sick|emaciated|unknown",
  "condition_notes": "penjelasan singkat kondisi fisik (luka, bekas jerat, kurus, bulu rontok, dll)",
  "behavior": "foraging|resting|moving|running|drinking|social|parenting|mating|alert|aggressive|unknown",
  "behavior_notes": "penjelasan singkat perilaku",
  "distinctive_features": "ciri fisik pembeda individu (bekas luka, pola belang, telinga sobek, ukuran cula, dll) atau null",
  "human_activity": {
    "humans_present": true|false,
    "weapons": true|false,
    "traps_or_snares": true|false,
    "logging": true|false,
    "vehicles": true|false,
    "fire_or_smoke": true|false,
    "notes": "keterangan singkat"
  },
  "objects": [{"label": "nama objek", "score": 0.0-1.0, "box": {"xmin": 0-100, "ymin": 0-100, "xmax": 0-100, "ymax": 0-100}}],
  "summary": "ringkasan 1-2 kalimat dalam bahasa Indonesia"
}
Koordinat box dalam persen lebar/tinggi gambar. Hanya gunakan null bila benar-benar tidak ada hewan atau gambar terlalu buram untuk dikenali. Jangan mengarang.
PROMPT;
    }

    private function normalize(array $json, string $raw): array
    {
        $human = is_array($json['human_activity'] ?? null) ? $json['human_activity'] : [];
        $flags = [
            'humans_present' => (bool) ($human['humans_present'] ?? false),
            'weapons' => (bool) ($human['weapons'] ?? false),
            'traps_or_snares' => (bool) ($human['traps_or_snares'] ?? $human['traps_snares'] ?? false),
            'logging' => (bool) ($human['logging'] ?? false),
            'vehicles' => (bool) ($human['vehicles'] ?? false),
            'fire_or_smoke' => (bool) ($human['fire_or_smoke'] ?? $human['fire'] ?? false),
            'notes' => isset($human['notes']) ? (string) $human['notes'] : null,
        ];

        $objects = collect(is_array($json['objects'] ?? null) ? $json['objects'] : [])
            ->filter(fn ($o) => is_array($o) && isset($o['label']))
            ->map(fn ($o) => [
                'label' => (string) $o['label'],
                'score' => isset($o['score']) ? round(min(1, max(0, (float) $o['score'])), 4) : null,
                'box' => is_array($o['box'] ?? null) ? array_map('floatval', array_intersect_key(
                    $o['box'], array_flip(['xmin', 'ymin', 'xmax', 'ymax'])
                )) : null,
            ])
            ->take(30)->values()->all();

        $explicitAnimalFlag = array_key_exists('animal_detected', $json) ? (bool) $json['animal_detected'] : null;
        $rawCommon = $explicitAnimalFlag === false ? null : $this->cleanSpeciesLabel($json['species_common_name'] ?? $json['label'] ?? null);
        $scientific = $explicitAnimalFlag === false ? null : $this->cleanSpeciesLabel($json['species_scientific_name'] ?? null);
        $summary = $this->str($json['summary'] ?? null);
        $conditionNotes = $this->str($json['condition_notes'] ?? null);
        $behaviorNotes = $this->str($json['behavior_notes'] ?? null);
        $distinctiveFeatures = $this->str($json['distinctive_features'] ?? null);

        $explicitList = [];
        if ($explicitAnimalFlag !== false && is_array($json['detected_species'] ?? null)) {
            foreach ($json['detected_species'] as $entry) {
                $cleaned = $this->cleanSpeciesLabel($entry);
                if ($cleaned !== null) {
                    $explicitList[] = $cleaned;
                }
            }
        }
        if ($rawCommon !== null) {
            $explicitList = $this->mergeSpeciesNames($this->splitSpeciesNames($rawCommon), $explicitList);
        }

        // Kumpulkan label hewan dari array objects (abaikan manusia/kendaraan/generik).
        $objectLabels = [];
        $objectMaxScore = 0.0;
        if ($explicitAnimalFlag !== false) {
            foreach ($objects as $obj) {
                $cleanedObj = $this->cleanSpeciesLabel($obj['label'] ?? null);
                if ($cleanedObj !== null) {
                    $objectLabels[] = $cleanedObj;
                    if (isset($obj['score'])) {
                        $objectMaxScore = max($objectMaxScore, (float) $obj['score']);
                    }
                }
            }
        }

        $animalDetected = (bool) ($explicitAnimalFlag ?? ($explicitList !== [] || $objectLabels !== []));
        $explicitNoAnimal = $this->isExplicitNoAnimal($summary, $conditionNotes, $behaviorNotes);

        // 1. Cocokkan semua spesies master dari field eksplisit, objek, dan deskripsi/ringkasan.
        $matchedCollection = collect();
        if (! $explicitNoAnimal) {
            $matchedCollection = $this->matcher->matchAll(...[
                $scientific,
                $rawCommon,
                ...$explicitList,
                ...$objectLabels,
                $summary,
                $conditionNotes,
                $behaviorNotes,
                $distinctiveFeatures,
            ]);
        }

        /** @var Species|null $matchedSpecies */
        $matchedSpecies = $matchedCollection->first();
        $masterNames = $matchedCollection->map(fn (Species $s) => $s->common_name)->values()->all();

        // 2. Ekstrak juga satwa non-master dari ringkasan/deskripsi/objek agar tidak pernah jadi "unknown".
        $generalNames = $explicitNoAnimal
            ? []
            : $this->matcher->extractGeneralAnimals(...[
                $rawCommon,
                ...$explicitList,
                ...$objectLabels,
                $summary,
                $conditionNotes,
                $behaviorNotes,
                $distinctiveFeatures,
            ]);

        // Gabungkan semua nama spesies unik (master diprioritaskan di depan).
        $allSpeciesNames = $this->mergeSpeciesNames(
            $masterNames,
            $explicitList,
            $generalNames,
        );

        // Bila masih kosong tetapi ada label objek hewan yang valid (dan bukan eksplisit no_animal), pakai label objek.
        if ($allSpeciesNames === [] && ! $explicitNoAnimal && $objectLabels !== []) {
            $allSpeciesNames = $this->mergeSpeciesNames($objectLabels);
        }

        $common = $allSpeciesNames !== [] ? implode(', ', $allSpeciesNames) : null;
        if ($matchedSpecies !== null) {
            $scientific = $scientific ?? $matchedSpecies->scientific_name;
        }
        if ($allSpeciesNames !== []) {
            $animalDetected = true;
        }

        $rawConfidence = round(min(1, max(0, (float) ($json['confidence'] ?? $json['score'] ?? 0))), 4);
        $confidence = $this->reconcileConfidence($rawConfidence, $objectMaxScore, $allSpeciesNames !== []);

        $individualCount = max(0, (int) ($json['individual_count'] ?? 0));
        if ($animalDetected) {
            $individualCount = max(1, $individualCount, count($allSpeciesNames));
        }

        return [
            'animal_detected' => $animalDetected,
            'species_common_name' => $common,
            'species_scientific_name' => $scientific,
            'species' => $matchedSpecies,
            'detected_species' => $allSpeciesNames,
            'confidence' => $confidence,
            'individual_count' => $individualCount,
            'physical_condition' => $animalDetected ? AnalysisVocabulary::condition($json['physical_condition'] ?? null) : null,
            'condition_notes' => $conditionNotes,
            'behavior' => $animalDetected ? AnalysisVocabulary::behavior($json['behavior'] ?? null) : null,
            'behavior_notes' => $behaviorNotes,
            'distinctive_features' => $distinctiveFeatures,
            'human_activity' => $flags,
            'objects' => $objects,
            'summary' => $summary,
            'raw' => mb_substr($raw, 0, 4000),
        ];
    }

    /**
     * Bila satwa berhasil diidentifikasi secara jelas (dari model, objek, atau deskripsi),
     * pastikan confidence tidak tertahan di 0 atau terlalu rendah.
     */
    private function reconcileConfidence(float $primary, float $secondary, bool $speciesIdentified): float
    {
        $best = max($primary, $secondary);
        if ($speciesIdentified && $best < 0.78) {
            $best = 0.78;
        }

        return round(min(1.0, max(0.0, $best)), 4);
    }

    /**
     * Gabungkan beberapa daftar nama spesies secara unik, menghapus nama generik bila versi lebih spesifik sudah ada.
     *
     * @param  list<string>  ...$lists
     * @return list<string>
     */
    private function mergeSpeciesNames(array ...$lists): array
    {
        $flat = [];
        foreach ($lists as $list) {
            foreach ($list as $item) {
                $clean = $this->cleanSpeciesLabel($item);
                if ($clean !== null) {
                    $flat[] = $clean;
                }
            }
        }

        $result = [];
        foreach ($flat as $name) {
            $lower = mb_strtolower($name);
            $duplicate = false;
            foreach ($result as $idx => $existing) {
                $existingLower = mb_strtolower($existing);
                if ($existingLower === $lower) {
                    $duplicate = true;
                    break;
                }
                // Jika yang sudah ada lebih spesifik (mis. "Harimau Sumatra" vs "Harimau"), lewati yang generik.
                if (str_contains($existingLower, $lower)) {
                    $duplicate = true;
                    break;
                }
                // Jika yang baru lebih spesifik, gantikan yang generik.
                if (str_contains($lower, $existingLower)) {
                    $result[$idx] = $name;
                    $duplicate = true;
                    break;
                }
            }
            if (! $duplicate) {
                $result[] = $name;
            }
        }

        return array_values($result);
    }

    /** @return list<string> */
    private function splitSpeciesNames(string $value): array
    {
        $parts = preg_split('/\s*(?:,|;|\/|\bdan\b|\band\b)\s*/iu', $value) ?: [$value];
        $out = [];
        foreach ($parts as $part) {
            $clean = $this->cleanSpeciesLabel($part);
            if ($clean !== null) {
                $out[] = $clean;
            }
        }

        return $out;
    }

    private function cleanSpeciesLabel(mixed $value): ?string
    {
        $clean = $this->str($value);
        if ($clean === null) {
            return null;
        }
        $lower = mb_strtolower($clean);
        if (in_array($lower, self::GENERIC_LABELS, true)) {
            return null;
        }

        return $clean;
    }

    private function isExplicitNoAnimal(?string ...$texts): bool
    {
        $combined = mb_strtolower(trim(implode(' ', array_filter($texts))));
        if ($combined === '') {
            return false;
        }

        // Hindari false-positive untuk frasa seperti "tidak ada satwa lain" / "tidak ada hewan lainnya" / "tanpa ada hewan lain"
        $stripped = preg_replace(
            '/(?:tidak\s+(?:ada|terlihat|ditemukan|tampak)|tanpa\s+(?:ada|adanya)?)\s+(?:satwa|hewan|binatang)\s+lain(?:nya)?/u',
            '',
            $combined,
        ) ?? $combined;

        return preg_match(
            '/(tidak\s+(?:ada|menampilkan|terlihat|ditemukan|terdeteksi)\s+(?:satwa|hewan|binatang)|tanpa\s+(?:adanya\s+)?(?:satwa|hewan)|\bno\s+animals?\b)/u',
            $stripped,
        ) === 1;
    }

    private function str(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' || in_array(mb_strtolower($value), ['null', 'none', 'unknown', '-'], true) ? null : $value;
    }
}
