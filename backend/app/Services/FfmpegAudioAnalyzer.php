<?php

namespace App\Services;

use App\Contracts\AudioAnalyzer;
use DomainException;

final class FfmpegAudioAnalyzer implements AudioAnalyzer
{
    public function analyze(string $file): array
    {
        if (! is_file($file) || is_link($file) || ! is_readable($file)) {
            throw new DomainException('Die Audio-Datei fehlt oder ist nicht sicher lesbar.');
        }
        $ffprobe = $this->binary('audio_ffprobe_path', 'ffprobe');
        $ffmpeg = $this->binary('audio_ffmpeg_path', 'ffmpeg');
        $durationOutput = trim($this->run([
            $ffprobe, '-v', 'error', '-select_streams', 'a:0', '-show_entries',
            'format=duration', '-of', 'default=noprint_wrappers=1:nokey=1', $file,
        ], 4096));
        if (! is_numeric($durationOutput) || ! is_finite((float) $durationOutput) || (float) $durationOutput <= 0) {
            throw new DomainException('Die Audio-Dauer konnte nicht ermittelt werden.');
        }
        $duration = round((float) $durationOutput, 6);
        $sampleRate = (int) config('cms.audio_waveform_sample_rate', 8000);
        $pcm = $this->run([
            $ffmpeg, '-v', 'error', '-nostdin', '-i', $file, '-map', '0:a:0',
            '-ac', '1', '-ar', (string) $sampleRate, '-f', 's16le', 'pipe:1',
        ], (int) config('cms.audio_analysis_max_decoded_bytes', 134217728));
        if ($pcm === '' || strlen($pcm) % 2 !== 0) {
            throw new DomainException('Die Audio-Waveform konnte nicht dekodiert werden.');
        }

        $bars = (int) config('audio-analysis.waveform_samples', 96);
        if ($bars < 16 || $bars > 512 || $sampleRate < 1000 || $sampleRate > 48000) {
            throw new DomainException('Die Audio-Analyse ist ungültig konfiguriert.');
        }
        $sampleCount = intdiv(strlen($pcm), 2);
        $peaks = array_fill(0, $bars, 0.0);
        for ($index = 0; $index < $sampleCount; $index++) {
            $offset = $index * 2;
            $value = ord($pcm[$offset]) | (ord($pcm[$offset + 1]) << 8);
            if ($value >= 0x8000) $value -= 0x10000;
            $bucket = min($bars - 1, intdiv($index * $bars, $sampleCount));
            $peaks[$bucket] = max($peaks[$bucket], abs($value) / 32768);
        }
        $maximum = max($peaks);
        if ($maximum > 0) {
            $peaks = array_map(static fn (float $peak): float => round($peak / $maximum, 6), $peaks);
        }
        $audioSha256 = hash_file('sha256', $file);
        if (! is_string($audioSha256)) throw new DomainException('Die Audio-Prüfsumme konnte nicht berechnet werden.');

        return [
            'duration' => $duration,
            'peaks' => $peaks,
            'audio_sha256' => $audioSha256,
            'waveform_sha256' => TrackAudioMetadata::waveformSha256($peaks),
        ];
    }

    public function binaryAvailable(string $configurationKey): bool
    {
        try {
            $this->binary($configurationKey, basename($configurationKey));
            return function_exists('proc_open');
        } catch (\Throwable) {
            return false;
        }
    }

    private function binary(string $configurationKey, string $label): string
    {
        $path = (string) config("cms.$configurationKey");
        $normalized = str_replace('\\', '/', trim($path));
        if ($normalized === '' || (! str_starts_with($normalized, '/') && ! preg_match('#^[A-Za-z]:/#D', $normalized))
            || str_contains($normalized, "\0") || preg_match('#(^|/)\.\.(/|$)#D', $normalized)
            || is_link($path) || ! is_file($path) || ! is_executable($path)) {
            throw new DomainException("Der konfigurierte {$label}-Pfad ist nicht verfügbar.");
        }
        $real = realpath($path);
        if ($real === false || str_replace('\\', '/', $real) !== $normalized) {
            throw new DomainException("Der konfigurierte {$label}-Pfad ist unsicher.");
        }
        return $real;
    }

    /** @param array<int, string> $command */
    private function run(array $command, int $maximumOutputBytes): string
    {
        if (! function_exists('proc_open') || $maximumOutputBytes < 1) {
            throw new DomainException('Die Audio-Analyse ist auf diesem Server nicht verfügbar.');
        }
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (! is_resource($process)) throw new DomainException('Die Audio-Analyse konnte nicht gestartet werden.');
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + max(1, (int) config('cms.audio_analysis_timeout_seconds', 120));
        $exitCode = null;
        try {
            while (true) {
                foreach ([1, 2] as $index) {
                    $chunk = stream_get_contents($pipes[$index]);
                    if ($chunk === false) throw new DomainException('Die Audio-Analyse konnte nicht gelesen werden.');
                    if ($index === 1) $stdout .= $chunk; else $stderr .= $chunk;
                }
                if (strlen($stdout) > $maximumOutputBytes || strlen($stderr) > 65536) {
                    proc_terminate($process, 9);
                    throw new DomainException('Die Audio-Analyse überschreitet das konfigurierte Limit.');
                }
                $status = proc_get_status($process);
                if (! $status['running']) { $exitCode = $status['exitcode']; break; }
                if (microtime(true) >= $deadline) {
                    proc_terminate($process, 9);
                    throw new DomainException('Zeitüberschreitung bei der Audio-Analyse.');
                }
                usleep(10000);
            }
            foreach ([1, 2] as $index) {
                $chunk = stream_get_contents($pipes[$index]);
                if ($chunk !== false) { if ($index === 1) $stdout .= $chunk; else $stderr .= $chunk; }
            }
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            $closed = proc_close($process);
            if ($exitCode === null && $closed >= 0) $exitCode = $closed;
        }
        if ($exitCode !== 0) throw new DomainException('Die Audio-Datei konnte nicht analysiert werden.');
        return $stdout;
    }
}
