<?php

namespace App\Services\ClassroomDesign;

/**
 * İlk açılıştaki ÖRNEK derslik: "Erbaa Bilgi Eğitim – TYT-A Dersliği", 7,20 × 5,80 m.
 * Akıllı tahta, öğretmen masası (sandalyeli), 20 tek kişilik masa (sandalyeli = 20 sandalye), kapı, 3 pencere.
 * Ölçüler ön yüzdeki katalogla aynıdır (resources/js/modules/classroom-design/catalog.ts):
 *   tek masa 0,70 × 0,50; masa arkasındaki sandalye alanı 0,42; öğretmen masası 1,40 × 0,70 (+0,55 sandalye).
 * Koordinat: metre; x sağa, z tahtadan arkaya. Çokgen saat yönünün tersine (pozitif alan) → duvar 0 = tahta duvarı.
 */
final class SampleLayout
{
    public const NAME = 'Erbaa Bilgi Eğitim – TYT-A Dersliği';

    public const WIDTH = 7.2;

    public const DEPTH = 5.8;

    /** @return array<string, mixed> */
    public static function data(): array
    {
        $w = self::WIDTH;
        $d = self::DEPTH;
        $objects = [
            ['id' => 'o-board', 'type' => 'smartboard', 'x' => 3.6, 'z' => 0.06, 'rot' => round(M_PI, 6), 'elev' => 0.9],
            ['id' => 'o-teacher', 'type' => 'teacher-desk', 'x' => 6.0, 'z' => 0.95, 'rot' => round(M_PI, 6), 'chair' => true],
        ];
        $no = 0;
        for ($row = 0; $row < 4; $row++) {
            for ($col = 0; $col < 5; $col++) {
                $no++;
                $objects[] = [
                    'id' => 'o-desk-'.$no, 'type' => 'desk-single',
                    'x' => round(0.95 + $col * 1.2, 3), 'z' => round(1.70 + $row * 1.04, 3), 'rot' => 0,
                    'chair' => true, 'no' => $no, 'status' => 'normal', 'seats' => [null],
                ];
            }
        }

        return [
            'schema' => 1,
            'room' => [
                'polygon' => [['x' => 0, 'z' => 0], ['x' => $w, 'z' => 0], ['x' => $w, 'z' => $d], ['x' => 0, 'z' => $d]],
                'ceiling' => 3.0,
                'wallThickness' => 0.2,
                'floor' => '1. Kat',
            ],
            'openings' => [
                // Sağ duvar (duvar 1), arkaya yakın, içe açılır
                ['id' => 'w-door', 'kind' => 'door', 'wall' => 1, 'offset' => 5.0, 'width' => 0.9, 'height' => 2.1, 'sill' => 0, 'swing' => 'in-left'],
                // Sol duvar (duvar 3, (0; 5,80) → (0; 0)): üç pencere
                ['id' => 'w-win-1', 'kind' => 'window', 'wall' => 3, 'offset' => 1.2, 'width' => 1.2, 'height' => 1.4, 'sill' => 0.9],
                ['id' => 'w-win-2', 'kind' => 'window', 'wall' => 3, 'offset' => 2.9, 'width' => 1.2, 'height' => 1.4, 'sill' => 0.9],
                ['id' => 'w-win-3', 'kind' => 'window', 'wall' => 3, 'offset' => 4.6, 'width' => 1.2, 'height' => 1.4, 'sill' => 0.9],
            ],
            'objects' => $objects,
            'camera' => null,
            'settings' => ['grid' => 0.1, 'snap' => true, 'labels' => true],
        ];
    }

    /** @return array<string, int|float> */
    public static function stats(): array
    {
        return ['area' => round(self::WIDTH * self::DEPTH, 2), 'desks' => 20, 'chairs' => 21, 'capacity' => 20, 'assigned' => 0, 'objects' => 22];
    }
}
