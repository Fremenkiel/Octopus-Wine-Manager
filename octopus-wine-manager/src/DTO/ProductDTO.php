<?php

namespace OctopusWineManagment\DTO;

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
        $this->id = $data['ID'] ?? null;
        $this->productGroupID = $data['ProductGroupID'] ?? null;
        $this->productGroupName = $data['productGroupName'] ?? null;
        $this->price = $data['Price'] ?? 0.0;
        $this->vatPercent = $data['VatPercent'] ?? 0.0;
        $this->storageLocation = $data['StorageLocation'] ?? null;
        $this->costPrice = $data['CostPrice'] ?? 0.0;
        
        if (isset($data['Attributes'])) {
            foreach ($data['Attributes'] as $attr) {
                $name  = $attr['AttributeName'];
                $value = $attr['AttributeValue'];
                if (isset($this->attributes[$name])) {
                    if (!is_array($this->attributes[$name])) {
                        $this->attributes[$name] = [$this->attributes[$name]];
                    }
                    $this->attributes[$name][] = $value;
                } else {
                    $this->attributes[$name] = $value;
                }
            }
        }
    }

    /**
     * Helper to get a specific attribute value (returns first value if multiple exist)
     */
    public function getAttribute($name) {
        $value = $this->attributes[$name] ?? null;
        return is_array($value) ? $value[0] : $value;
    }

    /**
     * Returns all values for an attribute as an array
     */
    public function getAttributeValues($name) {
        $value = $this->attributes[$name] ?? null;
        if ($value === null) return [];
        return is_array($value) ? $value : [$value];
    }
}
