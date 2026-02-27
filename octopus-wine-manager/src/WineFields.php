<?php

namespace OctopusWineManagment;

class WineFields {

    /**
     * Human-readable labels for every wine field used across the PDF
     * generator and the admin palette.
     *
     * @return array<string, string>  key => display label
     */
    public static function labels(): array {
        return [
            'wine'        => 'Wine',
            'origin'      => 'Origin',
            'vintage'     => 'Vintage',
            'price'       => 'Price',
        ];
    }
}
