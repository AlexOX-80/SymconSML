<?php
class SMLAlignment
{
    public static function append(array $samples, float $time, array $values, float $keepSeconds = 90.0): array
    {
        $samples[] = ['t' => $time, 'v' => $values];
        usort($samples, static function ($a,$b) { return $a['t'] <=> $b['t']; });
        return array_values(array_filter($samples, static function ($s) use ($time,$keepSeconds) {
            return $s['t'] >= $time - $keepSeconds;
        }));
    }
    public static function at(array $samples, string $field, float $target, float $maxGap): ?float
    {
        $before = null;
        $after = null;
        foreach ($samples as $sample) {
            if (!isset($sample['v'][$field]) || !is_numeric($sample['v'][$field])) continue;
            if ($sample['t'] <= $target) $before = $sample;
            if ($sample['t'] >= $target) { $after = $sample; break; }
        }
        if ($before === null || $after === null) return null;
        $duration = $after['t'] - $before['t'];
        if ($duration > $maxGap) return null;
        if ($duration <= 0) return (float) $before['v'][$field];
        return (float) $before['v'][$field] + ((float)$after['v'][$field] - (float)$before['v'][$field]) * (($target - $before['t']) / $duration);
    }
}
