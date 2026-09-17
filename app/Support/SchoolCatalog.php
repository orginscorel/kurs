<?php

namespace App\Support;

/**
 * Bölge okul kataloğu (liseler). Kaynak: MEB il/ilçe okul listeleri (Eylül 2026 taraması).
 * Kurum kurulumda bölge(ler) seçer; okullar kurumun `schools` tablosuna kopyalanır, sonra ayarlardan düzenlenir.
 */
final class SchoolCatalog
{
    public const KINDS = [
        'anadolu' => 'Anadolu Lisesi',
        'fen' => 'Fen Lisesi',
        'sosyal' => 'Sosyal Bilimler Lisesi',
        'imam_hatip' => 'Anadolu İmam Hatip Lisesi',
        'mesleki' => 'Mesleki ve Teknik Anadolu Lisesi',
        'cok_programli' => 'Çok Programlı Anadolu Lisesi',
        'ozel' => 'Özel Lise',
        'ortaokul' => 'Ortaokul',
        'diger' => 'Diğer',
    ];

    public const REGIONS = [
        'tokat-erbaa' => [
            'label' => 'Tokat / Erbaa',
            'city' => 'Tokat',
            'district' => 'Erbaa',
            'schools' => [
                ['Erbaa Merkez Anadolu Lisesi', 'anadolu'],
                ['Coşkun Önder Anadolu Lisesi', 'anadolu'],
                ['Fatih Anadolu Lisesi', 'anadolu'],
                ['Yılmaz Kayalar Fen Lisesi', 'fen'],
                ['Erbaa Anadolu İmam Hatip Lisesi', 'imam_hatip'],
                ['Erbaa Kız Anadolu İmam Hatip Lisesi', 'imam_hatip'],
                ['Erbaa Mesleki ve Teknik Anadolu Lisesi', 'mesleki'],
                ['Abdulhamid Han Mesleki ve Teknik Anadolu Lisesi', 'mesleki'],
                ['Erek Mesleki ve Teknik Anadolu Lisesi', 'mesleki'],
                ['Seyrantepe Mesleki ve Teknik Anadolu Lisesi', 'mesleki'],
                ['Gökal Güldere Çok Programlı Anadolu Lisesi', 'cok_programli'],
                ['Karayaka Çok Programlı Anadolu Lisesi', 'cok_programli'],
                ['Özel Açı Temel Lisesi', 'ozel'],
                ['Özel Erbaa Sınav Temel Lisesi', 'ozel'],
            ],
        ],
        'amasya-tasova' => [
            'label' => 'Amasya / Taşova',
            'city' => 'Amasya',
            'district' => 'Taşova',
            'schools' => [
                ['Şehit İdris Bolat Anadolu Lisesi', 'anadolu'],
                ['Şehit Bekir Özdemir Anadolu İmam Hatip Lisesi', 'imam_hatip'],
                ['Şehit Orhan Gülmez Çok Programlı Anadolu Lisesi', 'cok_programli'],
                ['Taşova Şehit Polis Ahmet Yaşar Mesleki ve Teknik Anadolu Lisesi', 'mesleki'],
                ['Taşova Süleyman Bursalı Mesleki ve Teknik Anadolu Lisesi', 'mesleki'],
            ],
        ],
    ];

    /** @return list<array{key:string,label:string,count:int}> */
    public static function regions(): array
    {
        return array_map(fn ($k, $r) => ['key' => $k, 'label' => $r['label'], 'count' => count($r['schools'])], array_keys(self::REGIONS), self::REGIONS);
    }
}
