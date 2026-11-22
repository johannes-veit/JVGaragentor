<?php
declare(strict_types=1);

/**
 * LCN-SKH Tastenprotokoll.
 * In LCN-PRO beim physikalischen Tastendruck je Ereignis "Sende
 * Kommando an Host", Freie Eingabe: 57 XX YY konfigurieren.
 * XX = Tabelle + Taste, YY = LOS 0 / LANG 1 / KURZ 2.
 *
 * IP-Symcon: Im sendenden LCN-Modul "Host-Kommando" erstellen.
 * Die dortige String-Variable "Befehl" enthält beide Bytes in DEZIMAL
 * mit jeweils drei Stellen: z. B. 033002 für C1 KURZ.
 */
final class JVLCNCodec
{
    public static function byte(string $table, int $key): ?int
    {
        $offsets = ['A' => 0, 'B' => 16, 'C' => 32, 'D' => 64];
        $table = strtoupper($table);
        if (!isset($offsets[$table]) || $key < 1 || $key > 8) {
            return null;
        }
        return $offsets[$table] + $key;
    }

    public static function gestureByte(string $gesture): ?int
    {
        return match (strtoupper($gesture)) {
            'KURZ' => 2,
            'LANG' => 1,
            'LOS'  => 0,
            default => null
        };
    }

    public static function code(string $table, int $key, string $gesture): ?string
    {
        $first = self::byte($table, $key);
        $second = self::gestureByte($gesture);
        if ($first === null || $second === null) {
            return null;
        }
        return sprintf('%03d%03d', $first, $second);
    }

    public static function command(string $table, int $key, string $gesture): string
    {
        $first = self::byte($table, $key);
        $second = self::gestureByte($gesture);
        if ($first === null || $second === null) {
            return 'ungültige Tabelle/Taste';
        }
        return sprintf('57 %02X %02X', $first, $second);
    }

    public static function normalize(mixed $raw): ?string
    {
        if (!is_string($raw) && !is_int($raw)) {
            return null;
        }
        $value = trim((string)$raw);
        if (!preg_match('/^[0-9]{1,6}$/D', $value)) {
            return null;
        }
        $value = str_pad($value, 6, '0', STR_PAD_LEFT);
        $first = (int)substr($value, 0, 3);
        $second = (int)substr($value, 3, 3);
        // Nur definierte Table-Key-Kombinationen und LCN-Tastentypen.
        if (!in_array($second, [0,1,2], true) ||
            self::decodeTableAndKey($first) === null) {
            return null;
        }
        return $value;
    }

    /** @return array{table:string,key:int}|null */
    public static function decodeTableAndKey(int $first): ?array
    {
        foreach (['D' => 64, 'C' => 32, 'B' => 16, 'A' => 0] as $table => $offset) {
            $key = $first - $offset;
            if ($key >= 1 && $key <= 8) {
                return ['table' => $table, 'key' => $key];
            }
        }
        return null;
    }

    public static function describe(string $normalized): string
    {
        $first = (int)substr($normalized, 0, 3);
        $second = (int)substr($normalized, 3, 3);
        $button = self::decodeTableAndKey($first);
        if ($button === null) {
            return 'ungültiges LCN-Ereignis';
        }
        return $button['table'] . $button['key'] . ' ' .
            ([0 => 'LOS', 1 => 'LANG', 2 => 'KURZ'][$second] ?? '?');
    }
}
