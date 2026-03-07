<?php

namespace OctopusWineManagment\DTO;

/**
 * Data Transfer Object for a single HotelBoss product.
 *
 * Constructed from the raw associative array returned by the
 * GET /api/Products/GetProducts endpoint. Named attributes (Country, Region,
 * Wine, etc.) are parsed from the nested 'Attributes' array into a keyed map
 * so callers can access them by name via getAttribute() / getAttributeValues().
 */
class ProductDTO {
    public $id;
    public $name;
    public $productGroupID;
    public $productGroupName;
    public $price;
    public $storageLocation;
    public $quantity;

    /**
     * Keyed map of attribute name → value (string) or values (string[]).
     * An attribute becomes an array when the API returns it more than once for
     * the same product (e.g. two grape varieties).
     *
     * @var array<string, string|string[]>
     */
    public $attributes = [];

    /**
     * @param array $data  Raw product array from the API JSON response.
     */
    public function __construct($data) {
        $this->id               = $data['ID']              ?? null;
        $this->name             = $data['name']            ?? '';
        $this->productGroupID   = $data['ProductGroupID']  ?? null;
        $this->productGroupName = $data['productGroupName'] ?? null;
        $this->price            = $data['Price']           ?? 0.0;
        $this->storageLocation  = $data['StorageLocation'] ?? null;

        // Flatten the Attributes array into a name-keyed map.
        // If the same attribute name appears more than once, upgrade the value
        // to an array so all values are preserved.
        if (isset($data['Attributes'])) {
            foreach ($data['Attributes'] as $attr) {
                $name  = $attr['AttributeName'];
                $value = $attr['AttributeValue'];
                if (isset($this->attributes[$name])) {
                    // Promote scalar to array on the second occurrence.
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
     * Returns the first value for the named attribute, or null if absent.
     * Use this when you only need a single representative value (e.g. Volume).
     *
     * @param string $name  Attribute name, e.g. 'Country', 'Vintage'.
     * @return string|null
     */
    public function getAttribute($name) {
        $value = $this->attributes[$name] ?? null;
        return is_array($value) ? $value[0] : $value;
    }

    /**
     * Returns all values for the named attribute as a flat array.
     * Returns an empty array if the attribute is absent.
     *
     * @param string $name  Attribute name, e.g. 'Grape'.
     * @return string[]
     */
    public function getAttributeValues($name) {
        $value = $this->attributes[$name] ?? null;
        if ($value === null) return [];
        return is_array($value) ? $value : [$value];
    }

    public function hasAttributes() {
      return !empty($this->attributes ?? []);
    }
}
