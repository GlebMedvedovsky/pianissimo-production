<?php

namespace App\Contracts;

interface AudioAnalyzer
{
    /** @return array{duration: float, peaks: array<int, float>, audio_sha256: string, waveform_sha256: string} */
    public function analyze(string $file): array;
}
