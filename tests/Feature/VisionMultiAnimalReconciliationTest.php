<?php

namespace Tests\Feature;

use App\Models\Species;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VisionMultiAnimalReconciliationTest extends TestCase
{
    private const SERVICE = 'http://vision.test';
    private array $headers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAll();
        Storage::fake('ai_media');
        config([
            'services.ai_vision.url' => self::SERVICE,
            'services.ai_vision.key' => '',
            'services.groq.key' => 'test-groq-key',
        ]);
        $this->headers = $this->loginAs('peneliti');
    }

    private function photo(string $name = 'frame.jpg'): UploadedFile
    {
        $img = imagecreatetruecolor(120, 90);
        ob_start();
        imagejpeg($img);
        $bytes = (string) ob_get_clean();

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    private function fakeVisionAndGroq(array $detections, ?array $topSpecies, array $groqJson): void
    {
        $counts = ['animal' => 0, 'person' => 0, 'vehicle' => 0];
        foreach ($detections as $d) {
            $cat = $d['category'] ?? 'animal';
            if (isset($counts[$cat])) {
                $counts[$cat]++;
            }
        }

        Http::fake([
            self::SERVICE . '/analyze/image' => Http::response([
                'detections' => $detections,
                'top_species' => $topSpecies,
                'animal_count' => $counts['animal'],
                'person_count' => $counts['person'],
                'vehicle_count' => $counts['vehicle'],
                'faces' => [],
                'embedding_model' => 'MegaDescriptor-T-224',
            ]),
            'api.groq.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode($groqJson)]]],
            ]),
        ]);
    }

    public function test_speciesnet_generic_labels_reconcile_to_described_monitored_species(): void
    {
        $cases = [
            ['no cv result', 'Foto memperlihatkan seekor Orangutan Sumatra sedang memanjat pohon ara.', 'Orangutan Sumatra'],
            ['mammal', 'Terlihat seekor Gajah Sumatra berjalan menyusuri tepi sungai.', 'Gajah Sumatra'],
            ['vertebrate', 'Seekor Badak Sumatra tampak berkubang di lumpur hutan.', 'Badak Sumatra'],
            ['unclassified', 'Kamera jebak menangkap Beruang Madu mencari rayap di batang kayu.', 'Beruang Madu'],
        ];

        foreach ($cases as [$junkLabel, $summary, $expectedName]) {
            Http::swap(new \Illuminate\Http\Client\Factory());
            $expectedSpecies = Species::where('common_name', $expectedName)->firstOrFail();

            $this->fakeVisionAndGroq(
                [['category' => 'animal', 'confidence' => 0.62, 'bbox' => [0.1, 0.1, 0.7, 0.7]]],
                ['common_name' => $junkLabel, 'scientific_name' => $junkLabel, 'rank' => 'class', 'genus' => '', 'score' => 0.45],
                [
                    'animal_detected' => true,
                    'species_common_name' => null,
                    'species_scientific_name' => null,
                    'confidence' => 0.55,
                    'individual_count' => 1,
                    'physical_condition' => 'healthy',
                    'behavior' => 'moving',
                    'summary' => $summary,
                ],
            );

            $res = $this->post('/api/ai/upload', ['file' => $this->photo()], $this->headers)
                ->assertCreated()
                ->assertJsonPath('data.prediction.processing_status', 'completed')
                ->assertJsonPath('data.prediction.predicted_species_id', $expectedSpecies->species_id)
                ->assertJsonPath('data.prediction.detected_label', $expectedName);

            $this->assertGreaterThanOrEqual(0.78, (float) $res->json('data.prediction.confidence'));
        }
    }

    public function test_non_monitored_animals_described_in_summary_never_return_unknown(): void
    {
        $cases = [
            ['Foto menampilkan seekor babi hutan sedang mencari makan di semak belukar.', 'Babi Hutan'],
            ['Terlihat macan dahan bertengger di atas dahan pohon pada malam hari.', 'Macan Dahan'],
            ['Seekor tapir melintas di jalur setapak hutan hujan.', 'Tapir'],
            ['Foto menunjukkan seekor singa jantan beristirahat di padang rumput.', 'Singa'],
            ['Terlihat seekor buaya berjemur di tepi sungai.', 'Buaya'],
            ['Kamera menangkap seekor elang hinggap di ranting tinggi.', 'Elang'],
        ];

        foreach ($cases as [$summary, $expectedLabel]) {
            Http::swap(new \Illuminate\Http\Client\Factory());

            $this->fakeVisionAndGroq(
                [['category' => 'animal', 'confidence' => 0.58, 'bbox' => [0.15, 0.2, 0.65, 0.65]]],
                null,
                [
                    'animal_detected' => true,
                    'species_common_name' => 'unknown',
                    'species_scientific_name' => null,
                    'confidence' => 0.5,
                    'individual_count' => 1,
                    'summary' => $summary,
                ],
            );

            $res = $this->post('/api/ai/upload', ['file' => $this->photo()], $this->headers)
                ->assertCreated()
                ->assertJsonPath('data.prediction.processing_status', 'completed')
                ->assertJsonPath('data.prediction.detected_label', $expectedLabel);

            $this->assertNotSame('unknown', strtolower((string) $res->json('data.prediction.detected_label')));
            $this->assertGreaterThanOrEqual(0.78, (float) $res->json('data.prediction.confidence'));
        }
    }

    public function test_two_monitored_animals_in_one_image_are_both_predicted_confidently(): void
    {
        $tiger = Species::where('common_name', 'Harimau Sumatra')->firstOrFail();

        $this->fakeVisionAndGroq(
            [
                ['category' => 'animal', 'confidence' => 0.88, 'bbox' => [0.05, 0.2, 0.4, 0.6]],
                ['category' => 'animal', 'confidence' => 0.84, 'bbox' => [0.55, 0.25, 0.35, 0.55]],
            ],
            null,
            [
                'animal_detected' => true,
                'species_common_name' => null,
                'confidence' => 0.6,
                'individual_count' => 2,
                'physical_condition' => 'healthy',
                'behavior' => 'alert',
                'summary' => 'Foto memperlihatkan seekor Harimau Sumatra mengintai Rusa Sambar di tepi padang rumput.',
            ],
        );

        $res = $this->post('/api/ai/upload', ['file' => $this->photo('tiger_deer.jpg')], $this->headers)
            ->assertCreated()
            ->assertJsonPath('data.prediction.processing_status', 'completed')
            ->assertJsonPath('data.prediction.predicted_species_id', $tiger->species_id)
            ->assertJsonPath('data.prediction.detected_label', 'Harimau Sumatra, Rusa Sambar')
            ->assertJsonPath('data.prediction.individual_count', 2);

        $this->assertGreaterThanOrEqual(0.78, (float) $res->json('data.prediction.confidence'));
    }

    public function test_three_diverse_monitored_animals_in_one_image(): void
    {
        $this->fakeVisionAndGroq(
            [
                ['category' => 'animal', 'confidence' => 0.9, 'bbox' => [0.05, 0.2, 0.3, 0.6]],
                ['category' => 'animal', 'confidence' => 0.82, 'bbox' => [0.4, 0.1, 0.25, 0.5]],
                ['category' => 'animal', 'confidence' => 0.79, 'bbox' => [0.7, 0.05, 0.2, 0.3]],
            ],
            ['common_name' => 'animal', 'scientific_name' => 'animal', 'rank' => 'kingdom', 'genus' => '', 'score' => 0.4],
            [
                'animal_detected' => true,
                'species_common_name' => 'unknown',
                'detected_species' => ['Gajah Sumatra', 'Orangutan Sumatra', 'Rangkong Badak'],
                'confidence' => 0.89,
                'individual_count' => 3,
                'summary' => 'Tampak Gajah Sumatra, Orangutan Sumatra, dan burung Rangkong Badak dalam satu lanskap hutan.',
            ],
        );

        $res = $this->post('/api/ai/upload', ['file' => $this->photo('three_sumatra.jpg')], $this->headers)
            ->assertCreated()
            ->assertJsonPath('data.prediction.processing_status', 'completed')
            ->assertJsonPath('data.prediction.individual_count', 3);

        $label = (string) $res->json('data.prediction.detected_label');
        $this->assertStringContainsString('Gajah Sumatra', $label);
        $this->assertStringContainsString('Orangutan Sumatra', $label);
        $this->assertStringContainsString('Rangkong Badak', $label);
        $this->assertGreaterThanOrEqual(0.89, (float) $res->json('data.prediction.confidence'));
    }

    public function test_mixed_monitored_and_non_monitored_animals_in_one_image(): void
    {
        $tiger = Species::where('common_name', 'Harimau Sumatra')->firstOrFail();

        $this->fakeVisionAndGroq(
            [
                ['category' => 'animal', 'confidence' => 0.85, 'bbox' => [0.1, 0.2, 0.3, 0.5]],
                ['category' => 'animal', 'confidence' => 0.81, 'bbox' => [0.45, 0.3, 0.25, 0.4]],
                ['category' => 'animal', 'confidence' => 0.77, 'bbox' => [0.72, 0.35, 0.2, 0.35]],
            ],
            null,
            [
                'animal_detected' => true,
                'species_common_name' => null,
                'confidence' => 0.5,
                'individual_count' => 3,
                'summary' => 'Terlihat Harimau Sumatra di dekat babi hutan dan seekor kijang di hutan sekunder.',
            ],
        );

        $res = $this->post('/api/ai/upload', ['file' => $this->photo('mixed_three.jpg')], $this->headers)
            ->assertCreated()
            ->assertJsonPath('data.prediction.processing_status', 'completed')
            ->assertJsonPath('data.prediction.predicted_species_id', $tiger->species_id)
            ->assertJsonPath('data.prediction.individual_count', 3);

        $label = (string) $res->json('data.prediction.detected_label');
        $this->assertStringContainsString('Harimau Sumatra', $label);
        $this->assertStringContainsString('Babi Hutan', $label);
        $this->assertStringContainsString('Kijang', $label);
        $this->assertGreaterThanOrEqual(0.78, (float) $res->json('data.prediction.confidence'));
    }

    public function test_multiple_non_monitored_savanna_animals_in_one_image_never_unknown(): void
    {
        $this->fakeVisionAndGroq(
            [
                ['category' => 'animal', 'confidence' => 0.72, 'bbox' => [0.1, 0.2, 0.25, 0.5]],
                ['category' => 'animal', 'confidence' => 0.69, 'bbox' => [0.4, 0.25, 0.25, 0.5]],
                ['category' => 'animal', 'confidence' => 0.71, 'bbox' => [0.7, 0.1, 0.2, 0.7]],
            ],
            null,
            [
                'animal_detected' => true,
                'species_common_name' => null,
                'confidence' => 0.4,
                'individual_count' => 3,
                'summary' => 'Foto memperlihatkan singa, zebra, dan jerapah berkumpul di dekat sumber air.',
            ],
        );

        $res = $this->post('/api/ai/upload', ['file' => $this->photo('savanna.jpg')], $this->headers)
            ->assertCreated()
            ->assertJsonPath('data.prediction.processing_status', 'completed')
            ->assertJsonPath('data.prediction.individual_count', 3);

        $label = (string) $res->json('data.prediction.detected_label');
        $this->assertStringContainsString('Singa', $label);
        $this->assertStringContainsString('Zebra', $label);
        $this->assertStringContainsString('Jerapah', $label);
        $this->assertGreaterThanOrEqual(0.78, (float) $res->json('data.prediction.confidence'));
    }

    public function test_multi_animal_extracted_from_objects_when_summary_is_generic(): void
    {
        $this->fakeVisionAndGroq(
            [
                ['category' => 'animal', 'confidence' => 0.81, 'bbox' => [0.1, 0.3, 0.35, 0.5]],
                ['category' => 'animal', 'confidence' => 0.76, 'bbox' => [0.55, 0.5, 0.35, 0.3]],
            ],
            null,
            [
                'animal_detected' => true,
                'species_common_name' => null,
                'confidence' => 0.5,
                'individual_count' => 2,
                'objects' => [
                    ['label' => 'Tapir', 'score' => 0.91],
                    ['label' => 'Buaya', 'score' => 0.87],
                ],
                'summary' => 'Dua satwa liar terlihat berada di tepi sungai pada sore hari.',
            ],
        );

        $res = $this->post('/api/ai/upload', ['file' => $this->photo('river.jpg')], $this->headers)
            ->assertCreated()
            ->assertJsonPath('data.prediction.processing_status', 'completed')
            ->assertJsonPath('data.prediction.detected_label', 'Tapir, Buaya')
            ->assertJsonPath('data.prediction.individual_count', 2);

        $this->assertGreaterThanOrEqual(0.91, (float) $res->json('data.prediction.confidence'));
    }

    public function test_phrase_tidak_ada_satwa_lain_does_not_suppress_detected_species(): void
    {
        $rhino = Species::where('common_name', 'Badak Sumatra')->firstOrFail();

        $this->fakeVisionAndGroq(
            [['category' => 'animal', 'confidence' => 0.83, 'bbox' => [0.2, 0.2, 0.6, 0.6]]],
            null,
            [
                'animal_detected' => true,
                'species_common_name' => null,
                'confidence' => 0.7,
                'individual_count' => 1,
                'summary' => 'Seekor Badak Sumatra terlihat berkubang di lumpur, tidak ada satwa lain di sekitar.',
            ],
        );

        $res = $this->post('/api/ai/upload', ['file' => $this->photo('rhino_alone.jpg')], $this->headers)
            ->assertCreated()
            ->assertJsonPath('data.prediction.processing_status', 'completed')
            ->assertJsonPath('data.prediction.predicted_species_id', $rhino->species_id)
            ->assertJsonPath('data.prediction.detected_label', 'Badak Sumatra');

        $this->assertGreaterThanOrEqual(0.78, (float) $res->json('data.prediction.confidence'));
    }

    public function test_groq_only_fallback_handles_multiple_animals_confidently(): void
    {
        config(['services.ai_vision.url' => '']);

        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'animal_detected' => true,
                            'species_common_name' => null,
                            'confidence' => 0.62,
                            'individual_count' => 3,
                            'summary' => 'Foto memperlihatkan Beruang Madu, Siamang di atas pohon, dan seekor kancil di lantai hutan.',
                        ]),
                    ],
                ]],
            ]),
        ]);

        $res = $this->post('/api/ai/upload', ['file' => $this->photo('three_fallback.jpg')], $this->headers)
            ->assertCreated()
            ->assertJsonPath('data.prediction.processing_status', 'completed')
            ->assertJsonPath('data.prediction.individual_count', 3);

        $label = (string) $res->json('data.prediction.detected_label');
        $this->assertStringContainsString('Beruang Madu', $label);
        $this->assertStringContainsString('Siamang', $label);
        $this->assertStringContainsString('Kancil', $label);
        $this->assertGreaterThanOrEqual(0.78, (float) $res->json('data.prediction.confidence'));
    }
}
