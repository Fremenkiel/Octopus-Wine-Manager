<?php

namespace OctopusWineManagment\DTO;

/**
 * Represents a Product Category
 */
class ProductGroupDTO {
    public $id;
    public $name;

    public function __construct($data) {
        $this->id = $data['id'] ?? null;
        $this->name = $data['name'] ?? null;
    }
}
