<?php

namespace OctopusWineManagment\API;

/**
 * Represents a single Product item
 */
class ProductDTO {
    public $id;
    public $name;
    public $productGroupID;
    public $productGroupName;
    public $price;
    public $vatPercent;
    public $storageLocation;
    public $quantity;
    public $costPrice;
    public $attributes = [];

    public function __construct($data) {
        $this->id = $data['id'] ?? null;
        $this->name = $data['name'] ?? null;
        $this->productGroupID = $data['productGroupID'] ?? null;
        $this->productGroupName = $data['productGroupName'] ?? null;
        $this->price = $data['price'] ?? 0.0;
        $this->vatPercent = $data['vatPercent'] ?? 0.0;
        $this->storageLocation = $data['storageLocation'] ?? null;
        $this->quantity = $data['quantity'] ?? 0.0;
        $this->costPrice = $data['costPrice'] ?? 0.0;
        
        if (isset($data['attributes'])) {
            foreach ($data['attributes'] as $attr) {
              $this->attributes[$attr['attributeName']] = $attr['attributeValue'];
            }
        }
    }

  /**
     * Helper to get a specific attribute value
     */
    public function getAttribute($name) {
        return $this->attributes[$name] ?? null;
    }
}
