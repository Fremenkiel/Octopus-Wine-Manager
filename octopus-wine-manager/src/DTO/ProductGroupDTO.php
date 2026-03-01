<?php

namespace OctopusWineManagment\DTO;

/**
 * Data Transfer Object for a HotelBoss product group (wine category).
 *
 * Constructed from the raw associative array returned by the
 * GET /api/Products/GetProductGroups endpoint.
 *
 * The 'id' is used in ProductSyncService::$product_group_filter to restrict
 * which groups are imported as owm_product posts.
 */
class ProductGroupDTO {
    /** @var int|null  HotelBoss product group ID. */
    public $id;

    /** @var string|null  Human-readable group name, e.g. 'Rødvin'. */
    public $name;

    /**
     * @param array $data  Raw group array from the API JSON response.
     */
    public function __construct($data) {
        $this->id   = $data['id']   ?? null;
        $this->name = $data['name'] ?? null;
    }
}
