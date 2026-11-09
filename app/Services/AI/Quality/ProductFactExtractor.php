<?php

namespace App\Services\AI\Quality;

/**
 * Pulls checkable facts out of text in English, Albanian or German using fixed
 * patterns only. A match is a reason to review the text, not proof of an error.
 */
final class ProductFactExtractor
{
    private const UNITS = [
        'mm' => ['mm', 'millimeter', 'millimeters', 'millimetre', 'millimetres', 'milimetër', 'milimetra'],
        'cm' => ['cm', 'centimeter', 'centimeters', 'centimetre', 'centimetres', 'zentimeter', 'centimetër', 'centimetra'],
        'm' => ['m', 'meter', 'meters', 'metre', 'metres', 'metër', 'metra'],
        'in' => ['inch', 'inches', 'zoll', 'inç', '"'],
        'g' => ['g', 'gr', 'gram', 'grams', 'gramm', 'gramë'],
        'kg' => ['kg', 'kilogram', 'kilograms', 'kilogramm'],
        'ml' => ['ml', 'milliliter', 'milliliters', 'mililitër'],
        'l' => ['l', 'liter', 'liters', 'litre', 'litres', 'litër', 'litra'],
        'oz' => ['oz', 'ounce', 'ounces'],
        'lb' => ['lb', 'lbs', 'pound', 'pounds'],
        'w' => ['w', 'watt', 'watts'],
        'v' => ['v', 'volt', 'volts'],
        'mah' => ['mah'],
        'gb' => ['gb'],
        'tb' => ['tb'],
        'hz' => ['hz'],
        '°c' => ['°c'],
        '%' => ['%'],
    ];

    /**
     * A leading "=" means the whole word must match. Otherwise any word starting with the
     * term matches, which allows for endings such as wooden, Leders or lëkurë.
     */
    private const MATERIALS = [
        'PLA' => ['=pla'],
        'PETG' => ['=petg'],
        'ABS' => ['=abs'],
        'ASA' => ['=asa'],
        'TPU' => ['=tpu'],
        'Nylon' => ['nylon', 'najlon'],
        'Resin' => ['resin', 'harz', 'rrëshir'],
        'Plastic' => ['plast', 'kunststoff'],
        'Wood' => ['wood', 'holz', '=dru', '=druri', '=druje'],
        'Bamboo' => ['bamboo', 'bambus', 'bambu'],
        'Metal' => ['metal'],
        'Steel' => ['steel', 'stahl', 'çelik'],
        'Aluminum' => ['alumin'],
        'Brass' => ['brass', 'messing', 'tunxh'],
        'Bronze' => ['bronze', 'bronz'],
        'Copper' => ['copper', 'kupfer', '=bakër', '=bakri'],
        'Iron' => ['=iron', 'eisen', '=hekur', '=hekuri'],
        'Leather' => ['leather', 'leder', 'lëkur'],
        'Cotton' => ['cotton', 'baumwoll', 'pambuk'],
        'Wool' => ['wool', 'wolle', '=lesh', '=leshi'],
        'Glass' => ['glass', '=glas', 'qelq'],
        'Ceramic' => ['ceramic', 'keramik', 'qeramik'],
        'Silicone' => ['silicon', 'silikon'],
        'Rubber' => ['rubber', '=gummi', '=gomë'],
        'Paper' => ['=paper', '=papier', '=letër'],
    ];

    /**
     * Mentioning the general material is fine when the facts name a specific one.
     */
    public const MATERIAL_FAMILIES = [
        'Metal' => ['Steel', 'Aluminum', 'Brass', 'Bronze', 'Copper', 'Iron'],
        'Plastic' => ['PLA', 'PETG', 'ABS', 'ASA', 'TPU', 'Nylon', 'Resin'],
    ];

    private const CLAIMS = [
        'a guarantee or warranty' => ['guarantee', 'warrant', 'garantie', 'garantiert', 'garanci', 'garantuar'],
        'a "best" or superlative claim' => ['the best', 'best-selling', 'best in class', 'world-class', 'top-rated', 'number one', '#1', 'die beste', 'der beste', 'das beste', 'bestseller', 'më i miri', 'më e mira'],
        'an absolute "100%" claim' => ['100%', '100 %'],
        'water resistance' => ['waterproof', 'water-resistant', 'water resistant', 'wasserdicht', 'wasserfest', 'papërshkueshëm'],
        'unbreakable durability' => ['unbreakable', 'indestructible', 'unzerbrechlich', 'unverwüstlich', 'pathyeshëm', 'pathyeshme'],
        'a certification' => ['certified', 'certification', 'zertifiziert', 'zertifikat', 'certifikuar', 'certifikim', 'fda'],
        'an environmental claim' => ['eco-friendly', 'eco friendly', 'environmentally friendly', 'sustainable', 'biodegradable', 'umweltfreundlich', 'nachhaltig', 'biologisch abbaubar', 'ekologjik', 'miqësor me mjedisin', 'biodegradueshëm'],
        'a health or safety claim' => ['food-safe', 'food safe', 'food-grade', 'food grade', 'bpa', 'non-toxic', 'nontoxic', 'hypoallergen', 'antibacterial', 'fireproof', 'lebensmittelecht', 'lebensmittelsicher', 'ungiftig', 'antibakteriell', 'feuerfest', 'jotoksik', 'hipoalergjik', 'antibakterial', 'i sigurt për ushqim'],
        'a lifetime claim' => ['lifetime', 'lifelong', 'lebenslang', 'gjithë jetën'],
    ];

    private const PLATFORMS = 'iphone|ipad|macbook|airpods|galaxy|pixel|xbox|playstation|nintendo|steam deck|android|samsung|huawei|sony|microsoft';

    private const NOT_MODELS = ['2d', '3d'];

    /**
     * @return list<string> such as "12 cm" or "10x20x5 cm"
     */
    public function measurements(string $text): array
    {
        return $this->scanMeasurements($text)[0];
    }

    /**
     * @return list<string>
     */
    public function allNumbers(string $text): array
    {
        preg_match_all('/\d+(?:[.,]\d+)?/u', $text, $matches);

        return array_values(array_unique(array_map($this->number(...), $matches[0])));
    }

    /**
     * Numbers that aren't part of a measurement or a model name.
     *
     * @return list<string>
     */
    public function bareNumbers(string $text): array
    {
        $rest = $this->scanMeasurements($text)[1];

        preg_match_all('/(?<![\p{L}\p{N}.,\-])\d+(?:[.,]\d+)?(?![\p{L}\p{N}]|[.,]\d)/u', $rest, $matches);

        return array_values(array_unique(array_map($this->number(...), $matches[0])));
    }

    /**
     * @return list<string> canonical material names
     */
    public function materials(string $text): array
    {
        $text = mb_strtolower($text);
        $found = [];

        foreach (self::MATERIALS as $material => $terms) {
            foreach ($terms as $term) {
                $pattern = str_starts_with($term, '=')
                    ? '/(?<![\p{L}\p{N}])'.preg_quote(substr($term, 1), '/').'(?![\p{L}\p{N}])/u'
                    : '/(?<![\p{L}\p{N}])'.preg_quote($term, '/').'/u';

                if (preg_match($pattern, $text) === 1) {
                    $found[] = $material;

                    break;
                }
            }
        }

        return $found;
    }

    /**
     * Devices, platforms and model-like tokens such as PS5, in one canonical spelling.
     *
     * @return list<string>
     */
    public function compatibility(string $text): array
    {
        $text = mb_strtolower($this->scanMeasurements($text)[1]);
        $text = (string) preg_replace('/(?<![\p{L}\p{N}])ps\s*(\d)(?![\p{L}\p{N}])/u', 'playstation$1', $text);

        $tokens = [];

        preg_match_all('/(?<![\p{L}\p{N}])('.self::PLATFORMS.')(?:\s*(\d+|series\s+[xs]|one|switch))?(?![\p{L}\p{N}])/u', $text, $platforms, PREG_SET_ORDER);

        foreach ($platforms as $match) {
            $tokens[] = (string) preg_replace('/\s+/', '', $match[1].($match[2] ?? ''));
        }

        preg_match_all('/(?<![\p{L}\p{N}])(?=\p{L}*\d)(?=\d*\p{L})[\p{L}\p{N}]{2,}(?![\p{L}\p{N}])/u', $text, $models);

        foreach ($models[0] as $model) {
            if (! in_array($model, self::NOT_MODELS, true) && preg_match('/^\d+(?:st|nd|rd|th)$/', $model) !== 1) {
                $tokens[] = $model;
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @return list<string> descriptions of the kinds of claim found
     */
    public function claims(string $text): array
    {
        $text = mb_strtolower($text);
        $found = [];

        foreach (self::CLAIMS as $claim => $terms) {
            foreach ($terms as $term) {
                if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($term, '/').'/u', $text) === 1) {
                    $found[] = $claim;

                    break;
                }
            }
        }

        return $found;
    }

    /**
     * @return array{list<string>, string} the measurements, and the text without them
     */
    private function scanMeasurements(string $text): array
    {
        $units = $this->unitPattern();
        $found = [];

        $text = (string) preg_replace_callback(
            '/(?<![\p{L}\p{N}.,])(\d+(?:[.,]\d+)?(?:\s*[x×]\s*\d+(?:[.,]\d+)?)+)\s*('.$units.')?(?![\p{L}\p{N}])/iu',
            function (array $match) use (&$found) {
                $numbers = array_map($this->number(...), preg_split('/\s*[x×]\s*/iu', $match[1]));
                $found[] = trim(implode('x', $numbers).' '.$this->canonicalUnit($match[2] ?? ''));

                return ' ';
            },
            $text,
        );

        $text = (string) preg_replace_callback(
            '/(?<![\p{L}\p{N}.,])(\d+(?:[.,]\d+)?)\s*('.$units.')(?![\p{L}\p{N}])/iu',
            function (array $match) use (&$found) {
                $found[] = $this->number($match[1]).' '.$this->canonicalUnit($match[2]);

                return ' ';
            },
            $text,
        );

        return [array_values(array_unique($found)), $text];
    }

    private function unitPattern(): string
    {
        $variants = array_merge(...array_values(self::UNITS));

        usort($variants, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        return implode('|', array_map(fn (string $variant) => preg_quote($variant, '/'), $variants));
    }

    private function canonicalUnit(string $unit): string
    {
        $unit = mb_strtolower($unit);

        foreach (self::UNITS as $canonical => $variants) {
            if (in_array($unit, $variants, true)) {
                return $canonical;
            }
        }

        return $unit;
    }

    private function number(string $value): string
    {
        $value = str_replace(',', '.', $value);

        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
