<?php

namespace App\Services\Ai;

use App\Models\Species;
use Illuminate\Support\Collection;

/** Mencocokkan label bebas dari model AI ke data master spesies & kamus fauna umum. */
class SpeciesMatcher
{
    /** Sinonim umum (ID/EN/latin) -> kata kunci yang muncul di nama spesies master. */
    private const ALIASES = [
        'orangutan' => ['orangutan', 'orang utan', 'pongo', 'great ape', 'kera besar'],
        'harimau' => ['harimau sumatra', 'harimau sumatera', 'harimau', 'sumatran tiger', 'tiger', 'panthera tigris', 'tigris'],
        'gajah' => ['gajah sumatra', 'gajah sumatera', 'gajah', 'sumatran elephant', 'asian elephant', 'elephant', 'elephas'],
        'badak' => ['badak sumatra', 'badak sumatera', 'badak', 'sumatran rhino', 'rhinoceros', 'rhino', 'dicerorhinus'],
        'rusa' => ['rusa sambar', 'rusa', 'sambar deer', 'sambar', 'deer', 'cervus', 'rusa unicolor'],
        'beruang' => ['beruang madu', 'beruang', 'sun bear', 'malayan sun bear', 'bear', 'helarctos'],
        'siamang' => ['siamang', 'gibbon', 'symphalangus', 'owa'],
        'kukang' => ['kukang', 'slow loris', 'nycticebus'],
        'trenggiling' => ['trenggiling', 'pangolin', 'manis javanica'],
        'tapir' => ['tapir asia', 'tapir', 'malayan tapir', 'tapirus'],
        'rangkong' => ['rangkong badak', 'rangkong', 'enggang', 'hornbill', 'buceros'],
        'beruk' => ['beruk', 'pig-tailed macaque', 'macaque', 'macaca'],
    ];

    /**
     * Kamus fauna umum (ID & EN -> Nama Indonesia baku) untuk satwa yang belum terdaftar
     * di tabel master `species` agar prediksi tidak jatuh ke "unknown" bila sudah dideskripsikan.
     *
     * @var array<string, list<string>>
     */
    private const GENERAL_ANIMALS = [
        'Harimau Sumatra' => ['harimau sumatra', 'harimau sumatera', 'sumatran tiger', 'panthera tigris sumatrae'],
        'Harimau' => ['harimau', 'tiger', 'panthera tigris'],
        'Orangutan Sumatra' => ['orangutan sumatra', 'orangutan sumatera', 'sumatran orangutan', 'pongo abelii'],
        'Orangutan' => ['orangutan', 'orang utan', 'pongo'],
        'Gajah Sumatra' => ['gajah sumatra', 'gajah sumatera', 'sumatran elephant', 'elephas maximus sumatranus'],
        'Gajah' => ['gajah', 'elephant', 'elephas'],
        'Badak Sumatra' => ['badak sumatra', 'badak sumatera', 'sumatran rhino', 'dicerorhinus sumatrensis'],
        'Badak' => ['badak', 'rhinoceros', 'rhino'],
        'Rusa Sambar' => ['rusa sambar', 'sambar deer', 'rusa unicolor'],
        'Rusa' => ['rusa', 'deer', 'cervus'],
        'Beruang Madu' => ['beruang madu', 'sun bear', 'helarctos malayanus'],
        'Beruang' => ['beruang', 'bear'],
        'Siamang' => ['siamang', 'symphalangus'],
        'Owa' => ['owa', 'gibbon'],
        'Rangkong Badak' => ['rangkong badak', 'rhinoceros hornbill', 'buceros rhinoceros'],
        'Rangkong' => ['rangkong', 'enggang', 'hornbill'],
        'Macan Dahan' => ['macan dahan', 'clouded leopard', 'neofelis'],
        'Macan Tutul' => ['macan tutul', 'leopard', 'panthera pardus'],
        'Kucing Emas' => ['kucing emas', 'asiatic golden cat', 'catopuma'],
        'Kucing Hutan' => ['kucing hutan', 'leopard cat', 'prionailurus'],
        'Kucing' => ['kucing', 'domestic cat', 'cat'],
        'Tapir' => ['tapir', 'tapirus'],
        'Trenggiling' => ['trenggiling', 'pangolin', 'manis javanica'],
        'Kukang' => ['kukang', 'slow loris', 'nycticebus'],
        'Babi Hutan' => ['babi hutan', 'celeng', 'wild boar', 'boar', 'sus scrofa'],
        'Kijang' => ['kijang', 'muntjac', 'barking deer', 'muntiacus'],
        'Kancil' => ['kancil', 'pelanduk', 'mouse deer', 'chevrotain', 'tragulus'],
        'Kambing Hutan' => ['kambing hutan', 'sumatran serow', 'serow', 'capricornis'],
        'Binturong' => ['binturong', 'bearcat', 'arctictis'],
        'Musang' => ['musang', 'luwak', 'civet', 'paradoxurus'],
        'Landak' => ['landak', 'porcupine', 'hystrix'],
        'Beruk' => ['beruk', 'pig-tailed macaque', 'macaca nemestrina'],
        'Lutung' => ['lutung', 'langur', 'leaf monkey', 'presbytis', 'trachypithecus'],
        'Monyet' => ['monyet', 'kera', 'monkey', 'macaque', 'macaca'],
        'Singa' => ['singa', 'lion', 'panthera leo'],
        'Cheetah' => ['cheetah', 'citah', 'acinonyx'],
        'Jaguar' => ['jaguar', 'panthera onca'],
        'Serigala' => ['serigala', 'wolf', 'canis lupus'],
        'Anjing Hutan' => ['anjing hutan', 'ajag', 'dhole', 'cuon alpinus'],
        'Anjing' => ['anjing', 'dog', 'canis'],
        'Rubah' => ['rubah', 'fox', 'vulpes'],
        'Hyena' => ['hyena', 'hiena'],
        'Zebra' => ['zebra', 'equus quagga'],
        'Jerapah' => ['jerapah', 'giraffe', 'giraffa'],
        'Kuda Nil' => ['kuda nil', 'hippopotamus', 'hippo'],
        'Kuda' => ['kuda', 'horse'],
        'Kerbau' => ['kerbau', 'buffalo', 'bubalus'],
        'Sapi' => ['sapi', 'lembu', 'cow', 'cattle', 'bos taurus'],
        'Kambing' => ['kambing', 'goat'],
        'Domba' => ['domba', 'sheep'],
        'Panda' => ['panda', 'ailuropoda'],
        'Koala' => ['koala'],
        'Kanguru' => ['kanguru', 'kangaroo'],
        'Tupai' => ['tupai', 'bajing', 'squirrel'],
        'Kelinci' => ['kelinci', 'rabbit', 'hare'],
        'Kelelawar' => ['kelelawar', 'kalong', 'bat'],
        'Buaya' => ['buaya', 'crocodile', 'alligator'],
        'Komodo' => ['komodo', 'varanus komodoensis'],
        'Biawak' => ['biawak', 'monitor lizard', 'varanus'],
        'Ular' => ['ular', 'python', 'piton', 'kobra', 'cobra', 'snake'],
        'Kura-kura' => ['kura-kura', 'kura kura', 'penyu', 'tortoise', 'turtle'],
        'Elang' => ['elang', 'rajawali', 'eagle', 'hawk'],
        'Burung Hantu' => ['burung hantu', 'owl'],
        'Merak' => ['merak', 'peacock', 'peafowl'],
        'Kuau Raja' => ['kuau raja', 'kuau', 'great argus', 'argusianus'],
        'Ayam Hutan' => ['ayam hutan', 'junglefowl', 'gallus'],
        'Bangau' => ['bangau', 'stork', 'heron'],
        'Burung' => ['burung', 'bird'],
    ];

    /** @var Collection<int, Species>|null */
    private ?Collection $species = null;

    public function all(): Collection
    {
        return $this->species ??= Species::query()->get();
    }

    public function match(?string ...$labels): ?Species
    {
        return $this->matchAll(...$labels)->first();
    }

    /**
     * Cocokkan SEMUA spesies master yang disebut dalam label/ringkasan (mendukung multi-hewan dalam 1 gambar).
     *
     * @return Collection<int, Species>
     */
    public function matchAll(?string ...$labels): Collection
    {
        $labels = array_values(array_filter(array_map(
            fn ($l) => $l === null ? null : mb_strtolower(trim($l)),
            $labels,
        )));

        if ($labels === []) {
            return collect();
        }

        $species = $this->all();
        $matched = collect();
        $seenIds = [];

        $addHit = function (?Species $hit) use (&$matched, &$seenIds): void {
            if ($hit !== null && ! isset($seenIds[$hit->species_id])) {
                $seenIds[$hit->species_id] = true;
                $matched->push($hit);
            }
        };

        // 1. Kecocokan persis nama ilmiah / nama umum
        foreach ($labels as $label) {
            foreach ($species as $s) {
                if (mb_strtolower($s->scientific_name) === $label || mb_strtolower($s->common_name) === $label) {
                    $addHit($s);
                }
            }
        }

        // 2. Substring dua arah (termasuk nama umum/ilmiah yang muncul di dalam kalimat deskripsi)
        foreach ($labels as $label) {
            if (in_array($label, ['unknown', 'none', 'tidak diketahui', ''], true)) {
                continue;
            }
            foreach ($species as $s) {
                $common = mb_strtolower($s->common_name);
                $scientific = mb_strtolower($s->scientific_name);

                if (
                    str_contains($common, $label)
                    || str_contains($scientific, $label)
                    || self::containsPhrase($label, $common)
                    || self::containsPhrase($label, $scientific)
                ) {
                    $addHit($s);
                }
            }
        }

        // 3. Lewat kamus sinonim (dengan batas kata agar "cat" tidak cocok "catch" / "ape" tidak cocok "escape")
        foreach ($labels as $label) {
            // Hindari tabrakan "Rangkong Badak" (Buceros rhinoceros) dengan "Badak Sumatra" (Dicerorhinus).
            $labelWithoutHornbill = preg_replace('/\b(rangkong\s+badak|rhinoceros\s+hornbill|buceros\s+rhinoceros)\b/u', '', $label) ?? $label;

            foreach (self::ALIASES as $keyword => $aliases) {
                $targetLabel = $keyword === 'badak' ? $labelWithoutHornbill : $label;
                foreach ($aliases as $alias) {
                    if (! self::containsPhrase($targetLabel, $alias)) {
                        continue;
                    }
                    foreach ($species as $s) {
                        $sCommon = mb_strtolower($s->common_name);
                        $sSci = mb_strtolower($s->scientific_name);
                        if ($keyword === 'badak' && (str_contains($sCommon, 'rangkong') || str_contains($sSci, 'buceros'))) {
                            continue;
                        }
                        if (
                            str_contains($sCommon, $keyword)
                            || collect($aliases)->contains(fn ($a) => str_contains($sSci, $a))
                        ) {
                            $addHit($s);
                        }
                    }
                }
            }
        }

        return $matched;
    }

    /**
     * Ekstrak semua nama hewan umum (ID/EN) yang disebut di teks deskripsi/ringkasan/objek.
     * Berguna untuk satwa non-master atau gambar berisi beberapa spesies berbeda sekaligus.
     *
     * @return list<string>
     */
    public function extractGeneralAnimals(?string ...$texts): array
    {
        $combined = mb_strtolower(trim(implode(' | ', array_filter($texts))));
        if ($combined === '') {
            return [];
        }

        $combinedWithoutHornbill = preg_replace('/\b(rangkong\s+badak|rhinoceros\s+hornbill|buceros\s+rhinoceros)\b/u', '', $combined) ?? $combined;

        $found = [];
        foreach (self::GENERAL_ANIMALS as $canonical => $aliases) {
            $haystack = in_array($canonical, ['Badak', 'Badak Sumatra'], true) ? $combinedWithoutHornbill : $combined;
            foreach ($aliases as $alias) {
                if (self::containsPhrase($haystack, $alias)) {
                    $found[] = $canonical;
                    break;
                }
            }
        }

        // Hapus nama generik bila bentuk lebih spesifik sudah ada (mis. "Harimau" bila "Harimau Sumatra" ada,
        // "Burung" bila "Rangkong" / "Elang" / "Burung Hantu" ada, "Kuda" bila "Kuda Nil" ada, "Kambing" bila "Kambing Hutan" ada).
        $specificSupersets = [
            'Harimau' => ['Harimau Sumatra'],
            'Orangutan' => ['Orangutan Sumatra'],
            'Gajah' => ['Gajah Sumatra'],
            'Badak' => ['Badak Sumatra'],
            'Rusa' => ['Rusa Sambar'],
            'Beruang' => ['Beruang Madu'],
            'Rangkong' => ['Rangkong Badak'],
            'Kucing' => ['Kucing Hutan', 'Kucing Emas'],
            'Kuda' => ['Kuda Nil'],
            'Kambing' => ['Kambing Hutan'],
            'Anjing' => ['Anjing Hutan'],
            'Burung' => ['Rangkong Badak', 'Rangkong', 'Elang', 'Burung Hantu', 'Merak', 'Kuau Raja', 'Ayam Hutan', 'Bangau'],
        ];

        $result = [];
        foreach ($found as $name) {
            $superseded = false;
            foreach ($specificSupersets[$name] ?? [] as $specific) {
                if (in_array($specific, $found, true)) {
                    $superseded = true;
                    break;
                }
            }
            if (! $superseded && ! in_array($name, $result, true)) {
                $result[] = $name;
            }
        }

        return $result;
    }

    /**
     * Pencocokan ketat untuk takson dari model terlatih (SpeciesNet), tanpa kamus sinonim:
     *  1. nama ilmiah spesies sama / subspesies dari spesies itu (Panthera tigris -> Panthera tigris sumatrae),
     *  2. satu genus dengan tepat satu spesies di master data (Pongo pygmaeus -> Pongo abelii).
     */
    public function matchTaxon(?string $scientific, ?string $rank = null, ?string $genus = null): ?Species
    {
        $scientific = mb_strtolower(trim((string) $scientific));
        if ($scientific === '') {
            return null;
        }
        $species = $this->all();

        if ($rank === 'species') {
            $hit = $species->first(fn (Species $s) => ($name = mb_strtolower($s->scientific_name)) === $scientific
                || str_starts_with($name, $scientific . ' '));
            if ($hit) {
                return $hit;
            }
        }

        $genus = mb_strtolower(trim((string) ($genus ?: ($rank === 'genus' ? $scientific : explode(' ', $scientific)[0]))));
        if ($genus === '' || ! in_array($rank, ['species', 'genus'], true)) {
            return null;
        }
        $sameGenus = $species->filter(fn (Species $s) => explode(' ', mb_strtolower($s->scientific_name))[0] === $genus);

        return $sameGenus->count() === 1 ? $sameGenus->first() : null;
    }

    /** Daftar spesies untuk konteks prompt AI. */
    public function promptList(): string
    {
        return $this->all()
            ->map(fn (Species $s) => "- {$s->common_name} ({$s->scientific_name})")
            ->implode("\n");
    }

    private static function containsPhrase(string $haystack, string $needle): bool
    {
        $needle = trim($needle);
        if ($needle === '') {
            return false;
        }

        return preg_match('/(?<![\p{L}\p{N}])' . preg_quote($needle, '/') . '(?![\p{L}\p{N}])/u', $haystack) === 1;
    }
}
