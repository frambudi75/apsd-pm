<?php
/**
 * Checklist & Photo Slot Definitions
 * PT Angkasa Pura Sarana Digital - Sistem PM
 */

function get_indoor_checklist_definitions(): array {
    return [
        'A' => [
            'name' => 'Air Conditioner',
            'items' => [
                1 => ['name' => 'Kondisi AC Indoor', 'ref' => 'Pastikan terpasang dengan benar dan tidak ada indikasi akan lepas dari bracket'],
                2 => ['name' => 'Kondisi AC Outdoor', 'ref' => 'Pastikan terpasang dengan benar dan tidak ada indikasi akan lepas dari bracket'],
                3 => ['name' => 'Kondisi Pipa', 'ref' => 'Pastikan kondisi pipa tidak bocor'],
                4 => ['name' => 'Pengaturan Suhu AC', 'ref' => 'Pastikan suhu dalam keadan <24°'],
                5 => ['name' => 'Suhu Ruangan', 'ref' => 'Masukkan nilai suhu ruangan'],
            ]
        ],
        'B' => [
            'name' => 'Ruang Server',
            'items' => [
                1 => ['name' => 'Kondisi Atap Ruangan', 'ref' => 'Pastikan atap ruangan tidak bocor dalam keadaan baik'],
                2 => ['name' => 'Dinding Server', 'ref' => 'Pastikan dinding tidak retak'],
                4 => ['name' => 'APAR', 'ref' => 'Pastikan isi APAR dalam keadaan baik'],
                5 => ['name' => 'Kondisi Pintu Ruangan', 'ref' => 'Pastikan kondisi pintu ruangan dalam keadaan baik dan terkunci'],
            ]
        ],
        'C' => [
            'name' => 'Daya Listrik MCB',
            'items' => [
                1 => ['name' => 'Main Circuit Bracker', 'ref' => 'Pastikan MCB dalam keadaan naik (ON)'],
            ]
        ],
        'D' => [
            'name' => 'Optical Termination Box',
            'items' => [
                1 => ['name' => 'Kondisi core', 'ref' => 'Pastikan core dalam keadaan baik dan tidak putus'],
            ]
        ],
        'E' => [
            'name' => 'Remote Radio Unit',
            'items' => [
                1 => ['name' => 'Connectors', 'ref' => 'Pastikan Connectors dalam keadaan terpasang dan tidak terlepas'],
                2 => ['name' => 'Kabel Jumper', 'ref' => 'Pastikan kabel jumper dalam keadaan baik'],
                3 => ['name' => 'Kabel Feeder', 'ref' => 'Pastikan kabel feeder dalam keadaan baik'],
                4 => ['name' => 'Bracket', 'ref' => 'Pastikan bracket dalam keadaan terpasang dengan baik'],
                5 => ['name' => 'Kondisi Patchcord', 'ref' => 'Pastikan kondisi konektor dan pigtail tidak putus'],
                6 => ['name' => 'Kabel Power RRU', 'ref' => 'Pastikan kabel power tersambung dengan baik'],
            ]
        ],
        'F' => [
            'name' => 'Multi Network Combiner',
            'items' => [
                1 => ['name' => 'Kondisi Fisik', 'ref' => 'Pastikan kondisi fisik MNC dalam keadaan baik tidak ada dent'],
                2 => ['name' => 'Kondisi Bracket', 'ref' => 'Pastikan kondisi bracket dalam keadaan terpasang dengan baik'],
                3 => ['name' => 'Connectors', 'ref' => 'Pastikan kondisi connectors dalam keadaan kencang dan terpasang'],
                4 => ['name' => 'Kondisi kabel', 'ref' => 'Pastikan kondisi kabel dalam keadaan terpasang'],
            ]
        ],
        'G' => [
            'name' => 'Splitter 2 Way',
            'items' => [
                1 => ['name' => 'Kondisi Fisik', 'ref' => 'Pastikan kondisi fisik S2W dalam keadaan baik tidak ada dent'],
                2 => ['name' => 'Connectors', 'ref' => 'Pastikan kondisi connectors dalam keadaan kencang dan terpasang'],
                3 => ['name' => 'Kondisi kabel Jumper', 'ref' => 'Pastikan kondisi kabel dalam keadaan baik'],
            ]
        ],
        'H' => [
            'name' => 'Splitter 3 Way',
            'items' => [
                1 => ['name' => 'Kondisi Fisik', 'ref' => 'Pastikan kondisi fisik S3W dalam keadaan baik tidak ada dent'],
                2 => ['name' => 'Connectors', 'ref' => 'Pastikan kondisi connectors dalam keadaan kencang dan terpasang'],
                3 => ['name' => 'Kondisi kabel Jumper', 'ref' => 'Pastikan kondisi kabel dalam keadaan baik'],
            ]
        ],
        'I' => [
            'name' => 'Splitter 4 Way',
            'items' => [
                1 => ['name' => 'Kondisi Fisik', 'ref' => 'Pastikan kondisi fisik S4W dalam keadaan baik tidak ada dent'],
                2 => ['name' => 'Connectors', 'ref' => 'Pastikan kondisi connectors dalam keadaan kencang dan terpasang'],
                3 => ['name' => 'Kondisi kabel Jumper', 'ref' => 'Pastikan kondisi kabel dalam keadaan baik'],
            ]
        ],
        'J' => [
            'name' => 'Antenna Planar',
            'items' => [
                1 => ['name' => 'Kondisi Fisik', 'ref' => 'Pastikan Antenna dalam keadaan terpasang dengan baik'],
                2 => ['name' => 'Connectors', 'ref' => 'Pastikan connector dalam keadaan terpasang dengan baik'],
            ]
        ],
        'K' => [
            'name' => 'Antenna Omnidirectional',
            'items' => [
                1 => ['name' => 'Kondisi Fisik', 'ref' => 'Pastikan Antenna dalam keadaan terpasang dengan baik'],
                2 => ['name' => 'Connectors', 'ref' => 'Pastikan connector dalam keadaan terpasang dengan baik'],
            ]
        ],
    ];
}

function get_indoor_photo_slots(): array {
    return [
        'A' => [
            'page_title' => 'Dokumentasi Halaman 4 (Grup A)',
            'slots' => [
                'A-1' => ['title' => 'KWH Multi Panel', 'has_measurement' => false],
                'A-2' => ['title' => 'KWH Meter Telkomsel', 'has_measurement' => false],
                'A-3' => ['title' => 'ACPDB Panel Closed', 'has_measurement' => false],
                'A-4' => ['title' => 'ACPDB Panel Open', 'has_measurement' => false],
                'A-5' => ['title' => 'View Area Domestik', 'has_measurement' => false],
                'A-6' => ['title' => 'View Kebersihan Area Domestik', 'has_measurement' => false],
            ]
        ],
        'B' => [
            'page_title' => 'Dokumentasi Halaman 5 (Grup B)',
            'slots' => [
                'B-1' => ['title' => 'MCB ACPDB Telkomsel', 'has_measurement' => false],
                'B-2' => ['title' => 'Pengukuran Tegangan (R-S)', 'has_measurement' => true, 'default_label' => 'R-S = 402.6'],
                'B-3' => ['title' => 'Pengukuran Tegangan (R-T)', 'has_measurement' => true, 'default_label' => 'R-T = 403.5'],
                'B-4' => ['title' => 'Pengukuran Tegangan (S-T)', 'has_measurement' => true, 'default_label' => 'S-T = 402.6'],
                'B-5' => ['title' => 'Pengukuran Tegangan (R-N)', 'has_measurement' => true, 'default_label' => 'R-N = 230.8'],
                'B-6' => ['title' => 'Pengukuran Tegangan (S-N)', 'has_measurement' => true, 'default_label' => 'S-N = 231.2'],
                'B-7' => ['title' => 'Pengukuran Tegangan (T-N)', 'has_measurement' => true, 'default_label' => 'T-N = 230.5'],
                'B-8' => ['title' => 'Pengukuran Tegangan (G-N)', 'has_measurement' => true, 'default_label' => 'G-N = 0.168'],
            ]
        ],
        'C' => [
            'page_title' => 'Dokumentasi Halaman 6 (Grup C)',
            'slots' => [
                'C-1' => ['title' => 'BTS Recty', 'has_measurement' => false],
                'C-2' => ['title' => 'RRU', 'has_measurement' => false],
                'C-3' => ['title' => 'Grounding Busbar 1', 'has_measurement' => false],
                'C-4' => ['title' => 'Grounding Busbar 2', 'has_measurement' => false],
                'C-5' => ['title' => 'View AC Ruangan', 'has_measurement' => false],
                'C-6' => ['title' => 'Pengaturan Suhu Ruangan', 'has_measurement' => false],
            ]
        ],
        'D' => [
            'page_title' => 'Dokumentasi Halaman 7 (Grup D)',
            'slots' => [
                'D-1' => ['title' => 'APAR Ruangan', 'has_measurement' => false],
                'D-2' => ['title' => 'Pengukuran Suhu Ruangan', 'has_measurement' => false],
                'D-3' => ['title' => 'Keterangan Foto', 'has_measurement' => false],
                'D-4' => ['title' => 'Keterangan Foto', 'has_measurement' => false],
                'D-5' => ['title' => 'Keterangan Foto', 'has_measurement' => false],
                'D-6' => ['title' => 'Keterangan Foto', 'has_measurement' => false],
            ]
        ]
    ];
}

function get_outdoor_photo_slots(): array {
    return [
        'A' => [
            'page_title' => 'Dokumentasi Halaman 6 (Grup A)',
            'slots' => [
                'A-1' => ['title' => '', 'has_measurement' => false],
                'A-2' => ['title' => 'Tampak akses', 'has_measurement' => false],
                'A-3' => ['title' => 'Tampak halaman site (Before Cleaning)', 'has_measurement' => false],
                'A-4' => ['title' => 'Tampak halaman site (After Cleaning)', 'has_measurement' => false],
                'A-5' => ['title' => 'Name Plate', 'has_measurement' => false],
                'A-6' => ['title' => 'Tampak Pedestal/Kaki tower', 'has_measurement' => false],
            ]
        ],
        'B' => [
            'page_title' => 'Dokumentasi Halaman 7 (Grup B)',
            'slots' => [
                'B-1' => ['title' => 'Status grounding - Splitzen', 'has_measurement' => false],
                'B-2' => ['title' => 'Status grounding - Bolt connection', 'has_measurement' => false],
                'B-3' => ['title' => 'OBL', 'has_measurement' => false],
                'B-4' => ['title' => 'Yard lamp', 'has_measurement' => false],
                'B-5' => ['title' => 'Pengukuran grounding - Fance/Pagar', 'has_measurement' => false],
                'B-6' => ['title' => 'Pengukuran grounding - Pedestal', 'has_measurement' => true, 'default_label' => '0.26'],
            ]
        ],
        'C' => [
            'page_title' => 'Dokumentasi Halaman 8 (Grup C)',
            'slots' => [
                'C-1' => ['title' => 'Pengukuran grounding - Box control', 'has_measurement' => false],
                'C-2' => ['title' => 'Tampak kebocoran site (rooftop only)', 'has_measurement' => false],
                'C-3' => ['title' => 'Tower view A-B', 'has_measurement' => false],
                'C-4' => ['title' => 'Tower view B-C', 'has_measurement' => false],
                'C-5' => ['title' => 'Tower view C-D', 'has_measurement' => false],
                'C-6' => ['title' => 'Tower view D-A', 'has_measurement' => false],
            ]
        ],
        'D' => [
            'page_title' => 'Dokumentasi Halaman 9 (Grup D)',
            'slots' => [
                'D-1' => ['title' => 'Tower view - From bottom to top 1', 'has_measurement' => false],
                'D-2' => ['title' => 'Tower view - From bottom to top 2', 'has_measurement' => false],
                'D-3' => ['title' => 'Tower view - From bottom to top 3', 'has_measurement' => false],
                'D-4' => ['title' => 'Tower view - From top to bottom 1', 'has_measurement' => false],
                'D-5' => ['title' => 'Tower view - From top to bottom 2', 'has_measurement' => false],
                'D-6' => ['title' => 'Tower view - From top to bottom 3', 'has_measurement' => false],
            ]
        ],
        'E' => [
            'page_title' => 'Dokumentasi Halaman 10 (Grup E - Panoramic 0-150)',
            'slots' => [
                'E-1' => ['title' => 'Site view - Panoramic 0', 'has_measurement' => false],
                'E-2' => ['title' => 'Site view - Panoramic 30', 'has_measurement' => false],
                'E-3' => ['title' => 'Site view - Panoramic 60', 'has_measurement' => false],
                'E-4' => ['title' => 'Site view - Panoramic 90', 'has_measurement' => false],
                'E-5' => ['title' => 'Site view - Panoramic 120', 'has_measurement' => false],
                'E-6' => ['title' => 'Site view - Panoramic 150', 'has_measurement' => false],
            ]
        ],
        'F' => [
            'page_title' => 'Dokumentasi Halaman 11 (Grup F - Panoramic 180-300)',
            'slots' => [
                'F-1' => ['title' => 'Site view - Panoramic 180', 'has_measurement' => false],
                'F-2' => ['title' => 'Site view - Panoramic 210', 'has_measurement' => false],
                'F-3' => ['title' => 'Site view - Panoramic 240', 'has_measurement' => false],
                'F-4' => ['title' => 'Site view - Panoramic 270', 'has_measurement' => false],
                'F-5' => ['title' => 'Site view - Panoramic 300', 'has_measurement' => false],
                'F-6' => ['title' => 'Stiker notification di rumah site keeper (if any)', 'has_measurement' => false],
            ]
        ],
        'G' => [
            'page_title' => 'Dokumentasi Halaman 12 (Grup G - Segment & Corrosion)',
            'slots' => [
                'G-1' => ['title' => 'Tower segment 1', 'has_measurement' => false],
                'G-2' => ['title' => 'Tower segment 2', 'has_measurement' => false],
                'G-3' => ['title' => 'Tower segment 3', 'has_measurement' => false],
                'G-4' => ['title' => 'Tower segment 4', 'has_measurement' => false],
                'G-5' => ['title' => 'Tower corrosion', 'has_measurement' => false],
                'G-6' => ['title' => 'Tower corrosion', 'has_measurement' => false],
            ]
        ],
        'H' => [
            'page_title' => 'Dokumentasi Halaman 13 (Grup H - Pondasi, Tanah & Safety)',
            'slots' => [
                'H-1' => ['title' => 'Pedestal & baseplate condition', 'has_measurement' => false],
                'H-2' => ['title' => 'Pedestal & baseplate condition', 'has_measurement' => false],
                'H-3' => ['title' => 'Soil & gravel condition', 'has_measurement' => false],
                'H-4' => ['title' => 'Soil & gravel condition', 'has_measurement' => false],
                'H-5' => ['title' => 'Foto Safety', 'has_measurement' => false],
                'H-6' => ['title' => 'Building existing condition', 'has_measurement' => false],
            ]
        ]
    ];
}
